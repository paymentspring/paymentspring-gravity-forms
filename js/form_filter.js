function create_token (callback) {
    var card_number =     jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_1").val();
    var exp_month =       jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_2_month").val();
    var exp_year =        jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_2_year").val();
    var csc =             jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_3").val();
    var cardholder_name = jQuery("#gform_{$form_id} #input_{$form_id}_{$cc_field_id}_5").val();

    paymentspring.generateToken( "{$public_key}", card_number, csc, cardholder_name, exp_month, exp_year, callback);
}

function token_callback (response) {
    var form = jQuery("#gform_{$form_id}");
    if (response.errors) {
        form.append("<input type='hidden' name='token_error' value='" + JSON.stringify(response.errors) + "' />");
    }
    else {
        form.append("<input type='hidden' name='card_exp_month' value='"  + response["card_exp_month"] + "' />");
        form.append("<input type='hidden' name='card_exp_year' value='"   + response["card_exp_year"] + "' />");
        form.append("<input type='hidden' name='card_owner_name' value='" + response["card_owner_name"] + "' />");
        form.append("<input type='hidden' name='card_type' value='"       + response["card_type"] + "' />");
        form.append("<input type='hidden' name='token_id' value='"        + response["id"] + "' />");
        form.append("<input type='hidden' name='card_last_4' value='"     + response["last_4"] + "' />");
    }
    //form.append("<input type='hidden' name='token_response' value='" + JSON.stringify(response) + "' />");
    console.log(response);
    form.get(0).submit();
}

jQuery(document).bind("gform_post_render", function (event, f_id, current_page) {
    if (f_id !== {$form_id}) {
        return;
    }
    jQuery("#gform_{$form_id}").submit(function () {
        create_token(token_callback);
        return false;
    });
});
