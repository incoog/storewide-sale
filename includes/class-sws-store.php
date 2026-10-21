<?php
defined( 'ABSPATH' ) || exit;

/** Durable per-product journal. Deactivation never deletes restoration data. */
final class SWS_Store {
    private static $cache = array();
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'sws_products';
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            campaign_id varchar(36) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL,
            needs_choice tinyint(1) NOT NULL DEFAULT 0,
            is_conflict tinyint(1) NOT NULL DEFAULT 0,
            payload longtext NOT NULL,
            PRIMARY KEY  (campaign_id,product_id),
            KEY campaign_status (campaign_id,status),
            KEY campaign_choice (campaign_id,needs_choice)
        ) $collate;" );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
            update_option( 'sws_schema_version', SWS_VERSION, false );
        }
    }
    public static function campaign() {
        $state = get_option( 'sws_campaign', array() );
        return is_array( $state ) ? $state : array();
    }
    public static function state( $state ) {
        update_option( 'sws_campaign', $state, false );
        if ( self::campaign() !== $state ) {
            throw new RuntimeException( 'Kunde inte spara kampanjens tillstånd.' );
        }
    }
    public static function get( $campaign, $id, $fresh = false ) {
        global $wpdb;
        $key = $campaign . ':' . $id;
        if ( ! $fresh && array_key_exists( $key, self::$cache ) ) {
            return self::$cache[ $key ];
        }
        $json = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . self::table() . ' WHERE campaign_id=%s AND product_id=%d', $campaign, $id ) );
        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'Kunde inte läsa återställningsdata.' );
        }
        $row = null === $json ? null : json_decode( $json, true );
        if ( null !== $json && ! is_array( $row ) ) {
            throw new RuntimeException( 'Produktens återställningsdata kan inte läsas.' );
        }
        self::$cache[ $key ] = $row;
        return $row;
    }
    public static function put( $state, $id, $row ) {
        global $wpdb;
        $json = wp_json_encode( $row );
        if ( false === $json || false === $wpdb->replace( self::table(), array(
            'campaign_id' => $state['id'], 'product_id' => $id,
            'status' => $row['status'], 'needs_choice' => ! empty( $row['conflict'] ) && empty( $row['choice'] ) ? 1 : 0,
            'is_conflict' => ! empty( $row['conflict'] ) ? 1 : 0, 'payload' => $json,
        ), array( '%s', '%d', '%s', '%d', '%d', '%s' ) ) ) {
            throw new RuntimeException( 'Kunde inte spara produktens återställningsdata. Priset har inte skrivits över.' );
        }
        self::$cache[ $state['id'] . ':' . $id ] = $row;
    }
    public static function rows( $state, $kind = 'open', $limit = 50, $offset = 0 ) {
        global $wpdb;
        $conditions = array(
            'open' => "status <> 'done'", 'conflicts' => 'needs_choice=1',
            'review' => "status='managed' AND is_conflict=1", 'manual' => "status='manual'",
            'pending' => "status IN ('pending','restoring')",
        );
        $where = isset( $conditions[ $kind ] ) ? $conditions[ $kind ] : $conditions['open'];
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT product_id,payload FROM ' . self::table() . " WHERE campaign_id=%s AND $where ORDER BY product_id LIMIT %d OFFSET %d", $state['id'], $limit, $offset ), ARRAY_A );
        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'Kunde inte läsa produktlistan.' );
        }
        foreach ( $rows as &$row ) {
            $row['data'] = json_decode( $row['payload'], true );
            if ( ! is_array( $row['data'] ) ) {
                throw new RuntimeException( 'En produkts återställningsdata är skadad.' );
            }
            unset( $row['payload'] );
        }
        return $rows;
    }
    public static function counts( $state ) {
        global $wpdb;
        if ( empty( $state['id'] ) ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n,SUM(needs_choice) AS conflicts FROM ' . self::table() . ' WHERE campaign_id=%s GROUP BY status', $state['id'] ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Kunde inte läsa kampanjens status.' ); }
        $out = array( 'conflicts' => 0 );
        foreach ( $rows as $row ) {
            $out[ $row['status'] ] = (int) $row['n'];
            $out['conflicts'] += (int) $row['conflicts'];
        }
        return $out;
    }
    /** Atomic, expiring lease; release compares the token to avoid deleting a newer lease. */
    public static function lock() {
        global $wpdb;
        $token = wp_json_encode( array( 'token' => wp_generate_uuid4(), 'expires' => time() + 120 ) );
        if ( ! add_option( 'sws_lock', $token, '', false ) ) {
            $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'sws_lock' ) );
            $data = json_decode( (string) $old, true );
            if ( is_array( $data ) && isset( $data['expires'] ) && (int) $data['expires'] < time() ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'sws_lock', $old ) );
                wp_cache_delete( 'sws_lock', 'options' );
                if ( add_option( 'sws_lock', $token, '', false ) ) { return $token; }
            }
            throw new RuntimeException( 'En kampanjåtgärd pågår. Försök igen om några sekunder.' );
        }
        return $token;
    }
    public static function unlock( $token ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'sws_lock', $token ) );
        wp_cache_delete( 'sws_lock', 'options' );
    }
}
