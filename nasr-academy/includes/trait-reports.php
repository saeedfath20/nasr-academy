<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/** Branches, attendance and manager-only reports share the API transaction and role checks. */
trait Reports {
    private static function branches($r) {
        global $wpdb; self::admin();
        return $wpdb->get_results('SELECT b.*,(SELECT COUNT(*) FROM '.Support::table('terms').' t WHERE t.branch_id=b.id AND t.deleted_at IS NULL) term_count FROM '.Support::table('branches').' b ORDER BY b.id',ARRAY_A);
    }
    private static function save_branches($rows) {
        global $wpdb;
        self::need(is_array($rows) && count($rows)<=100,'حداکثر ۱۰۰ شعبه وارد کنید.');
        $seen=array(); $names=array();
        foreach ($rows as $row) {
            self::need(is_array($row),'مشخصات شعبه معتبر نیست.');
            $id=absint($row['id'] ?? 0); $name=self::text($row,'name',true,150);
            self::need(!in_array($name,$names,true),'نام شعبه تکراری است.'); $names[]=$name;
            self::need(!$id || !in_array($id,$seen,true),'شناسهٔ شعبه تکراری است.');
            if ($id) { self::row('branches',$id); $seen[]=$id; }
            self::need(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('branches').' WHERE name=%s AND id<>%d',$name,$id)),'نام شعبه قبلاً استفاده شده است.');
            $data=array('name'=>$name,'active'=>empty($row['active']) ? 0 : 1);
            if ($id) { Support::checked($wpdb->update(Support::table('branches'),$data,array('id'=>$id))); }
            else { Support::checked($wpdb->insert(Support::table('branches'),$data)); }
        }
        // Omitted existing branches are retained; archive with active=0 to preserve term identities.
        Support::audit('branches_saved','branches',0,array('count'=>count($rows)));
    }
    private static function store_teacher_rate($teacher_id,$term_id,$rate) {
        global $wpdb;
        $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('teacher_rates').' WHERE teacher_id=%d AND term_id=%d',$teacher_id,$term_id),ARRAY_A);
        $data=array('teacher_id'=>$teacher_id,'term_id'=>$term_id,'hourly_rate'=>$rate,'updated_at'=>Support::now());
        if ($old) { Support::checked($wpdb->update(Support::table('teacher_rates'),$data,array('id'=>$old['id']))); }
        else { Support::checked($wpdb->insert(Support::table('teacher_rates'),$data)); }
        Support::audit('teacher_rate_saved','teacher',$teacher_id,array('term_id'=>$term_id,'old_rate'=>$old['hourly_rate'] ?? null,'hourly_rate'=>$rate,'currency'=>'تومان'));
    }
    private static function report_options($r) {
        global $wpdb; self::admin();
        return array(
            'terms'=>$wpdb->get_results('SELECT t.*,b.name branch_name FROM '.Support::table('terms').' t LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id WHERE t.deleted_at IS NULL ORDER BY t.start_date DESC,t.id DESC',ARRAY_A),
            'classes'=>$wpdb->get_results('SELECT c.id,c.name,c.term_id,c.teacher_id,c.sessions_planned,c.deleted_at FROM '.Support::table('classes').' c JOIN '.Support::table('terms').' t ON t.id=c.term_id JOIN '.Support::table('people').' p ON p.user_id=c.teacher_id WHERE c.deleted_at IS NULL AND t.deleted_at IS NULL AND p.deleted_at IS NULL ORDER BY c.name,c.id',ARRAY_A),
            'people'=>$wpdb->get_results("SELECT user_id,kind,first_name,last_name,first_name_en,last_name_en,student_code,deleted_at FROM ".Support::table('people')." WHERE kind IN ('student','teacher') AND deleted_at IS NULL ORDER BY last_name,first_name,user_id",ARRAY_A)
        );
    }
    private static function attendance_class($id) {
        self::need(in_array(Support::role(),array('teacher','manager','secretary'),true),'اجازهٔ دسترسی به حضور و غیاب ندارید.',403);
        $c=self::row('classes',$id); self::row('terms',$c['term_id']);
        self::need(Support::staff() || (int)$c['teacher_id']===get_current_user_id(),'این کلاس به شما اختصاص ندارد.',403);
        self::need((int)$c['sessions_planned']>0,'ابتدا مدیر تعداد جلسات برنامه‌ریزی‌شدهٔ کلاس را ثبت کند.');
        return $c;
    }
    private static function attendance($r) {
        global $wpdb;
        $c=self::attendance_class($r['class_id']);
        $n=self::valid(Support::number($r['session_no'] ?: 1,1,(int)$c['sessions_planned']),'شمارهٔ جلسه');
        $session=$wpdb->get_row($wpdb->prepare('SELECT id,session_date,version FROM '.Support::table('attendance_sessions').' WHERE class_id=%d AND session_no=%d',$c['id'],$n),ARRAY_A);
        $from=' FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id LEFT JOIN '.Support::table('attendance').' a ON a.enrollment_id=e.id AND a.session_id=%d WHERE e.class_id=%d AND e.deleted_at IS NULL AND p.deleted_at IS NULL AND (e.active=1 OR a.id IS NOT NULL)';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT e.id enrollment_id,p.first_name,p.last_name,p.first_name_en,p.last_name_en,p.student_code,COALESCE(a.status,'unmarked') status".$from.' ORDER BY p.last_name_en,p.first_name_en,e.id',$session['id'] ?? 0,$c['id']),ARRAY_A);
        return array('class_id'=>$c['id'],'class_name'=>$c['name'],'sessions_planned'=>$c['sessions_planned'],'session_no'=>$n,'session_date'=>$session['session_date'] ?? null,'version'=>(int)($session['version'] ?? 0),'rows'=>$rows);
    }
    private static function save_attendance($r) {
        global $wpdb; $b=self::body($r); $c=self::attendance_class($b['class_id'] ?? 0);
        $n=self::valid(Support::number($b['session_no'] ?? '',1,(int)$c['sessions_planned']),'شمارهٔ جلسه');
        $day=self::valid(Support::date($b['session_date'] ?? ''));
        $term=self::row('terms',$c['term_id']);
        self::need($day>=$term['start_date'] && $day<=$term['end_date'],'تاریخ جلسه باید در بازهٔ ترم باشد.');
        $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('attendance_sessions').' WHERE class_id=%d AND session_no=%d',$c['id'],$n),ARRAY_A);
        $version=self::valid(Support::number($b['version'] ?? '',0,2147483647));
        self::need($version===(int)($old['version'] ?? 0),'حضور و غیاب توسط فرد دیگری تغییر کرده است؛ جلسه را دوباره باز کنید.',409);
        self::need(isset($b['rows']) && is_array($b['rows']) && count($b['rows'])<=5000,'فهرست حضور و غیاب معتبر نیست.');
        $valid_ids=array_map('intval',$wpdb->get_col($wpdb->prepare('SELECT e.id FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id WHERE e.class_id=%d AND e.deleted_at IS NULL AND p.deleted_at IS NULL',$c['id'])));
        $seen=array();
        foreach ($b['rows'] as $row) {
            self::need(is_array($row),'سطر حضور و غیاب معتبر نیست.');
            $eid=absint($row['enrollment_id'] ?? 0);
            self::need(in_array($eid,$valid_ids,true) && !in_array($eid,$seen,true),'زبان‌آموز نامعتبر یا تکراری در حضور و غیاب.'); $seen[]=$eid;
            self::need(in_array($row['status'] ?? '',array('present','absent','late','excused','unmarked'),true),'وضعیت حضور معتبر نیست.');
        }
        $data=array('class_id'=>$c['id'],'session_no'=>$n,'session_date'=>$day,'version'=>$version+1,'updated_by'=>get_current_user_id(),'updated_at'=>Support::now());
        if ($old) { $sid=$old['id']; $updated=Support::checked($wpdb->update(Support::table('attendance_sessions'),$data,array('id'=>$sid,'version'=>$old['version']))); self::need($updated===1,'حضور و غیاب هم‌زمان تغییر کرده است؛ نسخهٔ جدید را بررسی کنید.',409); }
        else { Support::checked($wpdb->insert(Support::table('attendance_sessions'),$data)); $sid=$wpdb->insert_id; }
        foreach ($b['rows'] as $row) {
            $eid=absint($row['enrollment_id']);
            $previous=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Support::table('attendance').' WHERE session_id=%d AND enrollment_id=%d',$sid,$eid));
            Support::checked($wpdb->delete(Support::table('attendance'),array('session_id'=>$sid,'enrollment_id'=>$eid)));
            if ($row['status']!=='unmarked') { Support::checked($wpdb->insert(Support::table('attendance'),array('session_id'=>$sid,'enrollment_id'=>$eid,'status'=>$row['status']))); }
            if ($row['status']!==$previous && in_array($row['status'],array('absent','late'),true)) SMS::record_event($row['status']==='absent'?'absence':'late',$sid.':'.$eid.':'.($version+1),array('enrollment_id'=>$eid,'extra'=>array('attendance_session_id'=>$sid,'attendance_raw'=>$row['status'],'session_no'=>(string)$n,'session_date'=>SMS::date_label($day),'attendance_status'=>$row['status']==='absent'?'غایب':'تأخیر')));
        }
        Support::audit('attendance_saved','class',$c['id'],array('session_no'=>$n,'session_date'=>$day,'version'=>$version+1,'rows'=>$b['rows']));
        return array('message'=>'حضور و غیاب ذخیره شد.','version'=>$version+1);
    }
    private static function report_year($raw) {
        $jalali=Support::settings()['calendar']==='jalali';
        $y=self::valid(Support::number($raw,$jalali?1399:2020,$jalali?1498:2199),'سال');
        if ($jalali) {
            return array(vsprintf('%04d-%02d-%02d',Support::jalali_to_gregorian($y,1,1)),vsprintf('%04d-%02d-%02d',Support::jalali_to_gregorian($y+1,1,1)));
        }
        return array($y.'-01-01',($y+1).'-01-01');
    }
    private static function report_filter($r,$finance=false) {
        global $wpdb;
        $where='c.deleted_at IS NULL AND t.deleted_at IS NULL AND EXISTS (SELECT 1 FROM '.Support::table('people').' report_teacher WHERE report_teacher.user_id=c.teacher_id AND report_teacher.deleted_at IS NULL)';
        foreach (array('term_id'=>'t.id','class_id'=>'c.id','teacher_id'=>'c.teacher_id') as $key=>$col) {
            if ($r[$key]!==null && $r[$key]!=='') { $value=self::valid(Support::number($r[$key],1,PHP_INT_MAX)); $where.=$wpdb->prepare(' AND '.$col.'=%d',$value); }
        }
        if ($r['branch_id']!==null && $r['branch_id']!=='') { $where.=$wpdb->prepare(' AND COALESCE(t.branch_id,0)=%d',self::valid(Support::number($r['branch_id'],0,PHP_INT_MAX))); }
        if ($r['year']!==null && $r['year']!=='') { list($a,$b)=self::report_year($r['year']); $where.=$wpdb->prepare(' AND t.start_date>=%s AND t.start_date<%s',$a,$b); }
        return $where;
    }
    private static function report_columns($pairs) {
        $out=array();
        foreach ($pairs as $key=>$label) { $parts=explode('|',$label); $out[]=array('key'=>$key,'label'=>$parts[0],'type'=>$parts[1] ?? 'text'); }
        return $out;
    }
    private static function report_result($sql,$pairs,$title,$r,$summary=array(),$note='') {
        global $wpdb;
        $q=trim(self::text(array('q'=>$r['q'] ?? ''),'q',false,150));
        if($q!==''){
            $q=Support::digits($q);$like='%'.$wpdb->esc_like($q).'%';$conditions=array();
            foreach(array_keys($pairs) as $key) {
                $field='CAST(nasr_search.`'.$key.'` AS CHAR)';$pattern=$like;
                if (in_array($r['type'],array('ungraded_students','student_grades'),true)) {
                    $field="REPLACE(REPLACE(REPLACE(REPLACE(".$field.",'ي','ی'),'ك','ک'),'‌',''),' ','')";
                    $pattern='%'.$wpdb->esc_like(str_replace(array('ي','ك',' '),array('ی','ک',''),$q)).'%';
                }
                $conditions[]=$wpdb->prepare($field.' LIKE %s',$pattern);
            }
            $sql='SELECT * FROM ('.$sql.') nasr_search WHERE ('.implode(' OR ',$conditions).')';
            // Stable ordering is required when the search is wrapped in a derived table.
            $orders=array('classes'=>'class_name,id','teacher_classes'=>'class_name,id','students'=>'student_name,student_id,class_id','new_students'=>'student_name,student_id,term_id','growth'=>'movement,student_name,user_id','attendance'=>'english_name,id','class_grades'=>'student_name,id','student_grades'=>'start_date DESC,term_id DESC,class_name,id','ungraded_students'=>'class_name,teacher_name,student_name,id','payments'=>'paid_on DESC,id DESC','unpaid'=>'balance DESC,id','student_payments'=>'term_id DESC,student_name,student_id','teacher_pay'=>'start_date DESC,term_id DESC,teacher_name,teacher_id','teacher_history'=>'start_date DESC,term_id DESC,teacher_name,teacher_id');
            if(isset($orders[$r['type']]))$sql.=' ORDER BY '.$orders[$r['type']];
            if($r['type']==='growth'){
                $counts=$wpdb->get_row("SELECT COALESCE(SUM(movement='رویش'),0) growth,COALESCE(SUM(movement='ریزش'),0) loss,COALESCE(SUM(movement='ماندگار'),0) retained FROM (".$sql.') nasr_growth_search',ARRAY_A);
                $summary=array();foreach(array('growth'=>'رویش در نتایج','loss'=>'ریزش در نتایج','retained'=>'ماندگار در نتایج') as $key=>$label)$summary[]=array('label'=>$label,'value'=>$counts[$key],'type'=>'number');
            }
            if(in_array($r['type'],array('payments','unpaid','student_payments','teacher_pay','teacher_history'),true)){
                $key=array('payments'=>'confirmed','unpaid'=>'balance','student_payments'=>'paid','teacher_pay'=>'net','teacher_history'=>'net')[$r['type']];
                $summary[0]['value']=$wpdb->get_var('SELECT COALESCE(SUM(`'.$key.'`),0) FROM ('.$sql.') nasr_search_sum');
                if(in_array($r['type'],array('teacher_pay','teacher_history'),true))$summary[1]['value']=$wpdb->get_var('SELECT COALESCE(SUM(net IS NULL),0) FROM ('.$sql.') nasr_missing_sum');
            }
        }
        $result=self::paged($sql,'SELECT COUNT(*) FROM ('.$sql.') nasr_report_count',$r);
        self::need(!$wpdb->last_error,'بازیابی گزارش انجام نشد.',500);
        return array_merge($result,array('title'=>$title,'columns'=>self::report_columns($pairs),'summary'=>$summary,'note'=>''));
    }
    private static function report($r) {
        global $wpdb; self::admin();
        $type=(string)$r['type'];
        if (in_array($type,array('payments','unpaid','student_payments','teacher_pay','teacher_history','annual'),true)) { self::manager_only(); }
        if ($type==='comprehensive_class') { return self::comprehensive_class_report($r); }
        if ($type==='attendance') { return self::attendance_report($r); }
        if ($type==='class_grades') { return self::class_grades_report($r); }
        if ($type==='student_grades') { return self::student_grades_report($r); }
        if ($type==='ungraded_students') { return self::ungraded_students_report($r); }
        if ($type==='growth') { return self::growth_report($r); }
        if ($type==='teacher_pay') { return self::teacher_pay_detail($r); }
        if ($type==='teacher_history') { return self::payroll_report($r); }
        if ($type==='annual') { return self::annual_report($r); }
        $E=Support::table('enrollments'); $C=Support::table('classes'); $T=Support::table('terms'); $P=Support::table('people'); $B=Support::table('branches'); $PAY=Support::table('payments');
        $finance=in_array($type,array('payments','unpaid','student_payments'),true);
        self::need(in_array($type,array('classes','students','new_students','teacher_classes','payments','unpaid','student_payments'),true),'نوع گزارش معتبر نیست.');
        $where=self::report_filter($r,$finance);
        $base=" FROM $C c JOIN $T t ON t.id=c.term_id LEFT JOIN $B b ON b.id=t.branch_id";
        $branch="COALESCE(b.name,'تعیین نشده') branch_name";
        if (in_array($type,array('classes','teacher_classes'),true)) {
            $sql="SELECT c.id,c.name class_name,t.name term_name,t.code term_code,$branch,CONCAT(p.first_name,' ',p.last_name) teacher_name,c.schedule,c.sessions_planned,c.sessions_held,c.duration,(SELECT COUNT(*) FROM $E e JOIN $P st ON st.user_id=e.student_id WHERE e.class_id=c.id AND e.active=1 AND e.deleted_at IS NULL AND st.deleted_at IS NULL) students".$base." JOIN $P p ON p.user_id=c.teacher_id WHERE $where ORDER BY t.start_date DESC,c.name,c.id";
            $pairs=array('branch_name'=>'شعبه','term_name'=>'ترم','term_code'=>'کد ترم','class_name'=>'کلاس','teacher_name'=>'معلم','schedule'=>'زمان کلاس','students'=>'زبان‌آموزان|number','sessions_planned'=>'جلسات برنامه‌ریزی‌شده|number','sessions_held'=>'جلسات برگزارشده|number','duration'=>'دقیقه هر جلسه|number');
            $metadata=array();
            if($type==='teacher_classes'){
                self::need($r['term_id'] && $r['teacher_id'],'ترم و معلم را انتخاب کنید.');$term=self::row('terms',$r['term_id']);$teacher=Support::profile(absint($r['teacher_id']));self::need($teacher && $teacher['kind']==='teacher' && empty($teacher['deleted_at']),'معلم معتبر نیست.');
                $branch=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.$B.' WHERE id=%d',$term['branch_id']));
                $metadata=array(array('label'=>'ترم','value'=>$term['name'].' · '.$term['code']),array('label'=>'معلم','value'=>trim($teacher['first_name'].' '.$teacher['last_name'])),array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده'));
                foreach(array('start_date'=>'شروع ترم','end_date'=>'پایان ترم','mid_date'=>'میان‌ترم','final_date'=>'آزمون پایان‌ترم') as $key=>$label)$metadata[]=array('label'=>$label,'value'=>$term[$key],'type'=>'date');
                foreach(array('branch_name','term_name','term_code','teacher_name') as $key)unset($pairs[$key]);
            }
            $out=self::report_result($sql,$pairs,$type==='classes'?'لیست کلاس‌های هر ترم':'لیست کلاس‌های یک معلم در یک ترم',$r);$out['metadata']=$metadata;$out['layout']=$type==='teacher_classes'?'teacher_classes':'table';return $out;
        }
        $base.=" JOIN $E e ON e.class_id=c.id JOIN $P p ON p.user_id=e.student_id";
        $where.=' AND e.deleted_at IS NULL AND p.deleted_at IS NULL';
        if (!$finance) { $where.=' AND e.active=1'; }
        if ($r['student_id']) { $where.=$wpdb->prepare(' AND p.user_id=%d',self::valid(Support::number($r['student_id'],1,PHP_INT_MAX))); }
        $person="p.user_id student_id,p.student_code,CONCAT(p.first_name,' ',p.last_name) student_name,TRIM(CONCAT(p.first_name_en,' ',p.last_name_en)) english_name";
        $pairs=array('branch_name'=>'شعبه','term_name'=>'ترم','class_name'=>'کلاس','student_code'=>'کد زبان‌آموز|number','student_name'=>'نام فارسی','english_name'=>'نام انگلیسی');
        if ($type==='students' || $type==='new_students') {
            // Explicit registration fields only; never select passwords or user metadata.
            $base.=" LEFT JOIN {$wpdb->users} registration_user ON registration_user.ID=p.user_id";
            $registration="p.first_name,p.last_name,p.first_name_en,p.last_name_en,p.national_id,p.birth_certificate,CASE p.gender WHEN 'female' THEN 'دختر / خانم' WHEN 'male' THEN 'پسر / آقا' ELSE '' END gender,p.mobile,p.landline,p.extra_phone,p.education,p.birth_date,p.birth_year,p.father_name,p.address,registration_user.user_email email,registration_user.user_login username,CASE WHEN p.active=1 THEN 'فعال' ELSE 'غیرفعال' END account_status";
            $pairs['student_name']='نام و نام خانوادگی فارسی';$pairs['english_name']='نام و نام خانوادگی انگلیسی';
            $pairs+=Preferences::student_registration_columns();$pairs['birth_date'].='|date';
            $sql="SELECT DISTINCT $person,t.id term_id,t.name term_name,$branch,$registration";
            if ($type==='new_students') {
                self::need($r['term_id'],'برای گزارش زبان‌آموزان جدید، ترم را انتخاب کنید.');
                $where.=" AND NOT EXISTS (SELECT 1 FROM $E old_e JOIN $C old_c ON old_c.id=old_e.class_id JOIN $T old_t ON old_t.id=old_c.term_id WHERE old_e.student_id=e.student_id AND old_e.deleted_at IS NULL AND old_c.deleted_at IS NULL AND old_t.deleted_at IS NULL AND EXISTS (SELECT 1 FROM $P old_teacher WHERE old_teacher.user_id=old_c.teacher_id AND old_teacher.deleted_at IS NULL) AND old_t.start_date<t.start_date)";
                unset($pairs['class_name']);
            } else { $sql.=',c.id class_id,c.name class_name'; }
            $order=$type==='students'?'student_name,student_id,class_id':'student_name,student_id,term_id';
            $out=self::report_result($sql.$base." WHERE $where ORDER BY $order",$pairs,$type==='new_students'?'لیست زبان‌آموزان جدید در یک ترم':'لیست زبان‌آموزان ترم / کلاس',$r,array(),$type==='new_students'?'جدید: ثبت‌نام فعال در ترم انتخابی و بدون ثبت‌نام در ترمی با تاریخ شروع قدیمی‌تر، در کل آموزشگاه.':'فهرست شامل ثبت‌نام‌های فعال و حذف‌نشده است.');
            $out['layout']='student_registration';return $out;
        }
        $removed="(c.deleted_at IS NOT NULL OR t.deleted_at IS NOT NULL OR e.deleted_at IS NOT NULL OR p.deleted_at IS NOT NULL) removed";
        if ($type==='payments') {
            $base.=" JOIN $PAY pay ON pay.enrollment_id=e.id";
            if ($r['paid_year']!==null && $r['paid_year']!=='') { list($a,$b)=self::report_year($r['paid_year']); $where.=$wpdb->prepare(' AND pay.paid_on>=%s AND pay.paid_on<%s',$a,$b); }
            $sql="SELECT pay.id,$person,c.name class_name,t.name term_name,$branch,pay.paid_on,pay.amount,pay.reference,CASE WHEN pay.voided_at IS NOT NULL THEN 'باطل‌شده' WHEN pay.status='success' THEN 'موفق' WHEN pay.status='failed' THEN 'ناموفق' ELSE 'در انتظار' END status,CASE WHEN pay.status='success' AND pay.voided_at IS NULL THEN pay.amount ELSE 0 END confirmed,$removed".$base." WHERE $where";
            $total=$wpdb->get_var('SELECT COALESCE(SUM(confirmed),0) FROM ('.$sql.') payment_total');
            return self::report_result($sql.' ORDER BY pay.paid_on DESC,pay.id DESC',array_merge($pairs,array('paid_on'=>'تاریخ پرداخت|date','amount'=>'مبلغ|money','status'=>'وضعیت','reference'=>'شماره پیگیری','removed'=>'سابقه حذف‌شده|yesno')),'لیست پرداختی‌های ترم و سال',$r,array(array('label'=>'جمع پرداخت موفق و باطل‌نشده','value'=>$total,'type'=>'money')),'سال ترم بر اساس تاریخ شروع ترم است؛ «سال پرداخت» تاریخ واریز را فیلتر می‌کند. سوابق حذف‌شده نیز برای حفظ تاریخچهٔ مالی نمایش داده می‌شوند.');
        }
        $base.=" LEFT JOIN (SELECT enrollment_id,SUM(amount) paid FROM $PAY WHERE status='success' AND voided_at IS NULL GROUP BY enrollment_id) paid ON paid.enrollment_id=e.id";
        $paid='COALESCE(paid.paid,0)'; $due="GREATEST(e.net_fee-$paid,0)";
        if ($type==='unpaid') {
            $where.=" AND $due>0";
            if ($r['debt_mode']!=='remaining') { $where.=" AND $paid=0"; }
            $sql="SELECT e.id,$person,c.name class_name,t.name term_name,$branch,e.snack_stationery_fee,e.private_fee,e.other_fee,e.net_fee,$paid paid,$due balance,$removed".$base." WHERE $where";
            $total=$wpdb->get_var('SELECT COALESCE(SUM(balance),0) FROM ('.$sql.') debt_total');
            return self::report_result($sql.' ORDER BY balance DESC,e.id',$pairs+array('snack_stationery_fee'=>'خوراکی تحریر|money','private_fee'=>'خصوصی|money','other_fee'=>'سایر هزینه‌ها|money','net_fee'=>'شهریه و هزینه‌ها|money','paid'=>'پرداخت موفق|money','balance'=>'مانده|money','removed'=>'سابقه حذف‌شده|yesno'),'لیست زبان‌آموزان پرداخت‌نکرده / بدهکار',$r,array(array('label'=>'جمع ماندهٔ این گزارش','value'=>$total,'type'=>'money')),'ثبت‌نام غیرفعال یا حذف‌شده، بدهی را تسویه نمی‌کند؛ فقط پرداخت موفق و باطل‌نشده کسر می‌شود.');
        }
        $sql="SELECT $person,t.id term_id,t.name term_name,$branch,SUM(e.snack_stationery_fee) snack_stationery_fee,SUM(e.private_fee) private_fee,SUM(e.other_fee) other_fee,SUM(e.net_fee) net_fee,SUM($paid) paid,SUM($due) balance,SUM(GREATEST($paid-e.net_fee,0)) credit".$base." WHERE $where GROUP BY p.user_id,p.student_code,p.first_name,p.last_name,p.first_name_en,p.last_name_en,t.id,t.name,t.start_date,b.name ORDER BY t.start_date DESC,t.id DESC,p.last_name,p.first_name";
        unset($pairs['class_name']);
        $total=$wpdb->get_var('SELECT COALESCE(SUM(paid),0) FROM ('.$sql.') student_total');
        return self::report_result($sql,$pairs+array('snack_stationery_fee'=>'خوراکی تحریر|money','private_fee'=>'خصوصی|money','other_fee'=>'سایر هزینه‌ها|money','net_fee'=>'شهریه و هزینه‌ها|money','paid'=>'پرداخت موفق|money','balance'=>'مانده|money','credit'=>'بستانکاری|money'),'پرداخت‌های شخص در ترم‌های مختلف',$r,array(array('label'=>'جمع پرداخت موفق','value'=>$total,'type'=>'money')),'جمع هر شخص در هر ترم؛ پرداخت ناموفق، در انتظار و باطل‌شده در جمع منظور نمی‌شود.');
    }
    private static function ungraded_students_report($r) {
        global $wpdb;
        self::need($r['branch_id']!==null && $r['branch_id']!=='' && $r['term_id'],'ابتدا شعبه و ترم را انتخاب کنید.');
        $branch_id=self::valid(Support::number($r['branch_id'],0,PHP_INT_MAX),'شعبه');
        $term=self::row('terms',self::valid(Support::number($r['term_id'],1,PHP_INT_MAX),'ترم'));
        self::need((int)$term['branch_id']===$branch_id,'ترم انتخاب‌شده متعلق به این شعبه نیست.');
        $where=self::report_filter($r).' AND e.active=1 AND e.deleted_at IS NULL AND p.deleted_at IS NULL';
        // A recorded zero is a grade. A partially graded enrollment is not blank.
        foreach (array('listening','speaking','reading','writing','midterm','final_exam') as $key) $where.=' AND e.'.$key.' IS NULL';
        $sql="SELECT e.id,c.name class_name,TRIM(CONCAT(teacher.first_name,' ',teacher.last_name)) teacher_name,TRIM(CONCAT(p.first_name,' ',p.last_name)) student_name FROM ".Support::table('enrollments').' e JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id JOIN '.Support::table('people').' p ON p.user_id=e.student_id JOIN '.Support::table('people').' teacher ON teacher.user_id=c.teacher_id WHERE '.$where.' ORDER BY c.name,teacher_name,student_name,e.id';
        $out=self::report_result($sql,array('class_name'=>'نام کلاس','teacher_name'=>'نام معلم','student_name'=>'نام زبان‌آموز'),'گزارش زبان‌آموزان بدون نمره',$r);
        $branch=$branch_id?$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Support::table('branches').' WHERE id=%d',$branch_id)):'';
        $out['metadata']=array(array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده'),array('label'=>'ترم','value'=>$term['name'].' · '.$term['code']));
        return $out;
    }
    private static function class_grades_report($r) {
        global $wpdb;
        self::need($r['term_id'] && $r['class_id'],'برای گزارش نمرات، ابتدا ترم و سپس کلاس را انتخاب کنید.');
        $class=self::row('classes',$r['class_id']);
        $term=self::row('terms',$r['term_id']);
        self::need((int)$class['term_id']===(int)$term['id'],'کلاس انتخاب‌شده متعلق به این ترم نیست.');

        $C=Support::table('classes'); $T=Support::table('terms'); $E=Support::table('enrollments');
        $P=Support::table('people'); $B=Support::table('branches');
        $where=self::report_filter($r).$wpdb->prepare(' AND c.id=%d',$class['id']);
        $allowed=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$C.' c JOIN '.$T.' t ON t.id=c.term_id WHERE '.$where);
        self::need($allowed===1,'شعبه، سال یا ترم انتخاب‌شده با کلاس مطابقت ندارد.');

        $skills_complete='e.listening IS NOT NULL AND e.speaking IS NOT NULL AND e.reading IS NOT NULL AND e.writing IS NOT NULL';
        $all_complete=$skills_complete.' AND e.midterm IS NOT NULL AND e.final_exam IS NOT NULL';
        $classwork='CASE WHEN '.$skills_complete.' THEN ROUND(e.listening+e.speaking+e.reading+e.writing,1) ELSE NULL END';
        $total='CASE WHEN '.$all_complete.' THEN ROUND(e.listening+e.speaking+e.reading+e.writing+e.midterm+e.final_exam,1) ELSE NULL END';
        $status="CASE WHEN e.grade_version=0 THEN 'ثبت نشده' WHEN $all_complete THEN 'کامل' ELSE 'در حال تکمیل' END";
        $sql="SELECT e.id,p.student_code,CONCAT(p.first_name,' ',p.last_name) student_name,e.listening,e.speaking,e.reading,e.writing,$classwork classwork,e.midterm,e.final_exam,$total total,$status grade_status
            FROM $E e JOIN $P p ON p.user_id=e.student_id JOIN $C c ON c.id=e.class_id JOIN $T t ON t.id=c.term_id
            WHERE $where AND e.active=1 AND e.deleted_at IS NULL AND p.deleted_at IS NULL
            ORDER BY p.last_name,p.first_name,e.id";
        $pairs=array(
            'student_code'=>'کد زبان‌آموز|number','student_name'=>'نام و نام خانوادگی',
            'listening'=>'Listening / 10|number','speaking'=>'Speaking / 10|number','reading'=>'Reading / 10|number','writing'=>'Writing / 10|number',
            'classwork'=>'Classwork / 40|number','midterm'=>'Midterm / '.self::grade_label($class['mid_max']).'|number',
            'final_exam'=>'Final Exam / '.self::grade_label($class['final_max']).'|number','total'=>'Total Score / '.self::grade_label(40+(float)$class['mid_max']+(float)$class['final_max']).'|number',
            'grade_status'=>'Grade Status'
        );
        $teacher=Support::profile((int)$class['teacher_id']);
        $branch=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.$B.' WHERE id=%d',$term['branch_id']));
        $metadata=array(
            array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده'),array('label'=>'ترم','value'=>$term['name'].' · '.$term['code']),
            array('label'=>'کلاس','value'=>$class['name']),array('label'=>'معلم','value'=>$teacher?trim($teacher['first_name'].' '.$teacher['last_name']):'تعیین نشده'),
            array('label'=>'شروع ترم','value'=>$term['start_date'],'type'=>'date'),array('label'=>'میان‌ترم','value'=>$term['mid_date'],'type'=>'date'),array('label'=>'پایان‌ترم','value'=>$term['final_date'],'type'=>'date')
        );
        $out=self::report_result($sql,$pairs,'گزارش نمرات کلاسی زبان‌آموزان',$r,array(), 'خانهٔ خالی یعنی آن نمره هنوز ثبت نشده است؛ جمع کلاسی پس از ثبت هر چهار مهارت و نمره کل پس از تکمیل همهٔ بخش‌ها نمایش داده می‌شود.');
        list($page,$per)=self::page($r);
        foreach ($out['items'] as $index=>&$item) { $item['row_number']=(($page-1)*$per)+$index+1; }
        unset($item);
        array_unshift($out['columns'],array('key'=>'row_number','label'=>'ردیف','type'=>'number'));
        $out['metadata']=$metadata;
        return $out;
    }
    private static function student_grades_report($r) {
        global $wpdb;
        $uid=self::valid(Support::number($r['student_id'],1,PHP_INT_MAX),'زبان‌آموز');
        $student=Support::profile($uid);
        self::need($student && empty($student['deleted_at']) && ($student['kind']==='student' || ($student['kind']==='teacher' && !empty($student['student_code']))),'زبان‌آموز معتبر نیست.',404);
        // Only visible filters apply to a learner's complete academic history.
        $scope=new \WP_REST_Request('GET','/nasr/v1/reports');
        foreach (array('branch_id','term_id','year') as $key) $scope->set_param($key,$r[$key]);
        if ($r['term_id']!==null && $r['term_id']!=='') self::row('terms',self::valid(Support::number($r['term_id'],1,PHP_INT_MAX),'ترم'));
        $where=self::report_filter($scope).$wpdb->prepare(' AND e.student_id=%d AND e.deleted_at IS NULL',$uid);
        $E=Support::table('enrollments');$C=Support::table('classes');$T=Support::table('terms');$P=Support::table('people');$B=Support::table('branches');
        $skills='e.listening IS NOT NULL AND e.speaking IS NOT NULL AND e.reading IS NOT NULL AND e.writing IS NOT NULL';
        $complete=$skills.' AND e.midterm IS NOT NULL AND e.final_exam IS NOT NULL';
        $recorded='e.listening IS NOT NULL OR e.speaking IS NOT NULL OR e.reading IS NOT NULL OR e.writing IS NOT NULL OR e.midterm IS NOT NULL OR e.final_exam IS NOT NULL';
        $sql="SELECT e.id,e.student_id,c.id class_id,c.name class_name,t.id term_id,t.name term_name,t.code term_code,t.start_date,COALESCE(b.name,'Unassigned') branch_name,
            CASE WHEN TRIM(CONCAT(teacher.first_name_en,' ',teacher.last_name_en))<>'' THEN TRIM(CONCAT(teacher.first_name_en,' ',teacher.last_name_en)) ELSE TRIM(CONCAT(teacher.first_name,' ',teacher.last_name)) END teacher_name,
            e.listening,e.speaking,e.reading,e.writing,CASE WHEN $skills THEN ROUND(e.listening+e.speaking+e.reading+e.writing,1) ELSE NULL END classwork,
            e.midterm,e.final_exam,CASE WHEN $complete THEN ROUND(e.listening+e.speaking+e.reading+e.writing+e.midterm+e.final_exam,1) ELSE NULL END total,
            c.mid_max,c.final_max,40+c.mid_max+c.final_max total_max,e.feedback,
            CASE WHEN $complete THEN 'Complete' WHEN $recorded THEN 'Incomplete' ELSE 'Not recorded' END grade_status,
            CASE WHEN e.active=1 THEN 'Active' ELSE 'Withdrawn / Archived' END enrollment_status
            FROM $E e JOIN $C c ON c.id=e.class_id JOIN $T t ON t.id=c.term_id JOIN $P student ON student.user_id=e.student_id AND student.deleted_at IS NULL JOIN $P teacher ON teacher.user_id=c.teacher_id LEFT JOIN $B b ON b.id=t.branch_id
            WHERE $where ORDER BY t.start_date DESC,t.id DESC,c.name,e.id";
        $pairs=Preferences::student_grade_columns();
        foreach (array('listening','speaking','reading','writing','classwork','midterm','final_exam','total') as $key) $pairs[$key].='|number_en';
        // Include term labels in AJAX search without repeating them in every displayed row.
        $search_pairs=array('term_name'=>'Term','term_code'=>'Term Code','branch_name'=>'Branch')+$pairs;
        $out=self::report_result($sql,$search_pairs,'Student Grade Report Card',$r);
        $out['columns']=self::report_columns($pairs);
        foreach ($out['columns'] as &$column) {
            $max_keys=array('midterm'=>'mid_max','final_exam'=>'final_max','total'=>'total_max');
            if (isset($max_keys[$column['key']])) $column['max_key']=$max_keys[$column['key']];
        }
        unset($column);
        $name=trim($student['first_name_en'].' '.$student['last_name_en']);
        $out['metadata']=array(array('label'=>'Student','value'=>$name ?: trim($student['first_name'].' '.$student['last_name'])),array('label'=>'Student ID','value'=>$student['student_code'],'type'=>'number_en'));
        if ($r['q']) $out['metadata'][]=array('label'=>'Search','value'=>self::text(array('q'=>$r['q']),'q',false,150));
        $out['export_columns']=self::report_columns(array('term_name'=>'Term','term_code'=>'Term Code','branch_name'=>'Branch'));
        $out['layout']='student_grades';$out['direction']='ltr';$out['empty_cells']=true;
        return $out;
    }
    private static function grade_label($value) {
        $number=(float)$value;
        return floor($number)===$number ? (string)(int)$number : rtrim(rtrim(number_format($number,1,'.',''),'0'),'.');
    }
    private static function comprehensive_class_report($r) {
        global $wpdb;
        self::need($r['class_id'],'برای گزارشات کلاسی جامع، کلاس را انتخاب کنید.');
        $class=self::row('classes',$r['class_id']);
        $term=self::row('terms',$class['term_id']);
        $teacher=Support::profile((int)$class['teacher_id']);
        self::need($teacher && $teacher['kind']==='teacher','معلم کلاس در دسترس نیست.');

        $C=Support::table('classes'); $T=Support::table('terms'); $E=Support::table('enrollments');
        $P=Support::table('people'); $B=Support::table('branches');
        $where=self::report_filter($r).$wpdb->prepare(' AND c.id=%d',$class['id']);
        $allowed=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$C.' c JOIN '.$T.' t ON t.id=c.term_id WHERE '.$where);
        self::need($allowed===1,'شعبه، سال یا ترم انتخاب‌شده با کلاس مطابقت ندارد.');

        $from=" FROM $C c JOIN $T t ON t.id=c.term_id JOIN $E e ON e.class_id=c.id JOIN $P p ON p.user_id=e.student_id";
        $where.=' AND e.active=1 AND e.deleted_at IS NULL AND p.deleted_at IS NULL';
        $q=trim(self::text(array('q'=>$r['q'] ?? ''),'q',false,150));
        if ($q!=='') {
            $q=Support::digits($q); $like='%'.$wpdb->esc_like($q).'%';
            $compact='%'.$wpdb->esc_like(strtr(preg_replace('/\s+/u','',$q),array('ي'=>'ی','ك'=>'ک','‌'=>''))).'%';
            $where.=$wpdb->prepare(" AND (REPLACE(REPLACE(REPLACE(REPLACE(CONCAT(p.first_name,p.last_name),'ي','ی'),'ك','ک'),'‌',''),' ','') LIKE %s OR REPLACE(CONCAT(p.first_name_en,p.last_name_en),' ','') LIKE %s OR CAST(p.student_code AS CHAR) LIKE %s OR p.mobile LIKE %s OR p.landline LIKE %s OR p.extra_phone LIKE %s)",$compact,$compact,$like,$like,$like,$like);
        }
        $fields="e.id enrollment_id,p.student_code,CONCAT(p.first_name,' ',p.last_name) student_name,CONCAT_WS('|',NULLIF(p.mobile,''),NULLIF(p.landline,''),NULLIF(p.extra_phone,'')) mobile";
        $sql='SELECT '.$fields.$from.' WHERE '.$where.' ORDER BY p.last_name,p.first_name,e.id';
        $result=self::paged($sql,'SELECT COUNT(*)'.$from.' WHERE '.$where,$r);
        self::need(!$wpdb->last_error,'بازیابی گزارش جامع کلاس انجام نشد.',500);
        list($page,$per)=self::page($r);
        $blank_columns=array('tuition_amount','payment_dates','payment_receipt_method','book_copy_amount','previous_debt','snack_stationery_fee');
        foreach ($result['items'] as $index=>&$item) {
            $item['row_number']=(($page-1)*$per)+$index+1;
            foreach ($blank_columns as $key) { $item[$key]=''; }
        }
        unset($item);

        $branch=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.$B.' WHERE id=%d',$term['branch_id']));
        $teacher_name=trim($teacher['first_name'].' '.$teacher['last_name']);
        $columns=self::report_columns(array(
            'row_number'=>'ردیف|number','student_code'=>'کد دانش‌آموزی|number','student_name'=>'نام و نام خانوادگی','mobile'=>'شماره‌های تماس|multiline',
            'tuition_amount'=>'مبلغ شهریه|money','payment_dates'=>'تاریخ|date_list','payment_receipt_method'=>'شماره فیش و نحوه پرداخت|multiline',
            'book_copy_amount'=>'مبلغ کتاب و کپی|money','previous_debt'=>'بدهی ترم قبل|money','snack_stationery_fee'=>'خوراکی|money'
        ));
        $metadata=array(
            array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده'),array('label'=>'ترم','value'=>$term['name'].' · '.$term['code']),
            array('label'=>'نام کلاس','value'=>$class['name']),array('label'=>'تایم کلاس','value'=>$class['schedule']),array('label'=>'نام معلم','value'=>$teacher_name),
            array('label'=>'شروع ترم','value'=>$term['start_date'],'type'=>'date'),array('label'=>'میان‌ترم','value'=>$term['mid_date'],'type'=>'date'),array('label'=>'پایان‌ترم','value'=>$term['final_date'],'type'=>'date')
        );
        return array_merge($result,array(
            'title'=>'لیست کلاس '.$term['code'],'layout'=>'comprehensive_class','columns'=>$columns,'summary'=>array(),'metadata'=>$metadata,'empty_cells'=>true,
            'note'=>'',
            'class_info'=>array('term_code'=>$term['code'],'term_name'=>$term['name'],'branch_name'=>$branch ?: 'تعیین نشده','class_name'=>$class['name'],'schedule'=>$class['schedule'],'base_fee'=>$class['base_fee'],'extras_fee'=>$class['extras_fee'],'teacher_name'=>$teacher_name,'start_date'=>$term['start_date'],'mid_date'=>$term['mid_date'],'final_date'=>$term['final_date'])
        ));
    }
    private static function annual_report($r) {
        global $wpdb;
        self::need($r['year']!==null && $r['year']!=='','سال گزارش را انتخاب کنید.');
        list($start,$end)=self::report_year($r['year']);
        $T=Support::table('terms');$C=Support::table('classes');$E=Support::table('enrollments');
        $P=Support::table('people');$B=Support::table('branches');$PAY=Support::table('payments');
        // Annual scope never inherits a hidden term, class or person selection.
        $where=$wpdb->prepare('t.deleted_at IS NULL AND t.start_date>=%s AND t.start_date<%s',$start,$end);
        if ($r['branch_id']!==null && $r['branch_id']!=='') $where.=$wpdb->prepare(' AND COALESCE(t.branch_id,0)=%d',self::valid(Support::number($r['branch_id'],0,PHP_INT_MAX),'شعبه'));
        $read=function($sql) use ($wpdb) {
            $rows=$wpdb->get_results($sql,ARRAY_A);
            self::need(is_array($rows) && !$wpdb->last_error,'بازیابی گزارش سالیانه انجام نشد.',500);
            return $rows;
        };
        $pairs=Preferences::annual_columns();
        $number_keys=array('class_count','enrollment_count','net_fee','paid','balance','credit','teaching_net','salary_addition','salary_deduction','net','calculable_net','missing');
        $terms=$read("SELECT t.id,t.name term_name,t.code term_code,t.start_date,t.end_date,COALESCE(b.name,'تعیین نشده') branch_name FROM $T t LEFT JOIN $B b ON b.id=t.branch_id WHERE $where ORDER BY t.start_date,t.id");
        $all=array();
        foreach ($terms as $term) $all[(int)$term['id']]=array_merge(array_fill_keys($number_keys,0),$term);
        $visible="c.deleted_at IS NULL AND EXISTS (SELECT 1 FROM $P teacher WHERE teacher.user_id=c.teacher_id AND teacher.deleted_at IS NULL)";
        // Aggregate successful, unvoided payments once per enrollment before joining.
        $paid="SELECT enrollment_id,SUM(amount) paid FROM $PAY WHERE status='success' AND voided_at IS NULL GROUP BY enrollment_id";
        $fees=$read("SELECT t.id term_id,COUNT(e.id) enrollment_count,COALESCE(SUM(e.net_fee),0) net_fee,COALESCE(SUM(COALESCE(pay.paid,0)),0) paid,COALESCE(SUM(GREATEST(e.net_fee-COALESCE(pay.paid,0),0)),0) balance,COALESCE(SUM(GREATEST(COALESCE(pay.paid,0)-e.net_fee,0)),0) credit FROM $T t JOIN $C c ON c.term_id=t.id JOIN $E e ON e.class_id=c.id JOIN $P student ON student.user_id=e.student_id LEFT JOIN ($paid) pay ON pay.enrollment_id=e.id WHERE $where AND $visible AND e.deleted_at IS NULL AND student.deleted_at IS NULL GROUP BY t.id");
        foreach ($fees as $fee) foreach (array('enrollment_count','net_fee','paid','balance','credit') as $key) $all[(int)$fee['term_id']][$key]=(int)$fee[$key];
        // Reuse the class payroll calculation, including per-class rounding and late deductions.
        $classes=$read("SELECT c.term_id,c.hourly_rate,c.sessions_held,c.duration,c.late_minutes,c.active,c.deleted_at FROM $C c JOIN $T t ON t.id=c.term_id WHERE $where AND $visible");
        foreach (self::payroll_class_rows($classes) as $class) {
            $tid=(int)$class['term_id'];$all[$tid]['class_count']++;
            $all[$tid]['missing']+=$class['missing'];
            $all[$tid]['teaching_net']+=(int)$class['teaching_net'];
        }
        // A teacher's term ledger is included only once, even when they teach several classes.
        $adjustments=$read('SELECT a.* FROM '.Support::table('payroll_adjustments')." a JOIN $T t ON t.id=a.term_id JOIN $P teacher ON teacher.user_id=a.teacher_id WHERE $where AND teacher.deleted_at IS NULL AND EXISTS (SELECT 1 FROM $C c WHERE c.term_id=a.term_id AND c.teacher_id=a.teacher_id AND c.deleted_at IS NULL)");
        foreach ($adjustments as $record) foreach (self::payroll_rows($record) as $entry) {
            if (in_array($entry['kind'],array('addition','deduction'),true)) $all[(int)$record['term_id']]['salary_'.$entry['kind']]+=(int)$entry['amount'];
        }
        foreach ($all as &$term) {
            $term['calculable_net']=$term['teaching_net']+$term['salary_addition']-$term['salary_deduction'];
            $term['net']=$term['missing'] ? null : $term['calculable_net'];
            $term['calculation_status']=$term['missing'] ? $term['missing'].' کلاس دارای اطلاعات حقوق ناقص' : 'کامل';
        }
        unset($term);
        $q=Support::digits(self::text(array('q'=>$r['q'] ?? ''),'q',false,150));
        $normalize=function($value) { return strtolower(str_replace(array('ي','ك','‌',' '),array('ی','ک','',''),(string)$value)); };
        $rows=array_values($all);
        if ($q!=='') {
            $needle=$normalize($q);
            $rows=array_values(array_filter($rows,function($row) use ($needle,$normalize,$pairs) {
                foreach (array_keys($pairs) as $key) if ($row[$key]!==null && strpos($normalize($row[$key]),$needle)!==false) return true;
                return false;
            }));
        }
        $totals=array_fill_keys($number_keys,0);
        foreach ($rows as $row) foreach ($number_keys as $key) $totals[$key]+=(int)$row[$key];
        if ($totals['missing']) $totals['net']=null;
        $totals['calculation_status']=$totals['missing'] ? $totals['missing'].' کلاس دارای اطلاعات حقوق ناقص' : 'کامل';
        $summary=array(array('label'=>'تعداد ترم‌ها','value'=>count($rows),'type'=>'number'));
        foreach (array('net_fee'=>'جمع شهریه','paid'=>'پرداخت‌های موفق','balance'=>'مانده بدهی','calculable_net'=>'حقوق قابل محاسبه') as $key=>$label) $summary[]=array('label'=>$label,'value'=>$totals[$key],'type'=>$key==='calculable_net'?'toman':'money');
        if ($totals['missing']) $summary[]=array('label'=>'کلاس‌های دارای اطلاعات حقوق ناقص','value'=>$totals['missing'],'type'=>'number');
        $metadata=array(array('label'=>'سال','value'=>Support::digits($r['year'])),array('label'=>'تقویم','value'=>Support::settings()['calendar']==='jalali'?'شمسی':'میلادی'));
        if ($r['branch_id']!==null && $r['branch_id']!=='') {
            $branch=$wpdb->get_var($wpdb->prepare("SELECT name FROM $B WHERE id=%d",absint($r['branch_id'])));
            self::need(!$wpdb->last_error,'خواندن شعبه انجام نشد.',500);
            $metadata[]=array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده');
        } else $metadata[]=array('label'=>'شعبه','value'=>'همهٔ شعبه‌ها');
        if ($q!=='') $metadata[]=array('label'=>'جست‌وجو','value'=>$q);
        list($page,$per)=self::page($r);
        $types=array('start_date'=>'date','end_date'=>'date','class_count'=>'number','enrollment_count'=>'number','net_fee'=>'money','paid'=>'money','balance'=>'money','credit'=>'money','teaching_net'=>'toman','salary_addition'=>'toman','salary_deduction'=>'toman','net'=>'toman');
        foreach ($types as $key=>$type) $pairs[$key].='|'.$type;
        return array('title'=>'گزارش سالیانه','layout'=>'annual','columns'=>self::report_columns($pairs),'items'=>array_slice($rows,($page-1)*$per,$per),'total'=>count($rows),'page'=>$page,'pages'=>max(1,(int)ceil(count($rows)/$per)),'metadata'=>$metadata,'summary'=>$summary,'totals'=>$totals,'totals_label'=>$q!==''?'جمع نتایج':'جمع سال','note'=>'');
    }
    private static function payroll_report($r) {
        global $wpdb;
        $where=self::report_filter($r,true);
        self::need(!$r['class_id'],'تعدیلات حقوق برای کل ترم است؛ گزارش حق‌التدریس را بدون فیلتر یک کلاس بگیرید.');
        $minutes='COALESCE(c.sessions_held,0)*COALESCE(c.duration,0)';
        $base="SELECT c.teacher_id,t.id term_id,t.name term_name,t.code term_code,t.start_date,COALESCE(b.name,'تعیین نشده') branch_name,CONCAT(p.first_name,' ',p.last_name) teacher_name,COUNT(*) class_count,SUM(c.sessions_planned) planned,SUM(c.sessions_held) held,SUM($minutes) minutes,SUM(c.late_minutes) late_minutes,ROUND(SUM($minutes)/60,2) hours,MIN(c.hourly_rate) min_rate,MAX(c.hourly_rate) max_rate,SUM(CASE WHEN c.hourly_rate IS NULL OR c.sessions_held IS NULL OR c.duration IS NULL THEN 1 ELSE 0 END) missing,SUM(ROUND(c.hourly_rate*($minutes)/60)) raw_gross,SUM(ROUND(c.hourly_rate*GREATEST(($minutes)-c.late_minutes,0)/60)) raw_teaching_net";
        $base.=' FROM '.Support::table('classes').' c JOIN '.Support::table('terms').' t ON t.id=c.term_id JOIN '.Support::table('people').' p ON p.user_id=c.teacher_id LEFT JOIN '.Support::table('branches')." b ON b.id=t.branch_id WHERE $where GROUP BY c.teacher_id,t.id,t.name,t.code,t.start_date,b.name,p.first_name,p.last_name";
        $sql="SELECT g.*,CASE WHEN g.min_rate=g.max_rate THEN g.min_rate ELSE NULL END hourly_rate,CASE WHEN g.min_rate IS NULL THEN '' WHEN g.min_rate=g.max_rate THEN CAST(g.min_rate AS CHAR) ELSE CONCAT(g.min_rate,' تا ',g.max_rate) END rate_range,CASE WHEN g.missing=0 THEN g.raw_gross ELSE NULL END gross,CASE WHEN g.missing=0 THEN g.raw_gross-g.raw_teaching_net ELSE NULL END deduction,CASE WHEN g.missing=0 THEN g.raw_teaching_net ELSE NULL END teaching_net,COALESCE(a.addition,0) salary_addition,COALESCE(a.deduction,0) salary_deduction,CASE WHEN g.missing=0 THEN CAST(g.raw_teaching_net AS DECIMAL(30,0))+COALESCE(a.addition,0)-CAST(COALESCE(a.deduction,0) AS DECIMAL(30,0)) ELSE NULL END net,CASE WHEN g.missing>0 THEN 'نرخ ساعتی، جلسات یا مدت بعضی کلاس‌ها ناقص است' ELSE 'قابل محاسبه' END calculation_status FROM ($base) g LEFT JOIN ".Support::table('payroll_adjustments').' a ON a.teacher_id=g.teacher_id AND a.term_id=g.term_id';
        $sum=$wpdb->get_row('SELECT COALESCE(SUM(net),0) total,SUM(CASE WHEN net IS NULL THEN 1 ELSE 0 END) missing FROM ('.$sql.') payroll_total',ARRAY_A);
        return self::report_result($sql.' ORDER BY g.start_date DESC,g.term_id DESC,g.teacher_name',array('branch_name'=>'شعبه','term_name'=>'ترم','term_code'=>'کد ترم','teacher_name'=>'معلم','rate_range'=>'نرخ ساعتی کلاس‌ها (تومان)','class_count'=>'کلاس|number','planned'=>'جلسات برنامه‌ریزی‌شده|number','held'=>'جلسات برگزارشده|number','hours'=>'ساعت تدریس|number','late_minutes'=>'کسورات دقیقه|number','gross'=>'حق‌التدریس ناخالص|toman','deduction'=>'کسورات تأخیر|toman','teaching_net'=>'حق‌التدریس پس از تأخیر|toman','salary_addition'=>'اضافه‌حقوق|toman','salary_deduction'=>'کاهش‌حقوق|toman','net'=>'حقوق نهایی|toman','calculation_status'=>'وضعیت محاسبه'),$r['type']==='teacher_pay'?'حق‌التدریس معلم در یک ترم':'حق‌التدریس معلم در ترم‌های مختلف',$r,array(array('label'=>'جمع حقوق قابل محاسبه','value'=>$sum['total'],'type'=>'toman'),array('label'=>'ردیف‌های دارای اطلاعات ناقص','value'=>$sum['missing'],'type'=>'number')),'حق‌التدریس هر کلاس با نرخ ساعتی همان کلاس و دقایق تدریس پس از تأخیر محاسبه می‌شود. جمع کلاس‌های ترم + اضافه‌حقوق − کاهش‌حقوق = حقوق نهایی. تعدیلات فقط یک بار در هر ترم اعمال می‌شوند. مبلغ منفی یعنی کاهش‌ها از حقوق بیشتر است. این گزارش پرداخت بانکی انجام نمی‌دهد؛ سوابق کلاس‌های حذف‌شده نیز محاسبه می‌شوند.');
    }
    private static function growth_report($r) {
        global $wpdb;
        $current=self::row('terms',$r['term_id']); $previous=self::row('terms',$r['previous_term_id']);
        self::need($current['start_date']>$previous['start_date'],'ترم مبنا باید قبل از ترم فعلی باشد.');
        self::need((int)$current['branch_id']===(int)$previous['branch_id'],'برای مقایسهٔ ریزش و رویش، دو ترم از یک شعبه انتخاب کنید.');
        if ($r['branch_id']!==null && $r['branch_id']!=='') { self::need((int)$current['branch_id']===self::valid(Support::number($r['branch_id'],0,PHP_INT_MAX)),'شعبه با ترم انتخابی مطابقت ندارد.'); }
        if ($r['year']!==null && $r['year']!=='') { list($a,$b)=self::report_year($r['year']); self::need($current['start_date']>=$a && $current['start_date']<$b,'سال شروع ترم با انتخاب شما مطابقت ندارد.'); }
        $cohort=function($term) use ($wpdb) {
            return $wpdb->prepare('SELECT DISTINCT e.student_id FROM '.Support::table('enrollments').' e JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('people').' st ON st.user_id=e.student_id WHERE e.active=1 AND e.deleted_at IS NULL AND c.deleted_at IS NULL AND st.deleted_at IS NULL AND EXISTS (SELECT 1 FROM '.Support::table('people').' teacher WHERE teacher.user_id=c.teacher_id AND teacher.deleted_at IS NULL) AND c.term_id=%d',$term);
        };
        $a=$cohort($current['id']); $b=$cohort($previous['id']);
        $from=' FROM '.Support::table('people')." p LEFT JOIN ($a) now_s ON now_s.student_id=p.user_id LEFT JOIN ($b) prev_s ON prev_s.student_id=p.user_id";
        $condition='(now_s.student_id IS NOT NULL OR prev_s.student_id IS NOT NULL)';
        $totals=$wpdb->get_row('SELECT COALESCE(SUM(now_s.student_id IS NOT NULL),0) current_count,COALESCE(SUM(prev_s.student_id IS NOT NULL),0) previous_count,COALESCE(SUM(now_s.student_id IS NOT NULL AND prev_s.student_id IS NULL),0) growth,COALESCE(SUM(now_s.student_id IS NULL AND prev_s.student_id IS NOT NULL),0) loss,COALESCE(SUM(now_s.student_id IS NOT NULL AND prev_s.student_id IS NOT NULL),0) retained'.$from.' WHERE '.$condition,ARRAY_A);
        $sql="SELECT p.user_id,p.student_code,CONCAT(p.first_name,' ',p.last_name) student_name,TRIM(CONCAT(p.first_name_en,' ',p.last_name_en)) english_name,CASE WHEN now_s.student_id IS NULL THEN 'ریزش' WHEN prev_s.student_id IS NULL THEN 'رویش' ELSE 'ماندگار' END movement".$from.' WHERE '.$condition;
        if ($r['movement']!=='all') { $sql.=' AND (now_s.student_id IS NULL OR prev_s.student_id IS NULL)'; }
        $summary=array();
        foreach (array('previous_count'=>'زبان‌آموز ترم مبنا','current_count'=>'زبان‌آموز ترم فعلی','growth'=>'رویش','loss'=>'ریزش','retained'=>'ماندگار') as $key=>$label) { $summary[]=array('label'=>$label,'value'=>$totals[$key],'type'=>'number'); }
        return self::report_result($sql.' ORDER BY movement,student_name,p.user_id',array('student_code'=>'کد زبان‌آموز|number','student_name'=>'نام فارسی','english_name'=>'نام انگلیسی','movement'=>'وضعیت'),'ریزش و رویش زبان‌آموزان',$r,$summary,'مقایسهٔ «'.$previous['name'].'» با «'.$current['name'].'». هر زبان‌آموز یک‌بار شمارش می‌شود. رویش: در ترم فعلی هست و در ترم مبنا نیست (شامل بازگشتی‌ها). ریزش: در ترم مبنا هست و در ترم فعلی نیست. بایگانی شدن خود ترم یا کلاس، عضویت را حذف نمی‌کند؛ ثبت‌نام غیرفعال یا حذف‌شده در جمعیت فعال نیست.');
    }
    private static function attendance_report($r) {
        global $wpdb;
        $c=self::row('classes',$r['class_id']);
        self::need((int)$c['sessions_planned']>0,'ابتدا تعداد جلسات برنامه‌ریزی‌شدهٔ کلاس را ثبت کنید.');
        $from=self::valid(Support::number($r['session_from'] ?: 1,1,(int)$c['sessions_planned']));
        // A fixed twenty-session sheet also leaves writing space for shorter classes.
        $to=$from+19;
        $where=self::report_filter($r).$wpdb->prepare(' AND c.id=%d',$c['id']).' AND e.deleted_at IS NULL AND p.deleted_at IS NULL';
        $fields="e.id,TRIM(CONCAT(p.first_name_en,' ',p.last_name_en)) english_name";
        $pairs=array('english_name'=>'Name');
        for ($n=$from;$n<=$to;$n++) {
            $fields.=',(SELECT a.status FROM '.Support::table('attendance').' a JOIN '.Support::table('attendance_sessions').' s ON s.id=a.session_id WHERE a.enrollment_id=e.id AND s.class_id=c.id AND s.session_no='.(int)$n.') s'.$n;
            $pairs['s'.$n]='Session '.$n.'|attendance';
        }
        $where.=' AND (e.active=1 OR EXISTS (SELECT 1 FROM '.Support::table('attendance').' a JOIN '.Support::table('attendance_sessions').' s ON s.id=a.session_id WHERE a.enrollment_id=e.id AND s.class_id=c.id AND s.session_no BETWEEN '.(int)$from.' AND '.(int)$to.'))';
        $sql='SELECT '.$fields.' FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE '.$where.' ORDER BY p.last_name_en,p.first_name_en,e.id';
        $out=self::report_result($sql,$pairs,'حضور و غیاب · '.$c['name'],$r,array(),'');
        $term=self::row('terms',$c['term_id']);
        $teacher=Support::profile((int)$c['teacher_id']);
        $branch=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Support::table('branches').' WHERE id=%d',$term['branch_id']));
        $out['metadata']=array(
            array('label'=>'کلاس','value'=>$c['name']),
            array('label'=>'معلم','value'=>$teacher?trim($teacher['first_name'].' '.$teacher['last_name']):'تعیین نشده'),
            array('label'=>'ترم','value'=>$term['name'].' · '.$term['code']),
            array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده'),
            array('label'=>'زمان کلاس','value'=>$c['schedule'])
        );
        $out['direction']='ltr';$out['layout']='attendance';$out['empty_cells']=true;return $out;
    }
    private static function attendance_date_label($date) {
        $d=array_map('intval',explode('-',$date));
        if (Support::settings()['calendar']==='jalali') { $d=Support::gregorian_to_jalali($d[0],$d[1],$d[2]); }
        return vsprintf('%04d/%02d/%02d',$d);
    }
}
