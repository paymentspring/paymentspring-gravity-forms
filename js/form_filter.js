function get_card_fields () {
    return {
        "card_number"     : jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_1"),
        "exp_month"       : jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_2_month"),
        "exp_year"        : jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_2_year"),
        "csc"             : jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_3"),
        "cardholder_name" : jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_5")
    };
}

function translate_token_error (error) {
    var code = error.code;
    if (code == 11301 || code == 11303 || code == 11306 || code == 11305 || code == 11302 || code == 11112 || code == 91611) {
        return "Invalid card number.";
    } else if (code == 11111 || code == 21111) {
        return "Invalid card expiration date.";
    } else {
        return "Invalid card information. " + code + ": " + error.message;
    }
}

function create_token (callback) {
    var ccf = get_card_fields();

    paymentspring.generateToken( 
        "{$public_key}", 
        ccf["card_number"].val(), 
        ccf["csc"].val(), 
        ccf["cardholder_name"].val(), 
        ccf["exp_month"].val(), 
        ccf["exp_year"].val(), 
        callback
    );
}

function token_callback (response) {
    var form = jQuery("#gform_{$form_id}");
    if (response.errors) {
        validate_field(false, get_card_fields()["card_number"], jQuery.map(response.errors, function (val, i) { return translate_token_error(val);}).join("<br/>"));
    }
    else {
        form.append("<input type='hidden' name='card_exp_month' value='"  + response["card_exp_month"] + "' />");
        form.append("<input type='hidden' name='card_exp_year' value='"   + response["card_exp_year"] + "' />");
        form.append("<input type='hidden' name='card_owner_name' value='" + response["card_owner_name"] + "' />");
        form.append("<input type='hidden' name='card_type' value='"       + response["card_type"] + "' />");
        form.append("<input type='hidden' name='token_id' value='"        + response["id"] + "' />");
        form.append("<input type='hidden' name='card_last_4' value='"     + response["last_4"] + "' />");
        form.get(0).submit();
    }
}

function validate_field (valid, field, message) {
    if (!valid) {
        if (jQuery(".validation_error").length == 0) {
            jQuery(".gform_heading").after("<div class='validation_error'>There was a problem with your submission. Errors have been highlighted below.</div>");
        }

        // Highlights the error field in red.
        jQuery(".gfield_error").removeClass("gfield_error");
        jQuery("#field_{$form_id}_{$cc_field_id}").addClass("gfield_error");

        // Displays the validation message under the error field.
        jQuery(".validation_message").remove();
        jQuery("#field_{$form_id}_{$cc_field_id}").append("<div class='gfield_description validation_message'>" + message + "</div>");

        field.focus();
        field.select();

        // Things added by GForms to indicate the form is being submitted.
        jQuery(".gform_ajax_spinner").remove();
        window["gf_submitting_{$form_id}"] = false;
    }
    return valid;
}

function validate_card () {
    var ccf = get_card_fields();

    return (validate_field(paymentspring.validateCardNumber(ccf["card_number"].val()), ccf["card_number"], "Please enter a valid card number.") &&
        validate_field(paymentspring.validateCardExpDate(ccf["exp_month"].val(), ccf["exp_year"].val()), ccf["exp_month"], "Please enter a valid expiration date.") &&
        validate_field(paymentspring.validateCSC(ccf["csc"].val()), ccf["csc"], "Please enter a valid CSC number.") &&
        validate_field(paymentspring.validateName(ccf["cardholder_name"].val()), ccf["cardholder_name"], "Please enter a valid cardholder name."));
}

if (!jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_1").is(":hidden")) {
    // Card field is on this page.
    jQuery("#gform_{$form_id}").submit(function () {
        var target = jQuery("#gform_target_page_number_{$form_id}").val();
        var source = jQuery("#gform_source_page_number_{$form_id}").val();
        if (target == 0 ) {
            // Form submission.
            if (validate_card()) {
                create_token(token_callback);
            }
        } else if (target < source) {
            // The user wants to move to the previous page.
            return true;
        } else if (target > source) {
            alert("Credit Card field must be on the last page of a multi-page form.");
        }
        return false;
    });
}
