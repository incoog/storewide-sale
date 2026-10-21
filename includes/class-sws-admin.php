<?php
defined( 'ABSPATH' ) || exit;

final class SWS_Admin {
    private $engine;
    public function __construct( $engine ) {
        $this->engine = $engine;
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_sws_action', array( $this, 'action' ) );
        add_action( 'wp_ajax_sws_process', array( $this, 'ajax' ) );
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
        wp_enqueue_style( 'sws-admin', plugins_url( 'assets/admin.css', SWS_FILE ), array(), SWS_VERSION );
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
    private function form( $command, $state ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sws-form">';
        wp_nonce_field( 'sws_action' );
        echo '<input type="hidden" name="action" value="sws_action"><input type="hidden" name="command" value="' . esc_attr( $command ) . '"><input type="hidden" name="campaign" value="' . esc_attr( isset( $state['id'] ) ? $state['id'] : '' ) . '">';
    }
    private function money( $price ) { return '' === $price ? 'Inget reapris' : wc_price( $price ); }
    private function soft79( $state, $running ) {
        $status = SWS_Engine::soft79_status();
        if ( 'fork' === $status ) {
            if ( $running ) { $this->form( 'integration', $state ); }
            echo '<p><label><input type="checkbox" name="soft79" value="1" ' . checked( ! empty( $state['soft79'] ), true, false ) . '> Inaktivera SOFT79 för produkter som hanteras av kampanjen</label></p>';
            echo '<p class="description">Gäller även manuellt ändrade kampanjprodukter fram till kampanjens slut. Övriga produkter följer SOFT79 som vanligt.</p>';
            if ( $running ) { submit_button( 'Spara SOFT79-inställning', 'secondary', 'submit', false ); echo '</form>'; }
        } elseif ( 'original' === $status ) {
            echo '<div class="notice notice-info inline"><p>SOFT79 finns installerat. Det finns en fristående fork med en hook som gör att SOFT79 kan undantas för kampanjprodukter: <a href="https://github.com/incoog/soft79-wc-pricing-rules" target="_blank" rel="noopener noreferrer">Hämta forken på GitHub</a>.</p><p>Den installerade originalversionen stängs inte av av Storewide Sale.</p></div>';
        } elseif ( 'inactive_fork' === $status ) {
            echo '<div class="notice notice-info inline"><p>SOFT79-forken finns installerad men körs inte. Aktivera forken för att kunna använda integrationen.</p></div>';
        }
    }
    public function render() {
        $this->access();
        $state = SWS_Store::campaign();
        $running = SWS_Engine::running( $state );
        $counts = SWS_Store::counts( $state );
        $phase = isset( $state['phase'] ) ? $state['phase'] : 'stopped';
        $labels = array( 'starting' => 'Aktiveras', 'active' => 'Aktiv', 'stopping' => 'Återställs', 'stopped' => 'Avstängd' );
        echo '<div class="wrap sws"><h1>Storewide Sale</h1>';
        $notice = get_transient( 'sws_notice_' . get_current_user_id() );
        if ( $notice ) { delete_transient( 'sws_notice_' . get_current_user_id() ); echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>'; }
        echo '<div class="sws-card"><h2>Kampanj <span class="sws-status">' . esc_html( isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase ) . '</span></h2>';
        echo '<p>Sätt en gemensam procentrabatt på sortimentet och återställ tidigare reapriser när kampanjen avslutas.</p>';
        if ( ! $running ) {
            $this->form( 'start', $state );
            echo '<p><label for="sws-percent"><strong>Procentrabatt</strong></label><br><input id="sws-percent" name="percent" type="number" min="0.01" max="100" step="0.01" value="' . esc_attr( isset( $state['percent'] ) ? $state['percent'] : 20 ) . '" required> % av ordinarie pris</p>';
            echo '<p><label for="sws-cutoff"><strong>Undanta produkter skapade efter</strong></label><br><input id="sws-cutoff" name="cutoff" type="date" value="' . esc_attr( isset( $state['cutoff'] ) ? $state['cutoff'] : '' ) . '"></p><p class="description">Valt datum ingår. Lämna tomt för att inte begränsa efter datum. Variationer följer huvudproduktens datum.</p>';
            echo '<p><label for="sws-categories"><strong>Undanta kategorier</strong></label><br><select id="sws-categories" name="categories[]" multiple size="8">';
            $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $selected = isset( $state['categories'] ) && in_array( (int) $term->term_id, $state['categories'], true );
                    echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $selected, true, false ) . '>' . esc_html( $term->name ) . '</option>';
                }
            }
            echo '</select></p><p class="description">Underkategorier ingår. Håll Ctrl eller Cmd för att välja flera. Variationer följer huvudproduktens kategorier.</p>';
            $this->soft79( $state, false );
            echo '<p class="description">En befintlig aktiv rea med samma eller lägre pris lämnas orörd. Kampanjen omfattar publicerade och privata enkla produkter, externa produkter och variationer som finns när den startas.</p>';
            echo '<p><button class="sws-switch" type="submit" role="switch" aria-checked="false"><span aria-hidden="true"></span> Aktivera kampanj</button></p></form>';
        } else {
            echo '<p><strong>' . esc_html( $state['percent'] ) . ' % rabatt</strong>. Inställningarna för urval och rabatt är låsta under kampanjen.</p>';
            echo '<p>' . esc_html( isset( $counts['managed'] ) ? $counts['managed'] : 0 ) . ' automatiskt hanterade · ' . esc_html( isset( $counts['manual'] ) ? $counts['manual'] : 0 ) . ' manuellt ändrade · ' . esc_html( isset( $counts['done'] ) ? $counts['done'] : 0 ) . ' återställda/klara.</p>';
            echo '<p>' . esc_html( $state['checked'] ) . ' katalogposter genomgångna, ' . esc_html( $state['skipped'] ) . ' överhoppade (till exempel undantagna, huvudprodukter eller redan lägre reapris).</p>';
            if ( ! empty( $state['error'] ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $state['error'] ) . '</p></div>'; }
            if ( ! empty( $counts['conflicts'] ) ) { echo '<div class="notice notice-warning inline"><p>Det finns prisval som måste göras. Kampanjen kan inte avslutas färdigt förrän samtliga konflikter är lösta.</p></div>'; }
            if ( 'stopping' !== $phase ) {
                $this->form( 'stop', $state );
                echo '<p><button class="sws-switch is-on" type="submit" role="switch" aria-checked="true" ' . disabled( ! empty( $counts['conflicts'] ) || ! empty( $counts['pending'] ), true, false ) . '><span aria-hidden="true"></span> Avsluta kampanj och återställ</button></p></form>';
            }
            if ( 'active' !== $phase || ! empty( $state['error'] ) || ! empty( $counts['pending'] ) ) {
                $this->form( 'retry', $state ); submit_button( 'Fortsätt körningen', 'secondary', 'submit', false ); echo '</form>';
            }
            echo '<p class="description">Låt pluginet vara aktivt tills återställningen är klar. Avaktivering i WordPress återställer inte priserna.</p>';
            $this->soft79( $state, true );
            $work = empty( $state['error'] ) && ( 'starting' === $phase || ( 'stopping' === $phase && empty( $counts['conflicts'] ) ) || ! empty( $counts['pending'] ) );
            echo '<div id="sws-progress" role="status" aria-live="polite" data-work="' . ( $work ? '1' : '0' ) . '" data-phase="' . esc_attr( $phase ) . '"></div>';
        }
        echo '</div>';
        if ( $running ) {
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
