<?php
/*
Plugin Name: Skautské akce
Description: Přehled plánovaných akcí střediska. Akce zadávají administrátoři ve wp-adminu, na web se vkládají shortcodem [skautske_akce]. Budoucí brigády z pluginu Skautské brigády se do přehledu načítají automaticky.
Version: 1.3
Author: Honza Outlý
Requires at least: 6.0
Requires PHP: 8.0
*/

if (!defined('ABSPATH')) exit;

define('SKA_OPT_AKCE', 'ska_akce');            // pole akcí [id => akce]
define('SKA_OPT_DALSI_ID', 'ska_dalsi_id');    // čítač ID akcí
define('SKA_OPT_NASTAVENI', 'ska_nastaveni');  // nastavení pluginu
define('SKA_CAP', 'manage_options');           // kdo smí akce spravovat (administrátoři)
define('SKA_VERZE', '1.3');                    // při změně se po nasazení vyčistí cache stránek

/*
 * Cache stránek (na webu WP Super Cache)
 * --------------------------------------
 * Přehled si prohlížeč po načtení stránky vždy stáhne čerstvý přes REST API,
 * takže ani zacachovaná stránka neukáže zastaralé akce. Cache se navíc maže
 * po každé změně akcí/nastavení a po nasazení nové verze pluginu, aby
 * aktuální byla i samotná stránka (bez JavaScriptu, vyhledávače) a hlavně
 * aby se do ní dostal případný nový skript.
 */
function ska_vycistit_cache() {
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache(); // WP Super Cache
    }
    do_action('ska_vycistit_cache'); // pro případnou jinou cache
}

add_action('init', function () {
    if (get_option('ska_verze') !== SKA_VERZE) {
        update_option('ska_verze', SKA_VERZE);
        ska_vycistit_cache();
    }
});

/*
 * Napojení na plugin Skautské brigády
 * -----------------------------------
 * Brigády se NEČTOU přes funkce pluginu Skautské brigády (sb_*), ale přímo
 * z databáze WordPressu: příspěvky typu 'brigada' a jejich post meta. Díky tomu
 * přehled akcí funguje, i když je plugin brigád zrovna aktualizovaný,
 * deaktivovaný nebo přejmenovaný a jeho interní funkce se změní.
 *
 * Jediné, na čem přehled závisí, jsou názvy níže uvedených klíčů. Kdyby se
 * v pluginu Skautské brigády změnily, stačí je upravit tady (nebo filtrem
 * 'ska_brigady_klice' z jiného pluginu). Stav napojení ukazuje wp-admin
 * (Plánované akce → Napojení na Skautské brigády).
 */
function ska_brigady_klice() {
    return apply_filters('ska_brigady_klice', [
        'post_type'       => 'brigada',
        'datum'           => 'datum_brigady',   // Y-m-d
        'cas'             => 'cas_brigady',     // volný text, např. „9:00“ nebo „9:00–13:00“
        'delka'           => 'delka_brigady',   // počet hodin
        'misto'           => 'misto_konani',
        'vhodne'          => 'vhodne_pro',
        'poznamka'        => 'poznamka_brigady',
        'datum_predbezne' => 'datum_predbezne', // '1' = termín předběžný
        'cas_predbezne'   => 'cas_predbezne',   // '1' = čas předběžný
        'shortcody'       => ['sb_prehled_brigad', 'prehled_brigad', 'moje_brigady'], // stránky s brigádami (pořadí = priorita)
    ]);
}

/* =========================================================================
 * Pomocné funkce
 * ========================================================================= */

function ska_dnes() {
    return wp_date('Y-m-d');
}

function ska_platne_datum($d) {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$r, $m, $den] = array_map('intval', explode('-', $d));
    return checkdate($m, $den, $r);
}

function ska_platny_cas($c) {
    return is_string($c) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $c);
}

// „9:05“ → „09:05“ (kvůli řazení a porovnávání)
function ska_normalizovat_cas($c) {
    [$h, $m] = explode(':', $c);
    return sprintf('%02d:%s', intval($h), $m);
}

// „09:05“ → „9:05“
function ska_format_cas($c) {
    if (!ska_platny_cas($c)) return '';
    [$h, $m] = explode(':', $c);
    return intval($h) . ':' . $m;
}

function ska_den_v_tydnu($d) {
    $dny = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];
    return $dny[intval(date('w', strtotime($d . ' 12:00:00')))];
}

// „so 14. 11. 2026“, rozmezí „pá 13. 11. – ne 15. 11. 2026“
function ska_format_datum($od, $do = '') {
    $t_od = strtotime($od . ' 12:00:00');
    if (!$do || $do === $od) {
        return ska_den_v_tydnu($od) . ' ' . date('j. n. Y', $t_od);
    }
    $t_do  = strtotime($do . ' 12:00:00');
    $prvni = date('Y', $t_od) === date('Y', $t_do) ? date('j. n.', $t_od) : date('j. n. Y', $t_od);
    return ska_den_v_tydnu($od) . ' ' . $prvni . ' – ' . ska_den_v_tydnu($do) . ' ' . date('j. n. Y', $t_do);
}

// Čas akce jako text; u vícedenní akce s dny v týdnu („pá 18:00 – ne 12:00“).
function ska_format_cas_akce($akce) {
    if (!empty($akce['cas_text'])) return $akce['cas_text'];
    $od = ska_format_cas($akce['cas_od'] ?? '');
    $do = ska_format_cas($akce['cas_do'] ?? '');
    if ($od === '' && $do === '') return '';
    $vicedenni = !empty($akce['datum_do']) && $akce['datum_do'] !== $akce['datum_od'];
    if ($vicedenni) {
        $txt = $od !== '' ? ska_den_v_tydnu($akce['datum_od']) . ' ' . $od : '';
        if ($do !== '') $txt .= ($txt !== '' ? ' – ' : 'do ') . ska_den_v_tydnu($akce['datum_do']) . ' ' . $do;
        return $txt;
    }
    if ($od !== '' && $do !== '') return $od . '–' . $do;
    return $od !== '' ? $od : 'do ' . $do;
}

// Popis jako HTML: escapovaný text, zalomení řádků a rozklikávací odkazy
// (http(s)://… i www.…). Odkazy na mapy se zkrátí na „mapa“.
function ska_popis_html($text) {
    $text = (string) $text;
    $html = '';
    $pos  = 0;
    if (preg_match_all('~(?:https?://|www\.)[^\s<>"]+~iu', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$url, $start]) {
            // interpunkce za odkazem (tečka, čárka, uzavírací závorka textu) k odkazu nepatří
            while ($url !== '') {
                $posl = substr($url, -1);
                if (strpos('.,;:!?\'', $posl) !== false
                    || ($posl === ')' && substr_count($url, '(') < substr_count($url, ')'))) {
                    $url = substr($url, 0, -1);
                } else {
                    break;
                }
            }
            if ($url === '') continue;
            $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            $mapa = preg_match('~(^|\.)(mapy\.(cz|com)|maps\.app\.goo\.gl)$~', $host)
                 || (preg_match('~(^|\.)google\.[a-z.]+$~', $host) && strpos($href, '/maps') !== false);
            $popisek = $mapa ? 'mapa' : preg_replace('~^https?://~i', '', $url);
            $html .= esc_html(substr($text, $pos, $start - $pos))
                   . '<a href="' . esc_url($href) . '" target="_blank" rel="noopener" class="ska-url">' . esc_html($popisek) . '</a>';
            $pos = $start + strlen($url);
        }
    }
    $html .= esc_html(substr($text, $pos));
    return nl2br($html);
}

// Poslední den akce (u jednodenní datum akce).
function ska_konec($akce) {
    return !empty($akce['datum_do']) ? $akce['datum_do'] : $akce['datum_od'];
}

function ska_je_budouci($akce, $dnes = null) {
    return ska_konec($akce) >= ($dnes ?? ska_dnes());
}

function ska_razeni($a, $b) {
    return [$a['datum_od'], ska_platny_cas($a['cas_od'] ?? '') ? ska_normalizovat_cas($a['cas_od']) : '99:99', $a['nazev'] ?? '']
       <=> [$b['datum_od'], ska_platny_cas($b['cas_od'] ?? '') ? ska_normalizovat_cas($b['cas_od']) : '99:99', $b['nazev'] ?? ''];
}

// Doplní chybějící pole. Akce uložené před zavedením názvu (verze 1.0)
// měly jen popis – ten se pak použije jako název.
function ska_normalizovat_akci($a) {
    $a = array_merge(['datum_do' => '', 'cas_od' => '', 'cas_do' => '', 'cas_text' => '', 'nazev' => '', 'popis' => '', 'url' => ''], $a);
    if (trim($a['nazev']) === '') {
        $a['nazev'] = $a['popis'];
        $a['popis'] = '';
    }
    return $a;
}

function ska_nastaveni() {
    $n = get_option(SKA_OPT_NASTAVENI, []);
    if (!is_array($n)) $n = [];
    return array_merge(['brigady' => 1, 'brigady_url' => '', 'brigady_predpona' => 'Brigáda'], $n);
}

/* =========================================================================
 * Úložiště akcí (option, bez vlastní tabulky)
 * ========================================================================= */

function ska_akce_vse() {
    $akce = get_option(SKA_OPT_AKCE, []);
    return is_array($akce) ? $akce : [];
}

function ska_ulozit_vse($akce) {
    update_option(SKA_OPT_AKCE, $akce, false);
}

/*
 * Ověří a očistí data z formuláře. Vrací [akce, chyby].
 */
function ska_validovat($vstup) {
    $chyby = [];
    $a = [
        'datum_od' => sanitize_text_field($vstup['datum_od'] ?? ''),
        'datum_do' => sanitize_text_field($vstup['datum_do'] ?? ''),
        'cas_od'   => sanitize_text_field($vstup['cas_od'] ?? ''),
        'cas_do'   => sanitize_text_field($vstup['cas_do'] ?? ''),
        'nazev'    => sanitize_text_field($vstup['nazev'] ?? ''),
        'popis'    => sanitize_textarea_field($vstup['popis'] ?? ''),
        'url'      => trim((string) ($vstup['url'] ?? '')),
    ];

    if (!ska_platne_datum($a['datum_od'])) $chyby[] = 'Zadejte platné datum (začátek akce).';
    if ($a['datum_do'] !== '') {
        if (!ska_platne_datum($a['datum_do'])) $chyby[] = 'Datum konce akce není platné.';
        elseif (ska_platne_datum($a['datum_od']) && $a['datum_do'] < $a['datum_od']) $chyby[] = 'Konec akce nemůže být dřív než začátek.';
        elseif ($a['datum_do'] === $a['datum_od']) $a['datum_do'] = '';
    }
    if ($a['cas_od'] !== '' && !ska_platny_cas($a['cas_od'])) $chyby[] = 'Čas začátku není platný (např. 9:00).';
    if ($a['cas_do'] !== '' && !ska_platny_cas($a['cas_do'])) $chyby[] = 'Čas konce není platný (např. 17:30).';
    if (ska_platny_cas($a['cas_od'])) $a['cas_od'] = ska_normalizovat_cas($a['cas_od']);
    if (ska_platny_cas($a['cas_do'])) $a['cas_do'] = ska_normalizovat_cas($a['cas_do']);
    if ($a['datum_do'] === '' && ska_platny_cas($a['cas_od']) && ska_platny_cas($a['cas_do']) && $a['cas_do'] < $a['cas_od']) {
        $chyby[] = 'Čas konce nemůže být dřív než čas začátku.';
    }
    if ($a['nazev'] === '') $chyby[] = 'Vyplňte název akce.';

    if ($a['url'] !== '') {
        if (!preg_match('#^https?://#i', $a['url'])) $a['url'] = 'https://' . $a['url'];
        $a['url'] = esc_url_raw($a['url'], ['http', 'https']);
        if ($a['url'] === '' || !filter_var($a['url'], FILTER_VALIDATE_URL)) $chyby[] = 'Odkaz na podrobnosti není platná adresa URL.';
    }
    return [$a, $chyby];
}

/* =========================================================================
 * Brigády z pluginu Skautské brigády (jen čtení)
 * ========================================================================= */

// Adresa stránky s přehledem brigád (nastavení, jinak dohledaná podle shortcodu).
function ska_brigady_url() {
    $n = ska_nastaveni();
    if (!empty($n['brigady_url'])) return $n['brigady_url'];

    $ulozena = get_transient('ska_brigady_url');
    if ($ulozena !== false) return $ulozena;

    global $wpdb;
    $url = '';
    foreach (ska_brigady_klice()['shortcody'] as $sc) {
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE %s ORDER BY ID LIMIT 1",
            '%' . $wpdb->esc_like('[' . $sc) . '%'
        ));
        if ($id) { $url = (string) get_permalink($id); break; }
    }
    set_transient('ska_brigady_url', $url, 12 * HOUR_IN_SECONDS);
    return $url;
}

// Při úpravě stránek se adresa dohledá znovu.
add_action('save_post_page', function () { delete_transient('ska_brigady_url'); });

// Z volného textu času brigády („9:00“, „9.00 – 13.30“, „od 8 h“) vytáhne začátek a konec.
function ska_rozparsovat_cas_brigady($text, $delka = '') {
    $text = trim((string) $text);
    $vysl = ['cas_od' => '', 'cas_do' => '', 'cas_text' => ''];
    if ($text === '') return $vysl;

    preg_match_all('/(?<![\d])([01]?\d|2[0-3])\s*[:.]\s*([0-5]\d)(?![\d])/', $text, $m, PREG_SET_ORDER);
    if (!$m) {
        $vysl['cas_text'] = $text;
        return $vysl;
    }
    $vysl['cas_od'] = sprintf('%02d:%s', intval($m[0][1]), $m[0][2]);
    if (isset($m[1])) {
        $vysl['cas_do'] = sprintf('%02d:%s', intval($m[1][1]), $m[1][2]);
    } else {
        // konec dopočítat z délky brigády (hodiny), pokud je číselná a nepřesáhne půlnoc
        $h = str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string) $delka));
        if ($h !== '' && is_numeric($h) && floatval($h) > 0) {
            $min = intval($m[0][1]) * 60 + intval($m[0][2]) + (int) round(floatval($h) * 60);
            if ($min < 24 * 60) $vysl['cas_do'] = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        }
    }
    // Text kolem času (např. „sraz v klubovně“) zachovat, aby se informace neztratila.
    $zbytek = trim(preg_replace('/(?<![\d])([01]?\d|2[0-3])\s*[:.]\s*([0-5]\d)(?![\d])|[–—-]|\b(od|do|h|hod)\b\.?/iu', '', $text), " \t,;");
    if ($zbytek !== '') $vysl['cas_text'] = $text;
    return $vysl;
}

/*
 * Budoucí (a právě probíhající dnešní) brigády převedené do formátu akcí.
 * Nikdy nevyhodí chybu — když se data nepodaří načíst, vrátí prázdné pole,
 * aby nespadl přehled akcí.
 */
function ska_brigady_jako_akce($dnes = null) {
    $dnes = $dnes ?? ska_dnes();
    $akce = [];
    try {
        $k = ska_brigady_klice();
        $n = ska_nastaveni();
        $posty = get_posts([
            'post_type'        => $k['post_type'],
            'post_status'      => 'publish',
            'numberposts'      => -1,
            'suppress_filters' => true,
        ]);
        $url = ska_brigady_url();
        foreach ($posty as $p) {
            $datum = trim((string) get_post_meta($p->ID, $k['datum'], true));
            // kdyby se formát data změnil na český zápis (14. 11. 2026)
            if (!ska_platne_datum($datum) && preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $datum, $dm)) {
                $datum = sprintf('%04d-%02d-%02d', $dm[3], $dm[2], $dm[1]);
            }
            if (!ska_platne_datum($datum) || $datum < $dnes) continue;

            $cas   = ska_rozparsovat_cas_brigady(get_post_meta($p->ID, $k['cas'], true), get_post_meta($p->ID, $k['delka'], true));
            $meta  = function ($pole) use ($p, $k) { return trim((string) get_post_meta($p->ID, $k[$pole], true)); };
            $nazev = trim((string) $p->post_title);
            $nazev = trim($n['brigady_predpona'] . ($nazev !== '' ? ': ' . $nazev : ''), ': ');

            // Podrobnosti jako v pluginu brigád (veřejný přehled brigád)
            $radky = [];
            if ($meta('misto') !== '')    $radky[] = 'Místo: ' . $meta('misto');
            $delka = $meta('delka');
            if ($delka !== '')            $radky[] = 'Trvání: ' . $delka . (is_numeric(str_replace(',', '.', $delka)) ? ' h' : '');
            if ($meta('vhodne') !== '')   $radky[] = 'Vhodné pro: ' . $meta('vhodne');
            if ($meta('poznamka') !== '') $radky[] = 'Poznámka: ' . $meta('poznamka');
            if ($meta('datum_predbezne') === '1') $radky[] = 'Termín je předběžný.';
            if ($meta('cas_predbezne') === '1' && ($cas['cas_od'] !== '' || $cas['cas_text'] !== '')) $radky[] = 'Čas je předběžný.';
            $popis = implode("\n", $radky);

            $akce[] = [
                'id'       => 'brigada-' . $p->ID,
                'datum_od' => $datum,
                'datum_do' => '',
                'cas_od'   => $cas['cas_od'],
                'cas_do'   => $cas['cas_do'],
                'cas_text' => $cas['cas_text'],
                'nazev'    => $nazev,
                'popis'    => $popis,
                'url'      => $url,
                'zdroj'    => 'brigady',
            ];
        }
    } catch (\Throwable $e) {
        if (function_exists('error_log')) error_log('Skautské akce: brigády se nepodařilo načíst – ' . $e->getMessage());
        return [];
    }
    return apply_filters('ska_brigady_akce', $akce);
}

/*
 * Akce pro přehled: vlastní + (volitelně) brigády, jen budoucí, seřazené.
 */
function ska_akce_k_zobrazeni($s_brigadami = true, $dnes = null) {
    $dnes = $dnes ?? ska_dnes();
    $vysl = [];
    foreach (ska_akce_vse() as $a) {
        if (!is_array($a) || !ska_platne_datum($a['datum_od'] ?? '')) continue;
        $a = ska_normalizovat_akci($a);
        $a['zdroj'] = 'vlastni';
        if (ska_je_budouci($a, $dnes)) $vysl[] = $a;
    }
    if ($s_brigadami && ska_nastaveni()['brigady']) {
        $vysl = array_merge($vysl, ska_brigady_jako_akce($dnes));
    }
    usort($vysl, 'ska_razeni');
    return $vysl;
}

/* =========================================================================
 * Frontend – shortcode [skautske_akce]
 * ========================================================================= */

function ska_shortcode_atts($atts) {
    $atts = shortcode_atts([
        'limit'   => 0,       // max. počet zobrazených akcí (0 = vše)
        'brigady' => 'ano',   // ano / ne – zahrnout brigády
        'prazdne' => 'Momentálně nejsou naplánované žádné akce.',
    ], $atts, 'skautske_akce');
    $atts['limit']   = max(0, intval($atts['limit']));
    $atts['brigady'] = in_array(strtolower((string) $atts['brigady']), ['ne', 'no', '0', 'false'], true) ? 'ne' : 'ano';
    $atts['prazdne'] = sanitize_text_field((string) $atts['prazdne']);
    return $atts;
}

/*
 * Shortcode vykreslí přehled rovnou do stránky (funguje i bez JavaScriptu)
 * a navíc ho po načtení stránky prohlížeč stáhne znovu přes REST API.
 * Stránková cache (hosting, Cloudflare, cache plugin) tak nikdy neukáže
 * zastaralý přehled – proběhlé akce zmizí a nové se objeví hned.
 */
function ska_shortcode($atts = []) {
    static $instance = 0;
    $instance++;
    $atts = ska_shortcode_atts($atts);
    $url  = add_query_arg([
        'limit'   => $atts['limit'],
        'brigady' => $atts['brigady'],
        'prazdne' => $atts['prazdne'],
        'inst'    => $instance,
    ], rest_url('skautske-akce/v1/prehled'));

    ob_start();
    ska_css();
    echo '<div class="ska-prehled" data-ska-url="' . esc_url($url) . '">' . ska_prehled_html($atts, 's' . $instance) . '</div>';
    ska_js();
    return ob_get_clean();
}

// Čerstvý přehled pro JavaScript; odpověď se nesmí cachovat.
add_action('rest_api_init', function () {
    register_rest_route('skautske-akce/v1', '/prehled', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'ska_rest_prehled',
    ]);
});

function ska_rest_prehled($req) {
    $atts = ska_shortcode_atts([
        'limit'   => $req->get_param('limit'),
        'brigady' => $req->get_param('brigady'),
        'prazdne' => $req->get_param('prazdne') ?? 'Momentálně nejsou naplánované žádné akce.',
    ]);
    $odp = new WP_REST_Response(['html' => ska_prehled_html($atts, 'r' . intval($req->get_param('inst')))]);
    $odp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $odp->header('CDN-Cache-Control', 'no-store');
    return $odp;
}

// Samotná tabulka + okna s podrobnostmi (bez stylů a skriptu).
function ska_prehled_html($atts, $prefix) {
    $akce = ska_akce_k_zobrazeni($atts['brigady'] === 'ano');
    if ($atts['limit'] > 0) $akce = array_slice($akce, 0, $atts['limit']);
    if (!$akce) {
        return '<p class="ska-prazdne">' . esc_html($atts['prazdne']) . '</p>';
    }
    ob_start();
    $dnes    = ska_dnes();
    $dialogy = '';
    echo '<div class="ska-wrap"><table class="ska-tabulka">';
    echo '<thead><tr><th>Datum</th><th>Čas</th><th>Akce</th></tr></thead><tbody>';
    foreach ($akce as $i => $a) {
        $trida = 'ska-' . $a['zdroj'] . ($a['datum_od'] <= $dnes ? ' ska-probiha' : '');
        $datum = ska_format_datum($a['datum_od'], $a['datum_do'] ?? '');
        $cas   = ska_format_cas_akce($a);
        echo '<tr class="' . esc_attr($trida) . '">';
        echo '<td class="ska-datum" data-label="Datum">' . esc_html($datum) . '</td>';
        echo '<td class="ska-cas' . ($cas === '' ? ' ska-prazdna' : '') . '" data-label="Čas">' . ($cas !== '' ? esc_html($cas) : '<span class="ska-nic">–</span>') . '</td>';
        echo '<td class="ska-akce" data-label="Akce">';
        if (trim($a['popis']) !== '' || !empty($a['url'])) {
            $id = 'ska-detail-' . $prefix . '-' . $i;
            echo '<button type="button" class="ska-nazev" data-ska-dialog="' . esc_attr($id) . '" aria-haspopup="dialog">' . esc_html($a['nazev']) . '</button>';
            $dialogy .= ska_detail_html($id, $a, $datum, $cas);
        } else {
            echo '<span class="ska-nazev-text">' . esc_html($a['nazev']) . '</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>' . $dialogy;
    return ob_get_clean();
}

// Okno s podrobnostmi akce (nativní <dialog>, zavírá se křížkem, Esc nebo kliknutím vedle).
function ska_detail_html($id, $a, $datum, $cas) {
    $h  = '<dialog class="ska-dialog" id="' . esc_attr($id) . '" aria-labelledby="' . esc_attr($id) . '-n">';
    $h .= '<form method="dialog" class="ska-zavrit-form"><button class="ska-zavrit" aria-label="Zavřít">&times;</button></form>';
    $h .= '<h3 class="ska-dialog-nazev" id="' . esc_attr($id) . '-n">' . esc_html($a['nazev']) . '</h3>';
    $h .= '<p class="ska-dialog-kdy"><strong>' . esc_html($datum) . '</strong>' . ($cas !== '' ? '<br>' . esc_html($cas) : '') . '</p>';
    if (trim($a['popis']) !== '') $h .= '<div class="ska-dialog-popis">' . ska_popis_html($a['popis']) . '</div>';
    if (!empty($a['url'])) {
        $externi = strpos($a['url'], home_url()) !== 0;
        $h .= '<p class="ska-dialog-odkaz"><a class="ska-tlacitko" href="' . esc_url($a['url']) . '"' . ($externi ? ' target="_blank" rel="noopener"' : '') . '>'
            . ($a['zdroj'] === 'brigady' ? 'Přihlásit se na brigádu' : 'Více informací') . '</a></p>';
    }
    return $h . '</dialog>';
}

function ska_js() {
    static $vypsano = false;
    if ($vypsano) return;
    $vypsano = true;
    echo '<script>
(function(){
 function obnov(){
  document.querySelectorAll(".ska-prehled[data-ska-url]").forEach(function(el){
   if(!window.fetch||el.querySelector("dialog[open]"))return;
   var u=el.getAttribute("data-ska-url");
   fetch(u+(u.indexOf("?")<0?"?":"&")+"_="+Date.now(),{credentials:"same-origin",cache:"no-store"})
    .then(function(r){return r.ok?r.json():null;})
    .then(function(d){if(d&&typeof d.html==="string"&&!el.querySelector("dialog[open]")){el.innerHTML=d.html;}})
    .catch(function(){});
  });
 }
 if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",obnov);}else{obnov();}
})();
document.addEventListener("click",function(e){
 var b=e.target.closest&&e.target.closest("[data-ska-dialog]");
 if(b){var d=document.getElementById(b.getAttribute("data-ska-dialog"));if(d&&d.showModal){d.showModal();}return;}
 if(e.target.classList&&e.target.classList.contains("ska-dialog")){e.target.close();}
});
</script>';
}
add_shortcode('skautske_akce', 'ska_shortcode');

function ska_css() {
    static $vypsano = false;
    if ($vypsano) return;
    $vypsano = true;
    echo '<style>
.ska-wrap{overflow-x:auto}
.ska-tabulka{width:100%;border-collapse:collapse;font-size:15px}
.ska-tabulka th,.ska-tabulka td{padding:8px 10px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
.ska-tabulka th{background:#f3f3f3;font-weight:600}
.ska-tabulka .ska-datum,.ska-tabulka .ska-cas{white-space:nowrap}
.ska-tabulka .ska-nic{color:#999}
.ska-tabulka tr.ska-probiha td{background:#fffbe6}
@media (max-width:640px){
 .ska-tabulka thead{display:none}
 .ska-tabulka,.ska-tabulka tbody,.ska-tabulka tr,.ska-tabulka td{display:block;width:100%;box-sizing:border-box}
 .ska-tabulka td.ska-prazdna{display:none}
 .ska-tabulka tr{border:1px solid #ddd;border-radius:6px;margin-bottom:10px;padding:4px 0}
 .ska-tabulka td{border:0;padding:3px 10px;white-space:normal}
 .ska-tabulka td::before{content:attr(data-label) ": ";font-weight:600}
 .ska-tabulka td.ska-akce::before{content:""}
}
.ska-tabulka .ska-nazev{background:none;border:0;padding:0;margin:0;font:inherit;font-weight:600;color:inherit;text-align:left;cursor:pointer;text-decoration:underline dotted;text-underline-offset:3px}
.ska-tabulka .ska-nazev:hover,.ska-tabulka .ska-nazev:focus-visible{text-decoration-style:solid}
.ska-tabulka .ska-nazev::after{content:" ›";text-decoration:none;display:inline-block;margin-left:2px}
.ska-tabulka .ska-nazev-text{font-weight:600}
.ska-dialog{max-width:min(520px,calc(100vw - 32px));width:100%;border:0;border-radius:8px;padding:20px 22px;box-shadow:0 10px 40px rgba(0,0,0,.3);box-sizing:border-box}
.ska-dialog::backdrop{background:rgba(0,0,0,.45)}
.ska-dialog .ska-zavrit-form{float:right;margin:-10px -12px 0 8px}
.ska-dialog .ska-zavrit{background:none;border:0;font-size:28px;line-height:1;cursor:pointer;padding:4px 8px;color:inherit}
.ska-dialog .ska-dialog-nazev{margin:0 0 8px}
.ska-dialog .ska-dialog-kdy{margin:0 0 12px}
.ska-dialog .ska-dialog-popis{margin:0 0 12px}
.ska-dialog a.ska-url{overflow-wrap:anywhere}
.ska-dialog .ska-dialog-odkaz{margin:16px 0 0}
.ska-dialog .ska-tlacitko{display:inline-block;padding:8px 16px;border-radius:4px;border:1px solid currentColor;text-decoration:none}
</style>';
}

/* =========================================================================
 * Administrace (jen administrátoři – capability manage_options)
 * ========================================================================= */

add_action('admin_menu', function () {
    add_menu_page('Plánované akce', 'Plánované akce', SKA_CAP, 'skautske-akce', 'ska_admin_stranka', 'dashicons-calendar-alt', 26);
});

function ska_admin_url($args = []) {
    return add_query_arg(array_merge(['page' => 'skautske-akce'], $args), admin_url('admin.php'));
}

function ska_presmerovat($args) {
    wp_safe_redirect(ska_admin_url($args));
    exit;
}

add_action('admin_post_ska_ulozit', 'ska_akce_ulozit');
function ska_akce_ulozit() {
    if (!current_user_can(SKA_CAP)) wp_die('Nemáte oprávnění.');
    check_admin_referer('ska_ulozit');
    [$a, $chyby] = ska_validovat(wp_unslash($_POST));
    $id = intval($_POST['id'] ?? 0);
    if ($chyby) {
        set_transient('ska_form_' . get_current_user_id(), ['akce' => $a, 'chyby' => $chyby], 300);
        ska_presmerovat($id ? ['upravit' => $id] : ['nova' => 1]);
    }
    $vse = ska_akce_vse();
    if (!$id || !isset($vse[$id])) {
        $id = max(intval(get_option(SKA_OPT_DALSI_ID, 1)), $vse ? max(array_keys($vse)) + 1 : 1);
        update_option(SKA_OPT_DALSI_ID, $id + 1, false);
    }
    $vse[$id] = array_merge(['id' => $id], $a);
    ska_ulozit_vse($vse);
    ska_vycistit_cache();
    ska_presmerovat(['zprava' => 'ulozeno']);
}

add_action('admin_post_ska_smazat', 'ska_akce_smazat');
function ska_akce_smazat() {
    if (!current_user_can(SKA_CAP)) wp_die('Nemáte oprávnění.');
    check_admin_referer('ska_smazat');
    $vse = ska_akce_vse();
    if (!empty($_POST['probehle'])) {
        $dnes = ska_dnes();
        $vse  = array_filter($vse, function ($a) use ($dnes) { return is_array($a) && ska_platne_datum($a['datum_od'] ?? '') && ska_je_budouci($a, $dnes); });
    } else {
        unset($vse[intval($_POST['id'] ?? 0)]);
    }
    ska_ulozit_vse($vse);
    ska_vycistit_cache();
    ska_presmerovat(['zprava' => 'smazano']);
}

add_action('admin_post_ska_nastaveni', 'ska_nastaveni_ulozit');
function ska_nastaveni_ulozit() {
    if (!current_user_can(SKA_CAP)) wp_die('Nemáte oprávnění.');
    check_admin_referer('ska_nastaveni');
    $url = trim((string) wp_unslash($_POST['brigady_url'] ?? ''));
    update_option(SKA_OPT_NASTAVENI, [
        'brigady'          => empty($_POST['brigady']) ? 0 : 1,
        'brigady_url'      => $url !== '' ? esc_url_raw($url, ['http', 'https']) : '',
        'brigady_predpona' => sanitize_text_field(wp_unslash($_POST['brigady_predpona'] ?? '')),
    ], false);
    delete_transient('ska_brigady_url');
    ska_vycistit_cache();
    ska_presmerovat(['zprava' => 'nastaveni']);
}

function ska_admin_stranka() {
    if (!current_user_can(SKA_CAP)) wp_die('Nemáte oprávnění.');
    $vse  = ska_akce_vse();
    $dnes = ska_dnes();

    echo '<div class="wrap"><h1 class="wp-heading-inline">Plánované akce</h1>';
    echo ' <a href="' . esc_url(ska_admin_url(['nova' => 1])) . '" class="page-title-action">Přidat akci</a><hr class="wp-header-end">';

    $zpravy = ['ulozeno' => 'Akce byla uložena.', 'smazano' => 'Smazáno.', 'nastaveni' => 'Nastavení bylo uloženo.'];
    $z = sanitize_key($_GET['zprava'] ?? '');
    if (isset($zpravy[$z])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($zpravy[$z]) . '</p></div>';

    // ---- Formulář (nová / úprava) ----
    $upravit = intval($_GET['upravit'] ?? 0);
    if (!empty($_GET['nova']) || ($upravit && isset($vse[$upravit]))) {
        $form = get_transient('ska_form_' . get_current_user_id());
        delete_transient('ska_form_' . get_current_user_id());
        $a = is_array($form) ? $form['akce'] : ($upravit ? $vse[$upravit] : []);
        $a = array_merge(['datum_od' => '', 'datum_do' => '', 'cas_od' => '', 'cas_do' => '', 'nazev' => '', 'popis' => '', 'url' => ''], (array) $a);
        // Stará akce bez názvu: nabídnout popis jako název (po chybě ve formuláři ponechat, co uživatel zadal).
        if (!is_array($form)) $a = ska_normalizovat_akci($a);
        if (is_array($form) && $form['chyby']) {
            echo '<div class="notice notice-error"><p>' . implode('<br>', array_map('esc_html', $form['chyby'])) . '</p></div>';
        }
        echo '<h2>' . ($upravit ? 'Upravit akci' : 'Nová akce') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ska_ulozit');
        echo '<input type="hidden" name="action" value="ska_ulozit"><input type="hidden" name="id" value="' . intval($upravit) . '">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th><label for="ska-od">Datum</label></th><td><input type="date" id="ska-od" name="datum_od" value="' . esc_attr($a['datum_od']) . '" required>'
           . ' – <input type="date" id="ska-do" name="datum_do" value="' . esc_attr($a['datum_do']) . '">'
           . '<p class="description">U jednodenní akce nechte druhé pole prázdné. Akce z přehledu zmizí den po svém (posledním) dni.</p></td></tr>';
        echo '<tr><th><label for="ska-cas-od">Čas (nepovinné)</label></th><td><input type="time" id="ska-cas-od" name="cas_od" value="' . esc_attr($a['cas_od']) . '">'
           . ' – <input type="time" id="ska-cas-do" name="cas_do" value="' . esc_attr($a['cas_do']) . '">'
           . '<p class="description">Od – do; vyplnit lze i jen jeden z časů. U vícedenní akce je to začátek prvního dne a konec posledního dne.</p></td></tr>';
        echo '<tr><th><label for="ska-nazev">Název</label></th><td><input type="text" id="ska-nazev" name="nazev" value="' . esc_attr($a['nazev']) . '" class="regular-text" maxlength="120" required placeholder="např. Skautský ples"><p class="description">Stručné označení – zobrazí se v tabulce. Kliknutím na něj se otevřou podrobnosti.</p></td></tr>';
        echo '<tr><th><label for="ska-popis">Popis (nepovinné)</label></th><td><textarea id="ska-popis" name="popis" rows="4" class="large-text">' . esc_textarea($a['popis']) . '</textarea><p class="description">Podrobnosti v okně po kliknutí na název (místo, co s sebou…). Adresy https://… a www.… budou klikací.</p></td></tr>';
        echo '<tr><th><label for="ska-url">Odkaz na podrobnosti (nepovinné)</label></th><td><input type="url" id="ska-url" name="url" value="' . esc_attr($a['url']) . '" class="regular-text" placeholder="https://"></td></tr>';
        echo '</table>';
        submit_button($upravit ? 'Uložit změny' : 'Přidat akci', 'primary', 'submit', false);
        echo ' <a href="' . esc_url(ska_admin_url()) . '" class="button">Zrušit</a></form><hr>';
    }

    // ---- Přehled budoucích akcí (vlastní i brigády) ----
    echo '<h2>Naplánované akce</h2>';
    echo '<p>Na web se přehled vkládá shortcodem <code>[skautske_akce]</code> (volitelně <code>[skautske_akce limit="5"]</code>, <code>[skautske_akce brigady="ne"]</code>).</p>';
    $budouci = ska_akce_k_zobrazeni(true, $dnes);
    ska_admin_tabulka($budouci, true);

    // ---- Proběhlé vlastní akce ----
    $probehle = array_filter($vse, function ($a) use ($dnes) { return is_array($a) && ska_platne_datum($a['datum_od'] ?? '') && !ska_je_budouci($a, $dnes); });
    if ($probehle) {
        usort($probehle, 'ska_razeni');
        echo '<h2>Proběhlé akce (' . count($probehle) . ')</h2><p>Na webu se už nezobrazují. Můžete je ponechat pro přehled, nebo smazat.</p>';
        echo '<details><summary style="cursor:pointer">Zobrazit</summary>';
        ska_admin_tabulka(array_reverse($probehle), false);
        echo '</details>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px" onsubmit="return confirm(\'Opravdu smazat všechny proběhlé akce?\')">';
        wp_nonce_field('ska_smazat');
        echo '<input type="hidden" name="action" value="ska_smazat"><input type="hidden" name="probehle" value="1">';
        submit_button('Smazat všechny proběhlé akce', 'delete', 'submit', false);
        echo '</form>';
    }

    ska_admin_nastaveni();
    echo '</div>';
}

function ska_admin_tabulka($akce, $s_brigadami) {
    if (!$akce) {
        echo '<p><em>Žádné akce.</em></p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Datum</th><th>Čas</th><th>Název</th><th>Popis</th><th>Odkaz</th><th></th></tr></thead><tbody>';
    foreach ($akce as $a) {
        $a = ska_normalizovat_akci($a);
        $brigada = ($a['zdroj'] ?? '') === 'brigady';
        echo '<tr><td>' . esc_html(ska_format_datum($a['datum_od'], $a['datum_do'] ?? '')) . '</td>';
        echo '<td>' . esc_html(ska_format_cas_akce($a)) . '</td>';
        echo '<td><strong>' . esc_html($a['nazev']) . '</strong></td>';
        echo '<td>' . ska_popis_html($a['popis']) . '</td>';
        echo '<td>' . (!empty($a['url']) ? '<a href="' . esc_url($a['url']) . '" target="_blank" rel="noopener">odkaz</a>' : '') . '</td><td style="white-space:nowrap">';
        if ($brigada) {
            echo '<em>z pluginu Skautské brigády</em>';
        } else {
            echo '<a href="' . esc_url(ska_admin_url(['upravit' => $a['id']])) . '" class="button button-small">Upravit</a> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" onsubmit="return confirm(\'Opravdu smazat tuto akci?\')">';
            wp_nonce_field('ska_smazat');
            echo '<input type="hidden" name="action" value="ska_smazat"><input type="hidden" name="id" value="' . intval($a['id']) . '">';
            echo '<button type="submit" class="button button-small button-link-delete">Smazat</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

function ska_admin_nastaveni() {
    $n = ska_nastaveni();
    $k = ska_brigady_klice();
    echo '<h2 id="ska-brigady">Napojení na Skautské brigády</h2>';

    // Diagnostika: je plugin aktivní a jsou data tam, kde je čekáme?
    $aktivni = function_exists('sb_prehled_brigad_frontend') || shortcode_exists('sb_prehled_brigad');
    $vsechny = get_posts(['post_type' => $k['post_type'], 'post_status' => 'publish', 'numberposts' => -1, 'suppress_filters' => true]);
    $s_datem = 0;
    foreach ($vsechny as $p) {
        if (ska_platne_datum((string) get_post_meta($p->ID, $k['datum'], true))) $s_datem++;
    }
    $budouci = count(ska_brigady_jako_akce());
    $url     = ska_brigady_url();
    echo '<ul style="list-style:disc;margin-left:20px">';
    echo '<li>Plugin Skautské brigády: ' . ($aktivni ? 'aktivní' : '<strong>není aktivní</strong> (brigády se přesto načtou z databáze)') . '</li>';
    echo '<li>Brigád v databázi: ' . count($vsechny) . ', z toho s platným datem: ' . $s_datem . ', budoucích: ' . $budouci . '</li>';
    if (count($vsechny) > 0 && $s_datem === 0) {
        echo '<li><strong style="color:#b32d2e">Pozor: žádná brigáda nemá datum v poli <code>' . esc_html($k['datum']) . '</code> – plugin Skautské brigády nejspíš změnil způsob ukládání. Upravte funkci <code>ska_brigady_klice()</code>.</strong></li>';
    }
    echo '<li>Stránka s brigádami: ' . ($url ? '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a>' : '<em>nenalezena</em> (brigády budou bez odkazu)') . '</li>';
    echo '</ul>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('ska_nastaveni');
    echo '<input type="hidden" name="action" value="ska_nastaveni"><table class="form-table" role="presentation">';
    echo '<tr><th>Brigády v přehledu</th><td><label><input type="checkbox" name="brigady" value="1"' . checked($n['brigady'], 1, false) . '> Automaticky zobrazovat budoucí brigády</label></td></tr>';
    echo '<tr><th><label for="ska-predpona">Označení brigád</label></th><td><input type="text" id="ska-predpona" name="brigady_predpona" value="' . esc_attr($n['brigady_predpona']) . '"><p class="description">Text před názvem brigády, např. „Brigáda: Úklid klubovny“.</p></td></tr>';
    echo '<tr><th><label for="ska-burl">Odkaz u brigád</label></th><td><input type="url" id="ska-burl" name="brigady_url" value="' . esc_attr($n['brigady_url']) . '" class="regular-text" placeholder="automaticky"><p class="description">Prázdné = stránka se shortcodem <code>[sb_prehled_brigad]</code>, <code>[prehled_brigad]</code> nebo <code>[moje_brigady]</code>.</p></td></tr>';
    echo '</table>';
    submit_button('Uložit nastavení');
    echo '</form>';
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($odkazy) {
    array_unshift($odkazy, '<a href="' . esc_url(ska_admin_url()) . '">Spravovat akce</a>');
    return $odkazy;
});
