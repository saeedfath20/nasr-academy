<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/** One adjustment ledger per teacher and term; included once in payroll totals. */
trait Payroll {
    private static function my_salary($r) {
        global $wpdb;
        self::need(Support::role()==='teacher','این بخش فقط در پنل معلم در دسترس است.',403);
        // The teacher identity and the complete term scope always come from
        // the server; client teacher/class/search/page filters cannot narrow it.
        $uid=get_current_user_id();
        $terms=$wpdb->get_results($wpdb->prepare('SELECT t.id,t.name,t.code,t.branch_id,b.name branch_name FROM '.Support::table('terms').' t LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id WHERE t.deleted_at IS NULL AND EXISTS (SELECT 1 FROM '.Support::table('classes').' c WHERE c.term_id=t.id AND c.teacher_id=%d AND c.deleted_at IS NULL) ORDER BY t.start_date DESC,t.id DESC',$uid),ARRAY_A);
        self::need(is_array($terms) && !$wpdb->last_error,'خواندن ترم‌های حقوق انجام نشد.',500);
        $out=array('terms'=>$terms,'term_id'=>null,'locked'=>true,'selection_required'=>true);
        if ($r['term_id']===null || $r['term_id']==='') return $out;
        $tid=self::valid(Support::number($r['term_id'],1,PHP_INT_MAX),'ترم');
        self::need(in_array($tid,array_map('intval',array_column($terms,'id')),true),'این ترم در دسترس شما نیست.',403);
        $missing=array();
        foreach (array('listening','speaking','reading','writing','midterm','final_exam') as $key) $missing[]='e.'.$key.' IS NULL';
        $classes=$wpdb->get_results($wpdb->prepare('SELECT c.id class_id,c.name class_name,c.active,COUNT(e.id) student_count,SUM(CASE WHEN e.id IS NOT NULL AND ('.implode(' OR ',$missing).') THEN 1 ELSE 0 END) missing_count FROM '.Support::table('classes').' c LEFT JOIN '.Support::table('enrollments').' e ON e.class_id=c.id AND e.active=1 AND e.deleted_at IS NULL AND EXISTS (SELECT 1 FROM '.Support::table('people').' p WHERE p.user_id=e.student_id AND p.deleted_at IS NULL) WHERE c.term_id=%d AND c.teacher_id=%d AND c.deleted_at IS NULL GROUP BY c.id,c.name,c.active ORDER BY c.name,c.id',$tid,$uid),ARRAY_A);
        self::need(is_array($classes) && count($classes)>0 && !$wpdb->last_error,'بررسی تکمیل نمرات انجام نشد.',500);
        $pending=array();$students=0;$incomplete=0;
        foreach ($classes as $c) {
            $students+=(int)$c['student_count'];$incomplete+=(int)$c['missing_count'];
            if ((int)$c['missing_count']>0) $pending[]=array('class_id'=>(int)$c['class_id'],'class_name'=>$c['class_name'],'missing_count'=>(int)$c['missing_count'],'active'=>(bool)$c['active']);
        }
        $out['term_id']=$tid;$out['selection_required']=false;
        $out['student_count']=$students;$out['incomplete_count']=$incomplete;
        if ($incomplete>0) {
            // No rates, amounts, adjustments or totals are read or returned.
            $out['pending_classes']=$pending;
            $out['message']='برای مشاهدهٔ حقوق این ترم، تمام نمرات زبان‌آموزان همهٔ کلاس‌های این ترم را تکمیل کنید.';
            return $out;
        }
        $q=new \WP_REST_Request('GET','/nasr/v1/my-salary');
        $q->set_param('term_id',$tid);$q->set_param('teacher_id',$uid);
        $report=self::teacher_pay_detail($q,true);
        $out['locked']=false;$out['items']=array();
        foreach ($report['items'] as $c) $out['items'][]=array('class_id'=>(int)$c['id'],'class_name'=>$c['class_name'],'amount'=>$c['teaching_net']);
        $out['totals']=array('teaching'=>$report['calculation']['teaching_net'],'addition'=>$report['calculation']['salary_addition'],'deduction'=>$report['calculation']['salary_deduction'],'net'=>$report['calculation']['net']);
        return $out;
    }
    private static function payroll_rows($record) {
        if (!$record) return array();
        $rows=json_decode($record['rows_json'],true);
        self::need(is_array($rows),'خواندن ردیف‌های اضافه و کاهش حقوق انجام نشد.',500);
        foreach ($rows as $index=>&$row) {
            if (($row['kind'] ?? '')==='addition' && !isset($row['hourly_rate'],$row['sessions'],$row['total_hours'])) $row['legacy_index']=$index;
        }
        unset($row);return $rows;
    }
    private static function payroll_adjustment_rows($incoming,$old) {
        self::need(is_array($incoming) && count($incoming)<=400,'حداکثر ۴۰۰ ردیف تعدیل حقوق مجاز است.');
        $rows=array();$totals=array('addition'=>0,'deduction'=>0);$legacy=self::payroll_rows($old);$used=array();
        foreach ($incoming as $row) {
            self::need(is_array($row) && in_array($row['kind'] ?? '',array('addition','deduction'),true),'نوع ردیف حقوق معتبر نیست.');
            $entry=array('kind'=>$row['kind'],'reason'=>self::text($row,'reason',true,200));
            if ($entry['kind']==='deduction') {
                $entry['amount']=self::valid(Support::number($row['amount'] ?? '',1,1000000000000),'مبلغ کاهش حقوق به تومان');
            } else {
                $has_details=false;
                foreach (array('hourly_rate','sessions','total_hours') as $key) if (isset($row[$key]) && $row[$key]!=='') $has_details=true;
                if (!$has_details && isset($row['legacy_index'])) {
                    $index=self::valid(Support::number($row['legacy_index'],0,399));$previous=$legacy[$index] ?? null;
                    self::need($previous && isset($previous['legacy_index']) && !isset($used[$index]),'ردیف قبلی اضافه‌حقوق معتبر نیست؛ اطلاعات را تازه کنید.',409);
                    $used[$index]=true;
                    // Preserve historical lump sums without inventing hours or trusting client amounts.
                    $entry['amount']=self::valid(Support::number($previous['amount'],1,1000000000000));
                } else {
                    $entry['hourly_rate']=self::valid(Support::number($row['hourly_rate'] ?? '',1,1000000000),'حق‌التدریس ساعتی');
                    $entry['sessions']=self::valid(Support::number($row['sessions'] ?? '',1,1000),'تعداد جلسات');
                    $entry['total_hours']=self::valid(Support::number($row['total_hours'] ?? '',0.01,100000,2),'میزان ساعت کل');
                    $hundredths=(int)round($entry['total_hours']*100);
                    $entry['amount']=(int)floor(($entry['hourly_rate']*$hundredths+50)/100);
                    self::need($entry['amount']>=1 && $entry['amount']<=1000000000000,'مبلغ کل اضافه‌حقوق باید بین ۱ تا ۱٬۰۰۰٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.');
                }
            }
            $totals[$entry['kind']]+=$entry['amount'];
            self::need($totals[$entry['kind']]<=2000000000000,'جمع اضافه یا کاهش حقوق بیش از حد مجاز است.');
            $rows[]=$entry;
        }
        return array('rows'=>$rows,'totals'=>$totals);
    }
    private static function payroll_teacher($term_id,$teacher_id) {
        global $wpdb;
        self::row('terms',$term_id);
        $teacher=Support::profile($teacher_id);self::need($teacher && $teacher['kind']==='teacher' && empty($teacher['deleted_at']),'معلم معتبر نیست.');
        self::need($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('classes').' WHERE term_id=%d AND teacher_id=%d AND deleted_at IS NULL LIMIT 1',$term_id,$teacher_id)),'معلم انتخاب‌شده در این ترم کلاس ندارد.');
    }
    private static function teacher_payroll($r) {
        global $wpdb;self::admin();
        $out=array('terms'=>$wpdb->get_results('SELECT t.*,b.name branch_name FROM '.Support::table('terms').' t LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id WHERE t.deleted_at IS NULL ORDER BY t.start_date DESC,t.id DESC',ARRAY_A),'teachers'=>array(),'rows'=>array(),'version'=>0,'calculation'=>null,'classes'=>array());
        if (!$r['term_id']) return $out;
        $tid=self::valid(Support::number($r['term_id'],1,PHP_INT_MAX));
        if (!in_array($tid,array_map('intval',array_column($out['terms'],'id')),true)) return $out;
        $out['teachers']=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT p.user_id,p.first_name,p.last_name,p.deleted_at FROM ".Support::table('people').' p JOIN '.Support::table('classes')." c ON c.teacher_id=p.user_id WHERE c.term_id=%d AND p.kind='teacher' AND c.deleted_at IS NULL AND p.deleted_at IS NULL ORDER BY p.last_name,p.first_name,p.user_id",$tid),ARRAY_A);
        if (!$r['teacher_id']) return $out;
        $uid=self::valid(Support::number($r['teacher_id'],1,PHP_INT_MAX));
        if (!in_array($uid,array_map('intval',array_column($out['teachers'],'user_id')),true)) return $out;
        $record=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('payroll_adjustments').' WHERE term_id=%d AND teacher_id=%d',$tid,$uid),ARRAY_A);
        if ($record) { $out['rows']=self::payroll_rows($record);$out['version']=(int)$record['version'];$out['updated_at']=$record['updated_at']; }
        $out['classes']=$wpdb->get_results($wpdb->prepare('SELECT id,name,hourly_rate,sessions_held,duration,late_minutes,deleted_at FROM '.Support::table('classes').' WHERE term_id=%d AND teacher_id=%d AND deleted_at IS NULL ORDER BY name,id',$tid,$uid),ARRAY_A);
        $q=new \WP_REST_Request('GET','/nasr/v1/reports');foreach(array('term_id'=>$tid,'teacher_id'=>$uid,'type'=>'teacher_pay') as $k=>$v)$q->set_param($k,$v);
        $report=self::teacher_pay_detail($q);$out['calculation']=$report['calculation'];
        return $out;
    }
    private static function save_teacher_payroll($r) {
        global $wpdb;self::admin();$b=self::body($r);
        $tid=self::valid(Support::number($b['term_id'] ?? '',1,PHP_INT_MAX));$uid=self::valid(Support::number($b['teacher_id'] ?? '',1,PHP_INT_MAX));self::payroll_teacher($tid,$uid);
        $table=Support::table('payroll_adjustments');$old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE term_id=%d AND teacher_id=%d",$tid,$uid),ARRAY_A);
        $version=self::valid(Support::number($b['version'] ?? '',0,2147483646));self::need($version===(int)($old['version'] ?? 0),'تعدیلات حقوق در پنجرهٔ دیگری تغییر کرده؛ اطلاعات را تازه کنید.',409);
        $parsed=self::payroll_adjustment_rows($b['rows'] ?? null,$old);$rows=$parsed['rows'];$totals=$parsed['totals'];
        $data=array('teacher_id'=>$uid,'term_id'=>$tid,'rows_json'=>wp_json_encode($rows,JSON_UNESCAPED_UNICODE),'addition'=>$totals['addition'],'deduction'=>$totals['deduction'],'version'=>$version+1,'updated_at'=>Support::now(),'updated_by'=>get_current_user_id());
        if($old)Support::checked($wpdb->update($table,$data,array('id'=>$old['id'],'version'=>$version)));
        else Support::checked($wpdb->insert($table,$data));
        Support::audit('payroll_adjustments_saved','teacher',$uid,array('term_id'=>$tid,'before'=>$old,'after'=>$data));
        return array('message'=>'اضافه‌حقوق و کاهش‌حقوق ذخیره و در گزارش مالی اعمال شد.','version'=>$version+1);
    }
    private static function payroll_class_rows($classes) {
        foreach ($classes as &$c) {
            $complete=$c['hourly_rate']!==null && $c['sessions_held']!==null && $c['duration']!==null;
            $c['minutes']=$c['sessions_held']!==null && $c['duration']!==null ? (int)$c['sessions_held']*(int)$c['duration'] : null;
            $c['hours']=$c['minutes']===null ? null : round($c['minutes']/60,2);
            $pay=$complete ? Support::payroll($c['hourly_rate'],$c['minutes'],$c['late_minutes']) : array('gross'=>null,'deduction'=>null,'net'=>null);
            $c['gross']=$pay['gross'];$c['deduction']=$pay['deduction'];$c['teaching_net']=$pay['net'];
            $c['missing']=$complete?0:1;
            $c['calculation_status']=$complete?'قابل محاسبه':'اطلاعات ناقص';
            if (!empty($c['deleted_at'])) $c['calculation_status'].=' · حذف‌شده';
            elseif (!(int)$c['active']) $c['calculation_status'].=' · غیرفعال';
        }
        unset($c);return $classes;
    }
    private static function payroll_class_totals($classes) {
        $keys=array('sessions_planned','sessions_held','duration','hours','hourly_rate','late_minutes','gross','deduction','teaching_net','missing');
        $out=array_fill_keys($keys,0);$out['class_name']='جمع';
        foreach ($classes as $c) foreach ($keys as $key) if ($c[$key]!==null) $out[$key]+=$c[$key];
        $out['hours']=round($out['hours'],2);$out['class_count']=count($classes);
        $out['calculation_status']=$out['missing'] ? $out['missing'].' کلاس ناقص' : 'قابل محاسبه';
        return $out;
    }
    private static function teacher_pay_detail($r,$all_rows=false) {
        global $wpdb;
        $tid=self::valid(Support::number($r['term_id'],1,PHP_INT_MAX));$uid=self::valid(Support::number($r['teacher_id'],1,PHP_INT_MAX));
        self::need(!$r['class_id'],'گزارش حق‌التدریس ترم را بدون فیلتر یک کلاس بگیرید.');
        $term=self::row('terms',$tid);$teacher=Support::profile($uid);
        self::need($teacher && $teacher['kind']==='teacher' && empty($teacher['deleted_at']),'معلم معتبر نیست.');
        if ($r['branch_id']!==null && $r['branch_id']!=='') self::need((int)$term['branch_id']===self::valid(Support::number($r['branch_id'],0,PHP_INT_MAX)),'شعبه با ترم انتخابی مطابقت ندارد.');
        if ($r['year']!==null && $r['year']!=='') { list($start,$end)=self::report_year($r['year']);self::need($term['start_date']>=$start && $term['start_date']<$end,'سال با ترم انتخابی مطابقت ندارد.'); }
        $classes=$wpdb->get_results($wpdb->prepare('SELECT id,name class_name,room,schedule,sessions_planned,sessions_held,duration,hourly_rate,late_minutes,active,deleted_at FROM '.Support::table('classes').' WHERE term_id=%d AND teacher_id=%d AND deleted_at IS NULL ORDER BY name,id',$tid,$uid),ARRAY_A);
        self::need(is_array($classes) && !$wpdb->last_error,'بازیابی کلاس‌های معلم انجام نشد.',500);
        self::need(count($classes)>0,'معلم انتخاب‌شده در این ترم کلاس ندارد.');
        $classes=self::payroll_class_rows($classes);$all=self::payroll_class_totals($classes);
        $record=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('payroll_adjustments').' WHERE term_id=%d AND teacher_id=%d',$tid,$uid),ARRAY_A);
        self::need(!$wpdb->last_error,'بازیابی اضافه و کاهش حقوق انجام نشد.',500);
        $adjustments=array('addition'=>array(),'deduction'=>array());$sums=array('addition'=>0,'deduction'=>0);
        foreach (self::payroll_rows($record) as $entry) {
            self::need(isset($adjustments[$entry['kind']]),'ردیف حقوق معتبر نیست.',500);
            $entry['amount']=(int)$entry['amount'];$adjustments[$entry['kind']][]=$entry;$sums[$entry['kind']]+=$entry['amount'];
        }
        $calculation=array('class_count'=>$all['class_count'],'missing'=>$all['missing'],'gross'=>$all['gross'],'deduction'=>$all['deduction'],
            'calculable_teaching_net'=>$all['teaching_net'],'teaching_net'=>$all['missing']?null:$all['teaching_net'],
            'salary_addition'=>$sums['addition'],'salary_deduction'=>$sums['deduction'],
            'calculable_net'=>$all['teaching_net']+$sums['addition']-$sums['deduction'],
            'net'=>$all['missing']?null:$all['teaching_net']+$sums['addition']-$sums['deduction']);
        $pairs=array('class_name'=>'نام کلاس','room'=>'اتاق','schedule'=>'روز و ساعت کلاس','sessions_planned'=>'جلسات برنامه‌ریزی‌شده|number','sessions_held'=>'جلسات برگزارشده|number','duration'=>'دقیقه هر جلسه|number','hours'=>'ساعت کل|number','hourly_rate'=>'حق‌التدریس ساعتی|toman','late_minutes'=>'تأخیر (دقیقه)|number','gross'=>'حق‌التدریس ناخالص|toman','deduction'=>'کسورات تأخیر|toman','teaching_net'=>'حق‌التدریس قابل محاسبه|toman','calculation_status'=>'وضعیت');
        $q=trim(Support::digits(self::text(array('q'=>$r['q'] ?? ''),'q',false,150)));
        if ($q!=='') {
            $normalize=function($v){return str_replace(array('ي','ك','‌',' '),array('ی','ک','',''),Support::digits((string)$v));};
            $needle=$normalize($q);
            $classes=array_values(array_filter($classes,function($c)use($pairs,$normalize,$needle){foreach(array_keys($pairs) as $key) if (stripos($normalize($c[$key] ?? ''),$needle)!==false) return true;return false;}));
        }
        $totals=self::payroll_class_totals($classes);$total=count($classes);list($page,$per)=self::page($r);
        $branch=$wpdb->get_var($wpdb->prepare('SELECT name FROM '.Support::table('branches').' WHERE id=%d',$term['branch_id']));
        $metadata=array(array('label'=>'ترم','value'=>$term['name'].' · '.$term['code']),array('label'=>'معلم','value'=>trim($teacher['first_name'].' '.$teacher['last_name'])),array('label'=>'شعبه','value'=>$branch ?: 'تعیین نشده'));
        foreach (array('start_date'=>'شروع ترم','end_date'=>'پایان ترم','mid_date'=>'میان‌ترم','final_date'=>'آزمون پایان‌ترم') as $key=>$label) $metadata[]=array('label'=>$label,'value'=>$term[$key],'type'=>'date');
        return array('title'=>'حق‌التدریس معلم در یک ترم','layout'=>'teacher_pay_detail','columns'=>self::report_columns($pairs),'items'=>$all_rows?$classes:array_slice($classes,($page-1)*$per,$per),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$per)),
            'summary'=>array(),'note'=>'','metadata'=>$metadata,'totals'=>$totals,'totals_label'=>$q!==''?'جمع کلاس‌های نتیجهٔ جست‌وجو':'جمع کل کلاس‌های ترم','adjustments'=>$adjustments,'calculation'=>$calculation);
    }
}
