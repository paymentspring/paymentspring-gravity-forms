<?php
/**
 * Plugin Name: Gravity Forms PaymentSpring Add-On
 * Plugin URI: http://paymentspring.com/wordpress
 * Description: Integrates Gravity Forms and PaymentSpring.
 * Version: 0.1.0
 * Author: PaymentSpring
 * Author URI: http://paymentspring.com/
 * License: GPL2
 *
 * ----------------------------------------------------------------------------
 * Copyright 2014 PaymentSpring
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as 
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

add_action( "init", array( "GFPaymentSpring", "init" ) );
add_action( "admin_init", array( "GFPaymentSpring", "admin_init" ) );
register_activation_hook( __FILE__, array( "GFPaymentSpring", "activate" ) );

class GFPaymentSpring {

  private static $transaction = "";

  public static function init () {
    load_plugin_textdomain( "gf_paymentspring" );

    add_filter( "gform_field_content", array( "GFPaymentSpring", "block_card_field" ), 10, 5 );
    add_filter( "gform_get_form_filter", array( "GFPaymentSpring", "inject_card_tokenizing_js" ), 10, 2 );

    add_filter( "gform_validation", array( "GFPaymentSpring", "validate_form" ) );
    add_filter( "gform_entry_created", array( "GFPaymentSpring", "process_transaction" ), 10, 2 );
  }

  public static function admin_init () {
    if ( is_admin() ) {
      if ( ! class_exists( "GFForms" ) || ! class_exists( "RGForms" ) ) {
        // Gravity Forms was deactivated, we need to deactivate too.
        deactivate_plugins( plugin_basename( __FILE__ ) );
        return;
      }

      RGForms::add_settings_page("PaymentSpring", array("GFPaymentSpring", "settings_page"), "");
      register_setting( "gf_paymentspring_account_options", "gf_paymentspring_account", array( "GFPaymentSpring", "validate_settings" ) );

      add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), array("GFPaymentSpring", "add_plugin_action_links" ) );

      add_action( "gform_field_standard_settings", array( "GFPaymentSpring", "field_settings_checkbox" ), 10, 2 );
      add_action( "gform_editor_js", array( "GFPaymentSpring", "field_settings_js" ) );

      add_filter( "gform_enable_credit_card_field", "__return_true" );
    }
  }

  public static function activate () {
    if ( ! class_exists( "GFForms" ) || ! class_exists( "RGForms" ) ) {
      deactivate_plugins( plugin_basename( __FILE__ ) );
      wp_die( __( "Please install and activate Gravity Forms first.", "gf_paymentspring" ) );
    }
  }

  /**
   * Inserts the "Settings" link under the plugin entry on the plugin page.
   */
  public static function add_plugin_action_links ( $links ) {
    return array_merge (
      array (
        "<a href='" . self_admin_url( "admin.php?page=gf_settings&subview=PaymentSpring" ) . "'>" . __( "Settings", "gf_paymentspring" ) . "</a>"
      ),
      $links
    );
  }

  public static function validate_form ( $validation_result ) {
    $form = &$validation_result["form"];
    $cc_field = &GFPaymentSpring::get_credit_card_field( $form );
    $current_page = rgpost( "gform_source_page_number_" . $form["id"] );

    if ( $cc_field == false 
         || ! GFPaymentSpring::is_paymentspring_form( $form )
         || $current_page != $cc_field["pageNumber"]
         || RGFormsModel::is_field_hidden( $form, $cc_field, array( ) ) ) {
      // We don't need to validate this form/page.
      return $validation_result;
    }

    $token_id = rgpost( "token_id" );
    $amount = rgpost( "input_" . rgar( $cc_field, "field_paymentspring_amount" ) );

    if ( strpos( $amount, "." ) !== false || strpos( $amount, "," ) !== false ) {
      // The amount field is in a "$1,234.56" format, strip out non-numeric
      // characters to yield the charge amount in cents, e.g. "123456".
      $amount = preg_replace( "/[^0-9]/", "", $amount );
    }
    else {
      // The Total field returns the amount as an integer dollar amount for
      // some reason, convert to cents by multiplying by 100 and truncating.
      $amount = intval( $amount * 100 );
    }

    if ( $token_id == false ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "A PaymentSpring token could not be created. Errors: ", "gf_paymentspring" ) . rgpost( "token_error" );
      return $validation_result;
    }

    if ( ! $amount ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "A suitable amount could not be found: ", "gf_paymentspring" ) . $amount;
      return $validation_result;
    }

    $response = GFPaymentSpring::post_charge( $token_id, $amount );
    error_log( print_r( $amount, true ) );
    error_log( print_r( $response, true ) );

    if ( is_wp_error( $response ) ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "A PaymentSpring charge call failed. Errors: ", "gf_paymentspring" ) . $response->get_error_message();
      return $validation_result;
    }

    if ( ! in_array( $response["response"]["code"], array( 200, 201 ) ) ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "A PaymentSpring charge failed. Errors: ", "gf_paymentspring" ) . $response["body"];
      return $validation_result;
    }

    GFPaymentSpring::$transaction = $response["body"];

    return $validation_result;
  }

  /**
   * Stores transaciton details in GF entry object.
   *
   * gform_entry_created
   */
  public static function process_transaction ( $entry, $form ) {
    if ( empty( GFPaymentSpring::$transaction ) ) {
      return;
    }

    $response = json_decode( GFPaymentSpring::$transaction );
    $entry["payment_status"] = $response->status;
    $entry["payment_date"] = $response->created_at;
    $entry["transaction_id"] = $response->id;
    $entry["payment_amount"] = $response->amount_settled / 100;
    $entry["payment_method"] = "paymentspring";
    $entry["is_fulfilled"] = $response->status == "SETTLED";
    $entry["transaction_type"] = 1; // one-time payment vs. subscription

    $cc_field = GFPaymentSpring::get_credit_card_field( $form );
    $entry[$cc_field["id"] . ".1"] = $response->card_number;

    RGFormsModel::update_lead( $entry );

    GFPaymentSpring::$transaction = "";
  }

  /**
   * Sends token and charge amount to paymentspring servers to charge token
   */
  public static function post_charge ( $token, $amount ) {
    $options = get_option( "gf_paymentspring_account" );
    $url = "http://localhost:9296/api/v1/charge";
    $args = array(
      "method" => "POST",
      "headers" => array(
        "Authorization" => "Basic " . base64_encode( GFPaymentSpring::get_private_key() . ":" )
      ),
      "body" => array(
        "token" => $token,
        "amount" => $amount
      )
    );
    return wp_remote_post( $url, $args );
  }

  /**
   * Returns a reference to the first credit card field found on the provided
   * form.
   */
  public static function &get_credit_card_field ( $form ) {
    foreach ( $form["fields"] as &$field )
    {
      if ($field["type"] == "creditcard" ) {
        return $field;
        }
    }
    return false;
  }

  /**
   * Adds "Use with PaymentSpring?" options to credit card fields on the
   * properties tab of the form editor.
   * 
   * gform_field_standard_settings
   */
  public static function field_settings_checkbox ( $position, $form_id ) {
    // right below Field Label
    if ( $position == 25 ) {
      ?>
      <li class="paymentspring_card_setting field_setting">
        <input type="checkbox" id="field_paymentspring_card_value" onclick="jQuery('#paymentspring_customer_fields').toggle(); SetFieldProperty('field_paymentspring_card', this.checked);" />
        <label for="field_paymentspring_card_value" class="inline"><?php _e( "Use with PaymentSpring?", "gf_paymentspring" ); ?></label>
        <span id="paymentspring_customer_fields">
          <br />
          <label for="field_paymentspring_amount_value" class="inline"><?php _e( "Amount Field", "gf_paymentspring" ); ?></label>
          <select id="field_paymentspring_amount_value" onchange="SetFieldProperty('field_paymentspring_amount', this.value);">
            <option value=""></option>
            <?php 
            $form = RGFormsModel::get_form_meta_by_id( $form_id );
            foreach ( GFPaymentSpring::get_form_fields($form[0]) as $field ) { 
              echo "<option value='" . $field[0] . "'>" . esc_html( $field[1] ) . "</option>\n";
            } ?>
          </select>
        </span>
      </li>
      <?php
    }
  }

  /**
   * Compiles a list of all fields and sub-fields of the given form.
   */
  public static function get_form_fields ( $form ) {
    $fields = array();

    foreach ( $form["fields"] as $field ) {
      if ( is_array( $field["inputs"] ) ) {
        foreach ( $field["inputs"] as $input ) {
          $fields[] = array( $input["id"], GFCommon::get_label( $field, $input["id"] ) );
        }
      }
      else {
        $fields[] = array( $field["id"], $field["label"] );
      }
    }

    return $fields;
  }

  /**
   * Adds JS needed to handle "Use with PaymentSpring?" options on credit card
   * fields on the properties tab in the form editor.
   *
   * gform_editor_js
   */
  public static function field_settings_js () {
    ?>
    <script type='text/javascript'>
      fieldSettings["creditcard"] += ", .paymentspring_card_setting";
      jQuery(document).bind("gform_load_field_settings", function (event, field, form) {
        jQuery("#field_paymentspring_card_value").attr("checked", field["field_paymentspring_card"] == true);
        jQuery("#paymentspring_customer_fields").toggle(field["field_paymentspring_card"] == true);
        jQuery("#field_paymentspring_amount_value").attr("value", field["field_paymentspring_amount"]);
      });
    </script>
    <?php
  }

  /**
   * Stops credit card information from being set to the server by removing 
   * 'name' attributes on the input tags.
   *
   * gform_field_content
   */
  public static function block_card_field ( $input, $field, $value, $lead_id, $form_id ) {
    if ( $field["type"] == "creditcard" and GFPaymentSpring::is_paymentspring_field( $field ) ) {
      // Strip out name="input_X.X" attributes from credit card field.
      return preg_replace("/name\s*=\s*[\"']input_{$field['id']}\.\d+.*?[\"']/", "", $input);
    }
    else {
      return $input;
    }
  }

  /**
   * Adds javascript to the form that will send card information to the 
   * paymentspring server to create a token and then include the token 
   * information as hidden fields in the form.
   *
   * gform_get_form_filter
   */
  public static function inject_card_tokenizing_js ( $form_string, $form ) {
    if ( ! GFPaymentSpring::is_paymentspring_form( $form ) ) {
      return $form_string;
    }
    foreach ( $form["fields"] as $field )
    {
      if ($field["type"] == "creditcard" ) {
        $form_string .= "<script type='text/javascript' src='" . plugins_url("js/paymentspring.js", __FILE__) . "'></script>";
        return $form_string .= "<script type='text/javascript'>" . 
          str_replace( array( "{\$form_id}", "{\$cc_field_id}", "{\$public_key}" ), array( $form["id"], $field["id"], GFPaymentSpring::get_public_key() ), 
                        file_get_contents( WP_PLUGIN_DIR . "/gravity-forms-paymentspring/js/form_filter.js" ) ) . 
        "</script>";
        }
    }
    return $form_string;
  }

  public static function is_paymentspring_form ( $form ) {
    $cc_field = &GFPaymentSpring::get_credit_card_field( $form );
    return GFPaymentSpring::is_paymentspring_field( $cc_field );
  }

  public static function is_paymentspring_field ( $field ) {
    return rgar( $field, "field_paymentspring_card" ) == true;
  }

  public static function get_private_key () {
    $options = get_option( "gf_paymentspring_account" );
    if ( $options["mode"] == "live" ) {
      return $options["live_private_key"];
    }
    else {
      return $options["test_private_key"];
    }
  }

  public static function get_public_key () {
    $options = get_option( "gf_paymentspring_account" );
    if ( $options["mode"] == "live" ) {
      return $options["live_public_key"];
    }
    else {
      return $options["test_public_key"];
    }
  }

  public static function settings_page () {
    ?>
    <form method="post" action="options.php">
      <h3><?php _e( "PaymentSpring Account Info", "gf_paymentspring" ); ?></h3>
      <?php settings_fields( "gf_paymentspring_account_options" ); ?>
      <?php $options = get_option( "gf_paymentspring_account" ); ?>
      <table class="form-table">
        <tr>
          <th>
            <label for="gf_paymentspring_mode"><?php _e( "API Mode", "gf_paymentspring" ); ?></label>
          </th>
          <td>
            <input type="radio" name="gf_paymentspring_account[mode]" id="gf_paymentspring_mode_live" value="live" 
              <?php echo $options['mode'] == 'live' ? 'checked="checked"' : ''; ?> />
            <label class="inline" for="gf_paymentspring_mode_live"><?php _e( "Live", "gf_paymentspring" ); ?></label>

            <input type="radio" name="gf_paymentspring_account[mode]" id="gf_paymentspring_mode_test" value="test" 
              <?php echo $options['mode'] == 'live' ? '' : 'checked="checked"'; ?> />
            <label class="inline" for="gf_paymentspring_mode_test"><?php _e( "Test", "gf_paymentspring" ); ?></label>
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_test_private_key"><?php _e( "Test Private Key", "gf_paymentspring" ); ?></label>
          </th>
          <td>
            <input id="gf_paymentspring_test_private_key" name="gf_paymentspring_account[test_private_key]" value="<?php echo $options['test_private_key']; ?>" />
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_test_public_key"><?php _e( "Test Public Key", "gf_paymentspring" ); ?></label>
          </th>
          <td>
            <input id="gf_paymentspring_test_public_key" name="gf_paymentspring_account[test_public_key]" value="<?php echo $options['test_public_key']; ?>" />
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_live_private_key"><?php _e( "Live Private Key", "gf_paymentspring" ); ?></label>
          </th>
          <td>
            <input id="gf_paymentspring_live_private_key" name="gf_paymentspring_account[live_private_key]" value="<?php echo $options['live_private_key']; ?>" />
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_live_public_key"><?php _e( "Live Public Key", "gf_paymentspring" ); ?></label>
          </th>
          <td>
            <input id="gf_paymentspring_live_public_key" name="gf_paymentspring_account[live_public_key]" value="<?php echo $options['live_public_key']; ?>" />
          </td>
        </tr>
      </table>

      <p class="submit">
        <input type="submit" name="gf_paymentspring_submit" value="Save Settings">
      </p>
    </form>
    <?php
  }

  public static function validate_settings( $input ) {
    return array(
      "mode" => $input["mode"] == "live" ? "live" : "test",
      "test_private_key" => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["test_private_key"] ),
      "test_public_key"  => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["test_public_key"]  ),
      "live_private_key" => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["live_private_key"] ),
      "live_public_key"  => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["live_public_key"]  )  );
  }
}
