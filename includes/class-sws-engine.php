<?php
defined( 'ABSPATH' ) || exit;

final class SWS_Engine {
    private $writing = false;
    private $pending = array();
    const JOB = 'sws_process_campaign';

    public function register() {
        add_action( self::JOB, array( $this, 'background' ) );
        add_action( 'woocommerce_before_product_object_save', array( $this, 'before_save' ), 99 );
        add_action( 'woocommerce_after_product_object_save', array( $this, 'after_save' ), 99 );
        add_filter( 'soft79_wcpr_product_pricing_enabled', array( $this, 'soft79_enabled' ), 20, 2 );
        add_action( 'shutdown', array( $this, 'release_pending' ) );
        add_action( 'deleted_post', array( $this, 'deleted_product' ) );
    }
    public static function running( $state ) {
        return isset( $state['phase'] ) && in_array( $state['phase'], array( 'starting', 'active', 'stopping' ), true );
    }
    public static function snapshot( $product ) {
        $from = $product->get_date_on_sale_from( 'edit' );
        $to = $product->get_date_on_sale_to( 'edit' );
        return array( 'sale' => (string) $product->get_sale_price( 'edit' ), 'from' => $from ? $from->getTimestamp() : null, 'to' => $to ? $to->getTimestamp() : null );
    }
    public static function same( $a, $b ) {
        $price_equal = ( '' === $a['sale'] || '' === $b['sale'] ) ? $a['sale'] === $b['sale'] : (float) $a['sale'] === (float) $b['sale'];
        return $price_equal && $a['from'] === $b['from'] && $a['to'] === $b['to'];
    }
    public static function price( $regular, $percent ) {
        if ( '' === $regular || ! is_numeric( $regular ) || (float) $regular <= 0 ) { return ''; }
        $price = wc_format_decimal( (float) $regular * ( 1 - (float) $percent / 100 ), wc_get_price_decimals() );
        return (float) $price < (float) $regular ? $price : '';
    }
    public static function target( $regular, $percent ) {
        return array( 'sale' => self::price( $regular, $percent ), 'from' => null, 'to' => null );
    }
    public function schedule( $delay = 10 ) {
        if ( ! wp_next_scheduled( self::JOB ) ) { wp_schedule_single_event( time() + $delay, self::JOB ); }
    }
    public function start( $input ) {
        $token = SWS_Store::lock();
        try {
            $previous = SWS_Store::campaign();
            if ( self::running( $previous ) ) { throw new RuntimeException( 'Avsluta den pågående kampanjen först.' ); }
            $percent = isset( $input['percent'] ) && is_scalar( $input['percent'] ) ? wc_format_decimal( $input['percent'] ) : '';
            if ( ! is_numeric( $percent ) || (float) $percent <= 0 || (float) $percent > 100 ) {
                throw new RuntimeException( 'Ange en procentrabatt större än 0 och högst 100.' );
            }
            $cutoff = isset( $input['cutoff'] ) && is_string( $input['cutoff'] ) ? $input['cutoff'] : '';
            $date = $cutoff ? DateTimeImmutable::createFromFormat( '!Y-m-d', $cutoff, wp_timezone() ) : false;
            if ( $cutoff && ( ! $date || $date->format( 'Y-m-d' ) !== $cutoff ) ) { throw new RuntimeException( 'Välj ett giltigt datum.' ); }
            $categories = array();
            if ( ! empty( $input['categories'] ) ) {
                if ( ! is_array( $input['categories'] ) ) { throw new RuntimeException( 'Kategorilistan är ogiltig.' ); }
                foreach ( $input['categories'] as $id ) {
                    if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) || ! term_exists( (int) $id, 'product_cat' ) ) { throw new RuntimeException( 'En vald kategori finns inte.' ); }
                    $categories[] = (int) $id;
                }
            }
            $excluded = $categories;
            foreach ( $categories as $id ) {
                $children = get_term_children( $id, 'product_cat' );
                if ( is_wp_error( $children ) ) { throw new RuntimeException( 'Kunde inte läsa underkategorier.' ); }
                $excluded = array_merge( $excluded, array_map( 'intval', $children ) );
            }
            global $wpdb;
            $max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->posts WHERE post_type IN ('product','product_variation')" );
            $state = array(
                'id' => wp_generate_uuid4(), 'phase' => 'starting', 'percent' => (float) $percent,
                'cutoff' => $cutoff, 'categories' => array_values( array_unique( $categories ) ), 'excluded' => array_values( array_unique( $excluded ) ),
                'soft79' => ! empty( $input['soft79'] ) && 'fork' === self::soft79_status(),
                'cursor' => 0, 'max_id' => $max, 'checked' => 0, 'skipped' => 0,
                'started' => time(), 'finished' => null, 'error' => '',
            );
            SWS_Store::state( $state );
            $this->schedule();
        } finally { SWS_Store::unlock( $token ); }
    }
    public function stop() {
        $token = SWS_Store::lock();
        try {
            $state = SWS_Store::campaign();
            if ( empty( $state['id'] ) || ! self::running( $state ) ) { throw new RuntimeException( 'Det finns ingen pågående kampanj.' ); }
            $counts = SWS_Store::counts( $state );
            if ( ! empty( $counts['conflicts'] ) || ! empty( $counts['pending'] ) ) { throw new RuntimeException( 'Gör klart alla prisval och pågående prisskrivningar innan kampanjen avslutas.' ); }
            $state['phase'] = 'stopping';
            $state['error'] = '';
            SWS_Store::state( $state );
            $this->schedule();
        } finally { SWS_Store::unlock( $token ); }
    }
    public function integration( $enabled ) {
        $token = SWS_Store::lock();
        try {
            $state = SWS_Store::campaign();
            if ( ! self::running( $state ) || 'fork' !== self::soft79_status() ) { throw new RuntimeException( 'Integrationen är inte tillgänglig.' ); }
            $state['soft79'] = (bool) $enabled;
            SWS_Store::state( $state );
        } finally { SWS_Store::unlock( $token ); }
    }
    private function eligible( $product, $state ) {
        if ( ! $product->is_type( array( 'simple', 'variation', 'external' ) ) ) { return false; }
        $parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
        if ( ! $parent || ! in_array( $parent->get_status( 'edit' ), array( 'publish', 'private' ), true ) ) { return false; }
        $created = $parent->get_date_created( 'edit' );
        if ( $state['cutoff'] && ( ! $created || $created->date( 'Y-m-d' ) > $state['cutoff'] ) ) { return false; }
        $terms = wp_get_object_terms( $parent->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $terms ) ) { throw new RuntimeException( 'Kunde inte läsa produktens kategorier.' ); }
        if ( array_intersect( array_map( 'intval', $terms ), $state['excluded'] ) ) { return false; }
        $target = self::price( $product->get_regular_price( 'edit' ), $state['percent'] );
        if ( '' === $target ) { return false; }
        // A storewide sale must not increase an already lower active sale price.
        return ! ( $product->is_on_sale( 'edit' ) && (float) $product->get_sale_price( 'edit' ) <= (float) $target );
    }
    private function finalize( $row, $product ) {
        $row['status'] = $row['after'];
        $row['applied'] = self::snapshot( $product );
        unset( $row['before'], $row['target'], $row['after'], $row['source'], $row['rollback'] );
        return $row;
    }
    /** Journal before saving, making an interrupted write distinguishable on retry. */
    private function write( $product, $state, $row, $target, $after ) {
        $row['before'] = self::snapshot( $product );
        $row['target'] = $target;
        $row['after'] = $after;
        $row['source'] = 'engine';
        $row['status'] = 'done' === $after ? 'restoring' : 'pending';
        SWS_Store::put( $state, $product->get_id(), $row );
        $this->writing = true;
        try {
            $product->set_sale_price( $target['sale'] );
            $product->set_date_on_sale_from( $target['from'] );
            $product->set_date_on_sale_to( $target['to'] );
            $product->save();
        } finally { $this->writing = false; }
        if ( ! self::same( self::snapshot( $product ), $target ) ) {
            throw new RuntimeException( 'En annan funktion ändrade reapriset under sparningen. Körningen har pausats för kontroll.' );
        }
        $row = $this->finalize( $row, $product );
        SWS_Store::put( $state, $product->get_id(), $row );
        $this->sync( $product );
    }
    private function sync( $product ) {
        wc_delete_product_transients( $product->get_id() );
        if ( $product->get_parent_id() ) {
            WC_Product_Variable::sync( $product->get_parent_id() );
            wc_delete_product_transients( $product->get_parent_id() );
        }
    }
    private function manual( $row, $product ) {
        $keep_choice = 'manual' === $row['status'] || ( isset( $row['after'] ) && 'manual' === $row['after'] );
        $manual_end = $keep_choice && isset( $row['manual_end'] ) ? $row['manual_end'] : 'keep';
        $row['status'] = 'manual';
        $row['conflict'] = false;
        $row['choice'] = '';
        $row['manual_end'] = $manual_end;
        $row['regular'] = (string) $product->get_regular_price( 'edit' );
        $row['applied'] = self::snapshot( $product );
        unset( $row['before'], $row['target'], $row['after'], $row['source'], $row['rollback'] );
        return $row;
    }
    private function recover( $id, $row, $state ) {
        $product = wc_get_product( $id );
        if ( ! $product ) { $row['status'] = 'done'; $row['conflict'] = false; SWS_Store::put( $state, $id, $row ); return; }
        $actual = self::snapshot( $product );
        if ( self::same( $actual, $row['target'] ) ) {
            $row = $this->finalize( $row, $product );
        } elseif ( self::same( $actual, $row['before'] ) ) {
            if ( 'external' === $row['source'] ) {
                $row = $row['rollback']; // A failed editor save is not a completed manual override.
            } else {
                if ( (string) $product->get_regular_price( 'edit' ) !== $row['regular'] ) {
                    $row = $this->changed_regular( $row, (string) $product->get_regular_price( 'edit' ) );
                    if ( 'managed' === $row['after'] ) { $row['target'] = self::target( $row['regular'], $state['percent'] ); }
                    elseif ( ! empty( $row['conflict'] ) ) {
                        $row['status'] = 'managed';
                        unset( $row['before'], $row['target'], $row['after'], $row['source'], $row['rollback'] );
                        SWS_Store::put( $state, $id, $row );
                        return;
                    }
                }
                $this->write( $product, $state, $row, $row['target'], $row['after'] );
                return;
            }
        } else {
            $row = $this->manual( $row, $product );
        }
        SWS_Store::put( $state, $id, $row );
        $this->sync( $product );
    }
    private function changed_regular( $row, $regular ) {
        if ( $row['regular'] !== $regular ) {
            $row['regular'] = $regular;
            if ( '' !== $row['original']['sale'] ) { $row['conflict'] = true; $row['choice'] = ''; }
        }
        return $row;
    }
    public static function restored_sale( $row ) {
        $sale = $row['original']['sale'];
        if ( ! empty( $row['conflict'] ) && 'percent' === $row['choice'] ) {
            $sale = self::price( $row['regular'], $row['original_percent'] );
        } elseif ( ! empty( $row['conflict'] ) && 'remove' === $row['choice'] ) { $sale = ''; }
        return $sale;
    }
    private function restore( $id, $row, $state ) {
        $product = wc_get_product( $id );
        if ( ! $product ) { $row['status'] = 'done'; $row['conflict'] = false; SWS_Store::put( $state, $id, $row ); return true; }
        if ( 'managed' === $row['status'] ) {
            if ( ! self::same( self::snapshot( $product ), $row['applied'] ) ) { $row = $this->manual( $row, $product ); }
            else { $row = $this->changed_regular( $row, (string) $product->get_regular_price( 'edit' ) ); }
        }
        if ( ! empty( $row['conflict'] ) && empty( $row['choice'] ) ) {
            SWS_Store::put( $state, $id, $row );
            return false;
        }
        if ( 'manual' === $row['status'] ) {
            if ( 'remove' === $row['manual_end'] ) {
                $this->write( $product, $state, $row, array( 'sale' => '', 'from' => null, 'to' => null ), 'done' );
            } else { $row['status'] = 'done'; SWS_Store::put( $state, $id, $row ); }
            return true;
        }
        $sale = self::restored_sale( $row );
        if ( '' !== $sale && ( '' === $row['regular'] || (float) $sale >= (float) $row['regular'] ) ) {
            $row['conflict'] = true; $row['choice'] = '';
            SWS_Store::put( $state, $id, $row );
            return false;
        }
        $remove_dates = ( ! empty( $row['conflict'] ) && 'remove' === $row['choice'] ) || ( '' === $sale && '' !== $row['original']['sale'] );
        $target = array( 'sale' => $sale, 'from' => $remove_dates ? null : $row['original']['from'], 'to' => $remove_dates ? null : $row['original']['to'] );
        $this->write( $product, $state, $row, $target, 'done' );
        return true;
    }
    public function process() {
        $token = SWS_Store::lock();
        try {
            $state = SWS_Store::campaign();
            if ( ! self::running( $state ) ) { return; }
            $started = microtime( true );
            // Recover journaled writes before moving a cursor or accepting completion.
            foreach ( SWS_Store::rows( $state, 'pending', 10 ) as $entry ) {
                $this->recover( (int) $entry['product_id'], $entry['data'], $state );
                if ( microtime( true ) - $started > 5 ) { $this->schedule(); return; }
            }
            $counts = SWS_Store::counts( $state );
            if ( ! empty( $counts['pending'] ) || ! empty( $counts['restoring'] ) ) { $this->schedule(); return; }
            if ( 'starting' === $state['phase'] ) {
                global $wpdb;
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private') AND ID>%d AND ID<=%d ORDER BY ID LIMIT 20", $state['cursor'], $state['max_id'] ) );
                if ( $wpdb->last_error ) { throw new RuntimeException( 'Kunde inte läsa produktkatalogen.' ); }
                foreach ( $ids as $id ) {
                    $id = (int) $id;
                    if ( ! SWS_Store::get( $state['id'], $id, true ) ) {
                        $product = wc_get_product( $id );
                        if ( $product && $this->eligible( $product, $state ) ) {
                            $original = self::snapshot( $product );
                            $regular = (string) $product->get_regular_price( 'edit' );
                            $row = array( 'status' => 'managed', 'original' => $original, 'original_regular' => $regular,
                                'original_percent' => '' !== $original['sale'] ? 100 * ( 1 - (float) $original['sale'] / (float) $regular ) : 0,
                                'regular' => $regular, 'applied' => $original, 'conflict' => false, 'choice' => '', 'manual_end' => 'keep' );
                            $this->write( $product, $state, $row, self::target( $regular, $state['percent'] ), 'managed' );
                        } else { $state['skipped']++; }
                    }
                    $state['cursor'] = $id;
                    $state['checked']++;
                    SWS_Store::state( $state );
                    if ( microtime( true ) - $started > 5 ) { break; }
                }
                if ( ! $ids ) { $state['phase'] = 'active'; }
            } elseif ( 'stopping' === $state['phase'] ) {
                if ( ! empty( $counts['conflicts'] ) ) { return; }
                $rows = SWS_Store::rows( $state, 'open', 20 );
                foreach ( $rows as $entry ) {
                    if ( ! $this->restore( (int) $entry['product_id'], $entry['data'], $state ) ) { return; }
                    if ( microtime( true ) - $started > 5 ) { break; }
                }
                if ( ! $rows ) { $state['phase'] = 'stopped'; $state['finished'] = time(); }
            }
            $state['error'] = '';
            SWS_Store::state( $state );
            if ( in_array( $state['phase'], array( 'starting', 'stopping' ), true ) ) { $this->schedule(); }
        } catch ( Throwable $error ) {
            // Never replace the original snapshot on retry, even if a product write failed.
            $latest = SWS_Store::campaign();
            if ( self::running( $latest ) ) { $latest['error'] = $error->getMessage(); SWS_Store::state( $latest ); }
            throw $error;
        } finally { SWS_Store::unlock( $token ); }
    }
    public function background() {
        try { $this->process(); } catch ( Throwable $error ) { /* Visible on the settings page; retry is explicit. */ }
    }
    public function before_save( $product ) {
        if ( $this->writing || ! $product->get_id() ) { return; }
        $changes = $product->get_changes();
        if ( ! array_intersect( array_keys( $changes ), array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' ) ) ) { return; }
        $state = SWS_Store::campaign();
        if ( ! self::running( $state ) ) { return; }
        $row = SWS_Store::get( $state['id'], $product->get_id() );
        if ( ! $row || 'done' === $row['status'] ) { return; }
        try { $token = SWS_Store::lock(); }
        catch ( Throwable $error ) { throw new WC_Data_Exception( 'sws_busy', $error->getMessage() ); }
        try {
            $state = SWS_Store::campaign();
            $row = SWS_Store::get( $state['id'], $product->get_id(), true );
            if ( ! self::running( $state ) || ! $row || 'done' === $row['status'] ) { SWS_Store::unlock( $token ); return; }
            if ( in_array( $row['status'], array( 'pending', 'restoring' ), true ) ) {
                $this->recover( $product->get_id(), $row, $state );
                $row = SWS_Store::get( $state['id'], $product->get_id(), true );
                if ( 'done' === $row['status'] ) { SWS_Store::unlock( $token ); return; }
            }
            $previous = $row;
            $actual = wc_get_product( $product->get_id() );
            $before = self::snapshot( $actual );
            if ( ! array_key_exists( 'regular_price', $changes ) ) { $product->set_regular_price( $actual->get_regular_price( 'edit' ) ); }
            if ( ! array_key_exists( 'sale_price', $changes ) ) { $product->set_sale_price( $before['sale'] ); }
            if ( ! array_key_exists( 'date_on_sale_from', $changes ) ) { $product->set_date_on_sale_from( $before['from'] ); }
            if ( ! array_key_exists( 'date_on_sale_to', $changes ) ) { $product->set_date_on_sale_to( $before['to'] ); }
            $incoming = self::snapshot( $product );
            // Only changed sale fields express an override; untouched stale editor fields do not.
            $sale_changed = false;
            foreach ( array( 'sale_price' => 'sale', 'date_on_sale_from' => 'from', 'date_on_sale_to' => 'to' ) as $prop => $key ) {
                if ( array_key_exists( $prop, $changes ) && $incoming[ $key ] !== $before[ $key ] ) { $sale_changed = true; }
            }
            if ( 'managed' === $row['status'] && ! self::same( $before, $row['applied'] ) ) { $sale_changed = true; }
            $regular = array_key_exists( 'regular_price', $changes ) ? (string) $product->get_regular_price( 'edit' ) : (string) $actual->get_regular_price( 'edit' );
            if ( $sale_changed ) {
                $row = $this->manual( $row, $product );
            } elseif ( 'managed' === $row['status'] ) {
                $row = $this->changed_regular( $row, $regular );
                $target = self::target( $regular, $state['percent'] );
                $product->set_sale_price( $target['sale'] );
                $product->set_date_on_sale_from( null );
                $product->set_date_on_sale_to( null );
            }
            $row['regular'] = $regular;
            $after = $row['status'];
            $row['before'] = $before; $row['target'] = self::snapshot( $product );
            $row['after'] = $after; $row['source'] = 'external'; $row['rollback'] = $previous; $row['status'] = 'pending';
            SWS_Store::put( $state, $product->get_id(), $row );
            $this->pending[ spl_object_hash( $product ) ] = array( 'token' => $token, 'state' => $state, 'row' => $row );
            $this->schedule( 30 );
        } catch ( Throwable $error ) {
            SWS_Store::unlock( $token );
            throw new WC_Data_Exception( 'sws_save_failed', $error->getMessage() );
        }
    }
    public function after_save( $product ) {
        $key = spl_object_hash( $product );
        if ( ! isset( $this->pending[ $key ] ) ) { return; }
        $pending = $this->pending[ $key ];
        unset( $this->pending[ $key ] );
        try {
            $row = self::same( self::snapshot( $product ), $pending['row']['target'] ) ? $this->finalize( $pending['row'], $product ) : $this->manual( $pending['row'], $product );
            SWS_Store::put( $pending['state'], $product->get_id(), $row );
        } finally { SWS_Store::unlock( $pending['token'] ); }
    }
    public function deleted_product( $id ) {
        $state = SWS_Store::campaign();
        if ( ! self::running( $state ) ) { return; }
        $row = SWS_Store::get( $state['id'], $id, true );
        if ( $row ) {
            $row['status'] = 'done'; $row['conflict'] = false;
            SWS_Store::put( $state, $id, $row );
        }
    }
    public function release_pending() {
        foreach ( $this->pending as $pending ) { SWS_Store::unlock( $pending['token'] ); }
        $this->pending = array();
    }
    public function decide( $id, $choice, $regular, $campaign ) {
        $token = SWS_Store::lock();
        try {
            $state = SWS_Store::campaign();
            if ( ! self::running( $state ) || $state['id'] !== $campaign ) { throw new RuntimeException( 'Kampanjen har ändrats. Ladda om sidan.' ); }
            $row = SWS_Store::get( $state['id'], $id, true );
            $product = wc_get_product( $id );
            if ( ! $row || ! $product ) { throw new RuntimeException( 'Produkten kunde inte hittas.' ); }
            if ( 'managed' === $row['status'] && ! empty( $row['conflict'] ) ) {
                $current = (string) $product->get_regular_price( 'edit' );
                if ( $current !== $regular || $row['regular'] !== $current ) { throw new RuntimeException( 'Ordinarie pris har ändrats igen. Ladda om sidan och välj på nytt.' ); }
                if ( ! in_array( $choice, array( 'amount', 'percent', 'remove' ), true ) ) { throw new RuntimeException( 'Välj hur reapriset ska återställas.' ); }
                $row['choice'] = $choice;
                $sale = self::restored_sale( $row );
                if ( '' !== $sale && ( '' === $current || (float) $sale >= (float) $current ) ) { throw new RuntimeException( 'Reapriset måste vara lägre än ordinarie pris. Välj bibehållen procent eller ta bort reapriset.' ); }
            } elseif ( 'manual' === $row['status'] && in_array( $choice, array( 'keep', 'remove' ), true ) ) { $row['manual_end'] = $choice; }
            else { throw new RuntimeException( 'Produktens status har ändrats. Ladda om sidan.' ); }
            SWS_Store::put( $state, $id, $row );
            if ( 'stopping' === $state['phase'] ) { $this->schedule(); }
        } finally { SWS_Store::unlock( $token ); }
    }
    public static function soft79_status() {
        if ( function_exists( 'soft79_wcpr_get_integration_info' ) ) {
            $info = soft79_wcpr_get_integration_info();
            if ( is_array( $info ) && isset( $info['fork_id'], $info['features'] ) && 'incoog/soft79-wc-pricing-rules' === $info['fork_id'] && in_array( 'product_pricing_exclusion', (array) $info['features'], true ) ) { return 'fork'; }
        }
        if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $original = false; $inactive_fork = false;
        foreach ( get_plugins() as $path => $plugin ) {
            if ( 'soft79-wc-pricing-rules' !== $plugin['TextDomain'] && false === stripos( $plugin['Name'], 'SOFT79 Pricing Rules' ) ) { continue; }
            $data = get_file_data( WP_PLUGIN_DIR . '/' . $path, array( 'fork' => 'SOFT79 Fork ID' ) );
            if ( isset( $data['fork'] ) && 'incoog/soft79-wc-pricing-rules' === $data['fork'] ) { $inactive_fork = true; continue; }
            $original = true;
        }
        return $original ? 'original' : ( $inactive_fork ? 'inactive_fork' : 'absent' );
    }
    public function soft79_enabled( $enabled, $product ) {
        if ( ! $enabled || ! ( $product instanceof WC_Product ) ) { return $enabled; }
        $state = SWS_Store::campaign();
        if ( ! self::running( $state ) || empty( $state['soft79'] ) ) { return $enabled; }
        $row = SWS_Store::get( $state['id'], $product->get_id() );
        if ( ! $row || 'done' === $row['status'] ) { return $enabled; }
        if ( 'manual' === $row['status'] ) { return false; }
        $expected = isset( $row['target'] ) && 'pending' === $row['status'] ? $row['target'] : $row['applied'];
        return self::same( self::snapshot( $product ), $expected ) ? false : $enabled;
    }
}
