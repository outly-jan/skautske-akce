<?php
// Token musí odpovídat hodnotě GitHub secret DEPLOY_SECRET v repozitáři
// outly-jan/skautske-akce. Po nahrání na server změň CHANGE_ME na náhodný řetězec.
define('DEPLOY_SECRET', 'CHANGE_ME');

// Nenastavený token (prázdný nebo výchozí CHANGE_ME z repozitáře) = webhook
// vypnutý; jinak by prošel kdokoli, kdo zná veřejnou výchozí hodnotu.
// hash_equals: porovnání v konstantním čase (bez časového útoku).
$token = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');
if (DEPLOY_SECRET === '' || DEPLOY_SECRET === 'CHANGE_ME' || !hash_equals(DEPLOY_SECRET, $token)) {
    http_response_code(403);
    exit('Unauthorized');
}

$repo = 'outly-jan/skautske-akce';

// Stahuje se podle konkrétního commitu, ne podle větve — raw.githubusercontent.com
// drží soubory z větve několik minut v cache, takže deploy hned po mergi
// by jinak mohl nahrát starou verzi. SHA posílá GitHub Actions v hlavičce.
$ref = $_SERVER['HTTP_X_DEPLOY_SHA'] ?? '';
if (!preg_match('/^[0-9a-f]{40}$/', $ref)) {
    $ref = 'main';
}

$api_context = stream_context_create([
    'http' => ['header' => "User-Agent: skautske-akce-deploy\r\n"],
]);

// Nasadit jde jen commit z historie větve main. GitHub zpřístupňuje přes
// adresu tohoto repozitáře i commity z cizích forků — bez téhle kontroly
// by šlo s uniklým tokenem nasadit na server cizí kód.
if ($ref !== 'main') {
    $compare_json = @file_get_contents(
        'https://api.github.com/repos/' . $repo . '/compare/main...' . $ref,
        false,
        $api_context
    );
    $compare = $compare_json ? json_decode($compare_json, true) : null;
    if (!$compare || !in_array($compare['status'] ?? '', ['identical', 'behind'], true)) {
        http_response_code(403);
        exit('CHYBA: commit ' . $ref . ' není v historii větve main (nebo ho nešlo ověřit)');
    }
}

$base_url = 'https://raw.githubusercontent.com/' . $repo . '/' . $ref . '/';
$target   = __DIR__;
$log      = [];
$errors   = 0;

// Soubory, které na server nepatří. Webhook sám sebe nikdy nestahuje —
// na serveru má ručně nastavený token, který by se přepsal na CHANGE_ME.
$skip_files    = ['deploy-webhook.php', 'CLAUDE.md', '.gitignore', '.gitattributes'];
$skip_prefixes = ['.github/', 'tests/'];

// Seznam souborů podle aktuálního obsahu repa (žádný natvrdo psaný seznam),
// ať se s přibývajícími částmi pluginu nemusí tenhle skript ručně rozšiřovat.
$tree_json = @file_get_contents(
    'https://api.github.com/repos/' . $repo . '/git/trees/' . $ref . '?recursive=1',
    false,
    $api_context
);
$tree = $tree_json ? json_decode($tree_json, true) : null;

if (!$tree || empty($tree['tree'])) {
    http_response_code(500);
    exit('CHYBA: nepodařilo se načíst seznam souborů z GitHubu');
}

foreach ($tree['tree'] as $item) {
    if ($item['type'] !== 'blob') continue;

    $path = $item['path'];
    if (in_array($path, $skip_files, true)) continue;
    foreach ($skip_prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) continue 2;
    }

    $data = @file_get_contents($base_url . $path);
    if ($data === false) {
        $log[] = 'CHYBA: ' . $path;
        $errors++;
        continue;
    }

    $dest = $target . '/' . $path;
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }
    if (file_put_contents($dest, $data) === false) {
        $log[] = 'CHYBA zápisu: ' . $path;
        $errors++;
        continue;
    }
    if (function_exists('opcache_invalidate') && str_ends_with($dest, '.php')) {
        opcache_invalidate($dest, true);
    }
    $log[] = $path . ' — ' . strlen($data) . ' bytes';
}

if ($errors > 0) {
    http_response_code(500);
}
$status = $errors === 0 ? 'OK' : "CHYBY: $errors";
echo $status . ' — ' . date('Y-m-d H:i:s') . ' — ' . $ref . "\n" . implode("\n", $log);
