<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/** Permanent deletion is restricted to the trash, with a server-side preview. */
trait Trash {
    private static $purge_locks = array();

    private static function purge_ids($rows,$key='id') {
        return array_values(array_unique(array_map('intval',array_column($rows,$key))));
    }
    private static function purge_in($column,$ids) {
        return $ids ? $column.' IN ('.implode(',',array_map('intval',$ids)).')' : '1=0';
    }
    private static function purge_read($table,$where) {
        global $wpdb;
        $rows=$wpdb->get_results('SELECT * FROM '.Support::table($table).' WHERE '.$where.' ORDER BY id',ARRAY_A);
        self::need(is_array($rows) && !$wpdb->last_error,'خواندن وابستگی‌های حذف انجام نشد.',500);
        return $rows;
    }
    private static function purge_context_matches($raw,$ids) {
        $context=json_decode($raw,true);
        if (!is_array($context)) return false;
        foreach (array('term_id'=>'terms','class_id'=>'classes','enrollment_id'=>'enrollments','student_id'=>'users','teacher_id'=>'users','user_id'=>'users') as $key=>$group) {
            if (isset($context[$key]) && is_scalar($context[$key]) && in_array((int)$context[$key],$ids[$group],true)) return true;
        }
        foreach (array('payment_id'=>'payments','attendance_session_id'=>'attendance_sessions') as $key=>$group) {
            if (isset($context['extra'][$key]) && is_scalar($context['extra'][$key]) && in_array((int)$context['extra'][$key],$ids[$group],true)) return true;
        }
        return false;
    }
    private static function purge_plan($r) {
        global $wpdb; self::admin();
        $entity=(string)$r['entity'];
        self::need(in_array($entity,array('people','classes','terms','enrollments'),true),'نوع مورد معتبر نیست.');
        $row=self::row($entity,$r['id'],true);
        self::need(!empty($row['deleted_at']),'حذف همیشگی فقط از بخش حذف‌شده‌ها انجام می‌شود.',409);
        $uid=$entity==='people'?(int)$row['user_id']:0;
        if ($uid) {
            $user=get_userdata($uid);
            self::need(in_array($row['kind'],array('teacher','student'),true) && $uid!==get_current_user_id() && (!$user || !user_can($user,'manage_options')),'حذف این حساب مجاز نیست.',403);
        }
        $records=array('people'=>$uid?array($row):array(),'terms'=>$entity==='terms'?array($row):array());
        $class_where=$entity==='classes'?'id='.(int)$row['id']:($entity==='terms'?'term_id='.(int)$row['id']:($uid?'teacher_id='.$uid:'1=0'));
        $records['classes']=self::purge_read('classes',$class_where);
        foreach ($records['classes'] as $class) self::need(!empty($class['deleted_at']),'ابتدا کلاس‌های وابسته را به حذف‌شده‌ها منتقل کنید یا وابستگی آن‌ها را تغییر دهید.',409);
        $classes=self::purge_ids($records['classes']);
        $records['enrollments']=self::purge_read('enrollments','('.self::purge_in('class_id',$classes).')'.($uid?' OR student_id='.$uid:'').($entity==='enrollments'?' OR id='.(int)$row['id']:''));
        $enrollments=self::purge_ids($records['enrollments']);
        $records['attendance_sessions']=self::purge_read('attendance_sessions',self::purge_in('class_id',$classes));
        $records['attendance']=self::purge_read('attendance','('.self::purge_in('enrollment_id',$enrollments).') OR ('.self::purge_in('session_id',self::purge_ids($records['attendance_sessions'])).')');
        $records['payments']=self::purge_read('payments',self::purge_in('enrollment_id',$enrollments));
        $records['payment_transactions']=self::purge_read('payment_transactions',self::purge_in('enrollment_id',$enrollments));
        foreach ($records['payment_transactions'] as $tx) self::need(in_array($tx['status'],array('paid','failed'),true),'تراکنش اینترنتی باز وجود دارد؛ ابتدا وضعیت پرداخت را مشخص کنید.',409);
        $ledger_where=$uid?'teacher_id='.$uid:($entity==='terms'?'term_id='.(int)$row['id']:'1=0');
        $records['teacher_rates']=self::purge_read('teacher_rates',$ledger_where);
        $records['payroll_adjustments']=self::purge_read('payroll_adjustments',$ledger_where);
        $records['family_links']=self::purge_read('family_links',$uid?'student_id='.$uid.' OR family_id='.$uid:'1=0');
        $ids=array('users'=>$uid?array($uid):array(),'terms'=>self::purge_ids($records['terms']),'classes'=>$classes,'enrollments'=>$enrollments,'payments'=>self::purge_ids($records['payments']),'attendance_sessions'=>self::purge_ids($records['attendance_sessions']));
        $records['sms_messages']=array();
        foreach (self::purge_read('sms_messages','1=1') as $message) {
            if (in_array((int)$message['enrollment_id'],$enrollments,true) || ($uid && ((int)$message['user_id']===$uid || (int)$message['student_id']===$uid)) || self::purge_context_matches($message['context_json'],$ids)) $records['sms_messages'][]=$message;
        }
        $records['sms_events']=array();
        foreach (self::purge_read('sms_events','1=1') as $event) if (self::purge_context_matches($event['context_json'],$ids)) $records['sms_events'][]=$event;
        $labels=array('people'=>'پروندهٔ فرد','terms'=>'ترم','classes'=>'کلاس','enrollments'=>'عضویت، شهریه و نمرات','attendance_sessions'=>'جلسهٔ حضور و غیاب','attendance'=>'وضعیت حضور و غیاب','payments'=>'سابقه و رسید پرداخت','payment_transactions'=>'تراکنش اینترنتی','teacher_rates'=>'نرخ معلم','payroll_adjustments'=>'جدول اضافه و کاهش حقوق','family_links'=>'ارتباط خانواده','sms_messages'=>'پیامک مرتبط','sms_events'=>'رویداد پیامک');
        $counts=array();foreach ($records as $table=>$rows) if ($rows) $counts[]=array('label'=>$labels[$table],'count'=>count($rows));
        $name=$row['name'] ?? ($entity==='people'?trim($row['first_name'].' '.$row['last_name']):'عضویت شمارهٔ '.$row['id']);
        // Sign the complete snapshot so edits, restored rows or new dependants
        // require another preview, even when row counts have not changed.
        $signature=hash_hmac('sha256',wp_json_encode(array(get_current_user_id(),$entity,(int)$row['id'],$records)),wp_salt('auth'));
        return array('records'=>$records,'entity'=>$entity,'id'=>(int)$row['id'],'user_id'=>$uid,'name'=>$name,'counts'=>$counts,'signature'=>$signature);
    }
    private static function purge_preview($r) {
        $plan=self::purge_plan($r);
        return array_intersect_key($plan,array_flip(array('entity','id','name','counts','signature','user_id')));
    }
    private static function acquire_purge_lock($name) {
        global $wpdb;
        self::need((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$name))===1,'پرداخت یا پیامک در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.',409);
        self::$purge_locks[]=$name;
    }
    private static function prepare_purge_locks($r) {
        global $wpdb; self::admin();
        // These names are shared with the payment and SMS workers. They remain
        // held until API::dispatch commits/rolls back, never just until DELETE.
        foreach (array('worker','edit') as $suffix) self::acquire_purge_lock('nasr_sms_'.md5($wpdb->prefix.DB_NAME.$suffix));
        $plan=self::purge_plan($r);
        foreach ($plan['records']['enrollments'] as $e) self::acquire_purge_lock('nasr_pay_'.substr(md5($wpdb->prefix.DB_NAME.'start:'.$e['id']),0,40));
        $plan=self::purge_plan($r);
        foreach ($plan['records']['payment_transactions'] as $tx) self::acquire_purge_lock('nasr_pay_'.substr(md5($wpdb->prefix.DB_NAME.'callback:'.$tx['token']),0,40));
    }
    private static function release_purge_locks() {
        global $wpdb;
        foreach (array_reverse(self::$purge_locks) as $name) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
        self::$purge_locks=array();
    }
    private static function purge_record($r) {
        global $wpdb; self::admin();$body=self::body($r);
        self::need(($body['confirm'] ?? false)===true,'حذف همیشگی و سوابق وابسته را تأیید کنید.');
        $plan=self::purge_plan($r);
        self::need(is_string($body['signature'] ?? null) && hash_equals($plan['signature'],$body['signature']),'اطلاعات تغییر کرده است؛ پنجره را ببندید و پیش‌نمایش حذف را دوباره باز کنید.',409);
        // Delete children before parents. WordPress accounts and audit records
        // belong to separate scopes and are not deleted by this panel operation.
        foreach (array('sms_messages','sms_events','attendance','attendance_sessions','payment_transactions','payments','enrollments','family_links','payroll_adjustments','teacher_rates','classes','terms','people') as $table) {
            foreach (array_chunk(self::purge_ids($plan['records'][$table]),300) as $ids) Support::checked($wpdb->query('DELETE FROM '.Support::table($table).' WHERE '.self::purge_in('id',$ids)));
        }
        Support::audit('record_purged',$plan['entity'],$plan['id'],array('counts'=>$plan['counts']));
        return array('message'=>'رکورد و سوابق وابسته برای همیشه از سامانه حذف شدند.');
    }
}
