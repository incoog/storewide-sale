<?php
defined( 'ABSPATH' ) || exit;

final class SWS_Admin {
    private $engine;
    public function __construct( $engine ) {
        $this->engine = $engine;
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_sws_action', array( $this, 'action' ) );
        add_action( 'wp_ajax_sws_process', array( $this, 'ajax' ) );
        add_action( 'wp_ajax_sws_search', array( $this, 'search' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'woocommerce_product_options_pricing', array( $this, 'product_notice' ) );
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'variation_notice' ), 20, 3 );
        add_filter( 'plugin_action_links_' . plugin_basename( SWS_FILE ), array( $this, 'links' ) );
    }
    public function links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=storewide-sale' ) ) . '">Inställningar</a>' );
        return $links;
    }
    public function menu() {
        add_submenu_page( 'woocommerce', 'Storewide Sale', 'Storewide Sale', 'manage_woocommerce', 'storewide-sale', array( $this, 'render' ) );
    }
    public function assets( $hook ) {
        if ( 'woocommerce_page_storewide-sale' !== $hook ) { return; }
        wp_enqueue_style( 'select2', WC()->plugin_url() . '/assets/css/select2.css', array(), WC_VERSION );
        wp_enqueue_style( 'sws-admin', plugins_url( 'assets/admin.css', SWS_FILE ), array( 'select2' ), SWS_VERSION );
        wp_enqueue_script( 'sws-selectors', plugins_url( 'assets/selectors.js', SWS_FILE ), array( 'jquery', 'wc-enhanced-select' ), SWS_VERSION, true );
        wp_localize_script( 'sws-selectors', 'swsSelectors', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sws_search' ) ) );
        wp_enqueue_script( 'sws-admin', plugins_url( 'assets/admin.js', SWS_FILE ), array(), SWS_VERSION, true );
        wp_localize_script( 'sws-admin', 'swsAdmin', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'sws_process' ) ) );
    }
    private function access() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( esc_html__( 'Du saknar behörighet.', 'storewide-sale' ), '', array( 'response' => 403 ) ); }
    }
    private function field( $key, $default = '' ) {
        return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
    }
    public function action() {
        $this->access();
        check_admin_referer( 'sws_action' );
        try {
            $command = $this->field( 'command' );
            $state = SWS_Store::campaign();
            if ( 'start' !== $command && $this->field( 'campaign' ) !== ( isset( $state['id'] ) ? $state['id'] : '' ) ) { throw new RuntimeException( 'Kampanjen har ändrats. Ladda om sidan.' ); }
            if ( 'start' === $command ) { $this->engine->start( wp_unslash( $_POST ) ); }
            elseif ( 'save' === $command ) {
                $this->engine->save_settings( wp_unslash( $_POST ) );
                set_transient( 'sws_notice_' . get_current_user_id(), array( 'type' => 'success', 'message' => 'Inställningarna har sparats. Kampanjen är fortfarande avstängd.' ), 90 );
            }
            elseif ( 'stop' === $command ) { $this->engine->stop(); }
            elseif ( 'retry' === $command ) { $this->engine->process(); }
            elseif ( 'integration' === $command ) { $this->engine->integration( '1' === $this->field( 'soft79' ) ); }
            elseif ( 'decide' === $command ) {
                $id = absint( $this->field( 'product_id' ) );
                if ( ! current_user_can( 'edit_post', $id ) ) { throw new RuntimeException( 'Du får inte ändra denna produkt.' ); }
                $this->engine->decide( $id, $this->field( 'choice' ), $this->field( 'regular' ), $this->field( 'campaign' ) );
            } else { throw new RuntimeException( 'Okänd åtgärd.' ); }
        } catch ( Throwable $error ) {
            set_transient( 'sws_notice_' . get_current_user_id(), $error->getMessage(), 90 );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=storewide-sale' ) );
        exit;
    }
    public function ajax() {
        $this->access();
        check_ajax_referer( 'sws_process', 'nonce' );
        try {
            $this->engine->process();
            $state = SWS_Store::campaign();
            wp_send_json_success( array( 'phase' => isset( $state['phase'] ) ? $state['phase'] : 'stopped', 'counts' => SWS_Store::counts( $state ), 'error' => isset( $state['error'] ) ? $state['error'] : '' ) );
        } catch ( Throwable $error ) { wp_send_json_error( array( 'message' => $error->getMessage() ), 409 ); }
    }
    public function search() {
        $this->access();
        check_ajax_referer( 'sws_search', 'nonce' );
        $kind = isset( $_GET['kind'] ) && is_string( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '';
        $term = isset( $_GET['term'] ) && is_string( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        wp_send_json( array( 'results' => $this->search_results( $kind, $term ) ) );
    }
    public static function category_label( $term ) {
        $names = array();
        foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $id ) {
            $ancestor = get_term( $id, 'product_cat' );
            if ( $ancestor && ! is_wp_error( $ancestor ) ) { $names[] = $ancestor->name; }
        }
        $names[] = $term->name;
        return html_entity_decode( implode( ' / ', $names ), ENT_QUOTES, 'UTF-8' );
    }
    public function search_results( $kind, $term ) {
        if ( ! current_user_can( 'manage_woocommerce' ) || strlen( $term ) < 1 || strlen( $term ) > 200 ) { return array(); }
        $results = array();
        if ( 'categories' === $kind ) {
            $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'search' => $term, 'number' => 30, 'orderby' => 'name' ) );
            if ( is_wp_error( $terms ) ) { return array(); }
            foreach ( $terms as $term ) { $results[] = array( 'id' => (int) $term->term_id, 'text' => self::category_label( $term ) ); }
        } elseif ( 'products' === $kind && strlen( $term ) >= 2 ) {
            $published = function () { return array( 'publish' ); };
            add_filter( 'woocommerce_search_products_post_statuses', $published );
            try { $ids = WC_Data_Store::load( 'product' )->search_products( $term, '', true, false, 50 ); }
            finally { remove_filter( 'woocommerce_search_products_post_statuses', $published ); }
            foreach ( $ids as $id ) {
                $product = wc_get_product( $id );
                if ( ! SWS_Engine::supported_product( $product, true ) ) { continue; }
                $edit_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
                if ( ! current_user_can( 'edit_post', $edit_id ) ) { continue; }
                $results[] = array( 'id' => (int) $id, 'text' => wp_strip_all_tags( $product->get_formatted_name() ) );
            }
        }
        return $results;
    }
    private function selector( $kind, $state ) {
        $label = 'categories' === $kind ? 'Sök kategorier att undanta…' : 'Sök produktnamn, artikelnummer eller ID…';
        echo '<select id="sws-' . esc_attr( $kind ) . '" name="' . esc_attr( $kind ) . '[]" class="sws-search" multiple data-kind="' . esc_attr( $kind ) . '" data-placeholder="' . esc_attr( $label ) . '" aria-describedby="sws-' . esc_attr( $kind ) . '-help" style="width:100%">';
        foreach ( isset( $state[ $kind ] ) ? $state[ $kind ] : array() as $id ) {
            $name = '#' . $id . ' (inte längre tillgänglig)';
            if ( 'categories' === $kind ) {
                $term = get_term( $id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) { $name = self::category_label( $term ); }
            } else {
                $product = wc_get_product( $id );
                if ( $product ) { $name = wp_strip_all_tags( $product->get_formatted_name() ); }
            }
            echo '<option value="' . esc_attr( $id ) . '" selected>' . esc_html( $name ) . '</option>';
        }
        echo '</select>';
    }
    private function form( $command, $state ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sws-form">';
        wp_nonce_field( 'sws_action' );
        echo '<input type="hidden" name="action" value="sws_action">';
        if ( 'start' !== $command ) { echo '<input type="hidden" name="command" value="' . esc_attr( $command ) . '">'; }
        echo '<input type="hidden" name="campaign" value="' . esc_attr( isset( $state['id'] ) ? $state['id'] : '' ) . '">';
    }
    private function money( $price ) { return '' === $price ? 'Inget reapris' : wc_price( $price ); }
    private function soft79( $state, $running ) {
        $status = SWS_Engine::soft79_status();
        if ( 'absent' === $status ) { return; }
        echo '<section class="sws-card"><div class="sws-section-heading"><span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span><h2>SOFT79</h2></div>';
        if ( 'fork' === $status ) {
            if ( $running ) { $this->form( 'integration', $state ); }
            echo '<label class="sws-checkbox"><input type="checkbox" name="soft79" value="1" ' . checked( ! empty( $state['soft79'] ), true, false ) . '><span><strong>Undanta kampanjprodukter från SOFT79</strong><small>Förhindra ytterligare SOFT79-rabatt på produkter som hanteras av kampanjen.</small></span></label>';
            echo '<p class="description">Gäller även manuellt ändrade kampanjprodukter fram till kampanjens slut. Övriga produkter följer SOFT79 som vanligt.</p>';
            if ( $running ) { submit_button( 'Spara integration', 'secondary', 'submit', false ); echo '</form>'; }
        } elseif ( 'original' === $status ) {
            echo '<p>Du har originalversionen av SOFT79. Vill du automatiskt undanta kampanjprodukter från dess rabatter finns en fristående fork med stöd för detta.</p><a class="sws-text-link" href="https://github.com/incoog/soft79-wc-pricing-rules" target="_blank" rel="noopener noreferrer">Visa SOFT79-forken <span aria-hidden="true">↗</span></a><p class="description">Originalversionen stängs inte av av Storewide Sale.</p>';
        } else {
            echo '<p>SOFT79-forken finns installerad men körs inte. Aktivera forken för att kunna använda integrationen.</p>';
        }
        echo '</section>';
    }
    private function scope_summary( $state ) {
        $category_count = isset( $state['categories'] ) ? count( $state['categories'] ) : 0;
        $product_count = isset( $state['products'] ) ? count( $state['products'] ) : 0;
        echo '<dl class="sws-summary"><div><dt>Rabatt</dt><dd><span id="sws-summary-percent">' . esc_html( isset( $state['percent'] ) ? $state['percent'] : 20 ) . '</span> %</dd></div><div><dt>Datumgräns</dt><dd id="sws-summary-date">' . esc_html( ! empty( $state['cutoff'] ) ? $state['cutoff'] : 'Ingen gräns' ) . '</dd></div><div><dt>Undantagna kategorier</dt><dd id="sws-summary-categories">' . esc_html( $category_count ) . '</dd></div><div><dt>Undantagna produkter</dt><dd id="sws-summary-products">' . esc_html( $product_count ) . '</dd></div></dl>';
    }
    private function locked_rules( $state ) {
        echo '<section class="sws-card"><div class="sws-section-heading"><span class="dashicons dashicons-filter" aria-hidden="true"></span><h2>Kampanjens urval</h2></div><p>Rabatt och undantag är låsta tills kampanjen har avslutats.</p>';
        foreach ( array( 'categories' => 'Undantagna kategorier', 'products' => 'Undantagna produkter' ) as $kind => $label ) {
            echo '<h3 class="sws-field-label">' . esc_html( $label ) . '</h3><div class="sws-chips">';
            if ( empty( $state[ $kind ] ) ) { echo '<span class="sws-muted">Inga undantag</span>'; }
            foreach ( isset( $state[ $kind ] ) ? $state[ $kind ] : array() as $id ) {
                $name = '#' . $id;
                if ( 'categories' === $kind ) { $term = get_term( $id, 'product_cat' ); if ( $term && ! is_wp_error( $term ) ) { $name = self::category_label( $term ); } }
                else { $product = wc_get_product( $id ); if ( $product ) { $name = $product->get_name(); } }
                echo '<span class="sws-chip">' . esc_html( $name ) . '</span>';
            }
            echo '</div>';
        }
        echo '</section>';
    }
    public function render() {
        $this->access();
        $state = SWS_Store::campaign();
        $running = SWS_Engine::running( $state );
        $counts = SWS_Store::counts( $state );
        $phase = isset( $state['phase'] ) ? $state['phase'] : 'stopped';
        $labels = array( 'starting' => 'Aktiveras', 'active' => 'Aktiv', 'stopping' => 'Återställs', 'stopped' => 'Avstängd' );
        echo '<div class="wrap sws"><header class="sws-header"><div><p class="sws-eyebrow">WOOCOMMERCE</p><h1>Storewide Sale</h1><p>En gemensam rabatt. Full kontroll när kampanjen är över.</p></div><span class="sws-status sws-status-' . esc_attr( $phase ) . '"><span aria-hidden="true"></span>' . esc_html( $labels[ $phase ] ) . '</span></header>';
        $notice = get_transient( 'sws_notice_' . get_current_user_id() );
        if ( $notice ) {
            delete_transient( 'sws_notice_' . get_current_user_id() );
            $success = is_array( $notice ) && isset( $notice['type'] ) && 'success' === $notice['type'];
            $message = is_array( $notice ) ? $notice['message'] : $notice;
            echo '<div class="notice notice-' . ( $success ? 'success' : 'error' ) . '"><p>' . esc_html( $message ) . '</p></div>';
        }
        if ( ! $running ) {
            $this->form( 'start', $state );
            echo '<div class="sws-layout"><div class="sws-main"><section class="sws-card"><div class="sws-section-heading"><span class="dashicons dashicons-tag" aria-hidden="true"></span><h2>Kampanjrabatt</h2></div><p>Rabatten beräknas från produktens ordinarie pris.</p><label for="sws-percent" class="sws-field-label">Procentrabatt</label><div class="sws-percent-input"><input id="sws-percent" name="percent" type="number" min="0.01" max="100" step="0.01" value="' . esc_attr( isset( $state['percent'] ) ? $state['percent'] : 20 ) . '" required aria-describedby="sws-percent-help"><span aria-hidden="true">%</span></div><p id="sws-percent-help" class="description">Tidigare reapriser sparas och kan återställas när kampanjen avslutas.</p></section>';
            echo '<section class="sws-card"><div class="sws-section-heading"><span class="dashicons dashicons-filter" aria-hidden="true"></span><h2>Undantag</h2><span class="sws-optional">Valfritt</span></div><p>Välj vilka delar av sortimentet som ska lämnas utanför kampanjen.</p><div class="sws-field"><label for="sws-cutoff" class="sws-field-label">Produkter skapade efter</label><input id="sws-cutoff" name="cutoff" type="date" value="' . esc_attr( isset( $state['cutoff'] ) ? $state['cutoff'] : '' ) . '" aria-describedby="sws-cutoff-help"><p id="sws-cutoff-help" class="description">Det valda datumet ingår. Lämna tomt för ingen datumgräns.</p></div>';
            echo '<div class="sws-field"><label for="sws-categories" class="sws-field-label">Undanta kategorier</label>';
            $this->selector( 'categories', $state );
            echo '<p id="sws-categories-help" class="description">Sök och välj en eller flera kategorier. Underkategorier ingår.</p></div><div class="sws-field"><label for="sws-products" class="sws-field-label">Undanta produkter</label>';
            $this->selector( 'products', $state );
            echo '<p id="sws-products-help" class="description">Välj enskilda produkter eller variationer. En huvudprodukt undantar alla sina variationer.</p></div></section>';
            $this->soft79( $state, false );
            echo '</div><aside class="sws-sidebar"><section class="sws-card sws-overview"><h2>Kampanjöversikt</h2>';
            $this->scope_summary( $state );
            echo '<div class="sws-action-area"><button class="button button-secondary sws-save" type="submit" name="command" value="save">Spara inställningar</button><p class="sws-save-help">Sparar dina val utan att aktivera kampanjen.</p><button class="sws-switch" type="submit" name="command" value="start" role="switch" aria-checked="false"><span aria-hidden="true"></span> Aktivera kampanj</button><p>Du avslutar och återställer kampanjen från den här sidan.</p></div><div class="sws-scope-note"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><p>Omfattar publicerade enkla produkter och publicerade variationer med en publicerad huvudprodukt.</p></div><p class="sws-fine-print">En befintlig aktiv rea med samma eller lägre pris lämnas orörd.</p></section></aside></div></form>';
        } else {
            echo '<div class="sws-layout"><div class="sws-main"><section class="sws-card"><div class="sws-section-heading"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><h2>Kampanjen just nu</h2></div><div class="sws-metrics">';
            foreach ( array( 'managed' => 'Automatiskt hanterade', 'manual' => 'Manuellt ändrade', 'done' => 'Återställda / klara' ) as $key => $label ) {
                echo '<div><strong>' . esc_html( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
            }
            echo '</div><p class="description">' . esc_html( $state['checked'] ) . ' katalogposter genomgångna · ' . esc_html( $state['skipped'] ) . ' överhoppade.</p>';
            if ( ! empty( $state['error'] ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $state['error'] ) . '</p></div>'; }
            if ( ! empty( $counts['conflicts'] ) ) { echo '<div class="notice notice-warning inline"><p>Gör klart alla prisval nedan innan kampanjen avslutas. Återställningen väntar tills konflikterna är lösta.</p></div>'; }
            $work = empty( $state['error'] ) && ( 'starting' === $phase || ( 'stopping' === $phase && empty( $counts['conflicts'] ) ) || ! empty( $counts['pending'] ) );
            echo '<div id="sws-progress" role="status" aria-live="polite" data-work="' . ( $work ? '1' : '0' ) . '" data-phase="' . esc_attr( $phase ) . '"></div>';
            if ( 'active' !== $phase || ! empty( $state['error'] ) || ! empty( $counts['pending'] ) ) { $this->form( 'retry', $state ); submit_button( 'Fortsätt körningen', 'secondary', 'submit', false ); echo '</form>'; }
            echo '</section>';
            $this->locked_rules( $state );
            $this->soft79( $state, true );
            echo '</div><aside class="sws-sidebar"><section class="sws-card sws-overview"><h2>Kampanjöversikt</h2>';
            $this->scope_summary( $state );
            echo '<div class="sws-action-area">';
            if ( 'stopping' !== $phase ) {
                $this->form( 'stop', $state );
                echo '<button class="sws-switch is-on" type="submit" role="switch" aria-checked="true" ' . disabled( ! empty( $counts['conflicts'] ) || ! empty( $counts['pending'] ), true, false ) . '><span aria-hidden="true"></span> Avsluta kampanj</button></form>';
            } else { echo '<p><strong>Priserna återställs</strong></p>'; }
            echo '<p>Låt pluginet vara aktivt tills återställningen är helt klar.</p></div></section></aside></div>';
            $this->list_products( $state, 'review', 'Prisändringar som kräver ett val', 'conflict_page' );
            $this->list_products( $state, 'manual', 'Manuellt ändrade reapriser', 'manual_page' );
        }
        echo '</div>';
    }
    private function list_products( $state, $kind, $title, $query ) {
        $page = isset( $_GET[ $query ] ) && is_scalar( $_GET[ $query ] ) ? max( 1, absint( $_GET[ $query ] ) ) : 1;
        $entries = SWS_Store::rows( $state, $kind, 26, ( $page - 1 ) * 25 );
        $more = count( $entries ) > 25;
        $entries = array_slice( $entries, 0, 25 );
        echo '<div class="sws-card"><h2>' . esc_html( $title ) . '</h2>';
        echo 'manual' === $kind ? '<p>Dessa produkter har lämnat kampanjens automatiska prishantering. Det manuella reapriset och dess datum behålls som standard efter kampanjen. Du kan i stället välja att ta bort reapriset helt vid avslut.</p>' : '<p>Dessa produkter hade reapris före kampanjen och har fått ändrat ordinarie pris. Välj återställning för varje produkt. Ett nytt ordinarie pris kräver ett nytt val.</p>';
        if ( ! $entries ) { echo '<p>Inga produkter i listan.</p>'; }
        foreach ( $entries as $entry ) {
            $id = (int) $entry['product_id']; $row = $entry['data']; $product = wc_get_product( $id );
            echo '<div class="sws-product"><h3>';
            if ( $product ) { echo '<a href="' . esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $id ) ) . '">' . esc_html( $product->get_name() ) . '</a>'; }
            else { echo 'Borttagen produkt'; }
            echo ' <small>#' . esc_html( $id ) . '</small></h3>';
            if ( 'manual' === $kind ) {
                echo '<p>Nuvarande reapris: ' . wp_kses_post( $product ? $this->money( $product->get_sale_price( 'edit' ) ) : 'Produkten finns inte kvar' ) . '</p>';
            } else {
                echo '<p>Tidigare ordinarie pris: ' . wp_kses_post( $this->money( $row['original_regular'] ) ) . '. Tidigare reapris: ' . wp_kses_post( $this->money( $row['original']['sale'] ) ) . ' (' . esc_html( wc_format_decimal( $row['original_percent'], 2 ) ) . ' % rabatt).</p><p>Nytt ordinarie pris: <strong>' . wp_kses_post( $this->money( $row['regular'] ) ) . '</strong>.</p>';
            }
            $this->form( 'decide', $state );
            echo '<input type="hidden" name="product_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="regular" value="' . esc_attr( $row['regular'] ) . '"><label for="sws-choice-' . esc_attr( $id ) . '">Vid kampanjens slut: </label><select id="sws-choice-' . esc_attr( $id ) . '" name="choice" required>';
            if ( 'manual' === $kind ) { $options = array( 'keep' => 'Behåll det manuella reapriset', 'remove' => 'Ta bort reapriset helt' ); $selected = $row['manual_end']; }
            else {
                $options = array( '' => 'Gör ett val', 'amount' => 'Återställ ursprungligt reapris: ' . wp_strip_all_tags( $this->money( $row['original']['sale'] ) ),
                    'percent' => 'Bibehåll ursprunglig rabattprocent: ' . wp_strip_all_tags( $this->money( SWS_Engine::price( $row['regular'], $row['original_percent'] ) ) ), 'remove' => 'Ta bort reapriset helt' );
                $selected = $row['choice'];
            }
            foreach ( $options as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) . '</option>'; }
            echo '</select> '; submit_button( 'Spara val', 'secondary', 'submit', false );
            if ( 'review' === $kind && $row['choice'] ) { echo '<span class="sws-saved"> Val sparat</span>'; }
            echo '</form></div>';
        }
        $base = admin_url( 'admin.php?page=storewide-sale' );
        if ( $page > 1 ) { echo '<a class="button" href="' . esc_url( add_query_arg( $query, $page - 1, $base ) ) . '">Föregående</a> '; }
        if ( $more ) { echo '<a class="button" href="' . esc_url( add_query_arg( $query, $page + 1, $base ) ) . '">Nästa</a>'; }
        echo '</div>';
    }
    public function product_notice() {
        global $product_object;
        if ( $product_object ) { $this->price_notice( $product_object->get_id() ); }
    }
    public function variation_notice( $loop, $data, $variation ) { $this->price_notice( $variation->ID ); }
    private function price_notice( $id ) {
        $state = SWS_Store::campaign();
        if ( ! SWS_Engine::running( $state ) ) { return; }
        $row = SWS_Store::get( $state['id'], $id );
        if ( ! $row || 'done' === $row['status'] ) { return; }
        $message = 'manual' === $row['status'] ? 'Manuellt reapris: kampanjens automatiska prishantering är avstängd för produkten. Priset behålls vid avslut om du inte väljer annat i Storewide Sale.' : 'Storewide Sale hanterar reapriset. Ändrar du ordinarie pris räknas kampanjpriset om. Ändrar du reapriset eller dess datum lämnar produkten automatiken och det manuella priset behålls som standard efter kampanjen.';
        echo '<div class="form-field" style="padding:10px"><strong>Storewide Sale:</strong> ' . esc_html( $message ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=storewide-sale' ) ) . '">Öppna inställningar</a></div>';
    }
}
