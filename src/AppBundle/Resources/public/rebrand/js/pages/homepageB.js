// homepageB.js

require('../../sass/pages/homepageB.scss');

// Require BS component(s)
require('bootstrap/js/dist/carousel');
require('bootstrap/js/dist/toast');
require('bootstrap/js/dist/util');

// Require components
require('../components/phone-search-dropdown-card.js');
let textFit = require('textfit');

// Lazy load images
require('intersection-observer');
import lozad from 'lozad';

const observer = lozad(); // lazy loads elements with default selector as '.lozad'
observer.observe();

$(function() {

    textFit($('.fit'), {detectMultiLine: false});

    let videoCont = $('#hero_video'),
        video = $('#hero_video').get(0),
        play  = $('#play_video'),
        quote = $('#get_a_quote_video');

    $('#watch_tv_advert').on('click', function(e) {
        e.preventDefault();

        // Reset
        quote.hide();
        videoCont.css('filter', 'brightness(1)');

        let diff = 50;

        if( /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ) {
            diff = 100;
        }

        $('html, body').animate({
            scrollTop: ($('#cred').offset().top + diff)
        }, 500);

        if (video.paused) {
            video.play();
            videoCont.attr('controls', 'controls');
            play.fadeOut();
        } else {
            video.pause();
        }
    });

    play.on('click', function(e) {
        e.preventDefault();

        if (video.paused) {
            video.play();
            videoCont.attr('controls', 'controls');
            $(this).fadeOut();
        } else {
            video.pause();
        }
    });

    videoCont.on('ended', function(e) {
        quote.fadeIn();
        $(this).css('filter', 'brightness(50%)');
    });

});

