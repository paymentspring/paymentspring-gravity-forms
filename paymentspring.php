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
  const apiURL = "http://localhost:9296/api/v1/charge";

  public static function init () {
    load_plugin_textdomain( "gf_paymentspring" );

    add_filter( "gform_field_content", array( "GFPaymentSpring", "block_card_field" ), 10, 5 );
    add_filter( "gform_register_init_scripts", array( "GFPaymentSpring", "inject_card_tokenizing_js" ), 10, 3 );

    add_filter( "gform_pre_validation", array( "GFPaymentSpring", "remove_cc_field_requirement" ), 10, 1 );
    add_filter( "gform_validation", array( "GFPaymentSpring", "validate_form" ), PHP_INT_MAX, 1 );
    add_filter( "gform_validation_message", array( "GFPaymentSpring", "card_charged_message" ), 10, 2 );
    add_filter( "gform_entry_post_save", array( "GFPaymentSpring", "process_transaction" ), 10, 2 );

    add_filter( "gform_tooltips", array( "GFPaymentSpring", "add_tooltips" ) );
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

  public static function remove_cc_field_requirement ( $form ) {
    $cc_field = &GFPaymentSpring::get_credit_card_field ( $form );
    if ( GFPaymentSpring::is_paymentspring_field( $cc_field ) ) {
      $cc_field["isRequired"] = false;
    }
    return $form;
  }

  public static function validate_form ( $validation_result ) {
    $form = &$validation_result["form"];
    if ( ! GFPaymentSpring::is_paymentspring_form( $form ) ) {
      return $validation_result;
    }

    $cc_field = &GFPaymentSpring::get_credit_card_field( $form );
    $current_page = rgpost( "gform_source_page_number_" . $form["id"] );

    if ( $validation_result["is_valid"] == false
         || $cc_field == false 
         || $current_page != $cc_field["pageNumber"]
         || RGFormsModel::is_field_hidden( $form, $cc_field, array( ) ) ) {
      // We don't need to validate this form/page.
      return $validation_result;
    }

    $token_id = rgpost( "token_id" );
    $ps_fields = GFPaymentSpring::paymentspring_fields( );
    foreach( GFPaymentSpring::paymentspring_fields( ) as $key => $value ) {
      $id = rgar( $cc_field, "field_paymentspring_{$key}" );
      if ( $id ) {
        $ps_fields[$key] = rgpost( "input_" . str_replace( ".", "_", $id ) );
      }
      else {
        $ps_fields[$key] = null;
      }
    }

    $amount_field = &GFPaymentSpring::get_field_by_id( $form, rgar( $cc_field, "field_paymentspring_amount" ) );
    $amount = $ps_fields["amount"];
    error_log( print_r( $amount, true ) );

    if ( ! $amount_field ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "Amount field configured incorrectly.", "gf_paymentspring" );
      return $validation_result;
    }

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

    $ps_fields["amount"] = $amount;

    if ( $token_id == false ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "A PaymentSpring token could not be created.", "gf_paymentspring" );
      return $validation_result;
    }

    error_log( print_r( $amount, true ) );

    if ( ! $amount || $amount < 0 ) {
      $validation_result["is_valid"] = false;
      $amount_field["failed_validation"] = true;
      $amount_field["validation_message"] = __( "Invalid purchase amount.", "gf_paymentspring" );
      return $validation_result;
    }

    $ps_fields["token"] = $token_id;
    $response = GFPaymentSpring::post_charge( $ps_fields );
    error_log( print_r( $response, true ) );

    if ( is_wp_error( $response ) ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "Could not connect to PaymentSpring. Please contact the site administrator.", "gf_paymentspring" ) 
        . "<br />" . $response->get_error_message();
      return $validation_result;
    }

    if ( ! in_array( $response["response"]["code"], array( 200, 201 ) ) ) {
      $validation_result["is_valid"] = false;
      $cc_field["failed_validation"] = true;
      $cc_field["validation_message"] = __( "Your card could not be charged.", "gf_paymentspring" ) . "<br />" . GFPaymentSpring::format_json_errors( $response["body"] );
      return $validation_result;
    }

    GFPaymentSpring::$transaction = $response["body"];

    return $validation_result;
  }

  public static function format_json_errors ( $json_errors ) {
    $errors = json_decode( $json_errors );
    $errors = $errors->errors;
    $str = "";
    foreach ( $errors as $error ) {
      $str .= "Code " . $error->code . " : " . $error->message . "<br />";
    }
    return $str;
  }

  /**
   * If an additional validation hook is executed after our validation hook and
   * fails GF will prompt the user to fix their error and resubmit, even though
   * their card has already been charged. If a transaction object exists when
   * the validation message is being generated that means the card has already
   * been charged. Therefore we need to intercept the validation message.
   */
  public static function card_charged_message ( $validation_message ) {
    if ( ! empty( GFPaymentSpring::$transaction ) ) {
      $msg = __( "Your card has been charged, but there was an unrelated error. Do not resubmit the form. Please contact the site administrator.", "gf_paymentspring" );
      $s = "<div class='validation_error'>" . $msg . "</div><script>alert('" . $msg . "');</script>";
      $s .= "<script>jQuery(\"form[id^='gform_']\").submit(function(){alert('" . $msg . "');return false;});</script>";
      return $s;
    }
    return $validation_message;
  }

  /**
   * Stores transaciton details in GF entry object.
   *
   * gform_entry_post_save
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

    RGFormsModel::update_lead( $entry );

    // Inserts the last 4 digits of the card into the wp_rg_lead_detail table
    $cc_field = GFPaymentSpring::get_credit_card_field( $form );
    $entry[$cc_field["id"] . ".1"] = $response->card_number;
    GFFormsModel::update_lead_field_value( $form, $entry, $cc_field, 0, $cc_field["id"] . ".1", $response->card_number );

    GFPaymentSpring::$transaction = "";
    return $entry;
  }

  /**
   * Sends token and charge amount to paymentspring servers to charge token
   */
  public static function post_charge ( $params ) {
    $options = get_option( "gf_paymentspring_account" );
    $args = array(
      "method" => "POST",
      "headers" => array(
        "Authorization" => "Basic " . base64_encode( GFPaymentSpring::get_private_key() . ":" )
      ),
      "body" => $params
    );
    return wp_remote_post( GFPaymentSpring::apiURL, $args );
  }

  /**
   * Returns a reference to the first credit card field found on the provided
   * form.
   */
  public static function &get_credit_card_field ( &$form ) {
    foreach ( $form["fields"] as &$field ) {
      if ($field["type"] == "creditcard" ) {
        return $field;
      }
    }
    $null = null;
    return $null;
  }

  /**
   * Returns a reference to the first field on the form with the id provided
   */
  public static function &get_field_by_id ( &$form, $id ) {
    foreach ( $form["fields"] as &$field ) {
      if ($field["id"] == $id ) {
        return $field;
      }
    }
    $null = null;
    return $null;
  }

  public static function paymentspring_fields () {
    return array(
      "amount" => "Amount",
      //"first_name" => "First Name",
      //"last_name" => "Last Name",
      "address_1" => "Address 1",
      "address_2" => "Address 2",
      "city" => "City",
      "state" => "State",
      "zip" => "Zip",
      "phone" => "Phone",
      "fax" => "Fax",
      "website" => "Website",
      "company" => "Company"
    );
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
        <label for="field_paymentspring_card_value" class="inline"><?php _e( "Use with PaymentSpring?", "gf_paymentspring" ); gform_tooltip( "gf_paymentspring_use_card_checkbox" ); ?></label>
        <div id="paymentspring_customer_fields">
          <table style="width:375px">
            <tbody>
              <?php
              $form = RGFormsModel::get_form_meta_by_id( $form_id );
              foreach ( GFPaymentSpring::paymentspring_fields() as $key => $value ) {
                echo "<tr>";
                echo "<td style='width:100px'>";
                echo "<label for='field_paymentspring_{$key}'>{$value}</label>";
                echo "</td>";
                echo "<td>";
                echo "<select style='width:100%' id='field_paymentspring_{$key}' onchange=\"SetFieldProperty('field_paymentspring_{$key}', this.value);\">";
                echo "<option value=''></option>";
                foreach ( GFPaymentSpring::get_form_fields($form[0]) as $field ) { 
                  echo "<option value='" . $field[0] . "'>" . esc_html( $field[1] ) . " (ID: " . $field[0] . ")" . "</option>\n";
                }
                echo "</select>";
                echo "</td>";
                echo "</tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
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
      // Makes anything with the '.paymentspring_card_setting' class appear
      // when the credit card settings drop down is clicked.
      fieldSettings["creditcard"] += ", .paymentspring_card_setting";

      // Initializes inputs to stored values.
      jQuery(document).bind("gform_load_field_settings", function (event, field, form) {
        jQuery("#field_paymentspring_card_value").attr("checked", field["field_paymentspring_card"] == true);
        jQuery("#paymentspring_customer_fields").toggle(field["field_paymentspring_card"] == true);

        var fields = [ <?php foreach ( GFPaymentSpring::paymentspring_fields() as $key => $value ) { echo "'{$key}',"; } ?> ];
        fields.map(function(fname) {
            jQuery("#field_paymentspring_" + fname).attr("value", field["field_paymentspring_" + fname]);
        });
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
  public static function inject_card_tokenizing_js ( $form, $field_values, $is_ajax ) {
    $cc_field = GFPaymentSpring::get_credit_card_field( $form );
    if ( GFPaymentSpring::is_paymentspring_field( $cc_field ) ) {

      GFFormDisplay::add_init_script( $form["id"], "gf_paymentspring_api", GFFormDisplay::ON_PAGE_RENDER, file_get_contents( plugin_dir_path( __FILE__ ) . "js/paymentspring.js" ) );
      GFFormDisplay::add_init_script( $form["id"], "gf_paymentspring_validator", GFFormDisplay::ON_PAGE_RENDER,
          str_replace( array( "{\$form_id}", "{\$cc_field_id}", "{\$public_key}" ), array( $form["id"], $cc_field["id"], GFPaymentSpring::get_public_key() ), 
          file_get_contents( plugin_dir_path( __FILE__ ) . "js/form_filter.js" ) ) );
    }

    return $form;
  }

  public static function is_paymentspring_form ( $form ) {
    $cc_field = GFPaymentSpring::get_credit_card_field( $form );
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
            <label for="gf_paymentspring_mode"><?php _e( "API Mode", "gf_paymentspring" ); ?></label><?php gform_tooltip( "gf_paymentspring_api_mode" ); ?>
          </th>
          <td>
            <input type="radio" name="gf_paymentspring_account[mode]" id="gf_paymentspring_mode_live" value="live"
              <?php echo $options['mode'] == 'live' ? 'checked="checked"' : ''; ?> />
            <label class="inline" for="gf_paymentspring_mode_live"><?php _e( "Live", "gf_paymentspring" ); ?></label>

            <input type="radio" name="gf_paymentspring_account[mode]" id="gf_paymentspring_mode_test" value="test" style="margin-left: 16px"
              <?php echo $options['mode'] == 'live' ? '' : 'checked="checked"'; ?> />
            <label class="inline" for="gf_paymentspring_mode_test"><?php _e( "Test", "gf_paymentspring" ); ?></label>
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_test_private_key"><?php _e( "Test Private Key", "gf_paymentspring" ); ?></label><?php gform_tooltip( "gf_paymentspring_test_private_key" ); ?>
          </th>
          <td>
            <input id="gf_paymentspring_test_private_key" name="gf_paymentspring_account[test_private_key]" style="width:350px" value="<?php echo $options['test_private_key']; ?>" />
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_test_public_key"><?php _e( "Test Public Key", "gf_paymentspring" ); ?></label><?php gform_tooltip( "gf_paymentspring_test_public_key" ); ?>
          </th>
          <td>
            <input id="gf_paymentspring_test_public_key" name="gf_paymentspring_account[test_public_key]" style="width:350px" value="<?php echo $options['test_public_key']; ?>" />
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_live_private_key"><?php _e( "Live Private Key", "gf_paymentspring" ); ?></label><?php gform_tooltip( "gf_paymentspring_live_private_key" ); ?>
          </th>
          <td>
            <input id="gf_paymentspring_live_private_key" name="gf_paymentspring_account[live_private_key]" style="width:350px" value="<?php echo $options['live_private_key']; ?>" />
          </td>
        </tr>
        <tr>
          <th>
            <label for="gf_paymentspring_live_public_key"><?php _e( "Live Public Key", "gf_paymentspring" ); ?></label><?php gform_tooltip( "gf_paymentspring_live_public_key" ); ?>
          </th>
          <td>
            <input id="gf_paymentspring_live_public_key" name="gf_paymentspring_account[live_public_key]" style="width:350px" value="<?php echo $options['live_public_key']; ?>" />
          </td>
        </tr>
      </table>

      <p class="submit">
        <input type="submit" name="gf_paymentspring_submit" class="button-primary" value="<?php _e( "Save Settings" ); ?>">
      </p>
    </form>
    <?php
  }

  public static function validate_settings ( $input ) {
    return array(
      "mode" => $input["mode"] == "live" ? "live" : "test",
      "test_private_key" => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["test_private_key"] ),
      "test_public_key"  => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["test_public_key"]  ),
      "live_private_key" => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["live_private_key"] ),
      "live_public_key"  => preg_replace( "/[^a-zA-Z0-9_]/", "", $input["live_public_key"]  )  );
  }

  public static function add_tooltips ( $tooltips ) {
    $tooltips["gf_paymentspring_api_mode"] = "<h6>" . __( "API Mode" ) . "</h6>" . __( "Select 'Test' mode to run charges in the PaymentSpring test environment. Switch to 'Live' mode when you want to run charges for real." );
    $tooltips["gf_paymentspring_test_private_key"] = "<h6>" . __( "Test Private Key" ) . "</h6>" . __( "Enter your test mode private key." );
    $tooltips["gf_paymentspring_test_public_key"]  = "<h6>" . __( "Test Public Key" )  . "</h6>" . __( "Enter your test mode public key." );
    $tooltips["gf_paymentspring_live_private_key"] = "<h6>" . __( "Live Private Key" ) . "</h6>" . __( "Enter your live mode private key." );
    $tooltips["gf_paymentspring_live_public_key"]  = "<h6>" . __( "Live Public Key" )  . "</h6>" . __( "Enter your live mode public key." );
    $tooltips["gf_paymentspring_use_card_checkbox"]  = "<h6>" . __( "Use with PaymentSpring?" )  . "</h6>" . __( "Check this box if you want to use PaymentSpring to process transactions using card information from this Credit Card field." );
    $tooltips["gf_paymentspring_amount_field"]  = "<h6>" . __( "PaymentSpring Amount Field" )  . "</h6>" . __( "Select the field containing the amount to charge to the card information entered into this field. If new fields are added to this form the form will have to be saved before they appear here." );
    return $tooltips;
  }
}
