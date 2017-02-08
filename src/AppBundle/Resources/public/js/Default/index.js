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


    $("#get-started").click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $("#download-now").offset().top
        }, 1500);
    });

    // Sticky Banner - For quotes TODO make modular

    var quoteBox = $('#quote');
    var quoteBoxHeight = quoteBox.height();

    $(window).scroll(function(event) {

        if (quoteBox.length) {

            var quoteBoxBottom = quoteBox.offset().top;

            if ($(window).scrollTop() > quoteBoxBottom + quoteBoxHeight) {

                $('#quote-banner').fadeIn();                

            } else {

                $('#quote-banner').fadeOut();                

            }
            
        }   

    });    
