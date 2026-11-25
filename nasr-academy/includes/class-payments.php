<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Payments {
    const OPTION = 'nasr_academy_payment_gateways';

    public static function boot() {
        add_action('admin_post_nasr_payment_callback',array(__CLASS__,'callback'));
        add_action('admin_post_nopriv_nasr_payment_callback',array(__CLASS__,'callback'));
    }

    public static function gateways() {
        $items = apply_filters('nasr_academy_payment_gateways',array());
        $valid = array();
        if (!is_array($items)) { return $valid; }
        foreach ($items as $item) {
            if (!($item instanceof Payment_Gateway)) { continue; }
            $id = sanitize_key($item->id());
            if (!$id || strlen($id) > 25 || !preg_match('/^[a-z][a-z0-9_-]+$/',$id) || isset($valid[$id])) { continue; }
            $valid[$id] = $item;
        }
        return $valid;
    }

    public static function settings($gateway_id = '') {
        $saved = get_option(self::OPTION,array());
        if (!is_array($saved)) { $saved = array(); }
        if ($gateway_id === '') { return $saved; }
        $settings=isset($saved[$gateway_id]) && is_array($saved[$gateway_id]) ? $saved[$gateway_id] : array();
        $gateways=self::gateways();
        return isset($gateways[$gateway_id]) ? self::decrypted_settings($gateways[$gateway_id],$settings) : $settings;
    }

    private static function cipher($value, $encrypt = true) {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) { return ''; }
        $key=hash('sha256',wp_salt('auth').'|nasr-academy-payments',true);
        if ($encrypt) {
            $iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
            return $encrypted===false ? '' : 'enc:'.base64_encode($iv.$tag.$encrypted);
        }
        if (strpos($value,'enc:')!==0) { return $value; }
        $raw=base64_decode(substr($value,4),true);
        if ($raw===false || strlen($raw)<29) { return ''; }
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        return $plain===false ? '' : $plain;
    }

    private static function decrypted_settings($gateway, $settings) {
        foreach (self::field_schema($gateway) as $key=>$field) {
            if ($field['secret'] && !empty($settings[$key])) { $settings[$key]=self::cipher((string)$settings[$key],false); }
        }
        return $settings;
    }

    public static function public_names() {
        $names=array();
        foreach (self::gateways() as $id=>$gateway) {
            $settings=self::settings($id);
            $names[$id]=sanitize_text_field($settings['title'] ?? $gateway->title());
        }
        return $names;
    }

    public static function callback_url($token, $gateway_id) {
        return add_query_arg(array('action'=>'nasr_payment_callback','tx'=>$token,'gateway'=>$gateway_id),admin_url('admin-post.php'));
    }

    public static function static_callback_url($gateway_id) {
        return add_query_arg(array('action'=>'nasr_payment_callback','gateway'=>$gateway_id),admin_url('admin-post.php'));
    }

    private static function fail($message, $status = 400) { throw new Problem($message,$status); }
    private static function need($condition, $message, $status = 400) { if (!$condition) { self::fail($message,$status); } }

    private static function body($r) {
        $body = $r->get_json_params();
        self::need(is_array($body),'اطلاعات ارسالی معتبر نیست.');
        return $body;
    }

    private static function scope($alias = 'e') {
        global $wpdb;
        $role = Support::role();
        if ($role === 'student') { return $wpdb->prepare("$alias.student_id=%d",get_current_user_id()); }
        if ($role === 'family') {
            return $wpdb->prepare("$alias.student_id IN (SELECT l.student_id FROM ".Support::table('family_links')." l JOIN ".Support::table('people')." child ON child.user_id=l.student_id WHERE l.family_id=%d AND child.deleted_at IS NULL)",get_current_user_id());
        }
        return '1=0';
    }

    private static function enrollment($id) {
        global $wpdb;
        self::need(in_array(Support::role(),array('student','family'),true),'پرداخت اینترنتی فقط برای صاحب ثبت‌نام یا خانواده انجام می‌شود.',403);
        $sql = 'SELECT e.*,c.name class_name,c.schedule,c.class_days,c.class_day,c.start_time,c.duration,c.room,c.active class_active,
            t.name term_name,t.code term_code,t.start_date,t.end_date,t.active term_active,b.name branch_name,
            p.first_name,p.last_name,p.mobile,p.student_code,
            CONCAT(tp.first_name,\' \',tp.last_name) teacher_name,
            COALESCE(pay.paid,0) paid
            FROM '.Support::table('enrollments').' e
            JOIN '.Support::table('classes').' c ON c.id=e.class_id
            JOIN '.Support::table('terms').' t ON t.id=c.term_id
            JOIN '.Support::table('people').' p ON p.user_id=e.student_id
            JOIN '.Support::table('people').' tp ON tp.user_id=c.teacher_id
            LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id
            LEFT JOIN (SELECT enrollment_id,SUM(amount) paid FROM '.Support::table('payments')." WHERE status='success' AND voided_at IS NULL GROUP BY enrollment_id) pay ON pay.enrollment_id=e.id
            WHERE e.id=%d AND e.deleted_at IS NULL AND c.deleted_at IS NULL AND t.deleted_at IS NULL AND p.deleted_at IS NULL AND ".self::scope('e');
        $row = $wpdb->get_row($wpdb->prepare($sql,absint($id)),ARRAY_A);
        self::need($row,'این ثبت‌نام برای پرداخت در دسترس شما نیست.',404);
        $row['paid'] = (int)$row['paid'];
        $row['balance'] = max(0,(int)$row['net_fee']-$row['paid']);
        return $row;
    }

    private static function public_context($row) {
        $settings = Support::settings();
        return array(
            'enrollment_id'=>(int)$row['id'],
            'class_id'=>(int)$row['class_id'],
            'class_name'=>$row['class_name'],
            'term_name'=>$row['term_name'],
            'branch_name'=>$row['branch_name'] ?: '',
            'teacher_name'=>$row['teacher_name'],
            'student_name'=>trim($row['first_name'].' '.$row['last_name']),
            'student_code'=>$row['student_code'],
            'mobile'=>$row['mobile'],
            'amount'=>(int)$row['balance'],
            'currency'=>$settings['currency'],
            'description'=>'پرداخت شهریه '.$row['class_name'].' - '.$row['term_name'],
        );
    }

    private static function gateway_public($id, $gateway, $settings, $context, $check_availability = true) {
        if (empty($settings['enabled']) || !$gateway->configured($settings)) { return null; }
        $available = $check_availability ? $gateway->availability($context,$settings) : true;
        if (is_wp_error($available)) { return $available; }
        if ($available === false) { return null; }
        $presentation = is_array($available) ? $available : array();
        return array(
            'id'=>$id,
            'title'=>sanitize_text_field($presentation['title'] ?? ($settings['title'] ?? $gateway->title())),
            'description'=>sanitize_text_field($presentation['description'] ?? ($settings['description'] ?? $gateway->description())),
        );
    }

    public static function checkout($r) {
        $row = self::enrollment($r['id']);
        self::need($row['balance'] > 0,'شهریه این کلاس تسویه شده است.',409);
        $context = self::public_context($row);
        $available = array(); $unavailable = array();
        foreach (self::gateways() as $id=>$gateway) {
            $item = self::gateway_public($id,$gateway,self::settings($id),$context,true);
            if (is_wp_error($item)) { $unavailable[] = array('id'=>$id,'title'=>$gateway->title(),'message'=>$item->get_error_message()); }
            elseif ($item) { $available[] = $item; }
        }
        return array(
            'enrollment'=>array('id'=>(int)$row['id'],'student_name'=>$context['student_name'],'student_code'=>$row['student_code']),
            'class'=>array('id'=>(int)$row['class_id'],'name'=>$row['class_name'],'term_name'=>$row['term_name'],'branch_name'=>$row['branch_name'],'teacher_name'=>$row['teacher_name'],'schedule'=>$row['schedule'],'room'=>$row['room']),
            'amount'=>$row['balance'],'currency'=>$context['currency'],'gateways'=>$available,'unavailable'=>$unavailable,
        );
    }

    private static function lock_name($suffix) {
        global $wpdb;
        return 'nasr_pay_'.substr(md5($wpdb->prefix.DB_NAME.$suffix),0,40);
    }

    private static function acquire($name, $seconds = 5) {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)',$name,$seconds)) === 1;
    }

    private static function release($name) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
    }

    private static function json($data) {
        return wp_json_encode($data,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function safe_provider_result($result) {
        if (!is_array($result)) { return array(); }
        $safe = array();
        foreach (array('request','response') as $key) {
            if (isset($result[$key]) && is_array($result[$key])) { $safe[$key]=$result[$key]; }
        }
        return $safe;
    }

    public static function start($r) {
        global $wpdb;
        $body = self::body($r);
        $gateway_id = sanitize_key(is_scalar($body['gateway'] ?? '') ? (string)$body['gateway'] : '');
        $gateways = self::gateways();
        self::need(isset($gateways[$gateway_id]),'درگاه انتخاب‌شده نصب یا فعال نیست.',404);
        $gateway = $gateways[$gateway_id]; $settings = self::settings($gateway_id);
        self::need(!empty($settings['enabled']) && $gateway->configured($settings),'تنظیمات این درگاه کامل یا فعال نیست.',409);

        $lock = self::lock_name('start:'.absint($r['id']));
        self::need(self::acquire($lock),'درخواست پرداخت دیگری در حال ثبت است. چند لحظه بعد دوباره تلاش کنید.',409);
        $tx = null; $row = null; $context = null;
        try {
            Support::checked($wpdb->query('START TRANSACTION'));
            $row = self::enrollment($r['id']);
            self::need($row['balance'] > 0,'شهریه این کلاس تسویه شده است.',409);
            $open = $wpdb->get_row($wpdb->prepare('SELECT id,gateway,status,redirect_url,created_at FROM '.Support::table('payment_transactions')." WHERE enrollment_id=%d AND status IN ('created','redirected') AND created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1",$row['id']),ARRAY_A);
            if ($open && $open['status']==='redirected' && $open['gateway']===$gateway_id && wp_http_validate_url($open['redirect_url'])) {
                Support::checked($wpdb->query('COMMIT'));
                return array('redirect_url'=>$open['redirect_url'],'transaction_id'=>(int)$open['id'],'resumed'=>true);
            }
            self::need(!$open,'یک پرداخت باز برای این شهریه وجود دارد. اگر انتقال کامل نشد، ۳۰ دقیقه بعد دوباره تلاش کنید.',409);
            $token = bin2hex(random_bytes(32));
            $context = self::public_context($row);
            $data = array(
                'token'=>$token,'request_key'=>$token,'enrollment_id'=>$row['id'],'student_id'=>$row['student_id'],'gateway'=>$gateway_id,
                'amount'=>$row['balance'],'currency'=>$context['currency'],'status'=>'created','provider_order_id'=>'N'.strtoupper(substr($token,0,20)),
                'provider_token'=>'','reference'=>'','redirect_url'=>'','request_json'=>'','response_json'=>'','callback_json'=>'','error_message'=>'',
                'payment_id'=>0,'created_by'=>get_current_user_id(),'created_at'=>Support::now(),'updated_at'=>Support::now(),
            );
            Support::checked($wpdb->insert(Support::table('payment_transactions'),$data));
            $data['id']=(int)$wpdb->insert_id; $tx=$data;
            Support::checked($wpdb->query('COMMIT'));
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        } finally { self::release($lock); }

        $context['callback_url'] = self::callback_url($tx['token'],$gateway_id);
        $context['static_callback_url'] = self::static_callback_url($gateway_id);
        $context['transaction_id'] = $tx['provider_order_id'];
        $availability = $gateway->availability($context,$settings);
        if (is_wp_error($availability) || $availability === false) {
            $message = is_wp_error($availability) ? $availability->get_error_message() : 'این روش پرداخت برای مبلغ فعلی در دسترس نیست.';
            $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>sanitize_text_field($message),'updated_at'=>Support::now()),array('id'=>$tx['id']));
            self::fail($message,409);
        }
        try { $result = $gateway->request($tx,$context,$settings); }
        catch (\Throwable $e) {
            $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>'خطای داخلی افزونهٔ درگاه','updated_at'=>Support::now()),array('id'=>$tx['id']));
            self::fail('افزونهٔ درگاه نتوانست درخواست پرداخت را ایجاد کند.',502);
        }
        if (is_wp_error($result)) {
            $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>sanitize_text_field($result->get_error_message()),'updated_at'=>Support::now()),array('id'=>$tx['id']));
            self::fail($result->get_error_message(),502);
        }
        if (!is_array($result)) {
            $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>'پاسخ نامعتبر افزونهٔ درگاه','updated_at'=>Support::now()),array('id'=>$tx['id']));
            self::fail('پاسخ درگاه برای شروع پرداخت معتبر نیست.',502);
        }
        $redirect = esc_url_raw($result['redirect_url'] ?? '');
        if (!$redirect || !wp_http_validate_url($redirect) || strtolower((string)wp_parse_url($redirect,PHP_URL_SCHEME))!=='https') {
            $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>'آدرس انتقال نامعتبر','updated_at'=>Support::now()),array('id'=>$tx['id']));
            self::fail('آدرس انتقال به درگاه معتبر نیست.',502);
        }
        $provider_token = sanitize_text_field((string)($result['provider_token'] ?? ''));
        if ($provider_token==='') {
            $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>'شناسهٔ پرداخت دریافت نشد','updated_at'=>Support::now()),array('id'=>$tx['id']));
            self::fail('شناسهٔ پرداخت از درگاه دریافت نشد.',502);
        }
        $safe = self::safe_provider_result($result);
        $updated = array(
            'status'=>'redirected','provider_token'=>$provider_token,'redirect_url'=>$redirect,
            'provider_order_id'=>sanitize_text_field((string)($result['provider_order_id'] ?? $tx['provider_order_id'])),
            'request_json'=>self::json($safe['request'] ?? array()),'response_json'=>self::json($safe['response'] ?? array()),'updated_at'=>Support::now(),
        );
        Support::checked($wpdb->update(Support::table('payment_transactions'),$updated,array('id'=>$tx['id'])));
        try { Support::audit('online_payment_started','payment_transaction',$tx['id'],array('gateway'=>$gateway_id,'amount'=>$tx['amount'],'enrollment_id'=>$tx['enrollment_id'])); } catch (\Throwable $ignored) {}
        return array('redirect_url'=>$redirect,'transaction_id'=>$tx['id']);
    }

    private static function field_schema($gateway) {
        $out = array();
        foreach ($gateway->fields() as $field) {
            if (!is_array($field)) { continue; }
            $key=sanitize_key($field['key'] ?? '');
            if (!$key || isset($out[$key])) { continue; }
            $type=in_array($field['type'] ?? 'text',array('text','password','url','number','checkbox','select'),true)?$field['type']:'text';
            $item=array('key'=>$key,'label'=>sanitize_text_field($field['label'] ?? $key),'type'=>$type,'required'=>!empty($field['required']),'secret'=>!empty($field['secret']));
            if (isset($field['default']) && is_scalar($field['default'])) { $item['default']=(string)$field['default']; }
            if (!empty($field['help'])) { $item['help']=sanitize_text_field($field['help']); }
            if ($type==='select' && is_array($field['options'] ?? null)) {
                $item['options']=array(); foreach ($field['options'] as $value=>$label) { $item['options'][(string)$value]=sanitize_text_field($label); }
            }
            $out[$key]=$item;
        }
        return $out;
    }

    public static function admin_settings() {
        global $wpdb;
        self::need(Support::staff(),'این بخش فقط در دسترس مدیر و منشی است.',403);
        $items=array();
        foreach (self::gateways() as $id=>$gateway) {
            $settings=self::settings($id);$fields=self::field_schema($gateway);$values=array();$secrets=array();
            foreach ($fields as $key=>$field) {
                if ($field['secret']) { $values[$key]='';$secrets[$key]=!empty($settings[$key]); }
                else { $values[$key]=$settings[$key] ?? ($field['default'] ?? ''); }
            }
            $items[]=array('id'=>$id,'name'=>$gateway->title(),'description'=>$gateway->description(),'enabled'=>!empty($settings['enabled']),
                'title'=>$settings['title'] ?? $gateway->title(),'checkout_description'=>$settings['description'] ?? $gateway->description(),
                'configured'=>$gateway->configured($settings),'fields'=>array_values($fields),'values'=>$values,'saved_secrets'=>$secrets,
                'callback_url'=>self::static_callback_url($id));
        }
        $transactions=$wpdb->get_results('SELECT tx.id,tx.gateway,tx.amount,tx.currency,tx.status,tx.reference,tx.error_message,tx.created_at,tx.paid_at,c.name class_name,t.name term_name,CONCAT(p.first_name,\' \',p.last_name) student_name FROM '.Support::table('payment_transactions').' tx JOIN '.Support::table('enrollments').' e ON e.id=tx.enrollment_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id JOIN '.Support::table('people').' p ON p.user_id=tx.student_id ORDER BY tx.id DESC LIMIT 50',ARRAY_A);
        return array('gateways'=>$items,'transactions'=>$transactions ?: array());
    }

    private static function scalar($value) { return is_scalar($value) ? trim((string)$value) : ''; }

    public static function save_admin_settings($r) {
        self::need(Support::staff(),'این بخش فقط در دسترس مدیر و منشی است.',403);
        $body=self::body($r);self::need(isset($body['gateways']) && is_array($body['gateways']),'تنظیمات درگاه‌ها معتبر نیست.');
        $all=self::settings();$registered=self::gateways();
        foreach ($body['gateways'] as $id=>$incoming) {
            $id=sanitize_key($id);self::need(isset($registered[$id]) && is_array($incoming),'یکی از درگاه‌ها معتبر نیست.');
            $gateway=$registered[$id];$old=isset($all[$id]) && is_array($all[$id])?$all[$id]:array();$next=array();
            $next['enabled']=!empty($incoming['enabled'])?1:0;
            $next['title']=sanitize_text_field(self::scalar($incoming['title'] ?? $gateway->title()));
            $next['description']=sanitize_text_field(self::scalar($incoming['description'] ?? $gateway->description()));
            self::need($next['title']!=='' && Support::length($next['title'])<=100 && Support::length($next['description'])<=300,'عنوان یا توضیح درگاه معتبر نیست.');
            foreach (self::field_schema($gateway) as $key=>$field) {
                $raw=self::scalar($incoming['values'][$key] ?? '');
                if ($field['secret']) {
                    self::need(Support::length($raw)<=500,'یکی از مشخصات محرمانهٔ درگاه بیش از حد طولانی است.');
                    if ($raw==='' && empty($incoming['clear'][$key])) {
                        $value=(string)($old[$key] ?? '');
                        if ($value!=='' && strpos($value,'enc:')!==0) { $value=self::cipher($value,true); }
                    } elseif ($raw!=='') { $value=self::cipher($raw,true); }
                    else { $value=''; }
                    self::need($raw==='' || $value!=='','ذخیرهٔ رمزگذاری‌شدهٔ مشخصات درگاه انجام نشد؛ افزونه OpenSSL در PHP را بررسی کنید.',500);
                }
                elseif ($field['type']==='checkbox') { $value=!empty($incoming['values'][$key])?1:0; }
                elseif ($field['type']==='url') { $value=esc_url_raw($raw); self::need($raw==='' || ($value && strtolower((string)wp_parse_url($value,PHP_URL_SCHEME))==='https'),'آدرس وب‌سرویس باید HTTPS و معتبر باشد.'); }
                elseif ($field['type']==='number') { self::need($raw==='' || preg_match('/^\d+$/',$raw),'فیلد عددی درگاه معتبر نیست.');$value=$raw; }
                elseif ($field['type']==='select') { self::need($raw==='' || isset($field['options'][$raw]),'گزینهٔ تنظیمات درگاه معتبر نیست.');$value=$raw; }
                else { $value=sanitize_text_field($raw); }
                if (!$field['secret']) { self::need(Support::length($value)<=500,'یکی از مقادیر تنظیمات درگاه بیش از حد طولانی است.'); }
                $next[$key]=$value;
            }
            $plain=self::decrypted_settings($gateway,$next);
            if ($next['enabled']) { self::need($gateway->configured($plain),'فیلدهای ضروری '.$gateway->title().' را کامل کنید.'); }
            $validation=$gateway->validate_settings($plain);
            self::need(!is_wp_error($validation),is_wp_error($validation)?$validation->get_error_message():'تنظیمات درگاه معتبر نیست.');
            $all[$id]=$next;
        }
        update_option(self::OPTION,$all,false);
        Support::audit('payment_gateways_saved','settings',0,array('gateways'=>array_keys($body['gateways'])));
        return array('message'=>'تنظیمات درگاه‌های پرداخت ذخیره شد.');
    }

    private static function callback_input() {
        $values=array_merge(is_array($_GET)?wp_unslash($_GET):array(),is_array($_POST)?wp_unslash($_POST):array());
        $content_type=strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($content_type,'application/json')!==false) {
            $json=json_decode((string)file_get_contents('php://input'),true);
            if (is_array($json)) { $values=array_merge($values,$json); }
        }
        $safe=array();
        foreach ($values as $key=>$value) {
            if (count($safe)>=50) { break; }
            if (is_scalar($value)) { $safe[sanitize_key($key)]=substr(sanitize_text_field((string)$value),0,1000); }
        }
        return $safe;
    }

    public static function callback() {
        global $wpdb;
        $input=self::callback_input();$token=strtolower((string)($input['tx'] ?? ''));$gateway_id=sanitize_key($input['gateway'] ?? '');
        if (!$gateway_id) { self::thank_you(false,null,'آدرس بازگشت پرداخت معتبر نیست.'); }
        $tx=null;
        if (preg_match('/^[a-f0-9]{64}$/',$token)) {
            $tx=$wpdb->get_row($wpdb->prepare('SELECT tx.*,e.class_id,c.name class_name,t.name term_name FROM '.Support::table('payment_transactions').' tx JOIN '.Support::table('enrollments').' e ON e.id=tx.enrollment_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE tx.token=%s',$token),ARRAY_A);
        } else {
            $provider_order=sanitize_text_field((string)($input['transactionid'] ?? ($input['providerid'] ?? '')));
            if ($provider_order!=='' && Support::length($provider_order)<=190) {
                $tx=$wpdb->get_row($wpdb->prepare('SELECT tx.*,e.class_id,c.name class_name,t.name term_name FROM '.Support::table('payment_transactions').' tx JOIN '.Support::table('enrollments').' e ON e.id=tx.enrollment_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE tx.gateway=%s AND tx.provider_order_id=%s ORDER BY tx.id DESC LIMIT 1',$gateway_id,$provider_order),ARRAY_A);
                if ($tx) { $token=$tx['token']; }
            }
        }
        if (!$tx || !hash_equals((string)$tx['gateway'],$gateway_id)) { self::thank_you(false,$tx,'تراکنش پرداخت پیدا نشد.'); }
        $lock=self::lock_name('callback:'.$token);
        if (!self::acquire($lock,10)) { self::thank_you(false,null,'نتیجهٔ پرداخت در حال بررسی است؛ چند لحظه بعد از صفحهٔ کلاس وضعیت را ببینید.'); }
        try {
            $tx=$wpdb->get_row($wpdb->prepare('SELECT tx.*,e.class_id,c.name class_name,t.name term_name FROM '.Support::table('payment_transactions').' tx JOIN '.Support::table('enrollments').' e ON e.id=tx.enrollment_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE tx.token=%s',$token),ARRAY_A);
            if (!$tx || !hash_equals((string)$tx['gateway'],$gateway_id)) { self::thank_you(false,$tx,'تراکنش پرداخت پیدا نشد.'); }
            if ($tx['status']==='paid' && (int)$tx['payment_id']>0) { self::thank_you(true,$tx,'پرداخت شما قبلاً با موفقیت ثبت شده است.'); }
            $gateways=self::gateways();
            if (!isset($gateways[$gateway_id])) { self::thank_you(false,$tx,'افزونهٔ درگاه این تراکنش فعال نیست؛ با آموزشگاه تماس بگیرید.'); }
            $gateway=$gateways[$gateway_id];$settings=self::settings($gateway_id);
            $result=$gateway->verify($tx,$input,$settings);
            if (is_wp_error($result) || !is_array($result) || empty($result['success'])) {
                $message=is_wp_error($result)?$result->get_error_message():'پرداخت توسط درگاه تأیید نشد.';
                $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','callback_json'=>self::json($input),'response_json'=>self::json(is_array($result)?($result['response'] ?? array()):array()),'error_message'=>sanitize_text_field($message),'updated_at'=>Support::now()),array('id'=>$tx['id']));
                self::thank_you(false,$tx,$message);
            }
            $reference=sanitize_text_field((string)($result['reference'] ?? $tx['provider_token']));
            if (Support::length($reference)>120) { $reference=substr($reference,0,120); }
            Support::checked($wpdb->query('START TRANSACTION'));
            $payment=$wpdb->get_row($wpdb->prepare('SELECT id FROM '.Support::table('payments').' WHERE request_key=%s',$tx['request_key']),ARRAY_A);
            if (!$payment) {
                $payment_data=array('enrollment_id'=>$tx['enrollment_id'],'amount'=>$tx['amount'],'status'=>'success','method'=>$gateway_id,'reference'=>$reference,
                    'paid_on'=>current_time('Y-m-d'),'notes'=>'پرداخت اینترنتی تأییدشده از '.$gateway->title(),'request_key'=>$tx['request_key'],'created_by'=>$tx['created_by'],'created_at'=>Support::now(),'void_reason'=>'');
                Support::checked($wpdb->insert(Support::table('payments'),$payment_data));$payment_id=(int)$wpdb->insert_id;
            } else { $payment_id=(int)$payment['id']; }
            Support::checked($wpdb->update(Support::table('payment_transactions'),array('status'=>'paid','reference'=>$reference,'payment_id'=>$payment_id,
                'callback_json'=>self::json($input),'response_json'=>self::json($result['response'] ?? array()),'error_message'=>'','updated_at'=>Support::now(),'paid_at'=>Support::now()),array('id'=>$tx['id'])));
            Support::checked($wpdb->query('COMMIT'));
            $tx['status']='paid';$tx['payment_id']=$payment_id;$tx['reference']=$reference;
            try { Support::audit('online_payment_confirmed','payment',$payment_id,array('transaction_id'=>(int)$tx['id'],'gateway'=>$gateway_id,'amount'=>(int)$tx['amount'],'enrollment_id'=>(int)$tx['enrollment_id'])); } catch (\Throwable $ignored) {}
            try { SMS::record_event('payment_received','payment:'.$payment_id,array('enrollment_id'=>$tx['enrollment_id'],'extra'=>array('payment_id'=>$payment_id,'payment_amount'=>(string)$tx['amount'],'payment_date'=>SMS::date_label(current_time('Y-m-d'))))); } catch (\Throwable $ignored) {}
            self::thank_you(true,$tx,'از پرداخت شما سپاسگزاریم. شهریه با موفقیت ثبت شد.');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($tx) { $wpdb->update(Support::table('payment_transactions'),array('status'=>'failed','error_message'=>'خطای ثبت نتیجهٔ پرداخت','updated_at'=>Support::now()),array('id'=>$tx['id'])); }
            self::thank_you(false,$tx,'تأیید نهایی پرداخت کامل نشد. لطفاً با آموزشگاه تماس بگیرید و دوباره پرداخت نکنید.');
        } finally { self::release($lock); }
    }

    private static function thank_you($success, $tx, $message) {
        $class_id=(int)($tx['class_id'] ?? 0);$return=Portal::url().($class_id?'#classes/'.$class_id:'#payments');
        $notice=array('class_id'=>$class_id,'status'=>$success?'success':'failed','message'=>$message,'reference'=>$tx['reference'] ?? '','amount'=>(int)($tx['amount'] ?? 0),'currency'=>$tx['currency'] ?? Support::settings()['currency']);
        nocache_headers();status_header(200);header('Content-Type: text/html; charset=utf-8',true);header('X-Robots-Tag: noindex, nofollow',true);header('Referrer-Policy: same-origin');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
        $json=wp_json_encode($notice,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html($success?'پرداخت موفق':'نتیجه پرداخت'); ?></title><style>body{margin:0;background:#f4f7fb;color:#16324f;font-family:Tahoma,Arial,sans-serif;display:grid;min-height:100vh;place-items:center}.card{width:min(520px,calc(100% - 40px));background:#fff;border:1px solid #dfe7ef;border-radius:22px;padding:34px;box-shadow:0 20px 60px #17324f1a;text-align:center}.mark{width:72px;height:72px;margin:auto;border-radius:50%;display:grid;place-items:center;font-size:38px;background:<?php echo $success?'#e7f8ef':'#fff1ef'; ?>;color:<?php echo $success?'#16834b':'#c23b32'; ?>}h1{font-size:24px;margin:20px 0 10px}p{line-height:1.9;color:#4e6477}.ref{background:#f4f7fb;border-radius:12px;padding:12px;margin:18px 0}.button{display:inline-block;background:#1565c0;color:#fff;text-decoration:none;border-radius:12px;padding:12px 22px;margin-top:8px}</style></head><body><main class="card"><div class="mark"><?php echo $success?'✓':'!'; ?></div><h1><?php echo esc_html($success?'پرداخت موفق بود':'پرداخت تکمیل نشد'); ?></h1><p><?php echo esc_html($message); ?></p><?php if (!empty($notice['reference'])) { ?><div class="ref">شماره پیگیری: <bdi><?php echo esc_html($notice['reference']); ?></bdi></div><?php } ?><p>تا چند ثانیه دیگر به صفحهٔ کلاس برمی‌گردید.</p><a class="button" href="<?php echo esc_url($return); ?>">بازگشت به صفحهٔ کلاس</a></main><script>try{sessionStorage.setItem('nasrPaymentNotice',JSON.stringify(<?php echo $json; ?>));}catch(e){}setTimeout(function(){location.replace(<?php echo wp_json_encode($return,JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);},5000);</script></body></html><?php
        exit;
    }
}
