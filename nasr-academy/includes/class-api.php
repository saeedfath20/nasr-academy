<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Problem extends \RuntimeException {}

final class API {
    use Reports;
    use Payroll;
    use Trash;
    private static $new_user = 0;
    private static $touched_user = 0;
    public static function boot() {
        add_action('rest_api_init', array(__CLASS__,'routes'));
        add_filter('rest_post_dispatch', function ($response, $server, $request) {
            if (strpos($request->get_route(), '/nasr/v1/') === 0 && $response instanceof \WP_REST_Response) {
                $response->header('Cache-Control','private, no-store, no-cache, must-revalidate, max-age=0');
                $response->header('Vary','Cookie');
            }
            return $response;
        },10,3);
    }
    public static function routes() {
        $routes = array(
            '/bootstrap'=>array('GET'=>'bootstrap'),
            '/updates'=>array('GET'=>'live_revision'),
            '/branches'=>array('GET'=>'branches'),
            '/reports'=>array('GET'=>'report'),
            '/teacher-payroll'=>array('GET'=>'teacher_payroll','POST'=>'save_teacher_payroll'),
            // Serialize the grade gate and salary read with grade/class writes.
            '/my-salary'=>array('GET'=>array('my_salary',true)),
            '/report-options'=>array('GET'=>'report_options'),
            '/attendance'=>array('GET'=>'attendance','POST'=>'save_attendance'),
            '/people'=>array('GET'=>'people','POST'=>'save_person'),
            '/people/(?P<id>\d+)'=>array('GET'=>'person','POST'=>'save_person'),
            '/secretary-access'=>array('POST'=>'grant_secretary'),
            '/secretary-access/(?P<id>\d+)/revoke'=>array('POST'=>'revoke_secretary'),
            '/directory'=>array('GET'=>'directory'),
            '/trash'=>array('GET'=>'trash'),
            '/(?P<entity>people|classes|terms|enrollments)/(?P<id>\d+)/purge-preview'=>array('GET'=>'purge_preview'),
            '/(?P<entity>people|classes|terms|enrollments)/(?P<id>\d+)/purge'=>array('POST'=>'purge_record'),
            '/(?P<entity>people|classes|terms|enrollments)/(?P<id>\d+)/(?P<operation>delete|restore)'=>array('POST'=>'change_deleted'),
            '/terms'=>array('GET'=>'terms','POST'=>'save_term'),
            '/terms/(?P<id>\d+)'=>array('POST'=>'save_term'),
            '/classes'=>array('GET'=>'classes','POST'=>'save_class'),
            '/class-terms'=>array('GET'=>'class_terms'),
            '/classes/(?P<id>\d+)'=>array('GET'=>'class_detail','POST'=>'save_class'),
            '/enroll'=>array('POST'=>'enroll'),
            '/enrollment-copy/classes'=>array('GET'=>'copy_classes'),
            '/enrollment-copy/roster'=>array('GET'=>'copy_roster'),
            '/enrollment-copy'=>array('POST'=>'copy_enrollments'),
            '/enrollments/(?P<id>\d+)'=>array('POST'=>'save_enrollment'),
            '/grades/(?P<id>\d+)'=>array('POST'=>'save_grades'),
            '/payments'=>array('GET'=>'payments','POST'=>'save_payment'),
            '/payment-options'=>array('GET'=>'payment_options'),
            '/payments/(?P<id>\d+)/receipt'=>array('GET'=>'payment_receipt'),
            '/payments/(?P<id>\d+)/void'=>array('POST'=>'void_payment'),
            '/checkout/(?P<id>\d+)'=>array('GET'=>'payment_checkout'),
            '/checkout/(?P<id>\d+)/start'=>array('POST'=>array('payment_start',false)),
            '/payment-gateways'=>array('GET'=>'payment_gateway_settings','POST'=>'save_payment_gateway_settings'),
            '/settings'=>array('POST'=>'save_settings'),
            '/audit'=>array('GET'=>'audit'),
            '/password'=>array('POST'=>'password'),
        );
        foreach ($routes as $path=>$verbs) {
            $args = array();
            foreach ($verbs as $verb=>$definition) {
                $method=is_array($definition)?$definition[0]:$definition;
                $transactional=is_array($definition)?(bool)$definition[1]:$verb !== 'GET';
                $args[] = array('methods'=>$verb,'permission_callback'=>array('Nasr\\Academy\\Support','access'),
                    'callback'=>function ($r) use ($method, $transactional) { return self::dispatch($method,$r,$transactional); });
            }
            register_rest_route('nasr/v1',$path,$args);
        }
    }
    public static function live_revision($r=null) {
        global $wpdb;
        $revision=$wpdb->get_var('SELECT COALESCE(MAX(id),0) FROM '.Support::table('audit'));
        self::need(!$wpdb->last_error,'دریافت وضعیت تازهٔ سامانه انجام نشد.',503);
        return array('revision'=>hash_hmac('sha256',get_current_user_id().'|'.Support::role().'|'.(string)$revision,wp_salt('auth')));
    }
    private static function dispatch($method, $request, $write) {
        global $wpdb;
        $lock = 'nasr_' . md5($wpdb->prefix . DB_NAME);
        $locked = false; self::$new_user = 0; self::$touched_user = 0;
        try {
            if ($write) {
                $locked = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) === 1;
                self::need($locked,'سامانه مشغول ثبت اطلاعات است. چند لحظه بعد دوباره تلاش کنید.',409);
                if ($method==='purge_record') self::prepare_purge_locks($request);
                Support::checked($wpdb->query('START TRANSACTION'));
            }
            $sync = $write ? self::sync_request($method,$request) : null;
            $result = $sync && $sync['receipt'] ? $sync['receipt'] : self::$method($request);
            if ($sync && !$sync['receipt']) {
                $result['_sync']=array('request_id'=>$sync['request_id'],'status'=>'confirmed');
                Support::checked($wpdb->insert(Support::table('sync_receipts'),array('user_id'=>get_current_user_id(),'request_id'=>$sync['request_id'],'role'=>Support::role(),'route'=>$sync['route'],'body_hash'=>$sync['body_hash'],'response_json'=>wp_json_encode($result),'created_at'=>Support::now())));
            }
            if ($write) { Support::checked($wpdb->query('COMMIT')); }
            return rest_ensure_response($result);
        } catch (Problem $e) {
            if ($write && $locked) { $wpdb->query('ROLLBACK'); self::cleanup_user(); }
            return Support::error($e->getMessage(),$e->getCode() ?: 400);
        } catch (\Throwable $e) {
            if ($write && $locked) { $wpdb->query('ROLLBACK'); self::cleanup_user(); }
            return Support::error('ثبت یا بازیابی اطلاعات انجام نشد. دوباره تلاش کنید؛ در صورت تکرار، سلامت پایگاه داده را بررسی کنید.',500,'nasr_storage');
        } finally {
            self::release_purge_locks();
            if ($locked) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        }
    }
    private static function sync_request($method,$r) {
        global $wpdb;
        if (!in_array($method,array('save_grades','save_attendance'),true)) { return null; }
        $body=self::body($r);
        if (!array_key_exists('client_request_id',$body)) { return null; }
        $key=self::text($body,'client_request_id',true,64);
        self::need(preg_match('/^[a-zA-Z0-9_-]{32,64}$/D',$key),'شناسهٔ ثبت معتبر نیست.');
        $tables=array_map(array('Nasr\\Academy\\Support','table'),array_merge(array('sync_receipts','audit','sms_events'),$method==='save_grades'?array('enrollments'):array('attendance_sessions','attendance')));
        $engines=$wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name IN ('.implode(',',array_fill(0,count($tables),'%s')).')',...$tables),ARRAY_A);
        self::need(is_array($engines) && !$wpdb->last_error && count($engines)===count($tables),'بررسی ثبت امن اطلاعات انجام نشد؛ با مدیر سامانه تماس بگیرید.',503);
        foreach ($engines as $table) { self::need(strtoupper($table['Engine'] ?? '')==='INNODB','پایگاه داده آمادهٔ ثبت امن اطلاعات نیست؛ با مدیر سامانه تماس بگیرید.',503); }
        self::need(in_array(Support::role(),array('teacher','manager','secretary'),true),'اجازهٔ ارسال این تغییر را ندارید.',403);
        if ($method==='save_grades') {
            $e=self::row('enrollments',$r['id']); $c=self::live_enrollment($e);
            self::need(Support::staff() || (int)$c['teacher_id']===get_current_user_id(),'این کلاس به شما اختصاص ندارد.',403);
            self::need(Support::staff() || ((int)$c['active']===1 && (int)$e['active']===1),'کلاس یا ثبت‌نام بایگانی شده است.',409);
            $route='grades/'.(int)$e['id'];
        } else {
            $c=self::attendance_class($body['class_id'] ?? 0);
            $route='attendance/'.(int)$c['id'].'/'.(int)($body['session_no'] ?? 0);
        }
        $hash=hash('sha256',wp_json_encode(self::sync_canonical($body)));
        $old=$wpdb->get_row($wpdb->prepare('SELECT role,route,body_hash,response_json FROM '.Support::table('sync_receipts').' WHERE user_id=%d AND request_id=%s',get_current_user_id(),$key),ARRAY_A);
        self::need(!$wpdb->last_error,'بررسی وضعیت ثبت انجام نشد.',500);
        $receipt=null;
        if ($old) {
            self::need($old['role']===Support::role() && $old['route']===$route && hash_equals($old['body_hash'],$hash),'این شناسه قبلاً برای تغییر دیگری استفاده شده است.',409);
            $receipt=json_decode($old['response_json'],true);
            self::need(is_array($receipt),'رسید ثبت معتبر نیست.',500);
        }
        return array('request_id'=>$key,'route'=>$route,'body_hash'=>$hash,'receipt'=>$receipt);
    }
    private static function sync_canonical($value) {
        if (!is_array($value)) { return $value; }
        if (array_keys($value)!==range(0,count($value)-1)) { ksort($value); }
        foreach ($value as $key=>$item) { $value[$key]=self::sync_canonical($item); }
        return $value;
    }
    private static function cleanup_user() {
        if (self::$touched_user) { clean_user_cache(self::$touched_user); }
        wp_cache_delete('nasr_academy_settings', 'options');
        wp_cache_delete('nasr_academy_preferences', 'options');
        wp_cache_delete(Payments::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        if (self::$new_user) {
            clean_user_cache(self::$new_user);
            // Also compensate on older sites with non-transactional WordPress user tables.
            if (get_userdata(self::$new_user)) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user(self::$new_user);
            }
        }
    }
    private static function need($condition, $message, $status = 400) {
        if (!$condition) { throw new Problem($message,$status); }
    }
    private static function valid($value, $label = '') {
        if (is_wp_error($value)) { throw new Problem(($label ? $label . ': ' : '') . $value->get_error_message(),400); }
        return $value;
    }
    private static function admin() { self::need(Support::staff(),'این بخش فقط در دسترس مدیر و منشی است.',403); }
    private static function manager_only() { self::need(Support::manager(),'این بخش فقط در دسترس مدیر است.',403); }
    private static function body($r) {
        $b = $r->get_json_params();
        self::need(is_array($b),'اطلاعات ارسالی معتبر نیست.');
        return $b;
    }
    private static function text($b, $key, $required = false, $max = 190) {
        self::need(!isset($b[$key]) || is_scalar($b[$key]),'نوع ورودی معتبر نیست.');
        $v = sanitize_text_field($b[$key] ?? '');
        self::need(!$required || $v !== '', 'فیلدهای ستاره‌دار را کامل کنید.');
        self::need(Support::length($v) <= $max,'متن واردشده بیش از حد طولانی است.');
        return $v;
    }
    private static function textarea($b, $key, $required = false, $max = 2000) {
        self::need(!isset($b[$key]) || is_scalar($b[$key]),'نوع ورودی معتبر نیست.');
        $v = sanitize_textarea_field($b[$key] ?? '');
        self::need(!$required || $v !== '', 'فیلدهای ستاره‌دار را کامل کنید.');
        self::need(Support::length($v) <= $max,'متن واردشده بیش از حد طولانی است.');
        return $v;
    }
    private static function row($table, $id, $include_deleted = false) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Support::table($table) . ' WHERE id=%d',absint($id)),ARRAY_A);
        self::need($row,'اطلاعات پیدا نشد.',404);
        self::need($include_deleted || empty($row['deleted_at']),'این مورد حذف شده است؛ مدیر می‌تواند آن را از حذف‌شده‌ها بازیابی کند.',404);
        return $row;
    }
    private static function page($r) {
        return array(max(1,absint($r['page'] ?: 1)),min(100,max(1,absint($r['per_page'] ?: 20))));
    }
    private static function paged($sql, $count_sql, $r) {
        global $wpdb;
        list($page,$per) = self::page($r);
        $items = $wpdb->get_results($sql . $wpdb->prepare(' LIMIT %d OFFSET %d',$per,($page-1)*$per),ARRAY_A);
        self::need(is_array($items) && !$wpdb->last_error,'بازیابی فهرست انجام نشد.',500);
        $total = (int)$wpdb->get_var($count_sql);
        self::need(!$wpdb->last_error,'شمارش فهرست انجام نشد.',500);
        return array('items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per)));
    }
    private static function student_scope($alias = 'e') {
        global $wpdb;
        $role = Support::role(); $uid = get_current_user_id();
        if (Support::staff()) { return '1=1'; }
        // A dual-role teacher reaches learner records only after explicitly
        // selecting student mode for this WordPress login session.
        if ($role === 'student') { return $wpdb->prepare("$alias.student_id=%d",$uid); }
        if ($role === 'family') {
            return $wpdb->prepare("$alias.student_id IN (SELECT l.student_id FROM " . Support::table('family_links') . ' l JOIN '.Support::table('people').' child ON child.user_id=l.student_id WHERE l.family_id=%d AND child.deleted_at IS NULL)',$uid);
        }
        return '1=0';
    }
    private static function class_scope() {
        global $wpdb;
        $live='c.deleted_at IS NULL AND t.deleted_at IS NULL AND p.deleted_at IS NULL';
        if (Support::staff()) { return $live; }
        if (Support::role() === 'teacher') {
            $uid=get_current_user_id();
            return $live.$wpdb->prepare(' AND c.teacher_id=%d',$uid);
        }
        return $live.' AND EXISTS (SELECT 1 FROM ' . Support::table('enrollments') . ' e WHERE e.class_id=c.id AND e.deleted_at IS NULL AND ' . self::student_scope() . ')';
    }
    private static function class_sql() {
        return ' FROM ' . Support::table('classes') . ' c JOIN ' . Support::table('terms') . ' t ON t.id=c.term_id JOIN ' . Support::table('people') . ' p ON p.user_id=c.teacher_id';
    }
    private static function class_fields() {
        return 'c.*,t.branch_id,(SELECT name FROM '.Support::table('branches').' b WHERE b.id=t.branch_id) branch_name,t.code term_code,t.name term_name,t.start_date,t.end_date,t.mid_date,t.final_date,CONCAT(p.first_name,\' \',p.last_name) teacher_name,TRIM(CONCAT(p.first_name_en,\' \',p.last_name_en)) teacher_name_en';
    }
    private static function bootstrap($r) {
        global $wpdb;
        $role = Support::role(); $p = Support::profile(); $u = wp_get_current_user();
        $class_count = (int)$wpdb->get_var('SELECT COUNT(*)' . self::class_sql() . ' WHERE c.active=1 AND ' . self::class_scope());
        $stats = array('classes'=>$class_count);
        if (in_array($role,array('manager','secretary'),true)) {
            $stats['students'] = (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Support::table('people')." WHERE (kind='student' OR (kind='teacher' AND student_code IS NOT NULL)) AND active=1 AND deleted_at IS NULL");
            $stats['teachers'] = (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Support::table('people')." WHERE kind='teacher' AND active=1 AND deleted_at IS NULL");
        }
        $student_access=in_array($role,array('student','family'),true);
        if ($role==='manager' || $student_access) {
            $scope = self::student_scope();
            $stats['due'] = (int)$wpdb->get_var('SELECT COALESCE(SUM(GREATEST(e.net_fee-COALESCE(p.paid,0),0)),0) FROM '.Support::table('enrollments').' e LEFT JOIN (SELECT enrollment_id,SUM(amount) paid FROM '.Support::table('payments')." WHERE status='success' AND voided_at IS NULL GROUP BY enrollment_id) p ON p.enrollment_id=e.id WHERE " . $scope);
            $stats['paid'] = (int)$wpdb->get_var('SELECT COALESCE(SUM(p.amount),0) FROM '.Support::table('payments').' p JOIN '.Support::table('enrollments')." e ON e.id=p.enrollment_id WHERE p.status='success' AND p.voided_at IS NULL AND " . $scope);
        }
        $children = array();
        if ($role === 'family') {
            $children = $wpdb->get_results($wpdb->prepare('SELECT p.user_id,p.first_name,p.last_name,p.student_code FROM '.Support::table('people').' p JOIN '.Support::table('family_links').' l ON l.student_id=p.user_id WHERE l.family_id=%d AND p.deleted_at IS NULL',get_current_user_id()),ARRAY_A);
        }
        $settings=Support::settings(); $settings['branches']=Support::branches(); $settings['preferences']=Preferences::get(); $settings['payment_method_names']=Payments::public_names();
        if (Support::staff()) {
            $settings['print_catalog']=Preferences::catalog();
            if (!Support::manager()) foreach (array('payments','unpaid','student_payments','teacher_pay','teacher_history','annual') as $type) unset($settings['print_catalog'][$type]);
        }
        return array('role'=>$role,'available_roles'=>Support::available_roles(),'name'=>$u->display_name,'student_code'=>$p['student_code'] ?? null,'student_access'=>$student_access,'settings'=>$settings,'stats'=>$stats,'children'=>$children,'version'=>NASR_ACADEMY_VERSION,'user_id'=>get_current_user_id(),'sync_protocol'=>1);
    }
    private static function grant_secretary_person($person) {
        global $wpdb;self::manager_only();
        self::need(in_array($person['kind'],array('teacher','student','secretary'),true),'دسترسی منشی فقط به حساب معلم، زبان‌آموز یا منشی اضافه می‌شود.');
        self::need(empty($person['deleted_at']) && (int)$person['active']===1,'ابتدا پروندهٔ این فرد را بازیابی یا فعال کنید.',409);
        $user=get_userdata((int)$person['user_id']);
        self::need($user && !user_can($user,'manage_options'),'حساب این فرد برای تعریف منشی معتبر نیست.',403);
        $already=!empty($person['secretary_access']);
        if (!$already) {
            Support::checked($wpdb->update(Support::table('people'),array('secretary_access'=>1,'updated_at'=>Support::now()),array('id'=>$person['id'])));
            Support::audit('secretary_access_granted','person',$person['id'],array('user_id'=>(int)$person['user_id'],'kind'=>$person['kind']));
        }
        return array('id'=>(int)$person['id'],'kind'=>$person['kind'],'reused_person'=>true,'message'=>$already?'این فرد از قبل دسترسی منشی دارد.':'دسترسی منشی به همان حساب اضافه شد؛ نام کاربری و رمز عبور قبلی حفظ شدند.');
    }
    private static function grant_secretary($r) {
        global $wpdb;self::manager_only();$b=self::body($r);
        $national=Support::digits(self::text($b,'national_id',true,10));
        self::need(preg_match('/^\d{10}$/',$national),'کد ملی باید ۱۰ رقم باشد.');
        $person=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('people').' WHERE national_id=%s',$national),ARRAY_A);
        self::need(!$wpdb->last_error,'خواندن پرونده انجام نشد.',500);
        self::need($person,'فردی با این کد ملی ثبت نشده است؛ از گزینهٔ تعریف منشی جدید استفاده کنید.',404);
        return self::grant_secretary_person($person);
    }
    private static function revoke_secretary($r) {
        global $wpdb;self::manager_only();$b=self::body($r);
        self::need(($b['confirm'] ?? false)===true,'ابتدا لغو دسترسی را تأیید کنید.');
        $person=self::row('people',$r['id']);
        self::need(!empty($person['secretary_access']),'این فرد دسترسی منشی ندارد.',409);
        Support::checked($wpdb->update(Support::table('people'),array('secretary_access'=>0,'updated_at'=>Support::now()),array('id'=>$person['id'])));
        Support::audit('secretary_access_revoked','person',$person['id'],array('user_id'=>(int)$person['user_id'],'kind'=>$person['kind']));
        \WP_Session_Tokens::get_instance((int)$person['user_id'])->destroy_all();
        return array('message'=>'دسترسی منشی لغو شد؛ سایر نقش‌های این فرد حفظ شدند.');
    }
    private static function people($r) {
        global $wpdb; self::admin();
        $where = 'p.deleted_at IS NULL';
        if (!Support::manager()) { $where.=" AND p.kind IN ('student','teacher','family')"; }
        if ($r['kind']==='manager') { self::manager_only(); }
        if ($r['kind']==='secretary') {
            self::manager_only();$where.=' AND p.secretary_access=1';
        } elseif ($r['kind']==='student') {
            $where .= " AND (p.kind='student' OR (p.kind='teacher' AND p.student_code IS NOT NULL))";
        } elseif (in_array($r['kind'],array('teacher','family','manager'),true)) {
            $where .= $wpdb->prepare(' AND p.kind=%s',$r['kind']);
        }
        if ($r['active'] !== null && $r['active'] !== '') { $where .= $wpdb->prepare(' AND p.active=%d',(int)$r['active'] === 1 ? 1 : 0); }
        $search=Support::digits(self::text(array('q'=>$r['q']??''),'q',false,150));
        if ($search!=='') {
            $q = '%' . $wpdb->esc_like($search) . '%';
            $name='%'.$wpdb->esc_like(str_replace(array('ي','ك',"\u{200C}",' '),array('ی','ک','',''),$search)).'%';
            $where .= $wpdb->prepare(" AND (REPLACE(REPLACE(REPLACE(REPLACE(CONCAT(p.first_name,p.last_name),'ي','ی'),'ك','ک'),'‌',''),' ','') LIKE %s OR p.student_code LIKE %s OR p.national_id LIKE %s OR p.mobile LIKE %s OR u.user_login LIKE %s OR REPLACE(CONCAT(p.first_name_en,p.last_name_en),' ','') LIKE %s)",$name,$q,$q,$q,$q,$name);
        }
        $from = ' FROM '.Support::table('people').' p JOIN '.$wpdb->users.' u ON u.ID=p.user_id WHERE '.$where;
        return self::paged('SELECT p.*,u.user_login username,u.user_email email'.$from.' ORDER BY p.id DESC','SELECT COUNT(*)'.$from,$r);
    }
    private static function directory($r) {
        global $wpdb; self::admin();
        $kind = in_array($r['kind'],array('teacher','student','family'),true) ? $r['kind'] : 'student';
        $purpose=is_scalar($r['purpose']) ? sanitize_key((string)$r['purpose']) : '';
        $financial=$kind==='student' && $purpose==='financial';
        $grade_history=$kind==='student' && $purpose==='grades';
        $enrollable=$purpose==='enrollment';
        if ($enrollable) {
            $where="p.kind IN ('student','teacher') AND p.deleted_at IS NULL";
        } elseif ($financial) {
            $where="p.kind IN ('student','teacher') AND p.deleted_at IS NULL AND EXISTS (SELECT 1 FROM ".Support::table('enrollments')." financial_enrollment JOIN ".Support::table('classes')." fc ON fc.id=financial_enrollment.class_id JOIN ".Support::table('terms')." ft ON ft.id=fc.term_id JOIN ".Support::table('people')." teacher ON teacher.user_id=fc.teacher_id WHERE financial_enrollment.student_id=p.user_id AND financial_enrollment.deleted_at IS NULL AND fc.deleted_at IS NULL AND ft.deleted_at IS NULL AND teacher.deleted_at IS NULL)";
        } elseif ($grade_history) {
            $where="(p.kind='student' OR (p.kind='teacher' AND p.student_code IS NOT NULL)) AND p.deleted_at IS NULL";
        } else {
            $where=$wpdb->prepare('p.kind=%s',$kind).' AND p.deleted_at IS NULL';
        }
        $fields='p.user_id,p.kind,p.first_name,p.last_name,p.first_name_en,p.last_name_en,p.student_code,p.active,p.deleted_at';
        if ($r['live']) {
            if(!$financial && !$grade_history)$where.=' AND p.active=1';
            $q=Support::digits(self::text(array('q'=>$r['q'] ?? ''),'q',false,100));
            if ($q!=='') {
                // Normalize common Arabic/Persian keyboard variants, without exposing private fields.
                $q=str_replace(array('ي','ك',"\u{200C}",' '),array('ی','ک','',''),$q);
                if (preg_match('/^[0-9٬,]+$/u',$q)) { $q=str_replace(array('٬',','),'',$q); }
                $like='%'.$wpdb->esc_like($q).'%';
                $where.=$wpdb->prepare(" AND (REPLACE(REPLACE(REPLACE(REPLACE(CONCAT(p.first_name,' ',p.last_name),'ي','ی'),'ك','ک'),'‌',''),' ','') LIKE %s OR CAST(p.student_code AS CHAR) LIKE %s OR REPLACE(CONCAT(p.first_name_en,p.last_name_en),' ','') LIKE %s)",$like,$like,$like);
            }
            if ($r['class_id']) {
                $c=self::row('classes',$r['class_id']);
                $where.=$wpdb->prepare(' AND NOT EXISTS (SELECT 1 FROM '.Support::table('enrollments').' enrolled WHERE enrolled.class_id=%d AND enrolled.student_id=p.user_id AND enrolled.deleted_at IS NULL)',$c['id']);
                $fields.=$wpdb->prepare(',EXISTS (SELECT 1 FROM '.Support::table('enrollments').' prior WHERE prior.class_id=%d AND prior.student_id=p.user_id AND prior.deleted_at IS NOT NULL) rejoin',$c['id']);
            }
        }
        $from=' FROM '.Support::table('people').' p WHERE '.$where;
        $sql='SELECT '.$fields.$from.' ORDER BY p.last_name,p.first_name,p.user_id';
        if ($r['live'] && $grade_history) {
            $items=$wpdb->get_results($sql,ARRAY_A);
            self::need(is_array($items) && !$wpdb->last_error,'بازیابی فهرست انجام نشد.',500);
            return array('items'=>$items,'total'=>count($items),'page'=>1,'pages'=>1);
        }
        return $r['live'] ? self::paged($sql,'SELECT COUNT(*)'.$from,$r) : $wpdb->get_results($sql,ARRAY_A);
    }
    private static function person($r) {
        global $wpdb; self::admin();
        $p = self::row('people',$r['id']); $u = get_userdata($p['user_id']);
        if ($p['kind']==='secretary') { self::manager_only(); }
        self::need($u,'حساب وردپرس این شخص حذف شده است.',404);
        $p['username'] = $u->user_login; $p['email'] = $u->user_email;

        $p['student_ids'] = array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT l.student_id FROM '.Support::table('family_links').' l JOIN '.Support::table('people').' child ON child.user_id=l.student_id WHERE l.family_id=%d AND child.deleted_at IS NULL',$p['user_id'])));
        return $p;
    }
    private static function trash($r) {
        global $wpdb; self::admin();
        $queries=array(
            "SELECT p.id,'people' entity,CONCAT(p.first_name,' ',p.last_name) name,CASE p.kind WHEN 'student' THEN CONCAT('زبان‌آموز · ',p.student_code) ELSE 'معلم' END context,p.deleted_at FROM ".Support::table('people')." p WHERE p.deleted_at IS NOT NULL AND p.kind IN ('student','teacher')",
            "SELECT c.id,'classes' entity,c.name,t.name context,c.deleted_at FROM ".Support::table('classes').' c JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE c.deleted_at IS NOT NULL',
            "SELECT t.id,'terms' entity,t.name,t.code context,t.deleted_at FROM ".Support::table('terms').' t WHERE t.deleted_at IS NOT NULL',
            "SELECT e.id,'enrollments' entity,CONCAT(p.first_name,' ',p.last_name) name,CONCAT(c.name,' · ',t.name) context,e.deleted_at FROM ".Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE e.deleted_at IS NOT NULL',
        );
        $from=' FROM ('.implode(' UNION ALL ',$queries).') removed';
        return self::paged('SELECT *'.$from.' ORDER BY deleted_at DESC,entity,id DESC','SELECT COUNT(*)'.$from,$r);
    }
    // Soft deletion never changes fees, grades, WordPress identities, or payment records.
    private static function change_deleted($r) {
        global $wpdb; self::admin(); $b=self::body($r);
        self::need(($b['confirm'] ?? false)===true,'ابتدا تأیید عملیات را علامت بزنید.');
        $entity=$r['entity']; $restore=$r['operation']==='restore';
        self::need(in_array($entity,array('people','classes','terms','enrollments'),true),'نوع مورد معتبر نیست.');
        $row=self::row($entity,$r['id'],$restore);
        if ($restore) { self::need(!empty($row['deleted_at']),'این مورد حذف نشده است.',409); }
        if ($entity==='people') {
            $u=get_userdata($row['user_id']);
            self::need(in_array($row['kind'],array('student','teacher'),true),'فقط زبان‌آموز و معلم از این بخش حذف یا بازیابی می‌شوند.',403);
            self::need($u && !user_can($u,'manage_options') && (int)$row['user_id']!==get_current_user_id(),'حساب مدیر اصلی یا حساب خودتان قابل حذف نیست.',403);
            if (!$restore && $row['kind']==='teacher') {
                self::need(!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Support::table('classes').' WHERE teacher_id=%d AND deleted_at IS NULL',$row['user_id'])),'این معلم کلاس دارد؛ ابتدا معلم کلاس‌ها را تغییر دهید یا کلاس‌ها را حذف کنید.',409);
            }
        } elseif ($entity==='terms' && !$restore) {
            self::need(!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Support::table('classes').' WHERE term_id=%d AND deleted_at IS NULL',$row['id'])),'این ترم کلاس دارد؛ ابتدا کلاس‌های آن را حذف یا به ترم دیگری منتقل کنید.',409);
        } elseif ($entity==='classes' && $restore) {
            self::row('terms',$row['term_id']);
            $teacher=Support::profile($row['teacher_id']);
            self::need($teacher && empty($teacher['deleted_at']),'ابتدا معلم این کلاس را بازیابی کنید.',409);
        } elseif ($entity==='enrollments') {
            self::live_enrollment($row);
        }
        Support::checked($wpdb->update(Support::table($entity),array('deleted_at'=>$restore ? null : Support::now()),array('id'=>$row['id'])));
        Support::audit($restore ? 'record_restored' : 'record_deleted',$entity,$row['id'],array('financial_history_preserved'=>true));
        if ($entity==='people' && !$restore) { \WP_Session_Tokens::get_instance($row['user_id'])->destroy_all(); }
        return array('message'=>$restore ? 'مورد با همان اطلاعات و سوابق بازیابی شد.' : 'مورد به حذف‌شده‌ها منتقل شد. نمره‌ها و سوابق مالی حفظ شدند؛ بدهی تسویه یا وجهی بازپرداخت نشد.');
    }
    private static function live_enrollment($e) {
        $c=self::row('classes',$e['class_id']); self::row('terms',$c['term_id']);
        $student=Support::profile($e['student_id']);
        self::need($student && empty($student['deleted_at']),'ابتدا پروندهٔ زبان‌آموز را بازیابی کنید.',409);
        return $c;
    }
    private static function save_person($r) {
        global $wpdb; self::admin(); $b = self::body($r); $id = absint($r['id']);
        self::need(!array_key_exists('secretary_access',$b),'دسترسی منشی را از بخش منشی‌ها تغییر دهید.',403);
        $old = $id ? self::row('people',$id) : null;
        $kind = $old ? $old['kind'] : self::text($b,'kind',true);
        self::need(in_array($kind,array('teacher','student','family','manager','secretary'),true),'نقش معتبر نیست.');
        if ($kind==='secretary') { self::manager_only(); }
        if ($kind === 'manager') { self::need(current_user_can('manage_options'),'تعریف یا ویرایش مدیر آموزشگاه فقط با مدیر اصلی وردپرس مجاز است.',403); }
        if ($old) {
            $u = get_userdata($old['user_id']);
            self::need($u && !user_can($u,'manage_options'),'این حساب قابل ویرایش نیست.',403);
        }
        $is_new = !$old;
        $national = Support::digits(self::text($b,'national_id',$is_new || in_array($kind,array('student','teacher','secretary'),true),20));
        self::need(!$is_new || $national !== '','برای ساخت حساب، کد ملی لازم است.');
        self::need($national === '' || preg_match('/^\d{10}$/',$national),'کد ملی باید ۱۰ رقم باشد.');
        $duplicate = null;
        if ($national) {
            $duplicate = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('people').' WHERE national_id=%s AND id<>%d',$national,$id),ARRAY_A);
        }
        $gender = $kind==='student' ? self::text($b,'gender',true,12) : (string)($old['gender'] ?? '');
        self::need($kind!=='student' || in_array($gender,array('female','male'),true),'جنسیت زبان‌آموز را انتخاب کنید.');
        // A teacher and a learner are one real person and therefore must not
        // receive two WordPress accounts or two rows with the same national ID.
        // When the student form finds a teacher, prepare that existing account
        // for class enrollment and preserve its teacher role and credentials.
        if ($duplicate) {
            if ($is_new && $kind==='secretary') { return self::grant_secretary_person($duplicate); }
            if ($is_new && !empty($duplicate['secretary_access']) && in_array($kind,array('teacher','student'),true) && ($duplicate['kind']==='secretary' || ($kind==='teacher' && $duplicate['kind']==='student'))) {
                self::manager_only();
                self::need(empty($duplicate['deleted_at']) && (int)$duplicate['active']===1,'ابتدا پروندهٔ این منشی را بازیابی یا فعال کنید.',409);
                $account=get_userdata((int)$duplicate['user_id']);
                self::need($account && !user_can($account,'manage_options'),'این حساب قابل تغییر نیست.',403);
                self::$touched_user=(int)$duplicate['user_id'];
                Support::checked($wpdb->update(Support::table('people'),array('kind'=>$kind,'gender'=>$kind==='student'?$gender:$duplicate['gender'],'updated_at'=>Support::now()),array('id'=>$duplicate['id'])));
                $code=$kind==='student'?self::ensure_student_code((int)$duplicate['user_id'],'secretary_student_form'):($duplicate['student_code'] ?? null);
                self::valid(wp_update_user(array('ID'=>(int)$duplicate['user_id'],'role'=>'nasr_'.$kind)));
                Support::audit('person_roles_updated','person',$duplicate['id'],array('before'=>$duplicate['kind'],'kind'=>$kind,'secretary_access'=>true));
                clean_user_cache((int)$duplicate['user_id']);
                return array('id'=>(int)$duplicate['id'],'kind'=>$kind,'student_code'=>$code,'reused_person'=>true,'message'=>'نقش جدید به همان حساب اضافه شد؛ نام کاربری و رمز عبور قبلی حفظ شدند.');
            }
            if ($is_new && $kind==='student' && $duplicate['kind']==='teacher') {
                self::need(empty($duplicate['deleted_at']),'این کد ملی متعلق به معلم حذف‌شده است؛ ابتدا پروندهٔ معلم را از بخش حذف‌شده‌ها بازیابی کنید.',409);
                self::need((int)$duplicate['active']===1,'این کد ملی متعلق به معلم غیرفعال است؛ ابتدا حساب معلم را فعال کنید.',409);
                self::need(get_userdata((int)$duplicate['user_id']),'حساب وردپرس این معلم پیدا نشد؛ ابتدا پروندهٔ معلم را بررسی کنید.',409);
                $already_ready=!empty($duplicate['student_code']);
                $student_code=self::ensure_student_code((int)$duplicate['user_id'],'student_form');
                if (empty($duplicate['gender'])) {
                    Support::checked($wpdb->update(Support::table('people'),array('gender'=>$gender,'updated_at'=>Support::now()),array('id'=>$duplicate['id'])));
                    Support::audit('person_updated','person',$duplicate['id'],array('kind'=>'teacher','student_gender_set'=>true,'via'=>'student_form'));
                }
                return array(
                    'id'=>(int)$duplicate['id'],
                    'kind'=>'teacher',
                    'student_code'=>$student_code,
                    'reused_teacher'=>true,
                    'message'=>$already_ready
                        ? 'این معلم از قبل برای ثبت‌نام به‌عنوان زبان‌آموز آماده است؛ همان حساب را از پنجرهٔ افزودن اعضای کلاس انتخاب کنید.'
                        : 'حساب جدید ساخته نشد؛ همان حساب معلم برای ثبت‌نام به‌عنوان زبان‌آموز آماده شد. اکنون او را از پنجرهٔ افزودن اعضای کلاس انتخاب کنید.'
                );
            }
            self::need(false,'این کد ملی قبلاً ثبت شده است؛ فهرست افراد و بخش حذف‌شده‌ها را بررسی کنید.',409);
        }
        $data = array('kind'=>$kind,'national_id'=>$national ?: null,'first_name'=>self::text($b,'first_name',true,100),'last_name'=>self::text($b,'last_name',true,100),
            'gender'=>$gender,
            'first_name_en'=>self::text($b,'first_name_en',false,100),'last_name_en'=>self::text($b,'last_name_en',false,100),
            'birth_certificate'=>Support::digits(self::text($b,'birth_certificate',false,50)),
            'mobile'=>self::valid(Support::phone(self::text($b,'mobile',true,30),true)),
            'landline'=>self::valid(Support::phone(self::text($b,'landline',false,30))),
            'extra_phone'=>self::valid(Support::phone(self::text($b,'extra_phone',false,30))),
            'address'=>self::textarea($b,'address',false,2000),'education'=>self::text($b,'education',false,150),
            'birth_date'=>null,'birth_year'=>null,
            'father_name'=>self::text($b,'father_name',false,150),'active'=>isset($b['active']) && !$b['active'] ? 0 : 1,'updated_at'=>Support::now());
        if ($is_new) { $data['secretary_access']=$kind==='secretary'?1:0; }
        self::need(Support::length($data['address']) <= 2000,'آدرس بیش از حد طولانی است.');
        foreach (array('first_name_en','last_name_en') as $key) {
            if (!array_key_exists($key,$b) && $old) { $data[$key]=$old[$key]; }
            self::need($data[$key]==='' || preg_match("/^[A-Za-z][A-Za-z .'-]*$/D",$data[$key]),'نام و نام خانوادگی انگلیسی را با حروف انگلیسی وارد کنید.');
        }
        self::need(empty($b['rate_term_id']) && (!isset($b['hourly_rate']) || $b['hourly_rate']===''),'حق‌التدریس به فرم کلاس منتقل شده؛ صفحه را تازه کنید.',409);
        if ($old && (int)$old['user_id'] === get_current_user_id()) { self::need($data['active'] === 1,'نمی‌توانید حساب خود را غیرفعال کنید.'); }
        $email = self::text($b,'email',false,190);
        self::need($email === '' || is_email($email),'ایمیل معتبر نیست.');
        $birth_date = array_key_exists('birth_date',$b) ? self::valid(Support::date(self::text($b,'birth_date',false,20),true)) : ($old['birth_date'] ?? null);
        $legacy_birth_year = null;
        if (!$birth_date && array_key_exists('birth_year',$b)) {
            $legacy_birth_year = self::valid(Support::number($b['birth_year'],1200,2200,0,true),'سال تولد');
        } elseif (!$birth_date && $old && $old['birth_year'] !== null) {
            $legacy_birth_year = (int)$old['birth_year'];
        }
        $data['birth_date'] = $birth_date; $data['birth_year'] = $legacy_birth_year;
        $user = array('first_name'=>$data['first_name'],'last_name'=>$data['last_name'],'display_name'=>$data['first_name'].' '.$data['last_name'],'user_email'=>$email);
        // ورود زبان‌آموز و معلم همیشه با کد ملی پرونده همگام است. این شرط
        // علاوه بر تغییرهای جدید، حساب‌های قدیمی با نام کاربری ناهماهنگ را
        // نیز هنگام ذخیرهٔ پرونده اصلاح می‌کند.
        $national_login=in_array($kind,array('student','teacher','secretary'),true);
        $national_changed = $old && $national_login && !hash_equals((string)($old['national_id'] ?? ''),$national);
        $sync_credentials = $old && $national_login && $national!=='' && ($national_changed || !hash_equals((string)$u->user_login,$national));
        if ($sync_credentials) {
            $owner=username_exists($national);
            self::need(!$owner || (int)$owner===(int)$old['user_id'],'نام کاربری متناظر با کد ملی جدید قبلاً استفاده شده است.',409);
        }
        // حساب‌های تازه با یک شناسهٔ ثابت و قابل تحویل ساخته می‌شوند: هر دو
        // نام کاربری و رمز اولیه، کد ملی هستند. این قاعده در سمت سرور enforce
        // می‌شود تا ارسال مقدار متفاوت از فرم هم نتواند آن را دور بزند.
        $password = $is_new || $sync_credentials ? $national : ($b['password'] ?? '');
        if (!$old || $password !== '') {
            self::need(Support::password_valid($password),'رمز عبور باید ۱۰ تا ۲۰۰ نویسه و بدون فاصله در ابتدا و انتها باشد.');
            $user['user_pass'] = $password;
        }
        if ($old) { $user['ID'] = (int)$old['user_id']; }
        else {
            $username = $national;
            self::need(preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{2,59}$/',$username),'نام کاربری باید ۳ تا ۶۰ نویسه و شامل حروف انگلیسی، عدد، نقطه، خط تیره یا زیرخط باشد.');
            self::need(!username_exists($username),'این نام کاربری قبلاً استفاده شده است.',409);
            $user['user_login'] = $username; $user['role'] = 'nasr_'.$kind;
        }
        if ($kind === 'family') {
            $ids = self::student_ids($b['student_ids'] ?? array(),false);
            if ($old) {
                // Editing a family must not silently discard hidden links to a
                // soft-deleted child; they become visible again after restore.
                $hidden=$wpdb->get_col($wpdb->prepare('SELECT l.student_id FROM '.Support::table('family_links').' l JOIN '.Support::table('people').' child ON child.user_id=l.student_id WHERE l.family_id=%d AND child.deleted_at IS NOT NULL',$old['user_id']));
                $ids=array_values(array_unique(array_merge($ids,array_map('intval',$hidden))));
            }
        }
        if ($old) { self::$touched_user = (int)$old['user_id']; }
        $uid = self::valid($old ? wp_update_user($user) : wp_insert_user($user));
        if ($sync_credentials) {
            // WordPress عمداً user_login را در wp_update_user تغییر نمی‌دهد؛
            // پس از کنترل یکتایی، مقدار ستون اصلی حساب به‌صورت تراکنشی عوض می‌شود.
            Support::checked($wpdb->update($wpdb->users,array('user_login'=>$national),array('ID'=>$uid)));
        }
        if (!$old) { self::$new_user = $uid; }
        if ($old) {
            Support::checked($wpdb->update(Support::table('people'),$data,array('id'=>$id)));
        } else {
            $data['user_id']=$uid; $data['created_at']=Support::now();
            if ($kind === 'student') {
                $data['student_code'] = self::next_student_code();
            }
            Support::checked($wpdb->insert(Support::table('people'),$data)); $id=(int)$wpdb->insert_id;
        }
        if ($kind === 'family') {
            Support::checked($wpdb->delete(Support::table('family_links'),array('family_id'=>$uid)));
            foreach ($ids as $sid) { Support::checked($wpdb->insert(Support::table('family_links'),array('family_id'=>$uid,'student_id'=>$sid))); }
        }
        Support::audit($old ? 'person_updated' : 'person_created','person',$id,array('kind'=>$kind,'active'=>$data['active'],'credentials_synced'=>(bool)$sync_credentials));
        if (!$data['active'] || $sync_credentials) { \WP_Session_Tokens::get_instance($uid)->destroy_all(); }
        clean_user_cache($uid);
        $account_label=$kind==='teacher'?'معلم':($kind==='secretary'?'منشی':'زبان‌آموز');
        return array('id'=>$id,'student_code'=>$old['student_code'] ?? ($data['student_code'] ?? null),'message'=>$sync_credentials?'اطلاعات ذخیره شد؛ نام کاربری و رمز عبور '.$account_label.' با کد ملی جدید همگام شد.':'اطلاعات ذخیره شد.');
    }
    private static function next_student_code() {
        global $wpdb;
        $incremented=$wpdb->query('UPDATE '.Support::table('sequences')." SET value=LAST_INSERT_ID(value+1) WHERE name='student'");
        self::need($incremented===1,'شمارندهٔ کد زبان‌آموزی در دسترس نیست.',500);
        $code=(int)$wpdb->get_var('SELECT LAST_INSERT_ID()');
        self::need($code>=10000,'تولید کد زبان‌آموزی انجام نشد.',500);
        return $code;
    }
    private static function ensure_student_code($user_id,$via='class_enrollment') {
        global $wpdb;
        $person=Support::profile($user_id);
        self::need($person && in_array($person['kind'],array('student','teacher'),true),'فرد قابل ثبت‌نام پیدا نشد.',404);
        if (!empty($person['student_code'])) { return (int)$person['student_code']; }
        $code=self::next_student_code();
        $changed=$wpdb->update(Support::table('people'),array('student_code'=>$code,'updated_at'=>Support::now()),array('id'=>$person['id']));
        self::need($changed===1,'ثبت کد زبان‌آموزی برای این فرد انجام نشد.',500);
        Support::audit('student_code_assigned','person',$person['id'],array('user_id'=>(int)$user_id,'kind'=>$person['kind'],'via'=>$via));
        return $code;
    }
    private static function student_ids($raw, $active = true, $allow_teachers = false) {
        self::need(is_array($raw) && count($raw) <= 500,'حداکثر ۵۰۰ نفر را در هر نوبت انتخاب کنید.');
        $ids = array_values(array_unique(array_map('absint',$raw)));
        $kinds=$allow_teachers ? array('student','teacher') : array('student');
        foreach ($ids as $id) {
            $p = Support::profile($id);
            self::need($id && $p && empty($p['deleted_at']) && in_array($p['kind'],$kinds,true) && (!$active || (int)$p['active']===1),$allow_teachers?'یکی از زبان‌آموزان یا معلم‌های انتخاب‌شده معتبر یا فعال نیست.':'یکی از زبان‌آموزان معتبر یا فعال نیست.');
        }
        return $ids;
    }
    private static function terms($r) {
        global $wpdb; self::admin();
        $where='t.deleted_at IS NULL';
        if ($r['branch_id']!==null && $r['branch_id']!=='') { $where.=$wpdb->prepare(' AND COALESCE(t.branch_id,0)=%d',absint($r['branch_id'])); }
        return $wpdb->get_results('SELECT t.*,b.name branch_name FROM '.Support::table('terms').' t LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id WHERE '.$where.' ORDER BY t.start_date DESC,t.id DESC',ARRAY_A);
    }
    private static function save_term($r) {
        global $wpdb; self::admin(); $b=self::body($r); $id=absint($r['id']);
        $old=$id ? self::row('terms',$id) : null;
        $data=array('code'=>Support::digits(self::text($b,'code',true,60)),'name'=>self::text($b,'name',true),'active'=>empty($b['active']) ? 0 : 1);
        $branch_id=absint($b['branch_id'] ?? ($old['branch_id'] ?? 0));
        self::need($branch_id || ($old && empty($old['branch_id'])),'قبل از ایجاد ترم، شعبه را انتخاب کنید.');
        if ($branch_id) {
            $branch=self::row('branches',$branch_id);
            self::need((int)$branch['active'] || ($old && (int)$old['branch_id']===$branch_id),'شعبه غیرفعال است.');
        }
        $data['branch_id']=$branch_id ?: null;
        foreach (array('start_date','end_date','mid_date','final_date') as $field) { $data[$field]=self::valid(Support::date($b[$field] ?? '',in_array($field,array('mid_date','final_date'),true))); }
        foreach (array('start_date','end_date','mid_date','final_date') as $field) {
            self::need(!$data[$field] || $data[$field]>='2020-03-20' || ($old && $old[$field]===$data[$field]),'تاریخ جدید ترم باید از ۱ فروردین ۱۳۹۹ به بعد باشد.');
        }
        self::need($data['start_date'] <= $data['end_date'],'تاریخ پایان نباید قبل از شروع باشد.');
        foreach (array('mid_date','final_date') as $field) { self::need(!$data[$field] || ($data[$field] >= $data['start_date'] && $data[$field] <= $data['end_date']),'تاریخ آزمون باید داخل بازهٔ ترم باشد.'); }
        self::need(!$data['mid_date'] || !$data['final_date'] || $data['mid_date'] <= $data['final_date'],'فاینال نباید قبل از میان‌ترم باشد.');
        self::need(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('terms').' WHERE code=%s AND id<>%d',$data['code'],$id)),'کد ترم تکراری است.',409);
        if ($id) { Support::checked($wpdb->update(Support::table('terms'),$data,array('id'=>$id))); }
        else { Support::checked($wpdb->insert(Support::table('terms'),$data)); $id=(int)$wpdb->insert_id; }
        Support::audit('term_saved','term',$id);
        if ($old && array_intersect_key($old,array_flip(array('start_date','end_date'))) != array_intersect_key($data,array_flip(array('start_date','end_date')))) SMS::record_event('schedule_changed',wp_generate_uuid4(),array('term_id'=>$id));
        return array('id'=>$id,'message'=>'ترم ذخیره شد.');
    }
    private static function class_terms($r) {
        global $wpdb;
        // Use the same scope as the class list, including the selected role.
        $rows=$wpdb->get_results('SELECT DISTINCT t.id,t.name,t.code,t.start_date,t.end_date,t.branch_id,(SELECT b.name FROM '.Support::table('branches').' b WHERE b.id=t.branch_id) branch_name'.self::class_sql().' WHERE '.self::class_scope().' ORDER BY t.start_date DESC,t.id DESC',ARRAY_A);
        self::need(is_array($rows) && !$wpdb->last_error,'بازیابی ترم‌های شما انجام نشد.',500);
        return $rows;
    }
    private static function classes($r) {
        global $wpdb;
        $where=self::class_scope();
        if ($r['branch_id']!==null && $r['branch_id']!=='') { $where.=$wpdb->prepare(' AND COALESCE(t.branch_id,0)=%d',absint($r['branch_id'])); }
        if ($r['term_id']) { $where.=$wpdb->prepare(' AND c.term_id=%d',absint($r['term_id'])); }
        if ($r['q']) {
            $q='%'.$wpdb->esc_like(sanitize_text_field($r['q'])).'%';
            $where.=$wpdb->prepare(' AND (c.name LIKE %s OR t.name LIKE %s)',$q,$q);
        }
        $from=self::class_sql().' WHERE '.$where;
        $fields=self::class_fields();
        // Roster counts are exposed only for classes the viewer manages or
        // teaches.  A teacher enrolled as a learner must not see classmate data.
        $role=Support::role();
        if (Support::staff()) {
            $fields.=',(SELECT COUNT(*) FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' student ON student.user_id=e.student_id WHERE e.class_id=c.id AND e.active=1 AND e.deleted_at IS NULL AND student.deleted_at IS NULL) student_count';
        } elseif ($role==='teacher') {
            $fields.=$wpdb->prepare(',IF(c.teacher_id=%d,(SELECT COUNT(*) FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' student ON student.user_id=e.student_id WHERE e.class_id=c.id AND e.active=1 AND e.deleted_at IS NULL AND student.deleted_at IS NULL),NULL) student_count',get_current_user_id());
        }
        $res=self::paged('SELECT '.$fields.$from.' ORDER BY c.active DESC,t.start_date DESC,c.id DESC','SELECT COUNT(*)'.$from,$r);
        foreach ($res['items'] as &$c) { self::trim_class($c); } unset($c);
        return $res;
    }
    private static function class_days() {
        return array('friday'=>'جمعه','saturday'=>'شنبه','sunday'=>'یکشنبه','monday'=>'دوشنبه','tuesday'=>'سه‌شنبه','wednesday'=>'چهارشنبه','thursday'=>'پنجشنبه');
    }
    private static function stored_class_days($c) {
        $raw=$c['class_days'] ?? '';
        $values=is_array($raw) ? $raw : explode(',',(string)$raw);
        if (!$raw && !empty($c['class_day'])) { $values=array($c['class_day']); }
        return array_values(array_intersect(array_keys(self::class_days()),$values));
    }
    private static function validate_class_days($raw) {
        self::need(is_array($raw) && count($raw)>=1 && count($raw)<=7,'حداقل یک و حداکثر هفت روز هفته را انتخاب کنید.');
        $allowed=self::class_days();
        foreach ($raw as $day) { self::need(is_string($day) && isset($allowed[$day]),'یکی از روزهای کلاس معتبر نیست.'); }
        return array_values(array_intersect(array_keys($allowed),array_unique($raw)));
    }
    private static function class_start_time($value) {
        self::need(is_scalar($value),'ساعت شروع معتبر نیست.');
        $value=trim(Support::digits($value));
        if (preg_match('/^\d{4}$/',$value)) { $value=substr($value,0,2).':'.substr($value,2,2); }
        self::need(preg_match('/^(\d{1,2}):(\d{2})$/',$value,$parts) && (int)$parts[1]<=23 && (int)$parts[2]<=59,'ساعت را به صورت ۲۴ساعته مانند 16:30 وارد کنید.');
        return sprintf('%02d:%02d',(int)$parts[1],(int)$parts[2]);
    }
    private static function class_schedule_label($selected, $hour, $minute, $duration) {
        $days=self::class_days(); $start=$hour*60+$minute; $end=$start+$duration;
        $next=$end>=1440; $end%=1440;
        $clock=function($minutes) { return sprintf('%02d:%02d',intdiv($minutes,60),$minutes%60); };
        $names=array();foreach ($selected as $day) { $names[]=$days[$day]; }
        return implode(' و ',$names).'، '.$clock($start).' تا '.$clock($end).' · '.$duration.' دقیقه'.($next?' (روز بعد)':'');
    }
    private static function trim_class(&$c) {
        $c['class_days']=self::stored_class_days($c);
        if (!Support::staff()) { unset($c['base_fee'],$c['extras_fee'],$c['late_minutes'],$c['hourly_rate']); }
        $c['total_max']=40+(float)$c['mid_max']+(float)$c['final_max'];
    }
    private static function save_class($r) {
        global $wpdb; self::admin(); $b=self::body($r); $id=absint($r['id']);
        $old=$id ? self::row('classes',$id) : null;
        $term=self::row('terms',$b['term_id'] ?? 0); $teacher=Support::profile(absint($b['teacher_id'] ?? 0));
        self::need((int)$term['active']===1 || ($old && (int)$old['term_id']===(int)$term['id']),'ترم غیرفعال است.');
        self::need($teacher && empty($teacher['deleted_at']) && $teacher['kind']==='teacher' && ((int)$teacher['active']===1 || ($old && (int)$old['teacher_id']===(int)$teacher['user_id'])),'معلم انتخاب‌شده معتبر یا فعال نیست.');
        $has_new_schedule=array_key_exists('class_days',$b) || array_key_exists('class_day',$b) || array_key_exists('start_time',$b) || array_key_exists('start_hour',$b) || array_key_exists('start_minute',$b) || array_key_exists('duration',$b);
        if (!$old || $has_new_schedule) {
            // Keep accepting the old single-day/hour/minute payload when updating older clients.
            $selected=self::validate_class_days(array_key_exists('class_days',$b) ? $b['class_days'] : (isset($b['class_day']) ? array($b['class_day']) : ($old ? self::stored_class_days($old) : array())));
            if (array_key_exists('start_time',$b)) {
                $start_time=self::class_start_time($b['start_time']);
                list($hour,$minute)=array_map('intval',explode(':',$start_time));
            } elseif (array_key_exists('start_hour',$b) || array_key_exists('start_minute',$b)) {
                $hour=self::valid(Support::number($b['start_hour'] ?? '',0,23,0),'ساعت شروع');
                $minute=self::valid(Support::number($b['start_minute'] ?? '',0,59,0),'دقیقه شروع');
                $start_time=sprintf('%02d:%02d',$hour,$minute);
            } else {
                $start_time=self::class_start_time($old['start_time'] ?? '');
                list($hour,$minute)=array_map('intval',explode(':',$start_time));
            }
            $duration=self::valid(Support::number($b['duration'] ?? '',15,480,0),'مدت کلاس');
            self::need($duration % 15 === 0,'مدت کلاس باید مضربی از ۱۵ دقیقه باشد.');
            $day=$selected[0];$schedule=self::class_schedule_label($selected,$hour,$minute,$duration);
        } else {
            $day=(string)($old['class_day'] ?? ''); $start_time=(string)($old['start_time'] ?? ''); $duration=$old['duration'] !== null ? (int)$old['duration'] : null;
            $selected=self::stored_class_days($old);
            $schedule=self::text($b,'schedule',false,250) ?: (string)$old['schedule'];
        }
        $planned=self::valid(Support::number($b['sessions_planned'] ?? ($old['sessions_planned'] ?? ''),1,1000,0,(bool)$old),'تعداد جلسات برنامه‌ریزی‌شده');
        $held=self::valid(Support::number($b['sessions_held'] ?? ($old['sessions_held'] ?? ''),0,1000,0,(bool)$old),'تعداد جلسات برگزارشده');
        $late=self::valid(Support::number($b['late_minutes'] ?? ($old['late_minutes'] ?? 0),0,480000),'کسورات به دقیقه');
        self::need($held===null || ($planned!==null && $held<=$planned),'جلسات برگزارشده نباید بیشتر از جلسات برنامه‌ریزی‌شده باشد.');
        self::need(!$late || ($held!==null && $duration && $late<=$held*$duration),'دقایق کسورات نباید بیشتر از کل زمان تدریس برگزارشده باشد.');
        $recorded=(int)($id ? $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(session_no),0) FROM '.Support::table('attendance_sessions').' WHERE class_id=%d',$id)) : 0);
        self::need(!$recorded || ($planned!==null && $planned>=$recorded),'تعداد جلسات از شمارهٔ جلسه‌های دارای حضور و غیاب کمتر است.');
        if ($old && ((int)$old['sessions_held']>0 || $recorded)) {
            self::need((int)$old['teacher_id']===(int)$teacher['user_id'] && (int)$old['term_id']===(int)$term['id'],'برای تغییر معلم یا ترم کلاس دارای سابقهٔ تدریس، کلاس جدید تعریف کنید.');
        }
        $data=array('term_id'=>(int)$term['id'],'teacher_id'=>(int)$teacher['user_id'],'name'=>self::text($b,'name',true),
            'sessions_planned'=>$planned,'sessions_held'=>$held,'late_minutes'=>$late,'hourly_rate'=>self::valid(Support::number($b['hourly_rate'] ?? ($old['hourly_rate'] ?? ''),0,1000000000,0,true),'حق‌التدریس ساعتی این کلاس (تومان)'),
            'base_fee'=>self::valid(Support::number($b['base_fee'] ?? '',0,1000000000000),'شهریه ثابت'),
            'extras_fee'=>self::valid(Support::number($b['extras_fee'] ?? '',0,1000000000000),'کپی و کتاب'),
            'schedule'=>$schedule,'class_day'=>$day,'class_days'=>implode(',',$selected),'start_time'=>$start_time,'duration'=>$duration,
            'room'=>self::text($b,'room',false,100),'notes'=>self::textarea($b,'notes',false,2000),'active'=>empty($b['active']) ? 0 : 1);
        if ($old) { Support::checked($wpdb->update(Support::table('classes'),$data,array('id'=>$id))); }
        else {
            $settings=Support::settings(); $data['mid_max']=$settings['mid_max']; $data['final_max']=$settings['final_max']; $data['created_at']=Support::now();
            Support::checked($wpdb->insert(Support::table('classes'),$data)); $id=(int)$wpdb->insert_id;
        }
        Support::audit('class_saved','class',$id,array('before'=>$old,'after'=>$data));
        if ($old && array_intersect_key($old,array_flip(array('class_days','start_time','duration','room','term_id'))) != array_intersect_key($data,array_flip(array('class_days','start_time','duration','room','term_id')))) {
            SMS::record_event('schedule_changed',wp_generate_uuid4(),array('class_id'=>$id));
        }
        return array('id'=>$id,'message'=>'کلاس ذخیره شد. شهریهٔ ثبت‌نام‌های قبلی ثابت می‌ماند.');
    }
    private static function class_detail($r) {
        global $wpdb;
        $c=$wpdb->get_row($wpdb->prepare('SELECT '.self::class_fields().self::class_sql().' WHERE c.id=%d AND '.self::class_scope(),absint($r['id'])),ARRAY_A);
        self::need($c,'این کلاس در دسترس شما نیست.',404);
        $role=Support::role();
        $staff_view=Support::staff() || ($role==='teacher' && (int)$c['teacher_id']===get_current_user_id());
        $where=$wpdb->prepare('e.class_id=%d AND e.deleted_at IS NULL AND p.deleted_at IS NULL',$c['id']);
        if (!$staff_view) { $where.=' AND '.self::student_scope(); }
        $rows=$wpdb->get_results('SELECT e.*,p.first_name,p.last_name,p.first_name_en,p.last_name_en,p.student_code FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id WHERE '.$where.' ORDER BY p.last_name,p.first_name',ARRAY_A);
        foreach ($rows as &$e) {
            $e['grades']=self::grades($e,$c);
            foreach (array('listening','speaking','reading','writing','midterm','final_exam','feedback','grade_version','grade_updated_at','grade_updated_by') as $key) { unset($e[$key]); }
            if ($staff_view && $role==='teacher') { foreach (array('base_fee','extras_fee','discount','discount_scope','net_fee','snack_stationery_fee','private_fee','other_fee','finance_version') as $key) { unset($e[$key]); } }
            else {
                $e['payments']=$wpdb->get_results($wpdb->prepare('SELECT id,amount,status,method,reference,paid_on,notes,created_at,voided_at,void_reason FROM '.Support::table('payments').' WHERE enrollment_id=%d ORDER BY id DESC',$e['id']),ARRAY_A);
                $e['paid']=0;
                foreach ($e['payments'] as $payment) { if ($payment['status']==='success' && !$payment['voided_at']) { $e['paid']+=(int)$payment['amount']; } }
                $e['balance']=max(0,(int)$e['net_fee']-$e['paid']); $e['credit']=max(0,$e['paid']-(int)$e['net_fee']);
            }
        } unset($e);
        self::trim_class($c);
        return array('class'=>$c,'enrollments'=>$rows,'viewer_mode'=>$staff_view?'staff':'student');
    }
    private static function grades($e,$c) {
        $g=array('version'=>(int)$e['grade_version'],'updated_at'=>$e['grade_updated_at'],'feedback'=>$e['feedback']);
        $sum=0; $complete=true; $skills=0; $skills_complete=true;
        foreach (array('listening','speaking','reading','writing','midterm','final_exam') as $i=>$key) {
            $g[$key]=$e[$key] === null ? null : (float)$e[$key];
            if ($g[$key]===null) { $complete=false; if ($i<4) { $skills_complete=false; } }
            else { $sum+=(float)$g[$key]; if ($i<4) { $skills+=$g[$key]; } }
        }
        $g['classwork']=$skills_complete ? round($skills,1) : null;
        $g['partial']=round($sum,1); $g['total']=$complete ? round($sum,1) : null;
        $g['total_max']=40+(float)$c['mid_max']+(float)$c['final_max'];
        return $g;
    }
    private static function enroll($r) {
        global $wpdb; self::admin(); $b=self::body($r); $c=self::row('classes',$b['class_id'] ?? 0);
        self::row('terms',$c['term_id']);
        self::need((int)$c['active']===1,'کلاس بایگانی شده است.');
        $ids=self::student_ids($b['student_ids'] ?? array(),true,true); self::need(count($ids)>0,'حداقل یک زبان‌آموز یا معلم انتخاب کنید.');
        return self::enroll_students($c,$ids,$b);
    }
    private static function enroll_students($c,$ids,$b) {
        global $wpdb;
        $discount=self::valid(Support::number($b['discount'] ?? 0,0,100,1),'تخفیف');
        $scope=$b['discount_scope'] ?? Support::settings()['discount_scope']; self::need(in_array($scope,array('base','total'),true),'مبنای تخفیف معتبر نیست.');
        $added=0; $skipped=0; $restored=0;
        foreach ($ids as $sid) {
            // Teachers keep their teacher account/role, but receive a student
            // code the first time they are enrolled so reports and attendance
            // remain consistent with the rest of the roster.
            self::ensure_student_code($sid);
            $exists=$wpdb->get_row($wpdb->prepare('SELECT id,deleted_at FROM '.Support::table('enrollments').' WHERE class_id=%d AND student_id=%d',$c['id'],$sid),ARRAY_A);
            if ($exists && empty($exists['deleted_at'])) { $skipped++; continue; }
            if ($exists) {
                Support::checked($wpdb->update(Support::table('enrollments'),array('deleted_at'=>null,'active'=>1),array('id'=>$exists['id'])));
                Support::audit('record_restored','enrollments',$exists['id'],array('via'=>'enroll','financial_history_preserved'=>true));
                SMS::record_event('enrolled',wp_generate_uuid4(),array('enrollment_id'=>$exists['id']));
                $restored++; continue;
            }
            $d=$discount;
            if (isset($b['discounts'][$sid])) { $d=self::valid(Support::number($b['discounts'][$sid],0,100,1),'تخفیف'); }
            $data=array('class_id'=>$c['id'],'student_id'=>$sid,'base_fee'=>$c['base_fee'],'extras_fee'=>$c['extras_fee'],'discount'=>$d,'discount_scope'=>$scope,
                'net_fee'=>Support::price((int)$c['base_fee'],(int)$c['extras_fee'],$d,$scope),'feedback'=>'','created_at'=>Support::now());
            Support::checked($wpdb->insert(Support::table('enrollments'),$data));
            $enrollment_id=(int)$wpdb->insert_id;
            Support::audit('enrolled','enrollment',$enrollment_id,array('class_id'=>(int)$c['id'],'discount'=>$d,'net_fee'=>$data['net_fee'])); $added++;
            SMS::record_event('enrolled','new:'.$enrollment_id,array('enrollment_id'=>$enrollment_id));
        }
        return array('added'=>$added,'skipped'=>$skipped,'restored'=>$restored,'message'=>$added.' نفر اضافه شد؛ '.$skipped.' نفر از قبل ثبت‌نام داشتند؛ '.$restored.' ثبت‌نام حذف‌شده با شهریه، تخفیف و سوابق قبلی بازگشت.');
    }
    private static function copy_classes($r) {
        global $wpdb; self::admin();
        $term=self::row('terms',$r['term_id']);$target=(int)$r['target']===1;
        if ($target) { self::need((int)$term['active']===1,'ترم مقصد غیرفعال است.',409); }
        $where=self::class_scope().$wpdb->prepare(' AND c.term_id=%d',$term['id']);
        if ($target) { $where.=' AND c.active=1'; }
        $items=$wpdb->get_results('SELECT c.id,c.name,c.term_id,c.active,c.base_fee,c.extras_fee,c.class_days,c.class_day,c.start_time,c.duration,c.schedule'.self::class_sql().' WHERE '.$where.' ORDER BY c.name,c.id',ARRAY_A);
        foreach ($items as &$item) { $item['class_days']=self::stored_class_days($item); } unset($item);
        return array('items'=>$items);
    }
    private static function copy_target($class_id) {
        $c=self::row('classes',$class_id);$term=self::row('terms',$c['term_id']);
        self::need((int)$c['active']===1 && (int)$term['active']===1,'کلاس و ترم مقصد باید فعال باشند.',409);
        $teacher=Support::profile($c['teacher_id']);
        self::need($teacher && empty($teacher['deleted_at']),'معلم کلاس مقصد در دسترس نیست.',409);
        return $c;
    }
    private static function copy_roster($r) {
        global $wpdb;self::admin();
        $source=self::row('classes',$r['source_class_id']);self::row('terms',$source['term_id']);
        $target_id=absint($r['target_class_id']);
        if ($target_id) {
            self::need($target_id!==(int)$source['id'],'کلاس مبدأ و مقصد نباید یکسان باشند.');
            self::copy_target($target_id);
        }
        // Only the selected class roster is loaded, without grades, phone numbers or payments.
        $sql='SELECT p.user_id,p.kind,p.first_name,p.last_name,p.student_code,p.active,e.active enrollment_active,
            (existing.id IS NOT NULL AND existing.deleted_at IS NULL) already_enrolled,
            (existing.id IS NOT NULL AND existing.deleted_at IS NOT NULL) rejoin
            FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id
            LEFT JOIN '.Support::table('enrollments').' existing ON existing.student_id=e.student_id AND existing.class_id=%d
            WHERE e.class_id=%d AND e.deleted_at IS NULL AND p.deleted_at IS NULL AND p.kind IN (\'student\',\'teacher\') ORDER BY p.last_name,p.first_name,p.user_id';
        $items=$wpdb->get_results($wpdb->prepare($sql,$target_id,$source['id']),ARRAY_A);
        return array('items'=>$items,'source_class_id'=>(int)$source['id'],'target_class_id'=>$target_id);
    }
    private static function copy_enrollments($r) {
        global $wpdb;self::admin();$b=self::body($r);
        $source=self::row('classes',$b['source_class_id'] ?? 0);self::row('terms',$source['term_id']);
        $target=self::copy_target($b['target_class_id'] ?? 0);
        self::need((int)$source['term_id']===absint($b['source_term_id'] ?? 0) && (int)$target['term_id']===absint($b['target_term_id'] ?? 0),'کلاس انتخاب‌شده متعلق به ترم انتخاب‌شده نیست.');
        self::need((int)$source['id']!==(int)$target['id'],'کلاس مبدأ و مقصد نباید یکسان باشند.');
        $ids=self::student_ids($b['student_ids'] ?? array(),true,true);self::need(count($ids)>0,'حداقل یک زبان‌آموز یا معلم انتخاب کنید.');
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $members=$wpdb->get_col($wpdb->prepare('SELECT student_id FROM '.Support::table('enrollments').' WHERE class_id=%d AND deleted_at IS NULL AND student_id IN ('.$placeholders.')',array_merge(array($source['id']),$ids)));
        self::need(count($members)===count($ids),'عضویت یکی از افراد در کلاس مبدأ تغییر کرده است. فهرست را تازه کنید و دوباره انتخاب کنید.',409);
        // The shared enrollment routine uses target tuition and inserts blank grades;
        // it never changes the source enrollment or copies source payments.
        $result=self::enroll_students($target,$ids,$b);
        Support::audit('enrollments_copied','class',$target['id'],array('source_class_id'=>(int)$source['id'],'selected'=>count($ids),'added'=>$result['added'],'skipped'=>$result['skipped'],'restored'=>$result['restored']));
        $result['class_id']=(int)$target['id'];
        $result['message'].=' عضویت و سوابق کلاس مبدأ حفظ شد.';
        return $result;
    }
    private static function save_enrollment($r) {
        global $wpdb; self::admin(); $b=self::body($r); $e=self::row('enrollments',$r['id']);
        self::live_enrollment($e);
        $version=self::valid(Support::number($b['finance_version'] ?? '',0,2147483646));
        self::need($version===(int)$e['finance_version'],'شهریه در پنجرهٔ دیگری تغییر کرده؛ پرونده را دوباره باز کنید.',409);
        $discount=self::valid(Support::number($b['discount'] ?? $e['discount'],0,100,1),'تخفیف');
        $scope=$b['discount_scope'] ?? $e['discount_scope'];self::need(in_array($scope,array('base','total'),true),'مبنای تخفیف معتبر نیست.');
        $data=array('discount'=>$discount,'discount_scope'=>$scope,'active'=>isset($b['active'])?(!empty($b['active'])?1:0):(int)$e['active'],'finance_version'=>$version+1);
        foreach(array('snack_stationery_fee','private_fee','other_fee') as $key)$data[$key]=self::valid(Support::number($b[$key] ?? $e[$key],0,1000000000000),'هزینهٔ اضافه');
        $data['net_fee']=Support::price((int)$e['base_fee'],(int)$e['extras_fee'],$discount,$scope)+$data['snack_stationery_fee']+$data['private_fee']+$data['other_fee'];
        self::need($data['net_fee']<=2000000000000,'مجموع شهریه و هزینه‌ها از حد مجاز بیشتر است.');
        Support::checked($wpdb->update(Support::table('enrollments'),$data,array('id'=>$e['id'])));
        Support::audit('enrollment_updated','enrollment',$e['id'],array('before'=>array_intersect_key($e,$data),'after'=>$data));
        return array('message'=>'شهریه، هزینه‌های اضافه و تخفیف ذخیره شد.','net_fee'=>$data['net_fee']);
    }
    private static function save_grades($r) {
        global $wpdb; $role=Support::role();
        self::need(in_array($role,array('teacher','manager','secretary'),true),'اجازهٔ ثبت نمره ندارید.',403);
        $b=self::body($r); $e=self::row('enrollments',$r['id']); $c=self::live_enrollment($e);
        self::need(Support::staff() || (int)$c['teacher_id']===get_current_user_id(),'این کلاس به شما اختصاص ندارد.',403);
        self::need(Support::staff() || ((int)$c['active']===1 && (int)$e['active']===1),'کلاس یا ثبت‌نام بایگانی شده است.');
        self::need(isset($b['version']) && (int)$b['version']===(int)$e['grade_version'],'نمره‌ها در پنجره‌ای دیگر تغییر کرده‌اند. صفحه را تازه کنید.',409);
        $data=array();
        $caps=array('listening'=>10,'speaking'=>10,'reading'=>10,'writing'=>10,'midterm'=>(float)$c['mid_max'],'final_exam'=>(float)$c['final_max']);
        foreach ($caps as $key=>$max) { $data[$key]=self::valid(Support::number($b[$key] ?? null,0,$max,1,true),$key); }
        $data['feedback']=self::textarea($b,'feedback',false,2000);
        $data['grade_version']=(int)$e['grade_version']+1; $data['grade_updated_at']=Support::now(); $data['grade_updated_by']=get_current_user_id();
        $updated=Support::checked($wpdb->update(Support::table('enrollments'),$data,array('id'=>$e['id'],'grade_version'=>$e['grade_version'])));
        self::need($updated===1,'نمره‌ها هم‌زمان تغییر کرده‌اند؛ نسخهٔ جدید را بررسی کنید.',409);
        Support::audit('grades_saved','enrollment',$e['id'],array('before'=>array_intersect_key($e,$data),'after'=>$data));
        $grade_changed=false;
        foreach ($caps as $key=>$max) if (($e[$key]===null)!==($data[$key]===null) || (float)$e[$key] !== (float)$data[$key]) { $grade_changed=true;break; }
        if ($grade_changed) SMS::record_event('grades_saved',$e['id'].':'.$data['grade_version'],array('enrollment_id'=>$e['id']));
        return array('grades'=>self::grades(array_merge($e,$data),$c),'message'=>'نمره‌ها ذخیره و در پنل زبان‌آموز و خانواده نمایش داده شد.');
    }
    private static function payment_options($r) {
        global $wpdb;
        self::need(in_array(Support::role(),array('manager','secretary','student','family'),true),'اجازهٔ مشاهدهٔ پرداخت ندارید.',403);
        // History keeps its existing scope, including archived records. A learner
        // or family only receives terms for their own permitted enrollments.
        $where=Support::staff()?'1=1':'EXISTS (SELECT 1 FROM '.Support::table('enrollments').' e JOIN '.Support::table('classes').' c ON c.id=e.class_id WHERE c.term_id=t.id AND '.self::student_scope().')';
        $terms=$wpdb->get_results('SELECT t.id,t.name,t.code,t.start_date,t.end_date,t.branch_id,t.deleted_at,b.name branch_name FROM '.Support::table('terms').' t LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id WHERE '.$where.' ORDER BY t.start_date DESC,t.id DESC',ARRAY_A);
        self::need(is_array($terms) && !$wpdb->last_error,'خواندن ترم‌های سوابق پرداخت انجام نشد.',500);
        $branches=array();
        foreach ($terms as $term) $branches[(int)$term['branch_id']]=array('id'=>(int)$term['branch_id'],'name'=>$term['branch_name'] ?: 'تعیین نشده');
        return array('terms'=>$terms,'branches'=>array_values($branches));
    }
    private static function payments($r) {
        global $wpdb;
        self::need(in_array(Support::role(),array('manager','secretary','student','family'),true),'اجازهٔ مشاهدهٔ پرداخت ندارید.',403);
        if ($r['branch_id']===null || $r['branch_id']==='' || $r['term_id']===null || $r['term_id']==='') return array('items'=>array(),'total'=>0,'page'=>1,'pages'=>1,'selection_required'=>true);
        $branch_id=self::valid(Support::number($r['branch_id'],0,PHP_INT_MAX),'شعبه');
        $term_id=self::valid(Support::number($r['term_id'],1,PHP_INT_MAX),'ترم');
        $term=self::row('terms',$term_id,true);
        self::need((int)$term['branch_id']===$branch_id,'ترم انتخاب‌شده متعلق به این شعبه نیست.');
        $from=' FROM '.Support::table('payments').' pay JOIN '.Support::table('enrollments').' e ON e.id=pay.enrollment_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id JOIN '.Support::table('people').' p ON p.user_id=e.student_id WHERE '.self::student_scope();
        $from.=$wpdb->prepare(' AND t.id=%d AND COALESCE(t.branch_id,0)=%d',$term_id,$branch_id);
        if (in_array($r['status'],array('success','pending','failed'),true)) { $from.=$wpdb->prepare(' AND pay.status=%s',$r['status']); }
        return self::paged("SELECT pay.id,pay.enrollment_id,pay.amount,pay.status,pay.method,pay.reference,pay.paid_on,pay.notes,pay.voided_at,pay.void_reason,c.id class_id,c.name class_name,t.name term_name,CONCAT(p.first_name,' ',p.last_name) student_name,p.student_code,(c.deleted_at IS NOT NULL OR t.deleted_at IS NOT NULL OR p.deleted_at IS NOT NULL OR e.deleted_at IS NOT NULL) removed".$from.' ORDER BY pay.id DESC','SELECT COUNT(*)'.$from,$r);
    }
    private static function payment_receipt($r) {
        global $wpdb;
        self::need(in_array(Support::role(),array('manager','secretary','student','family'),true),'اجازهٔ مشاهدهٔ رسید ندارید.',403);
        // History remains accessible after soft deletion, with the same owner
        // and family-link checks used by payment history. Never accept client amounts.
        $sql="SELECT pay.id,pay.enrollment_id,pay.amount,pay.status,pay.method,pay.reference,pay.paid_on,pay.notes,pay.voided_at,pay.void_reason,c.id class_id,c.name class_name,t.name term_name,t.code term_code,COALESCE(b.name,'') branch_name,CONCAT(p.first_name,' ',p.last_name) student_name,p.student_code
            FROM ".Support::table('payments').' pay JOIN '.Support::table('enrollments').' e ON e.id=pay.enrollment_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id JOIN '.Support::table('people').' p ON p.user_id=e.student_id LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id WHERE '.self::student_scope().$wpdb->prepare(' AND pay.id=%d',absint($r['id']));
        $payment=$wpdb->get_row($sql,ARRAY_A);
        self::need(!$wpdb->last_error,'بازیابی رسید پرداخت انجام نشد.',500);
        self::need($payment,'رسید پرداخت در دسترس شما نیست.',404);
        self::need($payment['status']==='success','رسید فقط برای پرداخت موفق صادر می‌شود.',409);
        $settings=Support::settings();
        return array('payment'=>$payment,'academy_name'=>$settings['academy_name'],'currency'=>$settings['currency']);
    }
    private static function payment_checkout($r) { return Payments::checkout($r); }
    private static function payment_start($r) { return Payments::start($r); }
    private static function payment_gateway_settings($r) { return Payments::admin_settings(); }
    private static function save_payment_gateway_settings($r) { return Payments::save_admin_settings($r); }
    private static function save_payment($r) {
        global $wpdb; self::admin(); $b=self::body($r); $e=self::row('enrollments',$b['enrollment_id'] ?? 0);
        self::live_enrollment($e);
        $key=self::text($b,'request_key',true,64); self::need(preg_match('/^[a-zA-Z0-9_-]{16,64}$/',$key),'شناسهٔ درخواست معتبر نیست.');
        $existing=$wpdb->get_row($wpdb->prepare('SELECT id,enrollment_id FROM '.Support::table('payments').' WHERE request_key=%s',$key),ARRAY_A);
        if ($existing) {
            self::need((int)$existing['enrollment_id']===(int)$e['id'],'شناسهٔ درخواست تکراری است.',409);
            return array('id'=>(int)$existing['id'],'message'=>'این پرداخت قبلاً ثبت شده است.');
        }
        $amount=self::valid(Support::number($b['amount'] ?? '',1,2000000000000),'مبلغ');
        $status=self::text($b,'status',true); self::need(in_array($status,array('success','pending','failed'),true),'وضعیت پرداخت معتبر نیست.');
        $method=self::text($b,'method',true); self::need(in_array($method,array('cash','card','transfer','manual'),true),'روش پرداخت معتبر نیست.');
        if ($status==='success') {
            $paid=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(amount),0) FROM '.Support::table('payments')." WHERE enrollment_id=%d AND status='success' AND voided_at IS NULL",$e['id']));
            self::need($amount <= max(0,(int)$e['net_fee']-$paid),'مبلغ پرداخت موفق از ماندهٔ بدهی بیشتر است.');
        }
        $data=array('enrollment_id'=>$e['id'],'amount'=>$amount,'status'=>$status,'method'=>$method,'reference'=>self::text($b,'reference',false,120),
            'paid_on'=>self::valid(Support::date($b['paid_on'] ?? '')),'notes'=>self::textarea($b,'notes',false,2000),'request_key'=>$key,'created_by'=>get_current_user_id(),'created_at'=>Support::now(),'void_reason'=>'');
        Support::checked($wpdb->insert(Support::table('payments'),$data)); $id=(int)$wpdb->insert_id;
        Support::audit('payment_recorded','payment',$id,array('amount'=>$amount,'status'=>$status,'enrollment_id'=>(int)$e['id']));
        if ($status==='success') SMS::record_event('payment_received','payment:'.$id,array('enrollment_id'=>$e['id'],'extra'=>array('payment_id'=>$id,'payment_amount'=>(string)$amount,'payment_date'=>SMS::date_label($data['paid_on']))));
        return array('id'=>$id,'message'=>'سابقهٔ پرداخت ثبت شد.');
    }
    private static function void_payment($r) {
        global $wpdb; self::admin(); $b=self::body($r); $p=self::row('payments',$r['id']);
        self::need(!$p['voided_at'],'این سابقه قبلاً باطل شده است.');
        $reason=self::text($b,'reason',true,500);
        Support::checked($wpdb->update(Support::table('payments'),array('voided_at'=>Support::now(),'void_reason'=>$reason),array('id'=>$p['id'])));
        Support::audit('payment_voided','payment',$p['id'],array('reason'=>$reason,'amount'=>$p['amount'],'enrollment_id'=>$p['enrollment_id']));
        return array('message'=>'سابقه با حفظ تاریخچه باطل شد. وجهی از این طریق مسترد نمی‌شود.');
    }
    private static function save_settings($r) {
        global $wpdb; self::admin(); $b=self::body($r); $old=Support::settings();
        if (array_key_exists('branches',$b)) { self::save_branches($b['branches']); }
        $education_options=$old['education_options'];
        if (array_key_exists('education_options',$b)) {
            self::need(is_scalar($b['education_options']) || is_array($b['education_options']),'فهرست مدارک تحصیلی معتبر نیست.');
            $raw=is_array($b['education_options']) ? $b['education_options'] : preg_split('/\R/u',sanitize_textarea_field((string)$b['education_options']));
            $education_options=array();
            foreach ($raw as $option) {
                self::need(is_scalar($option),'یکی از مدارک تحصیلی معتبر نیست.');
                $option=trim(sanitize_text_field((string)$option));
                if ($option==='') { continue; }
                self::need(Support::length($option)<=100,'عنوان مدرک تحصیلی بیش از حد طولانی است.');
                $education_options[]=$option;
            }
            $education_options=array_values(array_unique($education_options));
            self::need(count($education_options)>=1 && count($education_options)<=50,'حداقل یک و حداکثر ۵۰ گزینهٔ مدرک تحصیلی وارد کنید.');
        }
        $s=array('academy_name'=>self::text($b,'academy_name',true,100),'currency'=>self::text($b,'currency',true,10),'calendar'=>self::text($b,'calendar',true,10),
            'mid_max'=>self::valid(Support::number($b['mid_max'] ?? '',1,100,1)),'final_max'=>self::valid(Support::number($b['final_max'] ?? '',1,100,1)),
            'discount_scope'=>self::text($b,'discount_scope',true,10),'support_phone'=>self::valid(Support::phone(self::text($b,'support_phone',false,30))),'education_options'=>$education_options);
        self::need(in_array($s['currency'],array('تومان','ریال'),true) && in_array($s['calendar'],array('jalali','gregorian'),true) && in_array($s['discount_scope'],array('base','total'),true),'تنظیمات انتخاب‌شده معتبر نیست.');
        if ($s['currency'] !== $old['currency']) {
            self::need(!(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Support::table('classes')),'واحد پول پس از ایجاد کلاس قابل تغییر نیست؛ این کار معنای مبالغ قبلی را تغییر می‌دهد.');
        }
        if (isset($b['preferences'])) { Preferences::save($b['preferences']); }
        update_option('nasr_academy_settings',$s,false);
        Support::audit('settings_saved','settings',0,array('before'=>$old,'after'=>$s));
        return array('message'=>'تنظیمات ذخیره شد. سقف نمرات جدید فقط برای کلاس‌های بعدی اعمال می‌شود.');
    }
    private static function audit($r) {
        global $wpdb; self::admin();
        return self::paged('SELECT a.*,COALESCE(u.display_name,\'کاربر حذف‌شده\') actor_name FROM '.Support::table('audit').' a LEFT JOIN '.$wpdb->users.' u ON u.ID=a.actor_id ORDER BY a.id DESC','SELECT COUNT(*) FROM '.Support::table('audit'),$r);
    }
    private static function password($r) {
        $b=self::body($r); $u=wp_get_current_user();
        $current=$b['current'] ?? ''; $next=$b['next'] ?? '';
        self::need(is_string($current) && wp_check_password($current,$u->user_pass,$u->ID),'رمز فعلی درست نیست.');
        self::need(Support::password_valid($next),'رمز جدید باید ۱۰ تا ۲۰۰ نویسه و بدون فاصله در ابتدا و انتها باشد.');
        self::need(!isset($b['confirm']) || (is_string($b['confirm']) && hash_equals($next,$b['confirm'])),'رمز جدید و تکرار آن یکسان نیستند.');
        Support::audit('password_changed','user',$u->ID);
        wp_set_password($next,$u->ID); \WP_Session_Tokens::get_instance($u->ID)->destroy_all();
        return array('message'=>'رمز تغییر کرد. با رمز جدید وارد شوید.','relogin'=>true);
    }
}
