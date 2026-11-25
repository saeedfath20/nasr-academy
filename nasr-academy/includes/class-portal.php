<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Portal {
    public static function boot() {
        add_shortcode('nasr_academy',array(__CLASS__,'shortcode'));
        add_action('template_redirect',array(__CLASS__,'render_page'),1);
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_init',array(__CLASS__,'restrict_admin'));
        add_action('admin_post_nopriv_nasr_login',array(__CLASS__,'login'));
        add_action('admin_post_nasr_login',array(__CLASS__,'login'));
        add_action('admin_post_nasr_choose_role',array(__CLASS__,'choose_role'));
        add_filter('wp_authenticate_user',array(__CLASS__,'check_active'),20,2);
        add_filter('login_redirect',function ($redirect,$requested,$user) {
            if ($user instanceof \WP_User && !user_can($user,'manage_options') && user_can($user,'nasr_access')) { return self::url(); }
            return $redirect;
        },10,3);
    }
    public static function url() {
        $id=(int)get_option('nasr_academy_page_id');
        return $id && get_post_status($id)==='publish' ? get_permalink($id) : home_url('/nasr-panel/');
    }
    public static function menu() {
        $hook=add_menu_page('سامانه آموزشگاه نصر','آموزشگاه نصر','nasr_manage','nasr-academy',function () {
            echo '<div class="wrap"><h1>سامانه آموزشگاه نصر</h1><p><a class="button button-primary" href="'.esc_url(self::url()).'">ورود به پنل آموزشگاه</a></p></div>';
        },'dashicons-welcome-learn-more',25);
    }
    public static function restrict_admin() {
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) { return; }
        if (current_user_can('nasr_access') && !current_user_can('manage_options') && basename($_SERVER['PHP_SELF'] ?? '') !== 'admin-post.php') {
            wp_safe_redirect(self::url()); exit;
        }
    }
    public static function check_active($user,$password) {
        if (is_wp_error($user) || !($user instanceof \WP_User) || user_can($user,'manage_options')) { return $user; }
        $p=Support::profile($user->ID);
        if ($p && (!(int)$p['active'] || !empty($p['deleted_at']))) { return new \WP_Error('nasr_disabled','این حساب غیرفعال یا حذف شده است. با آموزشگاه تماس بگیرید.'); }
        return $user;
    }
    private static function redirect_error($code) {
        wp_safe_redirect(add_query_arg('nasr_error',$code,self::url())); exit;
    }
    private static function role_choice_requested() {
        if (!is_user_logged_in() || count(Support::available_roles()) < 2) { return false; }
        $raw=$_GET['nasr_choose_role'] ?? '';
        $forced=is_scalar($raw) && sanitize_key(wp_unslash((string)$raw))==='1';
        return $forced || Support::needs_role_choice();
    }
    private static function redirect_role_error($code) {
        wp_safe_redirect(add_query_arg(array('nasr_choose_role'=>'1','nasr_role_error'=>sanitize_key($code)),self::url())); exit;
    }
    public static function choose_role() {
        if (!is_user_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { self::redirect_role_error('invalid'); }
        $raw_nonce=$_POST['_nasr_nonce'] ?? '';
        $nonce=is_scalar($raw_nonce) ? sanitize_text_field(wp_unslash((string)$raw_nonce)) : '';
        if (!wp_verify_nonce($nonce,'nasr_choose_role')) { self::redirect_role_error('expired'); }
        $raw_mode=$_POST['role_mode'] ?? '';
        $mode=is_scalar($raw_mode) ? sanitize_key(wp_unslash((string)$raw_mode)) : '';
        if (!Support::set_role_mode($mode)) { self::redirect_role_error('session'); }
        wp_safe_redirect(self::url()); exit;
    }
    public static function login() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { self::redirect_error('invalid'); }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_nasr_nonce'] ?? '')),'nasr_login')) { self::redirect_error('expired'); }
        $origin=get_http_origin();
        if ($origin) {
            $parts=wp_parse_url(home_url());
            $expected=$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            if (strtolower(rtrim($origin,'/')) !== strtolower($expected)) { self::redirect_error('invalid'); }
        }
        $key='nasr_login_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown',wp_salt('auth'));
        $tries=(int)get_transient($key);
        if ($tries>=8) { self::redirect_error('locked'); }
        $username=wp_unslash($_POST['username'] ?? '');
        $password=wp_unslash($_POST['password'] ?? '');
        if (!is_string($username) || !is_string($password) || strlen($username)>100 || strlen($password)>4096) { self::redirect_error('invalid'); }
        $username=Support::digits(trim($username));
        // برای رمز اولیهٔ کد ملی، رقم‌های فارسی/عربی نیز مثل رقم‌های لاتین
        // پذیرفته می‌شوند؛ رمزهای تغییر یافته ابتدا بدون دست‌کاری آزموده می‌شوند.
        $passwords=array($password);
        if (preg_match('/^[۰-۹٠-٩]+$/u',$password)) {
            $normalized=Support::digits($password);
            if ($normalized !== $password) { $passwords[]=$normalized; }
        }
        $u=null;
        foreach ($passwords as $candidate) {
            $u=wp_signon(array('user_login'=>$username,'user_password'=>$candidate,'remember'=>!empty($_POST['remember'])),is_ssl());
            if (!is_wp_error($u)) { break; }
        }
        if (is_wp_error($u)) { set_transient($key,$tries+1,15*MINUTE_IN_SECONDS); self::redirect_error('invalid'); }
        wp_set_current_user($u->ID);
        if (!Support::role()) { wp_logout(); self::redirect_error('denied'); }
        delete_transient($key);
        wp_safe_redirect(self::url()); exit;
    }
    public static function render_page() {
        if (!is_singular('page')) { return; }
        $post=get_queried_object();
        if (!$post || !has_shortcode($post->post_content,'nasr_academy') || post_password_required($post)) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        if (isset($_GET['nasr_app']) && is_scalar($_GET['nasr_app']) && (string)$_GET['nasr_app']==='1') { Mobile::render_shell(); }
        // This dedicated panel owns its document; theme/shop footer widgets do not belong here.
        $application=Support::role() && !self::role_choice_requested();
        $config=array('assets'=>NASR_ACADEMY_URL.'assets/','api'=>esc_url_raw(rest_url('nasr/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'logout'=>wp_logout_url(self::url()),'portal'=>self::url(),'roleChoice'=>esc_url_raw(add_query_arg('nasr_choose_role','1',self::url())),'isWPAdmin'=>current_user_can('manage_options'));
        ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html(Support::settings()['academy_name']); ?> | سامانه آموزش</title><link rel="stylesheet" href="<?php echo esc_url(NASR_ACADEMY_URL.'assets/portal.css?ver='.NASR_ACADEMY_VERSION); ?>"><style><?php echo Preferences::css(); ?></style></head><body class="nasr-portal"><?php echo self::markup(); ?><?php if ($application) { ?><script>window.NasrConfig=<?php echo wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script><script src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/portal-features.js?ver='.NASR_ACADEMY_VERSION); ?>"></script><script src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/portal.js?ver='.NASR_ACADEMY_VERSION); ?>"></script><?php } elseif (!Support::role()) { ?><script src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/auth.js?ver='.NASR_ACADEMY_VERSION); ?>"></script><?php } ?></body></html><?php
        exit;
    }
    private static function assets() {
        wp_enqueue_style('nasr-academy',NASR_ACADEMY_URL.'assets/portal.css',array(),NASR_ACADEMY_VERSION);
        wp_add_inline_style('nasr-academy',Preferences::css());
        if (Support::role() && !self::role_choice_requested()) {
            wp_enqueue_script('nasr-academy-features',NASR_ACADEMY_URL.'assets/portal-features.js',array(),NASR_ACADEMY_VERSION,true);
            wp_enqueue_script('nasr-academy',NASR_ACADEMY_URL.'assets/portal.js',array('nasr-academy-features'),NASR_ACADEMY_VERSION,true);
            wp_localize_script('nasr-academy','NasrConfig',array('assets'=>NASR_ACADEMY_URL.'assets/','api'=>esc_url_raw(rest_url('nasr/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'logout'=>wp_logout_url(self::url()),'portal'=>self::url(),'roleChoice'=>esc_url_raw(add_query_arg('nasr_choose_role','1',self::url())),'isWPAdmin'=>current_user_can('manage_options')));
        } elseif (!Support::role()) {
            wp_enqueue_script('nasr-academy-auth',NASR_ACADEMY_URL.'assets/auth.js',array(),NASR_ACADEMY_VERSION,true);
        }
    }
    public static function shortcode() { self::assets(); return self::markup(); }
    private static function role_choice_markup() {
        $s=Support::settings();$u=wp_get_current_user();$selected=Support::selected_role();
        $labels=array('teacher'=>'معلم','student'=>'زبان‌آموز','secretary'=>'منشی','family'=>'خانواده');
        $symbols=array('teacher'=>'م','student'=>'ز','secretary'=>'ن','family'=>'خ');
        $errors=array('invalid'=>'درخواست انتخاب نقش معتبر نیست.','expired'=>'مهلت انتخاب نقش تمام شد؛ دوباره انتخاب کنید.','session'=>'ذخیرهٔ نقش در نشست ورود انجام نشد؛ یک‌بار خارج شوید و دوباره وارد شوید.');
        $raw_error=$_GET['nasr_role_error'] ?? '';
        $error=is_scalar($raw_error) ? sanitize_key(wp_unslash((string)$raw_error)) : '';
        $nonce=wp_create_nonce('nasr_choose_role');
        ob_start();
        ?><main id="nasr-app" class="nasr-role-choice-page" dir="rtl"><div class="nasr-role-choice-backdrop"><section class="nasr-role-choice-dialog" role="dialog" aria-modal="true" aria-labelledby="nasr-role-choice-title"><div class="nasr-role-choice-brand"><img class="nasr-logo" src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/nasr-logo.png'); ?>" alt=""><div><strong><?php echo esc_html($s['academy_name']); ?></strong><small>NASR ACADEMY</small></div></div><span class="nasr-eyebrow"><?php echo esc_html($u->display_name); ?></span><h1 id="nasr-role-choice-title">با کدام نقش وارد می‌شوید؟</h1><?php if (isset($errors[$error])) { ?><div class="nasr-alert error" role="alert"><?php echo esc_html($errors[$error]); ?></div><?php } ?><div class="nasr-role-choice-grid"><?php foreach (Support::available_roles() as $mode) { if (!isset($labels[$mode])) continue; ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="nasr-role-choice-form"><input type="hidden" name="action" value="nasr_choose_role"><input type="hidden" name="role_mode" value="<?php echo esc_attr($mode); ?>"><input type="hidden" name="_nasr_nonce" value="<?php echo esc_attr($nonce); ?>"><button type="submit" class="nasr-role-choice-card <?php echo esc_attr($mode); ?>"><span class="nasr-role-choice-icon" aria-hidden="true"><?php echo esc_html($symbols[$mode]); ?></span><span class="nasr-role-choice-copy"><strong>ورود به‌عنوان <?php echo esc_html($labels[$mode]); ?></strong></span><?php if ($selected===$mode) { ?><span class="nasr-role-current">نقش فعلی</span><?php } ?><span class="nasr-role-choice-enter">ورود به پنل <?php echo esc_html($labels[$mode]); ?> ←</span></button></form><?php } ?></div><a class="nasr-role-choice-logout" href="<?php echo esc_url(wp_logout_url(self::url())); ?>">خروج از حساب</a></section></div></main><?php
        return ob_get_clean();
    }
    private static function markup() {
        if (self::role_choice_requested()) { return self::role_choice_markup(); }
        if (Support::role()) { return '<div id="nasr-app" dir="rtl"><div class="nasr-loading" role="status"><img class="nasr-logo" src="'.esc_url(NASR_ACADEMY_URL.'assets/nasr-logo.png').'" alt=""><p>در حال آماده‌سازی پنل شما…</p></div></div><noscript>برای استفاده از سامانه، جاوااسکریپت مرورگر را فعال کنید.</noscript>'; }
        ob_start(); $s=Support::settings();
        $errors=array('invalid'=>'نام کاربری یا رمز عبور درست نیست.','expired'=>'مهلت فرم تمام شد. صفحه را تازه کنید و دوباره وارد شوید.','locked'=>'تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه دیگر امتحان کنید.','denied'=>'این حساب به سامانه دسترسی ندارد. با آموزشگاه تماس بگیرید.');
        $error=sanitize_key($_GET['nasr_error'] ?? '');
        ?><main id="nasr-app" class="nasr-auth" dir="rtl"><section class="nasr-auth-story"><div class="nasr-brand"><img class="nasr-logo" src="<?php echo esc_url(NASR_ACADEMY_URL.'assets/nasr-logo.png'); ?>" alt=""><div><strong><?php echo esc_html($s['academy_name']); ?></strong><small>NASR LANGUAGE ACADEMY</small></div></div><div class="nasr-auth-copy"><span class="nasr-eyebrow">هر روز، یک قدم جلوتر</span><h1>دنیای زبان،<br>از اینجا شروع می‌شود.</h1><div class="nasr-auth-art" aria-hidden="true"><div class="nasr-orbit"></div><div class="nasr-book">Aa<span>LEARN · GROW · CONNECT</span></div><span class="nasr-word word-one">Bonjour!</span><span class="nasr-word word-two">Hallo!</span><span class="nasr-word word-three">Hello!</span></div></div><footer>یادگیری امروز، فرصت‌های فردا</footer></section><section class="nasr-auth-form"><div class="nasr-login-card"><span class="nasr-eyebrow">خوش آمدید</span><h2>ورود به سامانه نصر</h2><?php if (isset($errors[$error])) { ?><div class="nasr-alert error" role="alert"><?php echo esc_html($errors[$error]); ?></div><?php } ?><?php if (is_user_logged_in()) { ?><div class="nasr-alert">حساب فعلی دسترسی فعال ندارد. <a href="<?php echo esc_url(wp_logout_url(self::url())); ?>">خروج از حساب</a></div><?php } ?><form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="nasr_login"><?php wp_nonce_field('nasr_login','_nasr_nonce'); ?><label>نام کاربری (کد ملی)<input name="username" type="text" dir="ltr" autocomplete="username" required maxlength="100" placeholder="کد ملی ۱۰ رقمی" inputmode="numeric"></label><label>رمز عبور<input name="password" type="password" dir="ltr" autocomplete="current-password" required maxlength="4096" placeholder="رمز عبور" autocapitalize="off" spellcheck="false"></label><div class="nasr-login-options"><label class="nasr-check"><input type="checkbox" name="remember" value="1">مرا به خاطر بسپار</label><a href="<?php echo esc_url(add_query_arg('nasr_recovery','form',self::url())); ?>" data-recovery-open aria-haspopup="dialog" aria-controls="nasr-recovery-dialog">فراموشی رمز عبور</a></div><button type="submit" class="nasr-button primary">ورود به پنل <span aria-hidden="true">←</span></button></form><div class="nasr-role-labels"><span>مدیریت</span><span>منشی</span><span>معلم</span><span>زبان‌آموز</span><span>خانواده</span></div></div><small class="nasr-auth-bottom"><?php echo esc_html($s['academy_name']); ?> · همراه مسیر یادگیری شما</small></section><?php echo Password_Recovery::markup(); ?></main><?php
        return ob_get_clean();
    }
}
