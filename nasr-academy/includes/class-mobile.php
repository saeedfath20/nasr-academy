<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Mobile {
    private static $session='';
    private static $uid=0;
    private static $error=null;
    public static function boot() {
        add_filter('determine_current_user',array(__CLASS__,'authenticate'),30);
        add_filter('rest_authentication_errors',array(__CLASS__,'rest_auth'),99);
        add_filter('rest_pre_serve_request',array(__CLASS__,'cors'),15,4);
        add_action('rest_api_init',array(__CLASS__,'routes'));
    }
    public static function session_token() { return self::$session; }
    private static function api_request() {
        $path=parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
        $route=$_GET['rest_route'] ?? '';
        return (is_string($path) && preg_match('~/nasr/v1(?:/|$)~',$path)) || (is_scalar($route) && strpos((string)$route,'/nasr/v1/')===0);
    }
    public static function authenticate($current) {
        if (!self::api_request()) { return $current; }
        $header=$_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!is_string($header) || strpos($header,'Nasr ')!==0) { return $current; }
        self::$session=''; self::$uid=0; self::$error=null;
        $token=substr($header,5);
        $parts=explode('.',$token);
        $valid=is_ssl() && strlen($token)<=2048 && count($parts)===3 && $parts[0]==='nasr1';
        if ($valid) {
            $signature=hash_hmac('sha256',$parts[0].'.'.$parts[1],wp_salt('auth'));
            $valid=hash_equals($signature,$parts[2]);
        }
        $payload=$valid ? json_decode(base64_decode(strtr($parts[1],'-_','+/'),true),true) : null;
        $valid=$valid && is_array($payload) && isset($payload['uid'],$payload['exp'],$payload['session']) && is_int($payload['uid']) && $payload['uid']>0 && is_int($payload['exp']) && $payload['exp']>time() && is_string($payload['session']) && strlen($payload['session'])<=256;
        $session=$valid ? \WP_Session_Tokens::get_instance($payload['uid'])->get($payload['session']) : null;
        $valid=$valid && is_array($session) && !empty($session['expiration']) && (int)$session['expiration']>=time();
        if ($valid) {
            $user=get_userdata($payload['uid']); $profile=Support::profile($payload['uid']);
            $valid=$user && (user_can($user,'manage_options') || (user_can($user,'nasr_access') && $profile && (int)$profile['active'] && empty($profile['deleted_at'])));
        }
        if (!$valid) { self::$error=Support::error('نشست برنامه معتبر نیست؛ دوباره وارد شوید.',401,'nasr_mobile_auth'); return 0; }
        self::$session=$payload['session']; self::$uid=$payload['uid'];
        return self::$uid;
    }
    public static function rest_auth($result) {
        if (is_wp_error($result)) { return $result; }
        if (self::$error) { return self::$error; }
        return self::$uid && get_current_user_id()===self::$uid ? true : $result;
    }
    public static function cors($served,$result,$request,$server) {
        $route=$request->get_route();
        if (strpos($route,'/nasr/v1/')!==0) { return $served; }
        $origin=get_http_origin();
        if (in_array($origin,array('capacitor://localhost','https://localhost','http://localhost'),true)) {
            header('Access-Control-Allow-Origin: '.$origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Allow-Credentials: false');
            header('Vary: Origin',false);
        }
        header('Cache-Control: private, no-store, max-age=0');
        return $served;
    }
    public static function routes() {
        foreach (array('info'=>array('GET','info',true),'login'=>array('POST','login',true),'me'=>array('GET','me',false),'role'=>array('POST','role',false),'logout'=>array('POST','logout',false)) as $path=>$def) {
            register_rest_route('nasr/v1','/mobile/'.$path,array('methods'=>$def[0],'permission_callback'=>$def[2] ? '__return_true' : array(__CLASS__,'permission'),'callback'=>function($r)use($def){try { return rest_ensure_response(self::{$def[1]}($r)); } catch (Problem $e) { return Support::error($e->getMessage(),$e->getCode() ?: 400); } catch (\Throwable $e) { return Support::error('ارتباط برنامه با سامانه انجام نشد.',500,'nasr_mobile'); }}));
        }
    }
    public static function permission() { return self::$uid && get_current_user_id()===self::$uid ? true : Support::error('ابتدا وارد برنامه شوید.',401); }
    private static function need($value,$message,$status=400) { if (!$value) { throw new Problem($message,$status); } }
    public static function info($r=null) {
        return array('name'=>Support::settings()['academy_name'],'version'=>NASR_ACADEMY_VERSION,'protocol'=>1,'api'=>rest_url('nasr/v1/'),'storage_mode'=>'online','live_interval'=>3);
    }
    public static function login($r) {
        self::need(is_ssl(),'ارتباط برنامه باید از طریق HTTPS باشد.',400);
        $body=$r->get_json_params(); self::need(is_array($body),'درخواست ورود معتبر نیست.');
        $username=$body['username'] ?? ''; $password=$body['password'] ?? '';
        self::need(is_string($username) && is_string($password) && strlen($username)<=100 && strlen($password)<=4096 && $username!=='' && $password!=='','نام کاربری و رمز را وارد کنید.');
        $username=Support::digits(trim($username));
        $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip_key='nasr_app_ip_'.hash_hmac('sha256',$ip,wp_salt('auth'));
        $name_key='nasr_app_user_'.hash_hmac('sha256',$username,wp_salt('auth'));
        self::need((int)get_transient($ip_key)<20 && (int)get_transient($name_key)<8,'تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه دیگر امتحان کنید.',429);
        $user=wp_authenticate($username,$password);
        if (is_wp_error($user)) {
            set_transient($ip_key,(int)get_transient($ip_key)+1,900);set_transient($name_key,(int)get_transient($name_key)+1,900);
            throw new Problem('نام کاربری یا رمز درست نیست.',401);
        }
        wp_set_current_user($user->ID);
        self::need(count(Support::available_roles())>0,'این حساب به سامانه دسترسی ندارد.',403);
        $expires=time()+7*86400;
        self::$uid=(int)$user->ID; self::$session=\WP_Session_Tokens::get_instance($user->ID)->create($expires);
        $payload=rtrim(strtr(base64_encode(wp_json_encode(array('uid'=>self::$uid,'exp'=>$expires,'session'=>self::$session))),'+/','-_'),'=');
        $signed='nasr1.'.$payload;
        $token=$signed.'.'.hash_hmac('sha256',$signed,wp_salt('auth'));
        delete_transient($name_key);
        $data=self::me(); $data['token']=$token; $data['expires_at']=$expires;
        return $data;
    }
    public static function me($r=null) {
        self::need(self::$uid && get_current_user_id()===self::$uid,'نشست ورود معتبر نیست.',401);
        $roles=Support::available_roles(); self::need(count($roles)>0,'دسترسی این حساب غیرفعال است.',403);
        $u=wp_get_current_user();
        return array('user_id'=>get_current_user_id(),'name'=>$u->display_name,'available_roles'=>$roles,'role'=>Support::selected_role(),'api'=>rest_url('nasr/v1/'),'portal'=>Portal::url(),'version'=>NASR_ACADEMY_VERSION,'protocol'=>1);
    }
    public static function role($r) {
        $body=$r->get_json_params(); $mode=is_array($body) ? ($body['role'] ?? '') : '';
        self::need(Support::set_role_mode($mode),'نقش انتخاب‌شده در دسترس شما نیست.',403);
        return self::me();
    }
    public static function logout($r=null) {
        if (self::$uid && self::$session) { \WP_Session_Tokens::get_instance(self::$uid)->destroy(self::$session); }
        self::$uid=0;self::$session='';return array('message'=>'از حساب خارج شدید.');
    }
    public static function render_shell() {
        $config=array('api'=>rest_url('nasr/v1/'),'assets'=>NASR_ACADEMY_URL.'assets/','portal'=>Portal::url(),'version'=>NASR_ACADEMY_VERSION);
        ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#edf4f5"><meta name="robots" content="noindex,nofollow"><title>برنامه آموزشگاه نصر</title><link rel="stylesheet" href="<?php echo esc_url(NASR_ACADEMY_URL.'assets/portal.css?ver='.NASR_ACADEMY_VERSION); ?>"><link rel="stylesheet" href="<?php echo esc_url(NASR_ACADEMY_URL.'assets/mobile.css?ver='.NASR_ACADEMY_VERSION); ?>"></head><body class="nasr-portal"><div id="nasr-app"></div><script>window.NasrAppConfig=<?php echo wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script><script src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/online.js?ver='.NASR_ACADEMY_VERSION); ?>"></script><script src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/mobile-app.js?ver='.NASR_ACADEMY_VERSION); ?>"></script></body></html><?php
        exit;
    }
}
