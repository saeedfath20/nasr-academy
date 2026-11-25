<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Releases {
    public static function boot() {
        add_action('template_redirect',array(__CLASS__,'route'),-1);
        add_action('admin_init',array(__CLASS__,'retire_addon'));
    }
    public static function retire_addon() {
        if (!current_user_can('activate_plugins')) { return; }
        $legacy='nasr-academy-webapp/nasr-academy-webapp.php';
        if (function_exists('is_plugin_active') && is_plugin_active($legacy) && !is_plugin_active_for_network($legacy)) {
            deactivate_plugins($legacy,true);
        }
    }
    public static function route() {
        $kind=$_GET['nasr_mobile_release'] ?? null;
        if (!is_string($kind) || !in_array($kind,array('manifest','bundle'),true)) { return; }
        nocache_headers();header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0',true);
        header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',array('GET','HEAD'),true)) { status_header(405);header('Allow: GET, HEAD');exit; }
        $root=NASR_ACADEMY_DIR.'mobile-release/';
        $raw=is_readable($root.'manifest.json')?file_get_contents($root.'manifest.json'):false;
        $envelope=$raw?json_decode($raw,true):null;
        $data=is_array($envelope) && isset($envelope['payload']) && is_string($envelope['payload'])?json_decode($envelope['payload'],true):null;
        if (!is_array($data) || empty($envelope['signature']) || empty($data['sequence']) || !is_readable($root.'bundle.zip')) { status_header(503);exit; }
        if ($kind==='manifest') {
            status_header(200);header('Content-Type: application/json; charset=utf-8');
            if (($_SERVER['REQUEST_METHOD'] ?? '')!=='HEAD') { echo $raw; }exit;
        }
        $sequence=$_GET['release'] ?? '';
        if (!is_scalar($sequence) || (string)$sequence!==(string)$data['sequence']) { status_header(404);exit; }
        status_header(200);header('Content-Type: application/zip');header('Content-Length: '.filesize($root.'bundle.zip'));
        header('Content-Disposition: attachment; filename="nasr-mobile-'.(int)$data['sequence'].'.zip"');
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='HEAD') { readfile($root.'bundle.zip'); }exit;
    }
}
