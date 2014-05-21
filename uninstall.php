<?php
if ( ! defined( "ABSPATH" ) && ! defined(" WP_UNINSTALL_PLUGIN" ) ) {
  exit();
}

delete_option( "gf_paymentspring_account" );
?>
