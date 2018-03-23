$(function(){

    // Phone data
    var phones = $('#select-phone-data').data('phones');

    console.log(phones);

    // Update the select
    var updatePhones = function() {
        // Make value
        var make = $('.select-phone-make').val();

        // Model Options
        var options = $('.select-phones');

        // Clear incase make changed
        options.empty();

        // If make selected customise default value
        if (make) {
            options.append($('<option />').val('').text('Select your ' + make + ' device'));
        } else {
            options.append($('<option />').val('').text('Select your phone make first'));
        }

        console.log(make);
        console.log(phones[make]);

        // Get phones from list and add to options
        $.each(phones[make], function(key, value) {
            options.append($('<option />').val(key).text(value));
        });
    }

    // When user selects option update results
    $('.select-phone-make').on('change', function(e) {
        // if ($(this).val() != '') {
        //     $('.select-phones').show();
        // } else {
        //     $('.select-phones').hide();
        // }
        updatePhones();
    });

    updatePhones();
});
