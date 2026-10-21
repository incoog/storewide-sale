<?php
/**
 * Plugin Name: Storewide Sale for WooCommerce
 * Description: Apply a percentage sale to many products, with exclusions and controlled restoration.
 * Version: 0.2.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * Update URI: https://github.com/incoog/storewide-sale
 * Author: incoog
 * License: GPL-2.0-or-later
 * Text Domain: storewide-sale
 */

defined( 'ABSPATH' ) || exit;
define( 'SWS_VERSION', '0.2.1' );
define( 'SWS_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-sws-store.php';
register_activation_hook( __FILE__, array( 'SWS_Store', 'install' ) );
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Storewide Sale kräver WooCommerce.', 'storewide-sale' ) . '</p></div>';
            }
        } );
        return;
    }
    if ( get_option( 'sws_schema_version' ) !== SWS_VERSION ) {
        SWS_Store::install();
        if ( get_option( 'sws_schema_version' ) !== SWS_VERSION ) {
            add_action( 'admin_notices', function () {
                if ( current_user_can( 'manage_woocommerce' ) ) { echo '<div class="notice notice-error"><p>Storewide Sale kunde inte skapa återställningstabellen. Kontrollera databasens behörigheter innan du aktiverar en kampanj.</p></div>'; }
            } );
            return;
        }
    }
    require_once __DIR__ . '/includes/class-sws-engine.php';
    require_once __DIR__ . '/includes/class-sws-admin.php';
    $engine = new SWS_Engine();
    $engine->register();
    new SWS_Admin( $engine );
}, 30 );
