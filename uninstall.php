<?php
// Při smazání pluginu ve wp-adminu odstraní jeho data.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('ska_akce');
delete_option('ska_dalsi_id');
delete_option('ska_nastaveni');
delete_transient('ska_brigady_url');
