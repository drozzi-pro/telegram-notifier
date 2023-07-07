document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);

    ['notice_message', 'notice_status'].forEach((param) => {
         params.delete(param);
    });

    const currentPage = window.location.href.substring(window.location.href.lastIndexOf('/') + 1);
    window.history.pushState({},null, currentPage.split('?')[0] + '?' + params.toString());
});