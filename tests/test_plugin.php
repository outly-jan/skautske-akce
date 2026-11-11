<?php
// Testy pluginu Skautské akce: php tests/test_plugin.php
require __DIR__ . '/wp_stubs.php';
require __DIR__ . '/../skautske-akce.php';

$chyb = 0; $testu = 0;
function ok($podminka, $nazev) {
    global $chyb, $testu;
    $testu++;
    if (!$podminka) { $chyb++; echo "SELHALO: $nazev\n"; }
}
function je($skutecne, $ocekavane, $nazev) {
    ok($skutecne === $ocekavane, $nazev . ' (čekáno ' . var_export($ocekavane, true) . ', je ' . var_export($skutecne, true) . ')');
}

// ---- Formátování ----
je(ska_format_datum('2026-11-14'), 'so 14. 11. 2026', 'jednodenní datum');
je(ska_format_datum('2026-11-13', '2026-11-15'), 'pá 13. 11. – ne 15. 11. 2026', 'rozmezí v jednom roce');
je(ska_format_datum('2026-12-30', '2027-01-02'), 'st 30. 12. 2026 – so 2. 1. 2027', 'rozmezí přes Nový rok');
je(ska_format_cas_akce(['datum_od' => '2026-11-14', 'cas_od' => '09:00', 'cas_do' => '12:30']), '9:00–12:30', 'čas od–do');
je(ska_format_cas_akce(['datum_od' => '2026-11-14', 'cas_od' => '09:00', 'cas_do' => '']), '9:00', 'jen začátek');
je(ska_format_cas_akce(['datum_od' => '2026-11-14', 'cas_od' => '', 'cas_do' => '17:00']), 'do 17:00', 'jen konec');
je(ska_format_cas_akce(['datum_od' => '2026-11-14', 'cas_od' => '', 'cas_do' => '']), '', 'bez času');
je(ska_format_cas_akce(['datum_od' => '2026-11-13', 'datum_do' => '2026-11-15', 'cas_od' => '18:00', 'cas_do' => '12:00']), 'pá 18:00 – ne 12:00', 'čas vícedenní akce');

// ---- Odkazy v popisu ----
je(ska_popis_html('Základna (Herálec): https://mapy.com/s/bejulodetu) – čas'),
   'Základna (Herálec): <a href="https://mapy.com/s/bejulodetu" target="_blank" rel="noopener" class="ska-url">mapa</a>) – čas', 'odkaz na mapy.com → „mapa“, závorka zůstane mimo');
je(ska_popis_html('Viz www.skaut.cz/akce, nebo https://cs.wikipedia.org/wiki/Skaut_(hnutí).'),
   'Viz <a href="https://www.skaut.cz/akce" target="_blank" rel="noopener" class="ska-url">www.skaut.cz/akce</a>, nebo <a href="https://cs.wikipedia.org/wiki/Skaut_(hnutí)" target="_blank" rel="noopener" class="ska-url">cs.wikipedia.org/wiki/Skaut_(hnutí)</a>.', 'www odkaz, vyvážená závorka a tečka na konci');
je(ska_popis_html('<b>x</b> & https://example.org/?a=1&b=2'),
   '&lt;b&gt;x&lt;/b&gt; &amp; <a href="https://example.org/?a=1&amp;b=2" target="_blank" rel="noopener" class="ska-url">example.org/?a=1&amp;b=2</a>', 'escapování textu i odkazu');
je(ska_popis_html("Řádek 1\nhttps://maps.app.goo.gl/abc"), "Řádek 1<br />\n<a href=\"https://maps.app.goo.gl/abc\" target=\"_blank\" rel=\"noopener\" class=\"ska-url\">mapa</a>", 'zalomení řádku a Google mapy');
je(ska_popis_html('javascript:alert(1)'), 'javascript:alert(1)', 'jiné schéma se neodkazuje');

// ---- Validace ----
[$a, $ch] = ska_validovat(['datum_od' => '2026-11-20', 'datum_do' => '', 'cas_od' => '9:00', 'cas_do' => '', 'nazev' => ' Výprava ', 'url' => 'skautchlumec.cz/vyprava']);
je($ch, [], 'platná akce bez chyb');
je($a['cas_od'], '09:00', 'čas se normalizuje');
je($a['nazev'], 'Výprava', 'název se ořízne');
je($a['url'], 'https://skautchlumec.cz/vyprava', 'URL bez schématu dostane https://');
[$a, $ch] = ska_validovat(['datum_od' => '2026-11-20', 'datum_do' => '2026-11-20', 'nazev' => 'x']);
je($a['datum_do'], '', 'stejné datum konce = jednodenní akce');
[, $ch] = ska_validovat(['datum_od' => '2026-11-20', 'datum_do' => '2026-11-19', 'nazev' => 'x']);
ok(count($ch) === 1, 'konec před začátkem je chyba');
[, $ch] = ska_validovat(['datum_od' => '2026-02-30', 'nazev' => '']);
ok(count($ch) === 2, 'neplatné datum a prázdný název');
[, $ch] = ska_validovat(['datum_od' => '2026-11-20', 'cas_od' => '14:00', 'cas_do' => '10:00', 'nazev' => 'x']);
ok(count($ch) === 1, 'jednodenní: konec dřív než začátek je chyba');
[, $ch] = ska_validovat(['datum_od' => '2026-11-20', 'datum_do' => '2026-11-21', 'cas_od' => '14:00', 'cas_do' => '10:00', 'nazev' => 'x']);
je($ch, [], 'vícedenní: konec ve dřívější hodinu je v pořádku');
[, $ch] = ska_validovat(['datum_od' => '2026-11-20', 'nazev' => 'x', 'url' => 'javascript:alert(1)']);
ok(count($ch) === 1, 'javascript: URL se odmítne');

// ---- Čas brigády z volného textu ----
je(ska_rozparsovat_cas_brigady('9:00'), ['cas_od' => '09:00', 'cas_do' => '', 'cas_text' => ''], 'brigáda: jen začátek');
je(ska_rozparsovat_cas_brigady('9:00', '4'), ['cas_od' => '09:00', 'cas_do' => '13:00', 'cas_text' => ''], 'brigáda: konec z délky');
je(ska_rozparsovat_cas_brigady('8.30', '2,5 h'), ['cas_od' => '08:30', 'cas_do' => '11:00', 'cas_text' => ''], 'brigáda: tečka a desetinná čárka');
je(ska_rozparsovat_cas_brigady('od 9:00 do 13:30 h'), ['cas_od' => '09:00', 'cas_do' => '13:30', 'cas_text' => ''], 'brigáda: od–do');
je(ska_rozparsovat_cas_brigady('dopoledne'), ['cas_od' => '', 'cas_do' => '', 'cas_text' => 'dopoledne'], 'brigáda: čas jen textem');
je(ska_rozparsovat_cas_brigady('sraz 9:00 u klubovny')['cas_text'], 'sraz 9:00 u klubovny', 'brigáda: doplňující text se zachová');
je(ska_rozparsovat_cas_brigady('22:00', '5')['cas_do'], '', 'brigáda: přes půlnoc se konec nedopočítá');

// ---- Data: vlastní akce + brigády ----
$GLOBALS['opts'][SKA_OPT_AKCE] = [
    1 => ['id' => 1, 'datum_od' => '2026-11-10', 'datum_do' => '',           'cas_od' => '',      'cas_do' => '', 'popis' => 'Včerejší schůzka', 'url' => ''],
    2 => ['id' => 2, 'datum_od' => '2026-11-11', 'datum_do' => '',           'cas_od' => '17:00', 'cas_do' => '', 'popis' => 'Dnešní schůzka',   'url' => ''],
    3 => ['id' => 3, 'datum_od' => '2026-11-09', 'datum_do' => '2026-11-12', 'cas_od' => '',      'cas_do' => '', 'popis' => 'Probíhající tábor', 'url' => 'https://example.org/tabor'],
    4 => ['id' => 4, 'datum_od' => '2026-12-05', 'datum_do' => '',           'cas_od' => '',      'cas_do' => '', 'popis' => 'Mikuláš <b>x</b>', 'url' => ''],
    5 => ['id' => 5, 'datum_od' => '2026-11-01', 'datum_do' => '2026-11-10', 'cas_od' => '',      'cas_do' => '', 'popis' => 'Skončená výprava', 'url' => ''],
    6 => 'rozbitý záznam',
];
atrapa_post(100, 'brigada', 'Úklid klubovny', ['datum_brigady' => '2026-11-21', 'cas_brigady' => '9:00', 'delka_brigady' => '3', 'misto_konani' => 'Klubovna', 'datum_predbezne' => '0', 'vhodne_pro' => 'dospělé', 'poznamka_brigady' => 'vezměte rukavice']);
atrapa_post(101, 'brigada', 'Stará brigáda',  ['datum_brigady' => '2026-11-01', 'cas_brigady' => '9:00']);
atrapa_post(102, 'brigada', 'Dnešní brigáda', ['datum_brigady' => '2026-11-11', 'cas_brigady' => '', 'datum_predbezne' => '1']);
atrapa_post(103, 'brigada', 'Koncept',        ['datum_brigady' => '2026-11-30'], 'draft');
atrapa_post(104, 'brigada', 'Bez data',       []);
atrapa_post(105, 'brigada', 'Český zápis',    ['datum_brigady' => '5. 12. 2026']);
atrapa_post(200, 'page', 'Brigády', [], 'publish', 'text [sb_prehled_brigad] text', 'brigady');

$akce = ska_akce_k_zobrazeni();
je(array_column($akce, 'nazev'), [
    'Probíhající tábor',
    'Dnešní schůzka',
    'Brigáda: Dnešní brigáda',
    'Brigáda: Úklid klubovny',
    'Brigáda: Český zápis',
    'Mikuláš <b>x</b>',
], 'přehled: jen budoucí/probíhající, seřazené, s brigádami');
$uklid = $akce[3];
je([$uklid['cas_od'], $uklid['cas_do'], $uklid['url']], ['09:00', '12:00', 'https://skautchlumec.cz/brigady/'], 'brigáda: čas a odkaz na stránku s brigádami');
je($uklid['popis'], "Místo: Klubovna\nTrvání: 3 h\nVhodné pro: dospělé\nPoznámka: vezměte rukavice", 'brigáda: podrobnosti');
je(array_column($akce, 'popis')[0], '', 'stará akce bez názvu: popis se stane názvem');

je(count(ska_akce_k_zobrazeni(false)), 3, 'bez brigád');
$GLOBALS['opts'][SKA_OPT_NASTAVENI] = ['brigady' => 0];
je(count(ska_akce_k_zobrazeni()), 3, 'brigády vypnuté v nastavení');
$GLOBALS['opts'][SKA_OPT_NASTAVENI] = ['brigady' => 1, 'brigady_url' => 'https://jinde.cz/b', 'brigady_predpona' => 'Pracovní akce'];
$b = array_column(ska_brigady_jako_akce(), null, 'id')['brigada-102'];
je([$b['nazev'], $b['popis'], $b['url']], ['Pracovní akce: Dnešní brigáda', 'Termín je předběžný.', 'https://jinde.cz/b'], 'vlastní označení a odkaz brigád');
unset($GLOBALS['opts'][SKA_OPT_NASTAVENI]);

// Plugin brigád neexistuje / nemá žádná data → přehled funguje dál
$zaloha = $GLOBALS['posts'];
$GLOBALS['posts'] = [];
$GLOBALS['transients'] = [];
je(count(ska_akce_k_zobrazeni()), 3, 'bez brigád v databázi');
$GLOBALS['posts'] = $zaloha;
$GLOBALS['transients'] = []; // na webu adresu stránky vymaže uložení stránky (save_post_page)

// ---- Shortcode ----
$html = ska_shortcode([]);
ok(strpos($html, 'Mikuláš &lt;b&gt;x&lt;/b&gt;') !== false, 'název je escapovaný');
ok(strpos($html, '<span class="ska-nazev-text">Dnešní schůzka</span>') !== false, 'akce bez podrobností není klikací');
ok(preg_match('~<button type="button" class="ska-nazev" data-ska-dialog="(ska-detail-s\d+-\d+)"[^>]*>Probíhající tábor</button>~', $html, $m) === 1
   && strpos($html, '<dialog class="ska-dialog" id="' . $m[1] . '"') !== false, 'název otevírá okno s podrobnostmi');
ok(substr_count($html, '<dialog') === 4 && substr_count($html, '<script>') === 1, 'okna jen u akcí s podrobnostmi, skript jednou');
ok(strpos($html, 'Včerejší schůzka') === false && strpos($html, 'Skončená výprava') === false, 'proběhlé akce se nezobrazují');
ok(strpos($html, 'pá 13. 11.') === false && strpos($html, 'po 9. 11. – čt 12. 11. 2026') !== false, 'vícedenní akce s rozmezím');
ok(strpos($html, 'target="_blank" rel="noopener">Více informací') !== false, 'externí odkaz v novém okně');
ok(strpos($html, 'href="https://skautchlumec.cz/brigady/">Přihlásit se na brigádu') !== false, 'brigáda odkazuje na přihlášení');
ok(substr_count($html, '<style>') === 1, 'CSS jen jednou');
ok(strpos($html, '<div class="ska-prehled" data-ska-url="https://skautchlumec.cz/wp-json/skautske-akce/v1/prehled?limit=0&amp;brigady=ano&amp;prazdne=') !== false, 'shortcode: adresa pro čerstvý přehled');

// REST: stejný obsah jako shortcode, bez stylů/skriptu, necachovat
$odp = ska_rest_prehled(new WP_REST_Request_Atrapa(['limit' => '2', 'brigady' => 'ne', 'inst' => '1']));
ok(substr_count($odp->data['html'], '<tr class=') === 2 && strpos($odp->data['html'], '<style') === false && strpos($odp->data['html'], '<script') === false, 'REST: tabulka bez stylů a skriptu');
ok(strpos($odp->data['html'], 'ska-detail-r1-') !== false, 'REST: vlastní ID oken (nekolidují se zacachovanou stránkou)');
ok(strpos($odp->headers['Cache-Control'], 'no-store') !== false, 'REST: odpověď se necachuje');
$odp = ska_rest_prehled(new WP_REST_Request_Atrapa(['brigady' => 'x<script>', 'prazdne' => '<b>Nic</b>']));
ok(strpos($odp->data['html'], '<tr class="ska-brigady') !== false, 'REST: neznámá hodnota brigady = ano');

$html = ska_shortcode(['limit' => '2', 'brigady' => 'ne']);
je(substr_count($html, '<tr class='), 2, 'limit a brigady="ne"');
$GLOBALS['opts'][SKA_OPT_AKCE] = [];
$GLOBALS['posts'] = [];
ok(strpos(ska_shortcode([]), 'Momentálně nejsou naplánované žádné akce.') !== false, 'prázdný přehled');
ok(strpos(ska_rest_prehled(new WP_REST_Request_Atrapa(['prazdne' => '<b>Nic</b>']))->data['html'], '<p class="ska-prazdne">Nic</p>') !== false, 'REST: vlastní text prázdného přehledu se očistí');

// ---- Administrace ----
function odeslat($akce, $post) {
    $_POST = $post + ['_wpnonce' => 'tok-' . $akce['nonce']];
    $GLOBALS['presmerovano'] = null;
    try { $akce['fn'](); } catch (Presmerovano $e) {}
    return $GLOBALS['presmerovano'];
}
$ulozit = ['fn' => 'ska_akce_ulozit', 'nonce' => 'ska_ulozit'];
$GLOBALS['cache_smazana'] = 0;
$smazat = ['fn' => 'ska_akce_smazat', 'nonce' => 'ska_smazat'];
$url = odeslat($ulozit, ['id' => 0, 'datum_od' => '2026-11-28', 'datum_do' => '2026-11-29', 'cas_od' => '8:00', 'cas_do' => '16:00', 'nazev' => 'Výprava', 'url' => '']);
ok(strpos($url, 'zprava=ulozeno') !== false, 'uložení nové akce');
odeslat($ulozit, ['id' => 0, 'datum_od' => '2026-10-01', 'nazev' => 'Stará']);
je(array_keys(ska_akce_vse()), [1, 2], 'nová akce dostane další ID');
odeslat($ulozit, ['id' => 1, 'datum_od' => '2026-11-28', 'nazev' => 'Výprava upravená']);
je(ska_akce_vse()[1]['nazev'], 'Výprava upravená', 'úprava akce');
$url = odeslat($ulozit, ['id' => 1, 'datum_od' => '', 'nazev' => '', 'popis' => 'jen popis']);
ok(strpos($url, 'upravit=1') !== false && count(get_transient('ska_form_1')['chyby']) === 2, 'chybný formulář se vrátí k opravě');
je(ska_akce_vse()[1]['nazev'], 'Výprava upravená', 'chybný formulář nic nepřepíše');

$_GET = ['page' => 'skautske-akce', 'upravit' => 1];
ob_start(); ska_admin_stranka(); $admin = ob_get_clean();
ok(strpos($admin, 'Datum konce akce') === false && strpos($admin, 'Zadejte platné datum') !== false && strpos($admin, 'Vyplňte název akce.') !== false, 'admin: zobrazí chyby formuláře');
ok(strpos($admin, 'name="nazev" value=""') !== false && strpos($admin, '>jen popis</textarea>') !== false, 'admin: po chybě zůstane zadaný název i popis');
ok(strpos($admin, 'Proběhlé akce (1)') !== false, 'admin: proběhlé akce');
ok(strpos($admin, 'Brigád v databázi: 0') !== false, 'admin: diagnostika brigád');
$_GET = [];

je($GLOBALS['cache_smazana'], 3, 'WP Super Cache se maže po uložení akce (ne po chybném formuláři)');
odeslat($smazat, ['probehle' => 1]);
je(array_keys(ska_akce_vse()), [1], 'smazání proběhlých');
odeslat($smazat, ['id' => 1]);
je(ska_akce_vse(), [], 'smazání akce');
$GLOBALS['muze'] = false;
try { odeslat($ulozit, ['id' => 0, 'datum_od' => '2026-11-28', 'nazev' => 'x']); ok(false, 'bez oprávnění'); }
catch (Exception $e) { ok(strpos($e->getMessage(), 'wp_die') === 0 && ska_akce_vse() === [], 'bez oprávnění nelze ukládat'); }
$GLOBALS['muze'] = true;

// ---- Cache po nasazení nové verze ----
$GLOBALS['cache_smazana'] = 0;
$init = $GLOBALS['akce_hooks']['init'][0];
$init(); $init();
je([$GLOBALS['cache_smazana'], get_option('ska_verze')], [1, SKA_VERZE], 'cache se po nové verzi smaže jen jednou');

echo $chyb ? "\n$chyb z $testu testů SELHALO\n" : "OK – všech $testu testů prošlo\n";
exit($chyb ? 1 : 0);
