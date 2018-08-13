// getQuote.js

require('./select.js');

$(function() {

    const quoteToggle = $('.get-a-quote'),
          quoteModal  = $('.getquote'),
          logo        = $('.navbar-brand__logo');

    quoteToggle.on('click', function(e) {
        e.preventDefault();

        // Toggle logo class
        if (!$('body').hasClass('quote')) {
            logo.toggleClass('navbar-brand__logo-white-light');
        }

        // Prevent scrolling whilst open
        $('body').toggleClass('body--overflow');

        quoteModal.toggleClass('getquote--open');
    });
});
