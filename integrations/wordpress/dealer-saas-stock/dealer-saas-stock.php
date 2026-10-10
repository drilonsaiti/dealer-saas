<?php
/**
 * Plugin Name:       Dealer SaaS – Fahrzeugbestand
 * Description:       Shows the dealer's published vehicles (stock list and detail page) and an enquiry form, from the Dealer SaaS public API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       dealer-saas-stock
 *
 * Shortcodes:
 *   [dealer_stock]              stock list with filters (make, fuel, max. price), sorting and pages
 *   [dealer_vehicle]            detail page of the vehicle in ?fahrzeug=<id>, with the enquiry form
 *   [dealer_enquiry]            general enquiry form
 */
if (! defined('ABSPATH')) {
    exit;
}

final class Dealer_Saas_Stock
{
    public const OPTION = 'dealer_saas_stock';

    public const QUERY_VAR = 'fahrzeug';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('wp_enqueue_scripts', fn () => wp_register_style('dealer-saas-stock', plugins_url('assets/stock.css', __FILE__), [], '1.0.0'));
        add_action('admin_post_nopriv_dealer_saas_enquiry', [self::class, 'handle_enquiry']);
        add_action('admin_post_dealer_saas_enquiry', [self::class, 'handle_enquiry']);
        add_shortcode('dealer_stock', [self::class, 'shortcode_stock']);
        add_shortcode('dealer_vehicle', [self::class, 'shortcode_vehicle']);
        add_shortcode('dealer_enquiry', [self::class, 'shortcode_enquiry']);
    }

    /** @return array{api_url: string, token: string, detail_page: string, language: string, cache_minutes: int} */
    public static function options(): array
    {
        $o = (array) get_option(self::OPTION, []);

        return [
            'api_url' => rtrim((string) ($o['api_url'] ?? ''), '/'),
            'token' => (string) ($o['token'] ?? ''),
            'detail_page' => (string) ($o['detail_page'] ?? ''),
            'language' => in_array($o['language'] ?? '', ['de', 'fr', 'it', 'en'], true) ? $o['language'] : 'de',
            'cache_minutes' => max(0, (int) ($o['cache_minutes'] ?? 5)),
        ];
    }

    // ---------------------------------------------------------------- settings

    public static function menu(): void
    {
        add_options_page('Dealer SaaS', 'Dealer SaaS', 'manage_options', 'dealer-saas-stock', [self::class, 'settings_page']);
    }

    public static function register_settings(): void
    {
        register_setting('dealer_saas_stock', self::OPTION, [
            'sanitize_callback' => static fn ($v) => [
                'api_url' => esc_url_raw((string) ($v['api_url'] ?? '')),
                'token' => sanitize_text_field((string) ($v['token'] ?? '')),
                'detail_page' => esc_url_raw((string) ($v['detail_page'] ?? '')),
                'language' => sanitize_key((string) ($v['language'] ?? 'de')),
                'cache_minutes' => absint($v['cache_minutes'] ?? 5),
            ],
        ]);
    }

    public static function settings_page(): void
    {
        $o = self::options();
        $test = isset($_GET['test']) ? self::request('GET', '/dealer') : null; ?>
        <div class="wrap">
            <h1>Dealer SaaS – Fahrzeugbestand</h1>
            <form method="post" action="options.php">
                <?php settings_fields('dealer_saas_stock'); ?>
                <table class="form-table">
                    <tr><th><label for="ds-url">API-Adresse</label></th>
                        <td><input id="ds-url" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[api_url]" value="<?php echo esc_attr($o['api_url']); ?>" placeholder="https://app.example.ch/api/v1"></td></tr>
                    <tr><th><label for="ds-token">API-Token</label></th>
                        <td><input id="ds-token" class="regular-text" type="password" name="<?php echo esc_attr(self::OPTION); ?>[token]" value="<?php echo esc_attr($o['token']); ?>" placeholder="dsk_…" autocomplete="off">
                        <p class="description">Einstellungen → API-Tokens im Dealer SaaS (Berechtigungen: Fahrzeuge lesen, Anfragen senden).</p></td></tr>
                    <tr><th><label for="ds-detail">Detailseite</label></th>
                        <td><input id="ds-detail" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[detail_page]" value="<?php echo esc_attr($o['detail_page']); ?>" placeholder="https://ihre-garage.ch/fahrzeug/">
                        <p class="description">Seite mit dem Shortcode [dealer_vehicle].</p></td></tr>
                    <tr><th><label for="ds-lang">Sprache</label></th>
                        <td><select id="ds-lang" name="<?php echo esc_attr(self::OPTION); ?>[language]">
                            <?php foreach (['de' => 'Deutsch', 'fr' => 'Français', 'it' => 'Italiano', 'en' => 'English'] as $code => $name) { ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($o['language'], $code); ?>><?php echo esc_html($name); ?></option>
                            <?php } ?>
                        </select></td></tr>
                    <tr><th><label for="ds-cache">Cache (Minuten)</label></th>
                        <td><input id="ds-cache" type="number" min="0" max="60" name="<?php echo esc_attr(self::OPTION); ?>[cache_minutes]" value="<?php echo esc_attr((string) $o['cache_minutes']); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <p><a class="button" href="<?php echo esc_url(add_query_arg('test', '1')); ?>">Verbindung testen</a></p>
            <?php if ($test !== null) { ?>
                <div class="notice <?php echo isset($test['data']) ? 'notice-success' : 'notice-error'; ?>"><p>
                    <?php echo isset($test['data']) ? esc_html('Verbunden mit '.$test['data']['attributes']['name']) : esc_html(self::error_text($test)); ?>
                </p></div>
            <?php } ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------- API

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function request(string $method, string $path, array $query = [], array $body = [], bool $cache = false): array
    {
        $o = self::options();

        if ($o['api_url'] === '' || $o['token'] === '') {
            return ['errors' => [['detail' => 'Plugin not configured (Settings → Dealer SaaS).']]];
        }

        $url = $o['api_url'].$path.($query === [] ? '' : '?'.http_build_query($query));
        $key = 'dss_'.md5($url);

        if ($cache && $o['cache_minutes'] > 0 && ($hit = get_transient($key)) !== false) {
            return $hit;
        }

        $args = ['timeout' => 10, 'headers' => ['Authorization' => 'Bearer '.$o['token'], 'Accept' => 'application/vnd.api+json']];
        $response = $method === 'POST'
            ? wp_remote_post($url, $args + ['body' => wp_json_encode($body), 'headers' => $args['headers'] + ['Content-Type' => 'application/json']])
            : wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return ['errors' => [['detail' => $response->get_error_message()]]];
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : ['errors' => [['detail' => 'Invalid answer']]];
        $data['_status'] = (int) wp_remote_retrieve_response_code($response);

        if ($cache && $o['cache_minutes'] > 0 && $data['_status'] === 200) {
            set_transient($key, $data, $o['cache_minutes'] * MINUTE_IN_SECONDS);
        }

        return $data;
    }

    /** @param array<string, mixed> $result */
    private static function error_text(array $result): string
    {
        return (string) ($result['errors'][0]['detail'] ?? 'Error');
    }

    // ---------------------------------------------------------------- shortcodes

    /** @param array<string, string>|string $atts */
    public static function shortcode_stock($atts = []): string
    {
        wp_enqueue_style('dealer-saas-stock');
        $o = self::options();
        $atts = shortcode_atts(['per_page' => '12'], (array) $atts);
        $filter = array_filter([
            'make' => sanitize_text_field(wp_unslash($_GET['marke'] ?? '')),
            'fuel' => sanitize_key(wp_unslash($_GET['treibstoff'] ?? '')),
            'price_max' => absint($_GET['preis_bis'] ?? 0) ?: null,
        ]);
        $sort = in_array($_GET['sort'] ?? '', ['price', '-price', '-published_at', 'mileage'], true) ? $_GET['sort'] : '-published_at';
        $page = max(1, absint($_GET['seite'] ?? 1));

        $result = self::request('GET', '/vehicles', ['lang' => $o['language'], 'filter' => $filter, 'sort' => $sort, 'page' => ['number' => $page, 'size' => absint($atts['per_page'])]], [], true);

        if (! isset($result['data'])) {
            return '<p class="dss-error">'.esc_html(self::t('unavailable')).'</p>';
        }

        $html = '<form class="dss-filter" method="get">'
            .'<input name="marke" value="'.esc_attr($filter['make'] ?? '').'" placeholder="'.esc_attr(self::t('make')).'">'
            .'<select name="treibstoff"><option value="">'.esc_html(self::t('fuel')).'</option>';

        foreach (['petrol', 'diesel', 'hybrid', 'electric'] as $fuel) {
            $html .= '<option value="'.esc_attr($fuel).'" '.selected($filter['fuel'] ?? '', $fuel, false).'>'.esc_html(self::t($fuel)).'</option>';
        }

        $html .= '</select><input type="number" name="preis_bis" min="0" step="1000" value="'.esc_attr((string) ($filter['price_max'] ?? '')).'" placeholder="'.esc_attr(self::t('price_max')).'">'
            .'<select name="sort">';

        foreach (['-published_at' => 'newest', 'price' => 'price_asc', '-price' => 'price_desc', 'mileage' => 'mileage_asc'] as $value => $label) {
            $html .= '<option value="'.esc_attr($value).'" '.selected($sort, $value, false).'>'.esc_html(self::t($label)).'</option>';
        }

        $html .= '</select><button type="submit">'.esc_html(self::t('search')).'</button></form>';

        if ($result['data'] === []) {
            return $html.'<p class="dss-empty">'.esc_html(self::t('none')).'</p>';
        }

        $html .= '<div class="dss-grid">';

        foreach ($result['data'] as $car) {
            $a = $car['attributes'];
            $link = esc_url(add_query_arg(self::QUERY_VAR, rawurlencode($car['id']), $o['detail_page'] ?: get_permalink()));
            $photo = $a['photos'][0]['url'] ?? '';
            $html .= '<a class="dss-card dss-'.esc_attr($a['availability']).'" href="'.$link.'">'
                .($photo !== '' ? '<img src="'.esc_url($photo).'" alt="'.esc_attr($a['title']).'" loading="lazy">' : '<span class="dss-nophoto"></span>')
                .self::badge($a['availability'])
                .'<span class="dss-title">'.esc_html($a['title']).'</span>'
                .'<span class="dss-facts">'.esc_html(implode(' · ', array_filter([$a['first_registration'] ? self::month($a['first_registration']) : null, $a['mileage_km'] !== null ? number_format((int) $a['mileage_km'], 0, '.', '\'').' km' : null, $a['fuel'] ? self::t($a['fuel']) : null]))).'</span>'
                .'<span class="dss-price">'.esc_html(self::price($a['price'])).'</span>'
                .'</a>';
        }

        $html .= '</div>';
        $meta = $result['meta'] ?? [];

        if (($meta['last_page'] ?? 1) > 1) {
            $html .= '<nav class="dss-pages">';

            for ($p = 1; $p <= (int) $meta['last_page']; $p++) {
                $html .= $p === $page ? '<span>'.$p.'</span>' : '<a href="'.esc_url(add_query_arg('seite', $p)).'">'.$p.'</a>';
            }

            $html .= '</nav>';
        }

        return $html;
    }

    public static function shortcode_vehicle(): string
    {
        wp_enqueue_style('dealer-saas-stock');
        $o = self::options();
        $id = sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR] ?? ''));

        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return '<p class="dss-empty">'.esc_html(self::t('not_found')).'</p>';
        }

        $result = self::request('GET', '/vehicles/'.$id, ['lang' => $o['language']], [], true);

        if (! isset($result['data'])) {
            return '<p class="dss-empty">'.esc_html(self::t('not_found')).'</p>';
        }

        $a = $result['data']['attributes'];
        $html = '<article class="dss-detail">'.self::badge($a['availability']).'<h2>'.esc_html($a['title']).'</h2>'
            .'<p class="dss-price dss-price-big">'.esc_html(self::price($a['price'])).'</p><div class="dss-gallery">';

        foreach ($a['photos'] as $photo) {
            $html .= '<img src="'.esc_url($photo['url']).'" alt="'.esc_attr($a['title']).'" loading="lazy">';
        }

        $facts = [
            'first_registration' => $a['first_registration'] ? self::month($a['first_registration']) : null,
            'mileage' => $a['mileage_km'] !== null ? number_format((int) $a['mileage_km'], 0, '.', '\'').' km' : null,
            'fuel' => $a['fuel'] ? self::t($a['fuel']) : null,
            'transmission' => $a['transmission'] ? self::t($a['transmission']) : null,
            'power' => $a['power_kw'] ? $a['power_hp'].' PS ('.$a['power_kw'].' kW)' : null,
            'color' => $a['color_exterior'],
            'mfk' => $a['mfk_due'] ? self::month($a['mfk_due']) : null,
        ];

        $html .= '</div><dl class="dss-facts-list">';

        foreach (array_filter($facts) as $label => $value) {
            $html .= '<dt>'.esc_html(self::t($label)).'</dt><dd>'.esc_html((string) $value).'</dd>';
        }

        $html .= '</dl>';

        if ($a['highlights'] !== []) {
            $html .= '<ul class="dss-highlights"><li>'.implode('</li><li>', array_map('esc_html', $a['highlights'])).'</li></ul>';
        }

        if ($a['description']) {
            $html .= '<div class="dss-description">'.wp_kses($a['description'], ['p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'ul' => [], 'ol' => [], 'li' => []]).'</div>';
        }

        if ($a['equipment'] !== []) {
            $html .= '<h3>'.esc_html(self::t('equipment')).'</h3><ul class="dss-equipment"><li>'.implode('</li><li>', array_map('esc_html', $a['equipment'])).'</li></ul>';
        }

        return $html.'</article>'.self::form($result['data']['id'], $a['title']);
    }

    public static function shortcode_enquiry(): string
    {
        wp_enqueue_style('dealer-saas-stock');

        return self::form(null, null);
    }

    private static function form(?string $vehicleId, ?string $title): string
    {
        $status = sanitize_key(wp_unslash($_GET['dss_anfrage'] ?? ''));
        $html = '<form class="dss-enquiry" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
            .'<h3>'.esc_html($title ? sprintf(self::t('ask_about'), $title) : self::t('ask')).'</h3>';

        if ($status === 'ok') {
            $html .= '<p class="dss-ok">'.esc_html(self::t('thanks')).'</p>';
        } elseif ($status === 'fehler') {
            $html .= '<p class="dss-error">'.esc_html(self::t('failed')).'</p>';
        }

        return $html.wp_nonce_field('dealer_saas_enquiry', '_dss', true, false)
            .'<input type="hidden" name="action" value="dealer_saas_enquiry">'
            .'<input type="hidden" name="vehicle_id" value="'.esc_attr((string) $vehicleId).'">'
            .'<input type="hidden" name="back" value="'.esc_url(home_url(add_query_arg([]))).'">'
            .'<p class="dss-hp"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></p>'
            .'<p><label>'.esc_html(self::t('name')).' *<input name="name" required maxlength="160"></label></p>'
            .'<p><label>'.esc_html(self::t('email')).'<input type="email" name="email" maxlength="190"></label></p>'
            .'<p><label>'.esc_html(self::t('phone')).'<input name="phone" maxlength="40"></label></p>'
            .'<p><label>'.esc_html(self::t('message')).' *<textarea name="message" required maxlength="5000" rows="5"></textarea></label></p>'
            .'<p><button type="submit">'.esc_html(self::t('send')).'</button></p></form>';
    }

    public static function handle_enquiry(): void
    {
        $back = esc_url_raw(wp_unslash($_POST['back'] ?? home_url()));

        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_dss'] ?? '')), 'dealer_saas_enquiry')) {
            wp_safe_redirect(add_query_arg('dss_anfrage', 'fehler', $back));
            exit;
        }

        $result = self::request('POST', '/enquiries', [], array_filter([
            'vehicle_id' => sanitize_text_field(wp_unslash($_POST['vehicle_id'] ?? '')) ?: null,
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')) ?: null,
            'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')) ?: null,
            'message' => sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')),
            'website' => sanitize_text_field(wp_unslash($_POST['website'] ?? '')) ?: null,
            'locale' => self::options()['language'],
            'visitor_ip' => filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null,
        ], static fn ($v) => $v !== null && $v !== ''));

        wp_safe_redirect(add_query_arg('dss_anfrage', ($result['_status'] ?? 0) === 201 ? 'ok' : 'fehler', $back).'#dss-anfrage');
        exit;
    }

    // ---------------------------------------------------------------- helpers

    private static function badge(string $availability): string
    {
        return in_array($availability, ['reserved', 'sold'], true) ? '<span class="dss-badge dss-badge-'.esc_attr($availability).'">'.esc_html(self::t($availability)).'</span>' : '';
    }

    /** @param array{amount: string, currency: string}|null $price */
    private static function price(?array $price): string
    {
        return $price === null ? self::t('on_request') : 'CHF '.number_format((float) $price['amount'], 0, '.', '\'').'.–';
    }

    private static function month(string $yearMonth): string
    {
        [$y, $m] = explode('-', $yearMonth) + [1 => '01'];

        return $m.'.'.$y;
    }

    private static function t(string $key): string
    {
        static $texts = [
            'de' => ['make' => 'Marke', 'fuel' => 'Treibstoff', 'price_max' => 'Preis bis CHF', 'search' => 'Suchen', 'none' => 'Keine Fahrzeuge gefunden.', 'unavailable' => 'Der Fahrzeugbestand ist zurzeit nicht verfügbar.', 'not_found' => 'Fahrzeug nicht gefunden.', 'newest' => 'Neueste', 'price_asc' => 'Preis aufsteigend', 'price_desc' => 'Preis absteigend', 'mileage_asc' => 'Kilometer aufsteigend', 'petrol' => 'Benzin', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'electric' => 'Elektro', 'manual' => 'Schaltgetriebe', 'automatic' => 'Automat', 'reserved' => 'Reserviert', 'sold' => 'Verkauft', 'on_request' => 'Preis auf Anfrage', 'first_registration' => '1. Inverkehrsetzung', 'mileage' => 'Kilometer', 'transmission' => 'Getriebe', 'power' => 'Leistung', 'color' => 'Farbe', 'mfk' => 'MFK bis', 'equipment' => 'Ausstattung', 'ask' => 'Anfrage', 'ask_about' => 'Anfrage zu %s', 'name' => 'Name', 'email' => 'E-Mail', 'phone' => 'Telefon', 'message' => 'Nachricht', 'send' => 'Senden', 'thanks' => 'Vielen Dank, wir melden uns bald.', 'failed' => 'Die Anfrage konnte nicht gesendet werden. Bitte rufen Sie uns an.'],
            'fr' => ['make' => 'Marque', 'fuel' => 'Carburant', 'price_max' => 'Prix jusqu’à CHF', 'search' => 'Rechercher', 'none' => 'Aucun véhicule trouvé.', 'unavailable' => 'Le stock n’est pas disponible pour le moment.', 'not_found' => 'Véhicule introuvable.', 'newest' => 'Plus récents', 'price_asc' => 'Prix croissant', 'price_desc' => 'Prix décroissant', 'mileage_asc' => 'Kilométrage croissant', 'petrol' => 'Essence', 'diesel' => 'Diesel', 'hybrid' => 'Hybride', 'electric' => 'Électrique', 'manual' => 'Manuelle', 'automatic' => 'Automatique', 'reserved' => 'Réservé', 'sold' => 'Vendu', 'on_request' => 'Prix sur demande', 'first_registration' => '1re mise en circulation', 'mileage' => 'Kilométrage', 'transmission' => 'Boîte', 'power' => 'Puissance', 'color' => 'Couleur', 'mfk' => 'Expertise jusqu’à', 'equipment' => 'Équipement', 'ask' => 'Demande', 'ask_about' => 'Demande concernant %s', 'name' => 'Nom', 'email' => 'E-mail', 'phone' => 'Téléphone', 'message' => 'Message', 'send' => 'Envoyer', 'thanks' => 'Merci, nous vous répondrons rapidement.', 'failed' => 'La demande n’a pas pu être envoyée. Merci de nous appeler.'],
            'it' => ['make' => 'Marca', 'fuel' => 'Carburante', 'price_max' => 'Prezzo fino a CHF', 'search' => 'Cerca', 'none' => 'Nessun veicolo trovato.', 'unavailable' => 'Il parco veicoli non è disponibile al momento.', 'not_found' => 'Veicolo non trovato.', 'newest' => 'Più recenti', 'price_asc' => 'Prezzo crescente', 'price_desc' => 'Prezzo decrescente', 'mileage_asc' => 'Chilometri crescenti', 'petrol' => 'Benzina', 'diesel' => 'Diesel', 'hybrid' => 'Ibrido', 'electric' => 'Elettrico', 'manual' => 'Manuale', 'automatic' => 'Automatico', 'reserved' => 'Riservato', 'sold' => 'Venduto', 'on_request' => 'Prezzo su richiesta', 'first_registration' => '1a immatricolazione', 'mileage' => 'Chilometri', 'transmission' => 'Cambio', 'power' => 'Potenza', 'color' => 'Colore', 'mfk' => 'Collaudo fino a', 'equipment' => 'Equipaggiamento', 'ask' => 'Richiesta', 'ask_about' => 'Richiesta su %s', 'name' => 'Nome', 'email' => 'E-mail', 'phone' => 'Telefono', 'message' => 'Messaggio', 'send' => 'Invia', 'thanks' => 'Grazie, vi risponderemo presto.', 'failed' => 'Non è stato possibile inviare la richiesta. Chiamateci per favore.'],
            'en' => ['make' => 'Make', 'fuel' => 'Fuel', 'price_max' => 'Price up to CHF', 'search' => 'Search', 'none' => 'No vehicles found.', 'unavailable' => 'The stock list is not available right now.', 'not_found' => 'Vehicle not found.', 'newest' => 'Newest', 'price_asc' => 'Price ascending', 'price_desc' => 'Price descending', 'mileage_asc' => 'Mileage ascending', 'petrol' => 'Petrol', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'electric' => 'Electric', 'manual' => 'Manual', 'automatic' => 'Automatic', 'reserved' => 'Reserved', 'sold' => 'Sold', 'on_request' => 'Price on request', 'first_registration' => 'First registration', 'mileage' => 'Mileage', 'transmission' => 'Gearbox', 'power' => 'Power', 'color' => 'Colour', 'mfk' => 'Inspection until', 'equipment' => 'Equipment', 'ask' => 'Enquiry', 'ask_about' => 'Enquiry about %s', 'name' => 'Name', 'email' => 'E-mail', 'phone' => 'Phone', 'message' => 'Message', 'send' => 'Send', 'thanks' => 'Thank you, we will get back to you soon.', 'failed' => 'The enquiry could not be sent. Please call us.'],
        ];

        return $texts[self::options()['language']][$key] ?? $texts['de'][$key] ?? $key;
    }
}

Dealer_Saas_Stock::init();
