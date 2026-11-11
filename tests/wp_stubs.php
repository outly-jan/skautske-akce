<?php
// Atrapy WordPressu pro testování pluginu mimo WordPress (bez databáze).
// Spuštění testů: php tests/test_plugin.php   (z kořene repozitáře)
// Tato složka se na server nenahrává (deploy-webhook.php ji přeskakuje).

error_reporting(E_ALL);
define('ABSPATH', '/x/');
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['posts'] = []; $GLOBALS['meta'] = []; $GLOBALS['opts'] = []; $GLOBALS['transients'] = [];
$GLOBALS['shortcodes'] = []; $GLOBALS['dnes'] = '2026-11-11';

class WPDB_Atrapa {
    public $posts = 'wp_posts';
    public function esc_like($t) { return addcslashes($t, '_%\\'); }
    public function prepare($q, ...$a) { return vsprintf(str_replace('%s', "'%s'", $q), $a); }
    public function get_var($q) {
        if (!preg_match("/LIKE '%(.*)%'/", $q, $m)) return null;
        $hledat = stripslashes($m[1]);
        foreach ($GLOBALS['posts'] as $p) {
            if ($p->post_type === 'page' && $p->post_status === 'publish' && strpos($p->post_content, $hledat) !== false) return $p->ID;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new WPDB_Atrapa();

function add_action(...$a) {} function add_filter(...$a) {} function add_menu_page(...$a) {}
function apply_filters($h, $v) { return $v; }
function add_shortcode($n, $cb) { $GLOBALS['shortcodes'][$n] = $cb; }
function shortcode_exists($n) { return isset($GLOBALS['shortcodes'][$n]); }
function shortcode_atts($def, $atts, $sc = '') { return array_merge($def, array_intersect_key((array) $atts, $def)); }
function plugin_basename($f) { return basename(dirname($f)) . '/' . basename($f); }
function wp_date($f) { return date($f, strtotime($GLOBALS['dnes'] . ' 12:00:00')); }
function wp_unslash($v) { return $v; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return esc_html($t); }
function esc_textarea($t) { return esc_html($t); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
function esc_url_raw($u, $prot = null) { return preg_match('#^https?://[^\s<>"]+$#i', $u) ? $u : ''; }
function sanitize_text_field($v) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $v))); }
function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function home_url($p = '') { return 'https://skautchlumec.cz' . $p; }
function get_permalink($id) { return 'https://skautchlumec.cz/' . $GLOBALS['posts'][$id]->post_name . '/'; }
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v, $al = null) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function get_post_meta($id, $k = '', $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_posts($args = []) {
    $r = [];
    foreach ($GLOBALS['posts'] as $p) {
        if (isset($args['post_type']) && $p->post_type !== $args['post_type']) continue;
        if (isset($args['post_status']) && $p->post_status !== $args['post_status']) continue;
        $r[] = $p;
    }
    return $r;
}

// Pomocník: založí příspěvek
function atrapa_post($id, $typ, $titulek, $meta = [], $status = 'publish', $obsah = '', $slug = '') {
    $GLOBALS['posts'][$id] = (object) ['ID' => $id, 'post_type' => $typ, 'post_title' => $titulek, 'post_status' => $status, 'post_content' => $obsah, 'post_name' => $slug];
    $GLOBALS['meta'][$id] = $meta;
}

// Administrace
$GLOBALS['muze'] = true; $GLOBALS['presmerovano'] = null;
class Presmerovano extends Exception {}
function current_user_can($c) { return $GLOBALS['muze']; }
function get_current_user_id() { return 1; }
function wp_die($t) { throw new Exception('wp_die: ' . $t); }
function check_admin_referer($a) { if (($_POST['_wpnonce'] ?? '') !== 'tok-' . $a) throw new Exception('neplatný nonce'); }
function wp_nonce_field($a) { echo "<input type='hidden' name='_wpnonce' value='tok-$a'>"; }
function admin_url($p = '') { return 'https://skautchlumec.cz/wp-admin/' . $p; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($u) { $GLOBALS['presmerovano'] = $u; throw new Presmerovano($u); }
function submit_button($t, $typ = 'primary', $n = 'submit', $wrap = true) { echo "<button type='submit'>" . esc_html($t) . '</button>'; }
function checked($a, $b, $echo = true) { return (string) $a === (string) $b ? ' checked' : ''; }
