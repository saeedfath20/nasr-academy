<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/** National-ID reset requested by the academy; an ID is not proof of ownership. */
final class Password_Recovery {
    public static function boot() {
        add_action('admin_post_nopriv_nasr_reset_password',array(__CLASS__,'submit'));
        add_action('admin_post_nasr_reset_password',array(__CLASS__,'submit'));
    }
    private static function input($key) {
        $value=$_POST[$key] ?? '';
        return is_string($value) ? wp_unslash($value) : '';
    }
    private static function result_url($state) {
        return add_query_arg('nasr_recovery',$state,Portal::url());
    }
    public static function submit() {
        $result=self::process();
        wp_safe_redirect(self::result_url($result),303); exit;
    }
    private static function process() {
        global $wpdb;
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { return 'invalid'; }
        if (!wp_verify_nonce(self::input('_nasr_nonce'),'nasr_reset_password')) { return 'expired'; }
        $origin=get_http_origin();
        if ($origin) {
            $parts=wp_parse_url(home_url());
            $expected=$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
            if (strtolower(rtrim($origin,'/'))!==strtolower($expected)) { return 'invalid'; }
        }
        $national=Support::digits(trim(self::input('national_id')));
        if (!preg_match('/^[0-9]{10}$/D',$national)) { return 'national'; }
        // Share the write lock with profile edits so national-ID changes cannot race a reset.
        $lock='nasr_'.md5($wpdb->prefix.DB_NAME);
        $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock))===1;
        if (!$locked) { return 'busy'; }
        try {
            $ip_key='nasr_recovery_ip_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown',wp_salt('auth'));
            $id_key='nasr_recovery_id_'.hash_hmac('sha256',$national,wp_salt('auth'));
            $ip_count=(int)get_transient($ip_key); $id_count=(int)get_transient($id_key);
            if ($ip_count>=8 || $id_count>=3) { return 'locked'; }
            set_transient($ip_key,$ip_count+1,15*MINUTE_IN_SECONDS);
            set_transient($id_key,$id_count+1,15*MINUTE_IN_SECONDS);
            $person=$wpdb->get_row($wpdb->prepare('SELECT user_id,kind,active,deleted_at FROM '.Support::table('people').' WHERE national_id=%s',$national),ARRAY_A);
            if ($wpdb->last_error) { return 'failed'; }
            if (!$person || !(int)$person['active'] || !empty($person['deleted_at'])) { return 'not_found'; }
            $user=get_userdata((int)$person['user_id']);
            if (!$user || !user_can($user,'nasr_access') || user_can($user,'manage_options')) { return 'not_found'; }
            $allowed=apply_filters('allow_password_reset',true,$user->ID);
            if (!$allowed || is_wp_error($allowed)) { return 'unavailable'; }
            // Do not log the national ID or password and do not sign the visitor in.
            Support::audit('password_reset_requested','user',$user->ID,array('method'=>'national_id'));
            reset_password($user,$national);
            clean_user_cache($user->ID);
            $updated=get_userdata($user->ID);
            if (!$updated || !wp_check_password($national,$updated->user_pass,$user->ID)) { return 'failed'; }
            \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            if (get_current_user_id()===(int)$user->ID) { wp_logout(); }
            Support::audit('password_reset_completed','user',$user->ID,array('method'=>'national_id'));
            return 'success';
        } catch (\Throwable $e) {
            return 'failed';
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    public static function markup() {
        $raw=$_GET['nasr_recovery'] ?? '';
        $state=is_string($raw) ? sanitize_key(wp_unslash($raw)) : '';
        $messages=array(
            'success'=>'رمز عبور فعلی شما به شماره ملی شما تبدیل شد',
            'national'=>'کد ملی را به صورت ۱۰ رقمی وارد کنید.',
            'not_found'=>'حساب فعالی با این کد ملی برای بازیابی پیدا نشد.',
            'expired'=>'مهلت فرم تمام شد؛ دوباره تلاش کنید.',
            'invalid'=>'درخواست معتبر نیست؛ دوباره تلاش کنید.',
            'locked'=>'تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه دیگر امتحان کنید.',
            'busy'=>'سامانه مشغول ثبت اطلاعات است؛ چند لحظه بعد دوباره تلاش کنید.',
            'unavailable'=>'بازیابی این حساب مجاز نیست؛ با آموزشگاه تماس بگیرید.',
            'failed'=>'بازیابی رمز انجام نشد؛ دوباره تلاش کنید.'
        );
        $open=$state==='form' || isset($messages[$state]);
        $success=$state==='success';
        ob_start(); ?>
        <dialog id="nasr-recovery-dialog" class="nasr-dialog nasr-recovery-dialog" aria-labelledby="nasr-recovery-title" <?php echo $open ? 'open' : ''; ?>>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <header><h2 id="nasr-recovery-title">بازیابی رمز عبور</h2><a href="<?php echo esc_url(Portal::url()); ?>" class="nasr-icon-button" data-recovery-close aria-label="بستن">×</a></header>
                <div class="nasr-dialog-body">
                    <?php if (isset($messages[$state])) { ?><div class="nasr-alert <?php echo $success ? '' : 'error'; ?>" role="<?php echo $success ? 'status' : 'alert'; ?>"><?php echo esc_html($messages[$state]); ?></div><?php } ?>
                    <?php if (!$success) { ?>
                        <input type="hidden" name="action" value="nasr_reset_password">
                        <?php wp_nonce_field('nasr_reset_password','_nasr_nonce'); ?>
                        <label class="nasr-field"><span>کد ملی</span><input name="national_id" type="text" dir="ltr" inputmode="numeric" autocomplete="username" maxlength="10" pattern="[0-9۰-۹٠-٩]{10}" placeholder="کد ملی ۱۰ رقمی" required></label>
                    <?php } ?>
                </div>
                <footer><?php if (!$success) { ?><button type="submit" class="nasr-button primary">بازیابی رمز عبور</button><?php } ?><a href="<?php echo esc_url(Portal::url()); ?>" class="nasr-button" data-recovery-close>بازگشت به ورود</a></footer>
            </form>
        </dialog>
        <?php return ob_get_clean();
    }
}
