<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class WebApp {
    const MARKER='nasr_pwa_payment_return';
    public static function boot() {
        // The integrated module replaces the legacy add-on hooks without redeclaring its class.
        add_action('template_redirect',array(__CLASS__,'route'),0);
        add_action('admin_menu',array(__CLASS__,'menu'),30);
        foreach (array('login','role','remember','logout') as $action) {
            add_action('admin_post_nasr_pwa_'.$action,function()use($action){self::submit($action);});
            add_action('admin_post_nopriv_nasr_pwa_'.$action,function()use($action){self::submit($action);});
        }
        add_filter('page_link',array(__CLASS__,'payment_return'),20,2);
        add_filter('post_type_link',array(__CLASS__,'payment_return'),20,2);
        add_filter('rest_post_dispatch',array(__CLASS__,'remember_checkout'),30,3);
    }
    public static function ready() {
        return class_exists('Nasr\\Academy\\Support') && class_exists('Nasr\\Academy\\Mobile');
    }
    public static function url($resource='app') {
        return add_query_arg('nasr_pwa',$resource,home_url('/'));
    }
    public static function assets() { return NASR_ACADEMY_URL.'assets/mobile/'; }
    public static function notice() {
        if (current_user_can('manage_options') && !self::ready()) { echo '<div class="notice notice-error"><p>وب‌اپ نصر به افزونهٔ اصلی سامانه آموزشگاه نصر نسخهٔ 1.5.0-beta.4 یا جدیدتر نیاز دارد.</p></div>'; }
    }
    public static function menu() {
        add_submenu_page('nasr-academy','وب‌اپ موبایل','وب‌اپ موبایل','manage_options','nasr-webapp',function(){
            echo '<div class="wrap"><h1>وب‌اپ موبایل آموزشگاه نصر</h1><p><a class="button button-primary" href="'.esc_url(self::url()).'" target="_blank" rel="noopener">بازکردن وب‌اپ</a></p><p>لینک نصب برای زبان‌آموزان: <a href="'.esc_url(self::url()).'">'.esc_html(self::url()).'</a></p><p>نسخهٔ یکپارچه: '.esc_html(NASR_ACADEMY_VERSION).'</p><p>در آیفون لینک را با Safari باز کنید و از Share گزینهٔ Add to Home Screen را انتخاب کنید. ارتباط سایت باید HTTPS باشد.</p></div>';
        });
    }
    public static function origin_ok() {
        $value=$_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if (!is_string($value) || $value==='' || $value==='null') { return false; }
        $origin=wp_parse_url($value);$home=wp_parse_url(home_url('/'));
        return is_array($origin) && is_array($home) && strtolower($origin['scheme'] ?? '')===strtolower($home['scheme'] ?? '') && strtolower($origin['host'] ?? '')===strtolower($home['host'] ?? '') && (int)($origin['port'] ?? 443)===(int)($home['port'] ?? 443);
    }
    public static function validate_post($action) {
        if (!is_ssl() || ($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !self::origin_ok()) { return false; }
        $nonce=$_POST['_nasr_pwa_nonce'] ?? '';
        return is_string($nonce) && wp_verify_nonce(wp_unslash($nonce),'nasr_pwa_'.$action);
    }
    private static function go($error='') {
        wp_safe_redirect($error?add_query_arg('pwa_error',$error,self::url()):self::url());exit;
    }
    private static function session_metadata($token,$uid,$remember) {
        $store=\WP_Session_Tokens::get_instance($uid);$session=$store->get($token);
        if (is_array($session)) { $session['nasr_pwa_remember']=(bool)$remember;$store->update($token,$session); }
    }
    public static function submit($action) {
        if (!self::ready()) { wp_die('ابتدا افزونهٔ اصلی آموزشگاه را فعال کنید.','',array('response'=>503)); }
        if (!self::validate_post($action)) { self::go('expired'); }
        if ($action==='login') { self::login(); }
        if (!is_user_logged_in() || !\Nasr\Academy\Support::available_roles()) { self::go('denied'); }
        if ($action==='role') {
            $role=$_POST['role'] ?? '';
            if (!is_string($role) || !\Nasr\Academy\Support::set_role_mode(wp_unslash($role))) { self::go('role'); }
        } elseif ($action==='remember') {
            $remember=isset($_POST['remember']);$uid=get_current_user_id();
            $old=wp_get_session_token();$roles=\Nasr\Academy\Support::selected_role();
            $capture=function($cookie,$expire,$expiration,$user_id,$scheme,$token)use($remember,$roles){
                self::session_metadata($token,$user_id,$remember);
                if ($roles) { $store=\WP_Session_Tokens::get_instance($user_id);$session=$store->get($token);if(is_array($session)){$session['nasr_role_mode']=$roles;$store->update($token,$session);} }
            };
            add_action('set_logged_in_cookie',$capture,10,6);
            wp_set_auth_cookie($uid,$remember,true);
            remove_action('set_logged_in_cookie',$capture,10);
            if($old) { \WP_Session_Tokens::get_instance($uid)->destroy($old); }
        } elseif ($action==='logout') {
            wp_logout();self::marker(false);
        }
        self::go();
    }
    private static function login() {
        $name=$_POST['username'] ?? '';$password=$_POST['password'] ?? '';
        if(!is_string($name) || !is_string($password) || $name==='' || strlen($name)>100 || $password==='' || strlen($password)>4096) { self::go('invalid'); }
        $name=\Nasr\Academy\Support::digits(trim(wp_unslash($name)));
        $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $keys=array('nasr_pwa_ip_'.hash_hmac('sha256',$ip,wp_salt('auth')),'nasr_pwa_user_'.hash_hmac('sha256',$name,wp_salt('auth')));
        if((int)get_transient($keys[0])>=20 || (int)get_transient($keys[1])>=8) { self::go('locked'); }
        $remember=isset($_POST['remember']);
        $capture=function($cookie,$expire,$expiration,$uid,$scheme,$token)use($remember){self::session_metadata($token,$uid,$remember);};
        add_action('set_logged_in_cookie',$capture,10,6);
        $user=wp_signon(array('user_login'=>$name,'user_password'=>wp_unslash($password),'remember'=>$remember),true);
        remove_action('set_logged_in_cookie',$capture,10);
        if(is_wp_error($user)) {
            foreach($keys as $key) { set_transient($key,(int)get_transient($key)+1,900); }
            self::go('invalid');
        }
        wp_set_current_user($user->ID);
        if(!\Nasr\Academy\Support::available_roles()) { wp_logout();self::go('denied'); }
        delete_transient($keys[1]);self::go();
    }
    private static function marker($enabled=true) {
        if(!is_ssl()) { return; }
        setcookie(self::MARKER,$enabled?'1':'',array('expires'=>$enabled?time()+1800:time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'));
    }
    public static function payment_return($url,$post) {
        $id=is_object($post)?($post->ID ?? 0):$post;
        $tx=$_REQUEST['tx'] ?? '';
        $tagged=is_string($tx) && preg_match('/^[a-f0-9]{64}$/D',$tx) && get_transient('nasr_pwa_return_'.hash('sha256',$tx));
        $from_app=$tagged || (isset($_COOKIE[self::MARKER]) && $_COOKIE[self::MARKER]==='1');
        if(self::ready() && $from_app && isset($_REQUEST['action']) && is_string($_REQUEST['action']) && $_REQUEST['action']==='nasr_payment_callback' && (int)$id===(int)get_option('nasr_academy_page_id')) { return self::url(); }
        return $url;
    }
    public static function remember_checkout($response,$server,$request) {
        if ($request->get_method()!=='POST' || $request->get_header('X-Nasr-PWA')!=='1' || !preg_match('~^/nasr/v1/checkout/\d+/start$~D',$request->get_route()) || !($response instanceof \WP_REST_Response) || $response->get_status()>=300) { return $response; }
        $data=$response->get_data();$id=is_array($data)?(int)($data['transaction_id'] ?? 0):0;
        if($id && is_user_logged_in()) {
            global $wpdb;$token=$wpdb->get_var($wpdb->prepare('SELECT token FROM '.\Nasr\Academy\Support::table('payment_transactions').' WHERE id=%d AND created_by=%d',$id,get_current_user_id()));
            if(is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)) { set_transient('nasr_pwa_return_'.hash('sha256',$token),true,86400); }
        }
        return $response;
    }
    public static function manifest() {
        return array('id'=>self::url(),'name'=>'آموزشگاه نصر','short_name'=>'نصر','description'=>'سامانهٔ آنلاین آموزشگاه زبان نصر','lang'=>'fa','dir'=>'rtl','start_url'=>self::url(),'scope'=>home_url('/'),'display'=>'standalone','background_color'=>'#f5f6fc','theme_color'=>'#f5f6fc','icons'=>array(array('src'=>self::assets().'icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'),array('src'=>self::assets().'icon-1024.png','sizes'=>'1024x1024','type'=>'image/png','purpose'=>'any')));
    }
    public static function config() {
        $s='Nasr\\Academy\\Support';$logged=is_user_logged_in() && self::ready() && $s::available_roles();
        $session=$logged?\WP_Session_Tokens::get_instance(get_current_user_id())->get(wp_get_session_token()):null;
        $user=$logged?wp_get_current_user():null;
        return array('version'=>'PWA '.NASR_ACADEMY_VERSION,'api'=>rest_url('nasr/v1/'),'assets'=>NASR_ACADEMY_URL.'assets/','app'=>self::url(),'worker'=>self::url('worker'),'scope'=>home_url('/'),'post'=>admin_url('admin-post.php'),'recovery'=>add_query_arg('nasr_recovery','form',\Nasr\Academy\Portal::url()),'restNonce'=>$logged?wp_create_nonce('wp_rest'):'','nonces'=>array('login'=>wp_create_nonce('nasr_pwa_login'),'role'=>wp_create_nonce('nasr_pwa_role'),'remember'=>wp_create_nonce('nasr_pwa_remember'),'logout'=>wp_create_nonce('nasr_pwa_logout')),'user'=>$logged?array('user_id'=>(int)$user->ID,'name'=>$user->display_name,'available_roles'=>$s::available_roles(),'role'=>$s::selected_role()):null,'remember'=>!empty($session['nasr_pwa_remember']),'error'=>isset($_GET['pwa_error']) && is_scalar($_GET['pwa_error'])?sanitize_key((string)$_GET['pwa_error']):'');
    }
    public static function route() {
        $resource=$_GET['nasr_pwa'] ?? null;
        if(!is_string($resource) || !in_array($resource,array('app','manifest','worker'),true)) { return; }
        nocache_headers();header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',true);header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header('X-Robots-Tag: noindex, nofollow');status_header(200);
        if($resource==='manifest') { header('Content-Type: application/manifest+json; charset=utf-8');echo wp_json_encode(self::manifest(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
        if($resource==='worker') { header('Content-Type: application/javascript; charset=utf-8');header('Service-Worker-Allowed: '.(wp_parse_url(home_url('/'),PHP_URL_PATH) ?: '/'));readfile(dirname(__DIR__).'/assets/mobile/worker.js');exit; }
        if(!self::ready()) { status_header(503);wp_die('افزونهٔ اصلی سامانهٔ آموزشگاه نصر باید فعال باشد.','وب‌اپ نصر',array('response'=>503)); }
        if(!is_ssl()) { status_header(400);wp_die('وب‌اپ باید روی نشانی HTTPS سایت باز شود.','وب‌اپ نصر',array('response'=>400)); }
        self::marker();$config=self::config();$assets=self::assets();
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f5f6fc"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="نصر"><meta name="robots" content="noindex,nofollow"><title>آموزشگاه نصر</title><link rel="manifest" href="<?php echo esc_url(self::url('manifest')); ?>"><link rel="apple-touch-icon" href="<?php echo esc_url($assets.'icon-1024.png'); ?>"><link rel="icon" href="<?php echo esc_url($assets.'icon-192.png'); ?>"><link rel="stylesheet" href="<?php echo esc_url(NASR_ACADEMY_URL.'assets/portal.css?v='.NASR_ACADEMY_VERSION); ?>"><link rel="stylesheet" href="<?php echo esc_url($assets.'app.css?v='.NASR_ACADEMY_VERSION); ?>"><link rel="stylesheet" href="<?php echo esc_url($assets.'pwa.css?v='.NASR_ACADEMY_VERSION); ?>"></head><body class="nasr-portal nasr-native-app nasr-pwa"><div id="nasr-app"></div><noscript>برای استفاده از سامانه، JavaScript مرورگر را فعال کنید.</noscript><script>window.NasrPWAConfig=<?php echo wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;window.NasrAppConfig=window.NasrPWAConfig;</script><script src="<?php echo esc_url($assets.'pwa.js?v='.NASR_ACADEMY_VERSION); ?>"></script><script src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/online.js?v='.NASR_ACADEMY_VERSION); ?>"></script><script src="<?php echo esc_url($assets.'app-ui.js?v='.NASR_ACADEMY_VERSION); ?>"></script><script src="<?php echo esc_url($assets.'pwa-auth.js?v='.NASR_ACADEMY_VERSION); ?>"></script></body></html><?php
        exit;
    }
}
