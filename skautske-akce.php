<?php
/*
Plugin Name: Skautské akce
Description: Přehled plánovaných akcí střediska. Akce zadávají administrátoři ve wp-adminu, na web se vkládají shortcodem [skautske_akce]. Budoucí brigády z pluginu Skautské brigády se do přehledu načítají automaticky.
Version: 1.0
Author: Honza Outlý
Requires at least: 6.0
Requires PHP: 8.0
*/

if (!defined('ABSPATH')) exit;

define('SKA_OPT_AKCE', 'ska_akce');            // pole akcí [id => akce]
define('SKA_OPT_DALSI_ID', 'ska_dalsi_id');    // čítač ID akcí
define('SKA_OPT_NASTAVENI', 'ska_nastaveni');  // nastavení pluginu
define('SKA_CAP', 'manage_options');           // kdo smí akce spravovat (administrátoři)

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

// Poslední den akce (u jednodenní datum akce).
function ska_konec($akce) {
    return !empty($akce['datum_do']) ? $akce['datum_do'] : $akce['datum_od'];
}

function ska_je_budouci($akce, $dnes = null) {
    return ska_konec($akce) >= ($dnes ?? ska_dnes());
}

function ska_razeni($a, $b) {
    return [$a['datum_od'], ska_platny_cas($a['cas_od'] ?? '') ? ska_normalizovat_cas($a['cas_od']) : '99:99', $a['popis']]
       <=> [$b['datum_od'], ska_platny_cas($b['cas_od'] ?? '') ? ska_normalizovat_cas($b['cas_od']) : '99:99', $b['popis']];
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
    if ($a['popis'] === '') $chyby[] = 'Vyplňte popis akce.';

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
            $misto = trim((string) get_post_meta($p->ID, $k['misto'], true));
            $nazev = trim((string) $p->post_title);
            $popis = trim($n['brigady_predpona'] . ($nazev !== '' ? ': ' . $nazev : ''), ': ');
            if ($misto !== '') $popis .= ' (' . $misto . ')';
            $pozn = [];
            if (get_post_meta($p->ID, $k['datum_predbezne'], true) === '1') $pozn[] = 'termín je předběžný';
            if (get_post_meta($p->ID, $k['cas_predbezne'], true) === '1' && ($cas['cas_od'] !== '' || $cas['cas_text'] !== '')) $pozn[] = 'čas je předběžný';
            if ($pozn) $popis .= ' – ' . implode(', ', $pozn);

            $akce[] = [
                'id'       => 'brigada-' . $p->ID,
                'datum_od' => $datum,
                'datum_do' => '',
                'cas_od'   => $cas['cas_od'],
                'cas_do'   => $cas['cas_do'],
                'cas_text' => $cas['cas_text'],
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

function ska_shortcode($atts = []) {
    $atts = shortcode_atts([
        'limit'   => 0,       // max. počet zobrazených akcí (0 = vše)
        'brigady' => 'ano',   // ano / ne – zahrnout brigády
        'prazdne' => 'Momentálně nejsou naplánované žádné akce.',
    ], $atts, 'skautske_akce');

    $s_brigadami = !in_array(strtolower((string) $atts['brigady']), ['ne', 'no', '0', 'false'], true);
    $akce = ska_akce_k_zobrazeni($s_brigadami);
    if (intval($atts['limit']) > 0) $akce = array_slice($akce, 0, intval($atts['limit']));

    ob_start();
    ska_css();
    if (!$akce) {
        echo '<p class="ska-prazdne">' . esc_html($atts['prazdne']) . '</p>';
        return ob_get_clean();
    }
    $dnes = ska_dnes();
    echo '<div class="ska-wrap"><table class="ska-tabulka">';
    echo '<thead><tr><th>Datum</th><th>Čas</th><th>Akce</th><th>Podrobnosti</th></tr></thead><tbody>';
    foreach ($akce as $a) {
        $trida = 'ska-' . $a['zdroj'] . ($a['datum_od'] <= $dnes ? ' ska-probiha' : '');
        $cas   = ska_format_cas_akce($a);
        echo '<tr class="' . esc_attr($trida) . '">';
        echo '<td class="ska-datum" data-label="Datum">' . esc_html(ska_format_datum($a['datum_od'], $a['datum_do'] ?? '')) . '</td>';
        echo '<td class="ska-cas' . ($cas === '' ? ' ska-prazdna' : '') . '" data-label="Čas">' . ($cas !== '' ? esc_html($cas) : '<span class="ska-nic">–</span>') . '</td>';
        echo '<td class="ska-popis" data-label="Akce">' . nl2br(esc_html($a['popis'])) . '</td>';
        echo '<td class="ska-odkaz' . (empty($a['url']) ? ' ska-prazdna' : '') . '" data-label="Podrobnosti">';
        if (!empty($a['url'])) {
            $externi = strpos($a['url'], home_url()) !== 0;
            echo '<a href="' . esc_url($a['url']) . '"' . ($externi ? ' target="_blank" rel="noopener"' : '') . '>'
               . ($a['zdroj'] === 'brigady' ? 'Přihlásit se' : 'Více informací') . '</a>';
        } else {
            echo '<span class="ska-nic">–</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
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
 .ska-tabulka td.ska-popis::before{content:""}
 .ska-tabulka td.ska-popis{font-weight:600}
}
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
        $a = array_merge(['datum_od' => '', 'datum_do' => '', 'cas_od' => '', 'cas_do' => '', 'popis' => '', 'url' => ''], (array) $a);
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
        echo '<tr><th><label for="ska-popis">Popis</label></th><td><textarea id="ska-popis" name="popis" rows="3" class="large-text" required>' . esc_textarea($a['popis']) . '</textarea></td></tr>';
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
    echo '<table class="widefat striped"><thead><tr><th>Datum</th><th>Čas</th><th>Popis</th><th>Odkaz</th><th></th></tr></thead><tbody>';
    foreach ($akce as $a) {
        $brigada = ($a['zdroj'] ?? '') === 'brigady';
        echo '<tr><td>' . esc_html(ska_format_datum($a['datum_od'], $a['datum_do'] ?? '')) . '</td>';
        echo '<td>' . esc_html(ska_format_cas_akce($a)) . '</td>';
        echo '<td>' . nl2br(esc_html($a['popis'])) . '</td>';
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
