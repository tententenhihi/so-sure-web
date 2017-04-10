$(function(){

    $.fn.extend({
        toggleText: function(a, b){
            return this.text(this.text() == b ? a : b);
        }
    });  

    $('#get-quote-btn').click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $('#get-quote').offset().top
        }, 1500);

        $('#search-phone').focus();
    });      

    $('#phone_phone').change(function() {
        $.get('/price/' + this.value + '/', function(data) {
            $('#policy-price').text('£' + data.price);
        });
    });

    $("#how-it-works-btn").click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#how-it-works").offset().top - 100
        }, 1500);
    });


    $("#so-sure-compared-btn").click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#so-sure-compared").offset().top - 200
        }, 1500);
    });


    $('#find-out-more').click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $('#why-so-sure').offset().top
        }, 1500);
    });

    $('#faq-calculator').click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $('#cashback-card').offset().top
        }, 1500);
    });    

    // Collapse Panels - FAQs
    $('.panel-heading').click(function(event) {
        
        event.preventDefault();

        $(this).toggleClass('panel-open');
        $('.panel-open').not(this).removeClass('panel-open');
    });

    $('#myCollapsible').on('show.bs.collapse', function () {
        // do something…
    })

});