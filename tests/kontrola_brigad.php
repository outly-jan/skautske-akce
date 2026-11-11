<?php
// Kontrola kompatibility s pluginem Skautské brigády.
// Ověří, že plugin brigád stále ukládá brigády pod klíči, které čte
// ska_brigady_klice(). Spouští GitHub Actions denně a při každém pushi.
//
// Spuštění: php tests/kontrola_brigad.php cesta/k/skautske-brigady.php

require __DIR__ . '/wp_stubs.php';
require __DIR__ . '/../skautske-akce.php';

$soubor = $argv[1] ?? '';
$kod = $soubor !== '' ? @file_get_contents($soubor) : false;
if (!$kod || strlen($kod) < 10000) {
    fwrite(STDERR, "Nepodařilo se načíst kód pluginu Skautské brigády ($soubor)\n");
    exit(2);
}

$k = ska_brigady_klice();
$chyby = [];

if (!preg_match("/'post_type'\s*=>\s*'" . preg_quote($k['post_type'], '/') . "'/", $kod)) {
    $chyby[] = "typ příspěvku '{$k['post_type']}' se v pluginu brigád nepoužívá";
}
foreach (['datum', 'cas', 'delka', 'misto', 'datum_predbezne', 'cas_predbezne'] as $pole) {
    $klic = $k[$pole];
    if (!preg_match("/update_post_meta\([^,]+,\s*'" . preg_quote($klic, '/') . "'/", $kod)) {
        $chyby[] = "plugin brigád už neukládá post meta '$klic' ($pole)";
    }
}
// Datum se ukládá z <input type="date"> → formát Y-m-d
if (!preg_match("/createFromFormat\('Y-m-d',\s*\\\$datum/", $kod)) {
    $chyby[] = "plugin brigád zřejmě už neukládá datum ve formátu Y-m-d";
}
$nalezen = false;
foreach ($k['shortcody'] as $sc) {
    if (preg_match("/add_shortcode\('" . preg_quote($sc, '/') . "'/", $kod)) $nalezen = true;
}
if (!$nalezen) {
    $chyby[] = 'plugin brigád neregistruje žádný ze shortcodů ' . implode(', ', $k['shortcody']) . ' (odkaz u brigád se nedohledá)';
}

if ($chyby) {
    echo "NEKOMPATIBILNÍ se Skautskými brigádami:\n - " . implode("\n - ", $chyby) . "\n";
    echo "Upravte ska_brigady_klice() ve skautske-akce.php.\n";
    exit(1);
}
echo "OK – Skautské brigády ukládají brigády tak, jak je Skautské akce čtou.\n";
