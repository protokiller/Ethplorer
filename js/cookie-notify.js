$('head').append('<link rel="stylesheet" type="text/css" href="/css/cookie-notify.css">');
$(document).ready(function() {
    var template = $('<div id="cookie-notification">' +
        '<div class="cn-container text-center">' +
            '<span>We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies. <span>Find out more in <a href="https://ethplorer.io/privacy" target="_blank">Privacy Policy</a>.</span></span>' +
            '<a class="agree-using-cookies cn-btn cn-btn-lg cn-btn-primary hidden-xs" href="">Got it</a>' +
            '<a class="agree-using-cookies cn-btn cn-btn-lg cn-btn-block cn-btn-primary visible-xs-inline-block" href="">Got it</a>' +
        '</div>'+
    '</div>');

    // checking cookie
    var matches = document.cookie.match(new RegExp("(?:^|; )agree_to_use=([^;]*)"));
    var agreeToUseCookie = matches && decodeURIComponent(matches[1]);
    if (!agreeToUseCookie) {
        setTimeout(function() {
            $('body').append(template);
            $('.agree-using-cookies').on('click', function() {
                var date = new Date();
                date.setFullYear(date.getFullYear() + 2); // + 2 years
                document.cookie = 'agree_to_use=' + Date.now() + '; path=/; expires=' + date.toUTCString();
                $('#cookie-notification').hide();
                return false;
            });
        }, 200);
    }
});