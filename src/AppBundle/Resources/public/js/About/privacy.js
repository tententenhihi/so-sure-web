$(document).ready(function () {
    $('.scroll-to-link').click(function(e) {
        e.preventDefault();
        var link = $(this).data('link');
        $(this).closest('.panel-collapse').collapse('hide');
        $(link).closest('.panel-collapse').collapse('show');
        $('html, body').animate({
            scrollTop: $(link).offset().top - 500
        }, 2000).bind(link);
    });

    $('.print').click(function() {
        //$('#privacy-accordion .collapse').collapse('show');
        window.print();
    });
});