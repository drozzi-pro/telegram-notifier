<?php

/**
 * Plugin Name: Telegram Notifier
 * Text Domain: telegram-notifier
 * Version: 1.0.0
 * Author: Drozzi Pro
 * Author URI: https://drozzi.pro
 */

if (! class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

define("TELEGRAM_NOTIFIER_TABLE_NAME", (function () {
    global $wpdb;
    return $wpdb->prefix . 'telegram_notifier';
})());


class TelegramNotifier_List_Table extends WP_List_Table
{
    protected bool $in_mailing_list_only;
    protected array $table_data;

    public function __construct(?bool $in_mailing_list_only = false)
    {
        parent::__construct([
            'singular' => 'Пользователь',
            'singular' => 'Пользователи',
            'ajax' => false
        ]);

        $this->in_mailing_list_only = $in_mailing_list_only;
    }

    public function get_columns()
    {
        return [
//            'cb' => '<input type="checkbox" />',
            'full_name' => 'Полное имя',
            'username' => 'Юзернейм в телеграм',
            'in_mailing_list' => 'Действие',
        ];
    }

    public function get_sortable_columns() {
        return [
            'full_name' => ['full_name', true],
            'username' => ['username', true],
        ];
    }

    public function column_default($item, $column_name)
    {
        if ($column_name === 'in_mailing_list') {
            $action_url = admin_url('admin.php');
            $action_name = 'add';
            $text = 'Добавить в расслыку';

            if ($this->in_mailing_list_only) {
                $text = 'Убрать из рассылки';
                $action_name = 'remove';
            }

            $submit_button = get_submit_button($text);

            return '
            <form method="POST" action="' . $action_url . '">
                <input type="hidden" name="action" value="telegram-notifier-' . $action_name . '">
                <input type="hidden" name="id" value="' . $item->id . '">
                ' . $submit_button . '</form>';
        }
        return $item->{$column_name};
    }

    public function usort_reorder($a, $b)
    {
        $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'full_name';
        $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc';
        $result = strcmp($a->{$orderby}, $b->{$orderby});
        return ($order==='asc') ? $result : -$result;
    }

    public function prepare_items()
    {
        global $wpdb;

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [$columns, $hidden, $sortable];

        $is_in_mailing_list_int = intval($this->in_mailing_list_only);

        $query = "SELECT * FROM " . TELEGRAM_NOTIFIER_TABLE_NAME . " WHERE in_mailing_list = " . $is_in_mailing_list_int . ";";

        $this->table_data = $wpdb->get_results($query);
        usort($this->table_data, [$this, 'usort_reorder']);

        $this->items = $this->table_data;
    }
}




class TelegramNotifier
{
    private function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'create_table']);
        register_deactivation_hook(__FILE__, [$this, 'drop_table']);

        add_action('init', [$this, 'init']);
    }

    private function get_telegram_api_url(string $action)
    {
        $key = get_option('telegram_notifier_options')['bot_key'];
        if ($key) {
            return "https://api.telegram.org/bot$key/$action";
        } else {
            throw new Exception('плагин не может работать без Bot Key. Укажите Bot Key в настройках');
        }
    }

    public function create_table()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE " . TELEGRAM_NOTIFIER_TABLE_NAME . " (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        chat_id BIGINT NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        username VARCHAR(255) NOT NULL,
        in_mailing_list BOOL NOT NULL DEFAULT 0
        ) " . $charset_collate . " ;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function drop_table()
    {
        global $wpdb;

        $sql = "DROP TABLE IF EXISTS " . TELEGRAM_NOTIFIER_TABLE_NAME;
        $wpdb->query($sql);

        delete_option('telegram_notifier_options');
    }

    public static function instance()
    {
        static $instance;

        if (null !== $instance) {
            return $instance;
        }

        return $instance = new static;
    }

    public function init()
    {
        if (!class_exists('GFAPI')) {
            add_action('admin_notices', [$this, 'gf_installed_notify']);
            return;
        }

        date_default_timezone_set('Europe/Moscow');

        add_action('admin_init', [$this, 'register_plugin_settings']);
        add_action('admin_menu', [$this, 'add_option_page']);
        add_action('admin_enqueue_scripts', [$this, 'plugin_assets']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('gform_confirmation', [$this, 'send_notifications'], 10, 3);

        $this->set_actions_handlers();
    }

    public function gf_installed_notify()
    {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>Telegram Notifier не работает</strong>, установите плагин GravityForms
            </p>
        </div>
        <?php
    }

    public function register_plugin_settings()
    {
        register_setting('telegram_notifier_options', 'telegram_notifier_options', [
            'sanitize_callback' => [$this, 'telegram_notifier_options_validation'],
            'default' => [
                'bot_key' => null,
                'gf_list' => []
            ],
            'type' => 'array'
        ]);
        add_settings_section('telegram_notifier_main', 'Основные настройки', [$this, 'empty_template'], 'telegram_notifier');
        add_settings_field('telegram_notifier_bot_key_input', 'Bot Key', [$this, 'bot_key_input'], 'telegram_notifier', 'telegram_notifier_main');
        add_settings_field('telegram_notifier_gravity_form_select', '', [$this, 'empty_template'], 'telegram_notifier', 'telegram_notifier_main');
    }

    public function telegram_notifier_options_validation($input)
    {
        $option_name = 'telegram_notifier_options';
        $message = 'Настройки успешно обновлены';
        $type = 'updated';

        $options = get_option('telegram_notifier_options');


        if ($options['gf_list'] !== $input['gf_list'] && $input['gf_list'] !== null) {
            return $input;
        }

        $bot_key_val = trim(strip_tags($input['bot_key']));

        if (preg_match('/^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$/', $bot_key_val)) {
            $options['bot_key'] = $bot_key_val;
        } elseif (empty($bot_key_val)) {
            $message = '"Bot Key" не должен быть пустым';
            $type = 'error';
        } else {
            $message = '"Bot Key" должен содержать валидный ключ';
            $type = 'error';
        }

        add_settings_error($option_name, 'settings_updated', $message, $type);

        return $options;
    }

    public function empty_template()
    {
        echo '<div class="empty"></div>';
    }

    public function bot_key_input()
    {
        $options = get_option('telegram_notifier_options');
        ?>
            <input
                id='telegram_notifier_bot_key_input'
                class='normal-text code'
                name='telegram_notifier_options[bot_key]'
                size='50' type='text'
                value='<?=$options['bot_key']?>'
            />
        <?php
    }

    public function gf_list_option()
    {
        $options = get_option('telegram_notifier_options');
        $forms = array_filter(GFAPI::get_forms(), function ($form) use ($options) {
            return !in_array($form['id'], $options['gf_list']);
        });
        $admin_url = admin_url('admin.php');

        ?>
            <div class="telegram-notifier_gf-list_setting">
                <div class="telegram-notifier_gf-list_items">
                    <?php foreach ($options['gf_list'] as $gf_id) :
                        $form = GFAPI::get_form($gf_id);
                        ?>
                        <form class="telegram-notifier_gf-list_remove-form" method="POST" action="<?=$admin_url?>">
                            <input type="hidden" name="action" value="telegram-notifier-gf-remove">
                            <input type="hidden" name="gf" value="<?=$form['id']?>">
                            <span>
                                <?=$form['title'] ?? ''?>
                            </span>
                            <?=get_submit_button('X')?>
                        </form>
                    <?php endforeach; ?>
                </div>

                <?php if ($forms) : ?>
                    <form class="telegram-notifier_gf-list_add-form" action="<?=$admin_url?>" method="POST">
                        <input type="hidden" name="action" value="telegram-notifier-gf-add">
                        <select name="gf">
                            <?php foreach ($forms as $form) : ?>
                                <option value="<?=$form['id']?>">
                                    <?=$form['title']?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?=get_submit_button('+')?>
                    </form>
                <?php endif; ?>
            </div>
        <?php
    }

    public function add_option_page()
    {
        add_submenu_page(
            'options-general.php',
            'Telegram Notifier Settings',
            'Telegram Notifier',
            'administrator',
            'telegram-notifier-options',
            [$this, 'telegram_notifier_options_page']
        );
    }

    public function telegram_notifier_options_page()
    {
        $in_mailing_list_table = new TelegramNotifier_List_Table(true);
        $not_in_mailing_list_table = new TelegramNotifier_List_Table();

        $in_mailing_list_table->prepare_items();
        $not_in_mailing_list_table->prepare_items();

        ?>
        <div class="wrap">
            <h1>Telegram notifier</h1>

            <form action="options.php" method="POST">
                <?php
                settings_errors('telegram_notifier');
                settings_fields('telegram_notifier_options');
                do_settings_sections('telegram_notifier');
                submit_button();
                ?>
            </form>

            <h2>Формы для рассылки</h2>
            <?php $this->gf_list_option() ?>

            <h2>Добавлены в список рассылки</h2>
            <?php $in_mailing_list_table->display(); ?>

            <h2>Остальные пользователи</h2>
            <form class="telegram-notifier_update-form" method="POST" action="<?=admin_url('admin.php')?>">
                <input type="hidden" name="action" value="telegram-notifier-update">
                <?php submit_button('Обновить список пользователей'); ?>
            </form>
            <?php $not_in_mailing_list_table->display(); ?>
        </div>
        <?php
    }

    public function plugin_assets()
    {
        wp_enqueue_style('telegram_notifier_style', plugins_url('/src/index.css', __FILE__));
        wp_enqueue_script('telegram_notifier_script', plugins_url('/src/index.js', __FILE__));
    }

    public function admin_notices()
    {
        $message = $_GET['notice_message'] ?? null;
        $status = $_GET['notice_status'] ?? '';

        if (!$message) {
            return;
        }
        ?>
        <div class="notice notice-<?=$status?> is-dismissible">
            <p>
                <?=$message?>
            </p>
        </div>
        <?php
    }

    public function send_notifications($confirmation, $form, $entry)
    {
        try {
            global $wpdb;

            if (!in_array($form['id'], get_option('telegram_notifier_options')['gf_list'])) {
                return;
            }

            $url = $this->get_telegram_api_url('sendMessage');
            $chats = $wpdb->get_col("SELECT chat_id FROM " . TELEGRAM_NOTIFIER_TABLE_NAME . " WHERE in_mailing_list = 1");
            $nl = urlencode("\n");

            foreach ($form['notifications'] as $notification) {
                foreach ($chats as $chat) {
                    $raw = GFCommon::replace_variables($notification['message'], $form, $entry);
                    $with_nl = strip_tags(preg_replace('/\<\/font\>/', $nl, $raw));
                    $text = trim(preg_replace("/$nl\s/", $nl, preg_replace('/\s+/', ' ', preg_replace('/\&nbsp\;/', ' ', $with_nl))));
                    $text .= $nl . date("d.m.Y, g:i");
                    $text = $form['title'] . $nl . $nl . $text;
                    file_get_contents($url . "?chat_id=$chat&text=$text&parse_mode=html");
                }
            }
        } catch (Exception $exception) {}
        finally {
            return $confirmation;
        }
    }

    public function set_actions_handlers()
    {
        add_action('admin_action_telegram-notifier-update', [$this, 'update_action_handler']);
        add_action('admin_action_telegram-notifier-add', [$this, 'add_action_handler']);
        add_action('admin_action_telegram-notifier-remove', [$this, 'remove_action_handler']);
        add_action('admin_action_telegram-notifier-gf-add', [$this, 'gf_add_action_handler']);
        add_action('admin_action_telegram-notifier-gf-remove', [$this, 'gf_remove_action_handler']);
    }

    public function action_handler_wrapper(callable $handler)
    {
        $message_prefix = 'Telegram Notifier: ';
        $default_error_message = 'внутренняя ошибка, обратитесь к разработчикам';
        $message = 'успех!';
        $status = 'success';


        try {
            $handler_message = $handler() ?? null;
            $message = $message_prefix . ($handler_message ?? $message);
        } catch (Exception $exception) {
            $exception_msg = $exception->getMessage();
            $message = $message_prefix . ($exception_msg ?: $default_error_message);
            $status = 'error';
        } finally {
            wp_redirect(add_query_arg([
                'notice_message' => $message,
                'notice_status' => $status,
            ], $_SERVER['HTTP_REFERER']));
            exit();
        }
    }

    public function update_action_handler()
    {
        $this->action_handler_wrapper(function () {
            global $wpdb;

            $url = $this->get_telegram_api_url('getUpdates');
            $response = json_decode(file_get_contents($url));

            if ($response->ok) {
                foreach ($response->result as $item) {
                    $chat = $item->message->chat;
                    $chat_id = $chat->id;

                    $user_exists = $wpdb->get_var(
                        $wpdb->prepare('SELECT chat_id FROM ' . TELEGRAM_NOTIFIER_TABLE_NAME . ' WHERE chat_id = %d', $chat_id)
                    );

                    if ($user_exists) {
                        continue;
                    }

                    $full_name = $chat->first_name;
                    $last_name = $chat->last_name;
                    $full_name .= $last_name ? " $last_name" : '';

                    $wpdb->insert(TELEGRAM_NOTIFIER_TABLE_NAME, [
                        'chat_id' => $chat->id,
                        'full_name' => $full_name,
                        'username' => $chat->username,
                    ], ['%d', '%s', '%s']);
                }
            }
        });
    }

    public function add_action_handler()
    {
        $this->action_handler_wrapper(function () {
            global $wpdb;

            $user_id = $_POST['id'] ?? null;

            if (!$user_id) {
                throw new Exception();
            }

            $wpdb->update(
                TELEGRAM_NOTIFIER_TABLE_NAME,
                ['in_mailing_list' => 1],
                ['id' => $user_id],
                ['%d']
            );

            return 'пользователь добавлен в список рассылки';
        });
    }

    public function remove_action_handler()
    {
        $this->action_handler_wrapper(function () {
            global $wpdb;

            $user_id = $_POST['id'] ?? null;

            if (!$user_id) {
                throw new Exception();
            }

            $wpdb->update(
                TELEGRAM_NOTIFIER_TABLE_NAME,
                ['in_mailing_list' => 0],
                ['id' => $user_id],
                ['%d']
            );

            return 'пользователь удалён из список рассылки';
        });
    }

    public function gf_add_action_handler()
    {
        $this->action_handler_wrapper(function () {
            $form_id = intval($_POST['gf']);
            $options = get_option('telegram_notifier_options');
            $options['gf_list'][] = $form_id;
            update_option('telegram_notifier_options', $options);
        });
    }

    public function gf_remove_action_handler()
    {
        $this->action_handler_wrapper(function () {
            $form_id = intval($_POST['gf']);
            $options = get_option('telegram_notifier_options');

            if (($key = array_search($form_id, $options['gf_list'])) !== false) {
                unset($options['gf_list'][$key]);
                update_option('telegram_notifier_options', $options);
            }
        });
    }
}

TelegramNotifier::instance();
