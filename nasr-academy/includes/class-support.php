<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Support {
    public static function branches() {
        global $wpdb;
        return $wpdb->get_results('SELECT id,name,active FROM '.self::table('branches').' ORDER BY id',ARRAY_A);
    }
    public static function payroll($rate, $minutes, $late_minutes) {
        if ($rate === null) { return array('gross'=>null,'deduction'=>null,'net'=>null); }
        $gross=(int)round((int)$rate * (int)$minutes / 60);
        $net=(int)round((int)$rate * max(0,(int)$minutes-(int)$late_minutes) / 60);
        return array('gross'=>$gross,'deduction'=>$gross-$net,'net'=>$net);
    }
    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'nasr_' . $name;
    }
    public static function settings() {
        $defaults = array(
            'academy_name' => 'آموزشگاه زبان نصر', 'currency' => 'تومان',
            'calendar' => 'jalali', 'mid_max' => 20, 'final_max' => 40,
            'discount_scope' => 'base', 'support_phone' => '',
            'education_options' => array('دیپلم','کاردانی','کارشناسی','کارشناسی ارشد','دکتری','سایر'),
        );
        $saved = get_option('nasr_academy_settings', array());
        $settings = wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
        $raw = $settings['education_options'];
        if (is_string($raw)) { $raw = preg_split('/\R/u', $raw); }
        if (!is_array($raw)) { $raw = $defaults['education_options']; }
        $options = array();
        foreach ($raw as $option) {
            if (!is_scalar($option)) { continue; }
            $option = trim(sanitize_text_field((string)$option));
            if ($option !== '') { $options[] = $option; }
        }
        $settings['education_options'] = array_values(array_unique($options));
        if (!$settings['education_options']) { $settings['education_options'] = $defaults['education_options']; }
        return $settings;
    }
    public static function error($message, $status = 400, $code = 'nasr_invalid') {
        return new \WP_Error($code, $message, array('status' => $status));
    }
    public static function digits($value) {
        if ($value !== null && !is_scalar($value)) { return ''; }
        $value = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', (string)$value);
        return strtr((string) $value, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.'));
    }
    public static function password_valid($value) {
        return is_string($value) && self::length($value)>=10 && self::length($value)<=200
            && trim($value)===$value && !preg_match('/[\x00-\x1f\x7f]/',$value);
    }
    public static function length($value) {
        if ($value !== null && !is_scalar($value)) { return PHP_INT_MAX; }
        $value = (string)($value ?? '');
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value, $matches);
    }
    public static function phone($value, $mobile = false) {
        $n = preg_replace('/[\s()\-]/u', '', self::digits($value));
        if ($n === '') { return ''; }
        if (substr_count($n, '+') > 0 && strpos($n, '+') !== 0) { return self::error('شماره تماس معتبر نیست.'); }
        if (strpos($n, '+98') === 0) { $n = '0' . substr($n, 3); }
        elseif (strpos($n, '0098') === 0) { $n = '0' . substr($n, 4); }
        elseif (preg_match('/^98[0-9]{10}$/', $n)) { $n = '0' . substr($n, 2); }
        elseif (preg_match('/^9[0-9]{9}$/', $n)) { $n = '0' . $n; }
        if ($mobile && !preg_match('/^09[0-9]{9}$/', $n)) {
            return self::error('شماره همراه معتبر نیست؛ نمونه: 09123456789 یا 9123456789 یا +989123456789.');
        }
        if (!$mobile && !preg_match('/^\+?[0-9]{5,18}$/', $n)) { return self::error('شماره تماس معتبر نیست.'); }
        return $n;
    }
    public static function number($value, $min, $max, $decimals = 0, $nullable = false) {
        $v = trim(self::digits($value));
        if ($nullable && ($value === null || $v === '')) { return null; }
        $pattern = $decimals ? '/^\d+(?:\.\d{1,' . $decimals . '})?$/' : '/^\d+$/';
        if (!preg_match($pattern, $v) || (float)$v < $min || (float)$v > $max) {
            return self::error('مقدار باید بین ' . $min . ' و ' . $max . ' و حداکثر با ' . $decimals . ' رقم اعشار باشد.');
        }
        return $decimals ? (float)$v : (int)$v;
    }
    public static function price($base, $extras, $discount, $scope) {
        // Round once to the configured currency unit. Discounts never change a payment record.
        return max(0, (int)round($base + $extras - ($scope === 'total' ? $base + $extras : $base) * $discount / 100));
    }
    public static function jalali_to_gregorian($jy, $jm, $jd) {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd;
        $days += ($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186;
        $gy = 400 * intdiv($days, 146097); $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524); $days %= 36524;
            if ($days >= 365) { $days++; }
        }
        $gy += 4 * intdiv($days, 1461); $days %= 1461;
        if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $months = array(0,31,($gy%4 === 0 && $gy%100 !== 0) || $gy%400 === 0 ? 29 : 28,31,30,31,30,31,31,30,31,30,31);
        for ($gm = 1; $gm <= 12 && $gd > $months[$gm]; $gm++) { $gd -= $months[$gm]; }
        return array($gy,$gm,$gd);
    }
    public static function gregorian_to_jalali($gy, $gm, $gd) {
        $sum = array(0,31,59,90,120,151,181,212,243,273,304,334);
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365*$gy + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400) + $gd + $sum[$gm-1];
        $jy = -1595 + 33*intdiv($days,12053); $days %= 12053;
        $jy += 4*intdiv($days,1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days-1,365); $days = ($days-1)%365; }
        if ($days < 186) { $jm = 1+intdiv($days,31); $jd = 1+($days%31); }
        else { $jm = 7+intdiv($days-186,30); $jd = 1+(($days-186)%30); }
        return array($jy,$jm,$jd);
    }
    public static function date($value, $optional = false) {
        $value = trim(self::digits($value));
        if ($value === '' && $optional) { return null; }
        if (!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $m)) {
            return self::error('تاریخ را به شکل سال/ماه/روز وارد کنید.');
        }
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
        if (self::settings()['calendar'] === 'jalali') {
            if ($y < 1200 || $y > 1499 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) { return self::error('تاریخ شمسی معتبر نیست؛ سال باید بین ۱۲۰۰ و ۱۴۹۹ باشد.'); }
            $g = self::jalali_to_gregorian($y,$mo,$d);
            if (self::gregorian_to_jalali($g[0],$g[1],$g[2]) !== array($y,$mo,$d)) { return self::error('روز یا ماه تاریخ شمسی معتبر نیست.'); }
            return sprintf('%04d-%02d-%02d', $g[0],$g[1],$g[2]);
        }
        if ($y < 1800 || $y > 2200 || !checkdate($mo,$d,$y)) { return self::error('تاریخ میلادی معتبر نیست.'); }
        return sprintf('%04d-%02d-%02d',$y,$mo,$d);
    }
    public static function manager() {
        if (current_user_can('manage_options')) { return true; }
        if (!current_user_can('nasr_manage')) { return false; }
        $p = self::profile();
        return $p && empty($p['deleted_at']) && $p['kind'] === 'manager' && (int)$p['active'] === 1;
    }
    public static function profile($user_id = 0) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('people') . ' WHERE user_id=%d', $user_id ?: get_current_user_id()), ARRAY_A);
    }
    public static function available_roles() {
        if (self::manager()) { return array('manager'); }
        $p = self::profile();
        if (!$p || !empty($p['deleted_at']) || !(int)$p['active'] || !current_user_can('nasr_access')) { return array(); }
        if (!in_array($p['kind'],array('teacher','student','family','secretary'),true)) { return array(); }
        $roles = $p['kind']==='secretary' ? array() : array($p['kind']);
        // A teacher keeps one WordPress account and one people record.  A
        // student code marks that same person as eligible for learner mode.
        if ($p['kind'] === 'teacher' && !empty($p['student_code'])) { $roles[] = 'student'; }
        if (!empty($p['secretary_access'])) { $roles[] = 'secretary'; }
        return array_values(array_unique($roles));
    }
    public static function staff() {
        return self::manager() || self::selected_role()==='secretary';
    }
    private static function stored_role_mode($roles) {
        if (count($roles) < 2 || !is_user_logged_in()) { return ''; }
        $token = self::session_token();
        if ($token === '' || !class_exists('\\WP_Session_Tokens')) { return ''; }
        $session = \WP_Session_Tokens::get_instance(get_current_user_id())->get($token);
        $raw = is_array($session) ? ($session['nasr_role_mode'] ?? '') : '';
        $mode = is_scalar($raw) ? sanitize_key((string)$raw) : '';
        return in_array($mode,$roles,true) ? $mode : '';
    }
    public static function selected_role() {
        $roles = self::available_roles();
        if (!$roles) { return ''; }
        if (count($roles) === 1) { return $roles[0]; }
        return self::stored_role_mode($roles);
    }
    public static function needs_role_choice() {
        $roles = self::available_roles();
        return count($roles) > 1 && self::stored_role_mode($roles) === '';
    }
    public static function set_role_mode($mode) {
        if (!is_scalar($mode)) { return false; }
        $mode = sanitize_key((string)$mode);
        $roles = self::available_roles();
        if (count($roles) < 2 || !in_array($mode,$roles,true)) { return false; }
        $token = self::session_token();
        if ($token === '' || !class_exists('\\WP_Session_Tokens')) { return false; }
        $manager = \WP_Session_Tokens::get_instance(get_current_user_id());
        $session = $manager->get($token);
        if (!is_array($session)) { return false; }
        $session['nasr_role_mode'] = $mode;
        $manager->update($token,$session);
        $saved = $manager->get($token);
        return is_array($saved) && isset($saved['nasr_role_mode']) && is_scalar($saved['nasr_role_mode']) && hash_equals($mode,(string)$saved['nasr_role_mode']);
    }
    public static function role() {
        $roles = self::available_roles();
        if (!$roles) { return ''; }
        if (count($roles) === 1) { return $roles[0]; }
        // Before the required chooser is submitted, retain the canonical
        // teacher role only so the portal can render the chooser safely.
        $selected = self::stored_role_mode($roles);
        return $selected ?: $roles[0];
    }
    public static function access() {
        if (is_user_logged_in() && self::needs_role_choice()) { return self::error('ابتدا نقش ورود را انتخاب کنید.',403,'nasr_role_required'); }
        return is_user_logged_in() && self::role() ? true : self::error('برای استفاده از سامانه با حساب مجاز وارد شوید.', is_user_logged_in() ? 403 : 401, 'nasr_access');
    }
    public static function session_token() {
        return Mobile::session_token() ?: wp_get_session_token();
    }
    public static function now() { return gmdate('Y-m-d H:i:s'); }
    public static function audit($action, $entity, $entity_id, $details = array()) {
        global $wpdb;
        $ok = $wpdb->insert(self::table('audit'), array('actor_id'=>get_current_user_id(),'action'=>$action,'entity'=>$entity,'entity_id'=>$entity_id,'details'=>wp_json_encode($details,JSON_UNESCAPED_UNICODE),'created_at'=>self::now()));
        if ($ok === false) { throw new \RuntimeException('Audit write failed'); }
    }
    public static function checked($result) {
        if ($result === false) { throw new \RuntimeException('Database write failed'); }
        return $result;
    }
}
