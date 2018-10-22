// modalOnboarding.js

$(function() {

    const carousel   = $('#onboarding-carousel'),
          indicators = $('.onboarding-indicators li');

    let clicked = false;

    // TODO: Refactor to be dynamic and handle any number of slides
    carousel.on('slide.bs.carousel', function(e){

        // Adjust controls dependant on slide
        if (e.to >= 1) {
            $('#onboarding-prev-btn').removeClass('disabled');
        } else {
            $('#onboarding-prev-btn').addClass('disabled');
        }

        if (e.to == 2) {
            $('#onboarding-next-btn').addClass('hide-controls');
            $('#onboarding-get-started-btn').removeClass('hide-controls');
        } else {
            $('#onboarding-next-btn').removeClass('hide-controls');
            $('#onboarding-get-started-btn').addClass('hide-controls');
        }

    });

});



