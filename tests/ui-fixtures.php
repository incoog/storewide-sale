<?php
if ( PHP_SAPI !== 'cli' || getenv( 'SWS_TEST_RUN' ) !== '1' || ! defined( 'ABSPATH' ) || get_option( 'sws_test_environment' ) !== 'disposable' ) { exit( 1 ); }
$category = wp_insert_term( 'UI Porcelain', 'product_cat' );
$product = new WC_Product_Simple();
$product->set_name( 'UI Vas' ); $product->set_sku( 'UI-VASE-1' ); $product->set_regular_price( 1000 ); $product->set_status( 'publish' ); $product->set_date_created( '2020-01-01' ); $product->save();
$fake = WP_PLUGIN_DIR . '/sws-ui-soft79';
wp_mkdir_p( $fake );
file_put_contents( $fake . '/plugin.php', "<?php\n/*\nPlugin Name: SOFT79 Pricing Rules for WooCommerce\nText Domain: soft79-wc-pricing-rules\n*/" );
wp_clean_plugins_cache();
delete_transient( '_wc_activation_redirect' );
