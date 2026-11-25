<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

trait SMS_Data {
    public static function purposes(){return array('general'=>'اطلاع‌رسانی عمومی','schedule'=>'اعلام برنامهٔ کلاس','debt'=>'یادآوری شهریه','enrollment'=>'ثبت‌نام در کلاس','payment'=>'رسید پرداخت','grades'=>'اعلام نمره','attendance'=>'غیبت و تأخیر','exam'=>'اطلاع‌رسانی آزمون');}
    public static function event_names(){return array('manual'=>'ارسال دستی / زمان‌بندی توسط مدیر','enrolled'=>'پس از ثبت‌نام در کلاس','schedule_changed'=>'پس از تغییر برنامهٔ کلاس','payment_received'=>'پس از پرداخت موفق','grades_saved'=>'پس از ثبت نمره','absence'=>'پس از ثبت غیبت','late'=>'پس از ثبت تأخیر','class_reminder'=>'یادآوری پیش از جلسه','debt_reminder'=>'یادآوری دوره‌ای شهریه','exam_reminder'=>'یادآوری پیش از آزمون');}
    public static function variables(){return array('academy'=>'نام آموزشگاه','first_name'=>'نام فارسی زبان‌آموز','last_name'=>'نام خانوادگی فارسی','full_name'=>'نام کامل فارسی','english_name'=>'نام کامل انگلیسی','student_code'=>'کد زبان‌آموز','recipient_name'=>'نام گیرنده','class_name'=>'نام کلاس','term_name'=>'نام ترم','branch_name'=>'نام شعبه','teacher_name'=>'نام معلم','schedule'=>'روزها و ساعت کلاس','start_time'=>'ساعت شروع','duration'=>'مدت جلسه (دقیقه)','room'=>'اتاق','term_start'=>'شروع ترم','term_end'=>'پایان ترم','balance'=>'ماندهٔ شهریهٔ این کلاس','paid'=>'جمع پرداخت موفق','fee'=>'شهریهٔ خالص','currency'=>'واحد شهریه','payment_amount'=>'مبلغ پرداخت اخیر','payment_date'=>'تاریخ پرداخت اخیر','total_grade'=>'نمره کل','session_no'=>'شماره جلسه','session_date'=>'تاریخ جلسه','attendance_status'=>'وضعیت حضور','exam_date'=>'تاریخ آزمون','exam_name'=>'نام آزمون','support_phone'=>'شماره پشتیبانی');}
    public static function template_defaults(){return array('mode'=>'text','content'=>'','body_id'=>'','params'=>'','audience'=>'student','branch_id'=>'','term_id'=>'','class_id'=>'','hours_before'=>2,'debt_after_days'=>7,'repeat_days'=>7);}
    public static function samples(){return array(
        array('name'=>'اعلام برنامه کلاس','purpose'=>'schedule','content'=>'{full_name} عزیز، برنامه کلاس {class_name} در ترم {term_name}: {schedule}. محل: {room}. {academy}'),
        array('name'=>'یادآوری شهریه','purpose'=>'debt','content'=>'{full_name} عزیز، مانده شهریه کلاس {class_name} شما {balance} {currency} است. لطفاً برای پرداخت با آموزشگاه هماهنگ کنید. {academy}'),
        array('name'=>'تأیید ثبت‌نام','purpose'=>'enrollment','content'=>'{full_name} عزیز، ثبت‌نام شما در کلاس {class_name} انجام شد. {schedule}. {academy}'),
        array('name'=>'رسید پرداخت','purpose'=>'payment','content'=>'{full_name} عزیز، پرداخت {payment_amount} {currency} در تاریخ {payment_date} ثبت شد. مانده کلاس {class_name}: {balance} {currency}. {academy}'),
        array('name'=>'اعلام غیبت','purpose'=>'attendance','content'=>'وضعیت {full_name} در جلسه {session_no} کلاس {class_name} به تاریخ {session_date}: {attendance_status}. {academy}'),
        array('name'=>'آماده شدن نمره','purpose'=>'grades','content'=>'{full_name} عزیز، نمرات کلاس {class_name} در پنل شما ثبت شد. نمره کل: {total_grade}. {academy}'),
        array('name'=>'یادآوری آزمون','purpose'=>'exam','content'=>'{full_name} عزیز، {exam_name} کلاس {class_name} در تاریخ {exam_date} برگزار می‌شود. برای ساعت آزمون با آموزشگاه هماهنگ کنید. {academy}')
    );}
    private static function template($id){global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('sms_templates').' WHERE id=%d',absint($id)),ARRAY_A);self::need($row,'قالب پیدا نشد.',404);return array_merge(self::template_defaults(),json_decode($row['config'],true)?:array(),array_diff_key($row,array('config'=>1)));}
    private static function templates($r){global $wpdb;$ids=$wpdb->get_col('SELECT id FROM '.Support::table('sms_templates').' ORDER BY id DESC');return array_map(array(__CLASS__,'template'),$ids);}
    private static function save_template($r){
        global $wpdb;$b=self::body($r);$id=absint($r['id']);$old=$id?self::template($id):null;
        self::need($old || (int)$wpdb->get_var('SELECT COUNT(*) FROM '.Support::table('sms_templates'))<100,'حداکثر ۱۰۰ قالب تعریف کنید.');
        if($old)self::need(self::n($b['version']??0,1,2147483647)===(int)$old['version'],'قالب تغییر کرده؛ آن را دوباره باز کنید.',409);
        $name=self::clean($b['name']??'',150);self::need($name!=='','نام قالب را وارد کنید.');
        $purpose=$b['purpose']??'general';$event=$b['event']??'manual';self::need(is_string($purpose)&&isset(self::purposes()[$purpose])&&is_string($event)&&isset(self::event_names()[$event]),'کاربرد یا رویداد معتبر نیست.');
        $allowed=array('enrolled'=>'enrollment','schedule_changed'=>'schedule','payment_received'=>'payment','grades_saved'=>'grades','absence'=>'attendance','late'=>'attendance','class_reminder'=>'schedule','debt_reminder'=>'debt','exam_reminder'=>'exam');
        self::need($event==='manual'||$purpose===$allowed[$event],'کاربرد قالب با رویداد خودکار مطابقت ندارد.');
        $v=self::template_defaults();foreach(array('content'=>2000,'params'=>2000,'body_id'=>25)as$k=>$max)$v[$k]=self::clean($b[$k]??'',$max);
        $v['body_id']=Support::digits($v['body_id']);
        foreach(array('mode'=>array('text','pattern'),'audience'=>array('student','family','both'))as$k=>$values){self::need(in_array($b[$k]??'', $values,true),'روش ارسال یا گیرنده معتبر نیست.');$v[$k]=$b[$k];}
        self::need($v['content']!=='','متن قالب / متن مرجع پترن را وارد کنید.');
        if($v['mode']==='pattern'){self::need(preg_match('/^[1-9]\d{0,9}$/D',$v['body_id']),'کد عددی پترن تأییدشده را وارد کنید.');self::need(trim($v['params'])!=='','متغیرهای پترن را به ترتیب، هر کدام در یک خط وارد کنید.');}
        foreach(array('content','params')as$k){preg_match_all('/\{([^{}]+)\}/u',$v[$k],$vars);foreach($vars[1]as$var)self::need(isset(self::variables()[$var]),'متغیر ناشناخته: '.$var);}
        foreach(array('branch_id','term_id','class_id')as$k){$v[$k]=isset($b[$k])&&$b[$k]!==''?self::n($b[$k],$k==='branch_id'?0:1,PHP_INT_MAX):'';}
        foreach(array('hours_before'=>array(1,168),'debt_after_days'=>array(0,365),'repeat_days'=>array(1,90))as$k=>$lim)$v[$k]=self::n($b[$k]??$v[$k],$lim[0],$lim[1]);
        self::validate_scope($v);
        $data=array('name'=>$name,'purpose'=>$purpose,'event'=>$event,'config'=>wp_json_encode($v,JSON_UNESCAPED_UNICODE),'active'=>!empty($b['active'])?1:0,'version'=>$old?(int)$old['version']+1:1,'updated_at'=>Support::now());
        if($id)Support::checked($wpdb->update(Support::table('sms_templates'),$data,array('id'=>$id)));else{Support::checked($wpdb->insert(Support::table('sms_templates'),$data));$id=$wpdb->insert_id;}
        Support::audit('sms_template_saved','sms_template',$id,array('name'=>$name,'event'=>$event,'active'=>$data['active']));return array('id'=>$id,'message'=>'قالب پیامک ذخیره شد.');
    }
    private static function validate_scope($f){
        global $wpdb;$term=null;
        if(!empty($f['class_id'])){$c=$wpdb->get_row($wpdb->prepare('SELECT term_id FROM '.Support::table('classes').' WHERE id=%d AND deleted_at IS NULL',$f['class_id']),ARRAY_A);self::need($c,'کلاس معتبر نیست.');self::need(empty($f['term_id'])||(int)$f['term_id']===(int)$c['term_id'],'کلاس متعلق به ترم انتخابی نیست.');$term=$c['term_id'];}
        if(!empty($f['term_id']))$term=$f['term_id'];
        if($term){$t=$wpdb->get_row($wpdb->prepare('SELECT branch_id FROM '.Support::table('terms').' WHERE id=%d AND deleted_at IS NULL',$term),ARRAY_A);self::need($t,'ترم معتبر نیست.');self::need(!isset($f['branch_id'])||$f['branch_id']===''||(int)$f['branch_id']===(int)$t['branch_id'],'شعبه با ترم مطابقت ندارد.');}
    }
    private static function contexts($f=array(),$after=0,$limit=5001){
        global $wpdb;$where='e.active=1 AND e.deleted_at IS NULL AND p.kind IN (\'student\',\'teacher\') AND p.active=1 AND p.deleted_at IS NULL AND c.active=1 AND c.deleted_at IS NULL AND t.active=1 AND t.deleted_at IS NULL';
        if(array_key_exists('enrollment_ids',$f)){
            self::need(is_array($f['enrollment_ids'])&&count($f['enrollment_ids'])>0&&count($f['enrollment_ids'])<=5000,'حداقل یک زبان‌آموز انتخاب کنید.');
            $ids=array_map(function($id){return self::n($id,1,PHP_INT_MAX);},$f['enrollment_ids']);
            $where.=$wpdb->prepare(' AND e.id IN ('.implode(',',array_fill(0,count($ids),'%d')).')',$ids);
        }
        foreach(array('class_id'=>'c.id','term_id'=>'t.id','student_id'=>'p.user_id','enrollment_id'=>'e.id')as$k=>$col){if(!empty($f[$k]))$where.=$wpdb->prepare(' AND '.$col.'=%d',self::n($f[$k],1,PHP_INT_MAX));}
        if(isset($f['branch_id'])&&$f['branch_id']!=='')$where.=$wpdb->prepare(' AND COALESCE(t.branch_id,0)=%d',self::n($f['branch_id'],0,PHP_INT_MAX));
        if(!empty($f['debt']))$where.=' AND e.net_fee>COALESCE(pay.paid,0)';
        if(!empty($f['unpaid']))$where.=' AND COALESCE(pay.paid,0)=0';
        if($after)$where.=$wpdb->prepare(' AND e.id>%d',$after);
        $sql='SELECT e.id enrollment_id,e.student_id,e.net_fee,COALESCE(pay.paid,0) paid,GREATEST(e.net_fee-COALESCE(pay.paid,0),0) balance,e.listening,e.speaking,e.reading,e.writing,e.midterm,e.final_exam,p.first_name,p.last_name,p.first_name_en,p.last_name_en,p.mobile,p.student_code,c.id class_id,c.name class_name,c.schedule,c.class_days,c.class_day,c.start_time,c.duration,c.room,c.teacher_id,t.id term_id,t.name term_name,t.branch_id,t.start_date,t.end_date,t.mid_date,t.final_date,COALESCE(b.name,\'تعیین نشده\') branch_name,CONCAT(teacher.first_name,\' \',teacher.last_name) teacher_name FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id LEFT JOIN '.Support::table('branches').' b ON b.id=t.branch_id LEFT JOIN '.Support::table('people').' teacher ON teacher.user_id=c.teacher_id LEFT JOIN (SELECT enrollment_id,SUM(amount) paid FROM '.Support::table('payments')." WHERE status='success' AND voided_at IS NULL GROUP BY enrollment_id) pay ON pay.enrollment_id=e.id WHERE $where ORDER BY e.id LIMIT ".(int)$limit;
        $rows=$wpdb->get_results($sql,ARRAY_A);self::need(is_array($rows)&&!$wpdb->last_error,'خواندن مخاطبان انجام نشد.',500);return $rows;
    }
    public static function date_label($day){if(!$day)return 'تعیین نشده';$d=array_map('intval',explode('-',substr($day,0,10)));if(Support::settings()['calendar']==='jalali')$d=Support::gregorian_to_jalali($d[0],$d[1],$d[2]);return vsprintf('%04d/%02d/%02d',$d);}
    private static function values($c,$extra=array()){
        $s=Support::settings();$name=trim(($c['first_name']??'').' '.($c['last_name']??''));
        $v=array('academy'=>$s['academy_name'],'support_phone'=>$s['support_phone'],'first_name'=>$c['first_name']??'','last_name'=>$c['last_name']??'','full_name'=>$name,'english_name'=>trim(($c['first_name_en']??'').' '.($c['last_name_en']??'')),'currency'=>$s['currency'],'recipient_name'=>$name);
        foreach(array('student_code','class_name','term_name','branch_name','teacher_name','schedule','start_time','duration','room','balance','paid')as$k){if(array_key_exists($k,$c))$v[$k]=(string)($c[$k]??'');}
        if(isset($c['net_fee']))$v['fee']=(string)$c['net_fee'];
        if(isset($c['start_date'])){$v['term_start']=self::date_label($c['start_date']);$v['term_end']=self::date_label($c['end_date']);}
        if(array_key_exists('listening',$c)){$grades=array_intersect_key($c,array_flip(array('listening','speaking','reading','writing','midterm','final_exam')));$v['total_grade']=count($grades)===6&&!in_array(null,$grades,true)?(string)array_sum($grades):'در حال تکمیل';}
        return array_merge($v,$extra);
    }
    private static function render_text($text,$values){
        return preg_replace_callback('/\{([^{}]+)\}/u',function($m)use($values){self::need(array_key_exists($m[1],$values),'اطلاعات متغیر {'.$m[1].'} برای این مخاطب/کاربرد موجود نیست.');return (string)$values[$m[1]];},$text);
    }
    private static function destinations($c,$audience){
        global $wpdb;$out=array();if(in_array($audience,array('student','both'),true))$out[]=array('user_id'=>$c['student_id'],'mobile'=>$c['mobile'],'name'=>trim($c['first_name'].' '.$c['last_name']));
        if(in_array($audience,array('family','both'),true)){$rows=$wpdb->get_results($wpdb->prepare('SELECT p.user_id,p.mobile,CONCAT(p.first_name,\' \',p.last_name) name FROM '.Support::table('family_links').' l JOIN '.Support::table('people')." p ON p.user_id=l.family_id WHERE l.student_id=%d AND p.kind='family' AND p.active=1 AND p.deleted_at IS NULL",$c['student_id']),ARRAY_A);$out=array_merge($out,$rows?:array());}
        return $out;
    }
    private static function schedule_signature($c){return hash('sha256',wp_json_encode(array_intersect_key($c,array_flip(array('class_days','class_day','start_time','duration','start_date','end_date','room')))));}
    private static function make_message($c,$dest,$template,$extra=array()){
        $phone=Support::phone($dest['mobile'],true);self::need(!is_wp_error($phone)&&$phone!=='','شماره همراه گیرنده معتبر نیست.');
        $values=self::values($c,array_merge($extra,array('recipient_name'=>$dest['name'])));
        $content=self::render_text($template['content'],$values);$params=array();
        if($template['mode']==='pattern'){
            foreach(preg_split('/\R/u',trim($template['params']))as$line){$value=trim(self::render_text($line,$values));self::need($value!==''&&strpos($value,';')===false&&Support::length($value)<=500,'متغیر پترن خالی، بیش از حد طولانی یا حاوی ; است.');$params[]=$value;}
            self::need(count($params)<=20,'حداکثر ۲۰ متغیر برای پترن مجاز است.');
        }else{if(self::settings()['signature']!=='')$content.="\n".self::settings()['signature'];}
        self::need(trim($content)!==''&&Support::length($content)<=2000,'متن نهایی خالی یا بیشتر از ۲۰۰۰ نویسه است.');
        $ctx=array('purpose'=>$template['purpose'],'class_id'=>$c['class_id']??0,'balance'=>$c['balance']??null,'schedule_signature'=>isset($c['class_id'])?self::schedule_signature($c):'','extra'=>$extra);
        return array('template_id'=>$template['id']??0,'user_id'=>$dest['user_id'],'student_id'=>$c['student_id']??0,'enrollment_id'=>$c['enrollment_id']??0,'recipient'=>$phone,'recipient_name'=>$dest['name'],'content'=>$content,'params'=>wp_json_encode($params,JSON_UNESCAPED_UNICODE),'mode'=>$template['mode'],'body_id'=>$template['body_id'],'sender'=>self::settings()['sender'],'context_json'=>wp_json_encode($ctx,JSON_UNESCAPED_UNICODE));
    }
    private static function new_campaign($title,$template,$key,$source,$state,$scheduled,$expires){
        global $wpdb;$table=Support::table('sms_campaigns');$old=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE request_key=%s",$key));if($old)return (int)$old;
        Support::checked($wpdb->insert($table,array('title'=>$title,'template_id'=>$template,'request_key'=>$key,'source'=>$source,'state'=>$state,'created_by'=>get_current_user_id(),'created_at'=>Support::now(),'scheduled_at'=>$scheduled,'expires_at'=>$expires)));return (int)$wpdb->insert_id;
    }
    private static function add_message($campaign,$data,$key,$scheduled,$expires){
        global $wpdb;$M=Support::table('sms_messages');$key=hash('sha256',$key);if($wpdb->get_var($wpdb->prepare("SELECT id FROM $M WHERE dedupe_key=%s",$key)))return false;
        Support::checked($wpdb->insert($M,array_merge($data,array('campaign_id'=>$campaign,'status'=>'queued','dedupe_key'=>$key,'scheduled_at'=>$scheduled,'expires_at'=>$expires,'error_message'=>'','updated_at'=>Support::now()))));return true;
    }
    private static function manual_selection($b){
        if(!array_key_exists('enrollment_ids',$b))return null;
        self::need(is_array($b['enrollment_ids'])&&count($b['enrollment_ids'])>0&&count($b['enrollment_ids'])<=5000,'حداقل یک زبان‌آموز و حداکثر ۵۰۰۰ نفر انتخاب کنید.');
        self::need(empty($b['student_id']),'انتخاب تکی و گروهی را هم‌زمان ارسال نکنید.');
        $class=self::n($b['class_id']??'',1,PHP_INT_MAX);
        $ids=array_values(array_unique(array_map(function($id){return self::n($id,1,PHP_INT_MAX);},$b['enrollment_ids'])));sort($ids,SORT_NUMERIC);
        global $wpdb;
        $sql='SELECT e.id FROM '.Support::table('enrollments').' e JOIN '.Support::table('people').' p ON p.user_id=e.student_id JOIN '.Support::table('classes').' c ON c.id=e.class_id JOIN '.Support::table('terms').' t ON t.id=c.term_id WHERE e.class_id=%d AND e.id IN ('.implode(',',array_fill(0,count($ids),'%d')).") AND e.active=1 AND e.deleted_at IS NULL AND p.kind IN ('student','teacher') AND p.active=1 AND p.deleted_at IS NULL AND c.active=1 AND c.deleted_at IS NULL AND t.active=1 AND t.deleted_at IS NULL";
        $found=$wpdb->get_col($wpdb->prepare($sql,array_merge(array($class),$ids)));
        self::need(is_array($found)&&!$wpdb->last_error,'خواندن زبان‌آموزان کلاس انجام نشد.',500);
        self::need(count($found)===count($ids),'یکی از زبان‌آموزان انتخاب‌شده در این کلاس فعال نیست؛ فهرست کلاس را تازه کنید.');
        return $ids;
    }
    private static function preview($r){
        global $wpdb;$b=self::body($r);$t=self::template($b['template_id']??0);self::need((int)$t['active'],'قالب غیرفعال است.');
        $key=self::clean($b['request_key']??'',64);self::need(preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$key),'شناسه درخواست معتبر نیست.');
        $selected=self::manual_selection($b);
        if($selected!==null){
            $signature=$b;unset($signature['request_key']);$signature['enrollment_ids']=$selected;ksort($signature);
            $key=hash('sha256',$key.'|'.get_current_user_id().'|'.wp_json_encode($signature));
        }
        $prior=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('sms_campaigns').' WHERE request_key=%s',$key));if($prior)return array('id'=>(int)$prior,'message'=>'پیش‌نمایش قبلی همین درخواست باز شد.');
        $f=array();foreach(array('branch_id','term_id','class_id','student_id')as$k)$f[$k]=isset($b[$k])&&$b[$k]!==''?self::n($b[$k],$k==='branch_id'?0:1,PHP_INT_MAX):'';
        if($selected!==null)$f['enrollment_ids']=$selected;
        self::validate_scope($f);$f['debt']=$t['purpose']==='debt';$f['unpaid']=!empty($b['unpaid']);
        $scheduled=Support::now();if(!empty($b['send_date'])){$d=Support::date($b['send_date']);self::need(!is_wp_error($d),'تاریخ ارسال معتبر نیست.');$time=Support::digits(self::clean($b['send_time']??'',5));self::need(preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time),'ساعت ارسال مثل 16:30 باشد.');$dt=new \DateTimeImmutable($d.' '.$time,new \DateTimeZone('Asia/Tehran'));self::need($dt->getTimestamp()>time()&&$dt->getTimestamp()<time()+365*86400,'زمان ارسال باید در آینده و حداکثر یک سال بعد باشد.');$scheduled=$dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
        self::need(empty($b['send_time']) || !empty($b['send_date']),'برای زمان‌بندی، تاریخ را هم وارد کنید.');
        $extra=self::manual_extra($b,$t);
        if(!empty($extra['payment_id'])){
            if($selected!==null)self::need(count($selected)===1&&(int)$selected[0]===(int)$extra['payment_enrollment_id'],'برای قالب رسید پرداخت، فقط زبان‌آموز مربوط به همان پرداخت را انتخاب کنید.');
            $f['enrollment_id']=$extra['payment_enrollment_id'];
        }
        foreach(array('branch_id','term_id','class_id')as$k)if($t[$k]!=='') {self::need($f[$k]===''||(int)$f[$k]===(int)$t[$k],'محدودهٔ مخاطبان با محدودهٔ قالب مطابقت ندارد.');$f[$k]=$t[$k];}
        $expires=gmdate('Y-m-d H:i:s',strtotime($scheduled.' UTC')+self::settings()['expire_hours']*3600);
        $rows=self::contexts($f);self::need(count($rows)<=5000,'بیش از ۵۰۰۰ ثبت‌نام یافت شد؛ شعبه، ترم یا کلاس را محدود کنید.');
        $campaign=self::new_campaign(self::clean($b['title']??$t['name']),$t['id'],$key,'manual','draft',$scheduled,$expires);
        $count=0;$skipped=0;$reasons=array();$seen=array();
        foreach($rows as$c){
            if(!empty($extra['attendance_session_id'])){$actual=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Support::table('attendance').' WHERE session_id=%d AND enrollment_id=%d',$extra['attendance_session_id'],$c['enrollment_id']));if($actual!==$extra['attendance_raw'])continue;}
            $dests=self::destinations($c,$t['audience']);if(!$dests){$skipped++;$reasons['خانوادهٔ فعال مرتبط ثبت نشده است.']=true;}
            foreach($dests as$d){try{$msg=self::make_message($c,$d,$t,$extra);$fingerprint=hash('sha256',$msg['recipient'].'|'.$msg['content'].'|'.$msg['params']);if(isset($seen[$fingerprint])){$skipped++;continue;}$seen[$fingerprint]=true;
                $blocked=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('sms_optouts').' WHERE phone=%s',$msg['recipient']));if($blocked){$skipped++;$reasons['شماره در فهرست عدم ارسال است.']=true;continue;}
                self::add_message($campaign,$msg,$key.':'.$fingerprint,$scheduled,$expires);$count++;
            }catch(Problem $e){$skipped++;$reasons[$e->getMessage()]=true;}}
        }
        self::need($count>0,'مخاطب قابل ارسال وجود ندارد. '.implode(' ',array_keys($reasons)));
        Support::audit('sms_preview_created','sms_campaign',$campaign,array('count'=>$count,'skipped'=>$skipped));
        return array('id'=>$campaign,'message'=>'پیش‌نمایش ساخته شد؛ هنوز چیزی ارسال نشده است.','count'=>$count,'skipped'=>$skipped,'reasons'=>array_keys($reasons));
    }
    private static function manual_extra($b,$t){
        global $wpdb;$extra=array();
        if($t['purpose']==='attendance'){
            $class=self::n($b['class_id']??$t['class_id'],1,PHP_INT_MAX);$n=self::n($b['session_no']??'',1,1000);
            $session=$wpdb->get_row($wpdb->prepare('SELECT id,session_no,session_date FROM '.Support::table('attendance_sessions').' WHERE class_id=%d AND session_no=%d',$class,$n),ARRAY_A);
            self::need($session,'حضور و غیاب جلسهٔ این کلاس هنوز ذخیره نشده است.');
            $labels=array('absent'=>'غایب','late'=>'تأخیر','present'=>'حاضر','excused'=>'موجه');$status=$b['attendance_status']??'';self::need(isset($labels[$status]),'وضعیت حضور را انتخاب کنید.');
            $extra=array('attendance_session_id'=>$session['id'],'attendance_raw'=>$status,'session_no'=>(string)$n,'session_date'=>self::date_label($session['session_date']),'attendance_status'=>$labels[$status]);
        }
        if($t['purpose']==='exam'){$d=Support::date($b['exam_date']??'');self::need(!is_wp_error($d)&&$d,'تاریخ آزمون معتبر نیست.');$extra['exam_date']=self::date_label($d);$extra['exam_name']=self::clean($b['exam_name']??'آزمون',100);}
        if($t['purpose']==='payment'){
            $id=self::n($b['payment_id']??'',1,PHP_INT_MAX);$p=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('payments')." WHERE id=%d AND status='success' AND voided_at IS NULL",$id),ARRAY_A);self::need($p,'شناسهٔ یک پرداخت موفق و باطل‌نشده را وارد کنید.');
            $extra=array('payment_id'=>$id,'payment_enrollment_id'=>$p['enrollment_id'],'payment_amount'=>(string)$p['amount'],'payment_date'=>self::date_label($p['paid_on']));
        }
        return $extra;
    }
    private static function campaigns($r){
        global $wpdb;$page=max(1,absint($r['page']));$C=Support::table('sms_campaigns');$M=Support::table('sms_messages');
        $where='1=1';if($r['state']&&in_array($r['state'],array('draft','queued','paused','cancelled'),true))$where=$wpdb->prepare('c.state=%s',$r['state']);
        $rows=$wpdb->get_results("SELECT c.*,(SELECT COUNT(*) FROM $M m WHERE m.campaign_id=c.id) total,(SELECT COUNT(*) FROM $M m WHERE m.campaign_id=c.id AND m.status='sent') sent,(SELECT COUNT(*) FROM $M m WHERE m.campaign_id=c.id AND m.status='queued') waiting,(SELECT COUNT(*) FROM $M m WHERE m.campaign_id=c.id AND m.status IN ('failed','unknown')) problems FROM $C c WHERE $where ORDER BY c.id DESC LIMIT 25 OFFSET ".(($page-1)*25),ARRAY_A);
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $C c WHERE $where");return array('items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,ceil($total/25)));
    }
    private static function campaign($r){
        global $wpdb;$id=absint($r['id']);$c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('sms_campaigns').' WHERE id=%d',$id),ARRAY_A);self::need($c,'ارسال پیدا نشد.',404);
        $M=Support::table('sms_messages');$where=$wpdb->prepare('campaign_id=%d',$id);$page=max(1,absint($r['page']));
        $counts=$wpdb->get_results("SELECT status,COUNT(*) count FROM $M WHERE $where GROUP BY status",ARRAY_A);
        if(in_array($r['status'],array('queued','sending','sent','failed','unknown','skipped','cancelled'),true))$where.=$wpdb->prepare(' AND status=%s',$r['status']);
        $rows=$wpdb->get_results("SELECT id,user_id,student_id,enrollment_id,recipient,recipient_name,content,params,mode,body_id,status,rec_id,delivery_text,delivery_code,error_code,error_message,attempts,scheduled_at,expires_at,sent_at FROM $M WHERE $where ORDER BY id LIMIT 50 OFFSET ".(($page-1)*50),ARRAY_A);
        $queue=self::queue_status($id);foreach($rows as &$m)$m['waiting_reason']=self::waiting_reason($m,$c['state'],$queue);unset($m);
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $M WHERE $where");return array('campaign'=>$c,'counts'=>$counts,'items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,ceil($total/50)),'queue'=>$queue);
    }
    private static function campaign_action($r){
        global $wpdb;$b=self::body($r);$id=absint($r['id']);$c=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Support::table('sms_campaigns').' WHERE id=%d',$id),ARRAY_A);self::need($c,'ارسال پیدا نشد.',404);$action=$b['action']??'';$C=Support::table('sms_campaigns');$M=Support::table('sms_messages');
        if($action==='process'){
            self::need($c['state']==='queued','فقط ارسال تأییدشده و در صف قابل پردازش است.',409);
            return array('id'=>$id,'message'=>'وضعیت ارسال انتخاب‌شده بررسی شد.');
        }
        if($action==='queue'){
            self::need($c['state']==='draft','این پیش‌نمایش قبلاً تعیین تکلیف شده است.',409);self::need(($b['confirm']??false)===true,'ارسال به مخاطبان پیش‌نمایش را تأیید کنید.');self::need(self::settings()['enabled'],'ارسال پیامک در تنظیمات اتصال فعال نیست.');
            self::need($c['expires_at']>Support::now(),'مهلت پیش‌نمایش گذشته؛ دوباره پیش‌نمایش بگیرید.');
            if(($b['selection']??'all')==='selected'){$ids=$b['ids']??array();self::need(is_array($ids)&&count($ids)<=5000&&count($ids)>0,'حداقل یک مخاطب انتخاب کنید.');$ids=array_values(array_unique(array_map('absint',$ids)));$sql=implode(',',$ids);self::need((int)$wpdb->get_var("SELECT COUNT(*) FROM $M WHERE campaign_id=$id AND id IN ($sql)")===count($ids),'یکی از مخاطبان متعلق به این ارسال نیست.');Support::checked($wpdb->query("UPDATE $M SET status='cancelled' WHERE campaign_id=$id AND id NOT IN ($sql) AND status='queued'"));}
            $needs_sender=(int)$wpdb->get_var("SELECT COUNT(*) FROM $M WHERE campaign_id=$id AND status='queued' AND mode='text' AND sender=''");self::need(!$needs_sender,'خط فرستنده خالی است؛ در اتصال وارد کنید و پیش‌نمایش تازه بسازید.');$state='queued';
        }elseif($action==='pause'){self::need($c['state']==='queued','ارسال در صف نیست.');$state='paused';}
        elseif($action==='resume'){self::need($c['state']==='paused','ارسال متوقف نیست.');$state='queued';}
        elseif($action==='cancel'){$state='cancelled';Support::checked($wpdb->query($wpdb->prepare("UPDATE $M SET status='cancelled',updated_at=%s WHERE campaign_id=%d AND status='queued'",Support::now(),$id)));}
        else{throw new Problem('عملیات معتبر نیست.');}
        Support::checked($wpdb->update($C,array('state'=>$state),array('id'=>$id)));Support::audit('sms_campaign_changed','sms_campaign',$id,array('state'=>$state));return array('id'=>$id,'message'=>'وضعیت ارسال به‌روز شد. پیام در حال ارسال ممکن است تکمیل شود.');
    }
    private static function message_action($r){
        global $wpdb;$id=absint($r['id']);$b=self::body($r);$m=$wpdb->get_row($wpdb->prepare('SELECT m.*,c.source FROM '.Support::table('sms_messages').' m JOIN '.Support::table('sms_campaigns').' c ON c.id=m.campaign_id WHERE m.id=%d',$id),ARRAY_A);self::need($m,'پیام پیدا نشد.',404);
        if(($b['action']??'')==='delivery'){self::need($m['status']==='sent','پیام شناسهٔ ارسال موفق ندارد.');self::refresh_deliveries($id);return array('message'=>'وضعیت تحویل بررسی شد.');}
        return self::locked('worker',function()use($b,$m,$wpdb,$id){
            self::need(($b['action']??'')==='retry'&&in_array($m['status'],array('failed','unknown'),true),'این پیام قابل ارسال مجدد نیست.');self::need(($b['confirm']??false)===true,'ارسال مجدد را تأیید کنید.');
            if($m['status']==='unknown')self::need(($b['checked_provider']??false)===true,'ابتدا پنل ملی‌پیامک را بررسی و عدم ارسال را تأیید کنید.');
            self::need(self::settings()['enabled'],'ارسال غیرفعال است.');
            $state=$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Support::table('sms_campaigns').' WHERE id=%d',$m['campaign_id']));self::need($state==='queued'||$state==='paused','این مجموعه لغو شده یا هنوز تأیید نشده است.');
            $m['expires_at']=gmdate('Y-m-d H:i:s',time()+self::settings()['expire_hours']*3600);$reason=self::eligibility($m);self::need(!$reason,$reason?:'');
            self::message_update($id,array('status'=>'queued','error_code'=>'','error_message'=>'','scheduled_at'=>Support::now(),'expires_at'=>$m['expires_at']));Support::audit('sms_retry','sms_message',$id,array('previous_status'=>$m['status']));return array('message'=>'پیام برای تلاش مجدد در صف قرار گرفت.');
        });
    }
    private static function optouts($r){global $wpdb;return $wpdb->get_results('SELECT * FROM '.Support::table('sms_optouts').' ORDER BY id DESC LIMIT 1000',ARRAY_A);}
    private static function save_optout($r){
        global $wpdb;$b=self::body($r);$phone=Support::phone($b['phone']??'',true);self::need(!is_wp_error($phone)&&$phone!=='','شماره همراه معتبر نیست.');$T=Support::table('sms_optouts');
        if(!empty($b['remove'])){Support::checked($wpdb->delete($T,array('phone'=>$phone)));}else{$note=self::clean($b['note']??'',300);$old=$wpdb->get_var($wpdb->prepare("SELECT id FROM $T WHERE phone=%s",$phone));if($old)Support::checked($wpdb->update($T,array('note'=>$note),array('id'=>$old)));else Support::checked($wpdb->insert($T,array('phone'=>$phone,'note'=>$note,'created_at'=>Support::now())));}
        Support::audit('sms_optout_changed','sms',0,array('phone'=>$phone,'removed'=>!empty($b['remove'])));return array('message'=>'فهرست عدم ارسال به‌روز شد.');
    }
    private static function test($r){
        $b=self::body($r);self::need(($b['confirm']??false)===true,'ارسال یک پیامک آزمایشی را تأیید کنید.');self::need(self::settings()['enabled'],'اتصال را فعال کنید.');$key=self::clean($b['request_key']??'',64);self::need(preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$key),'شناسه درخواست معتبر نیست.');
        $phone=Support::phone($b['phone']??'',true);self::need(!is_wp_error($phone)&&$phone!=='','شمارهٔ تست معتبر نیست.');self::need(self::settings()['sender']!=='','برای تست متنی خط فرستنده لازم است.');
        $now=Support::now();$exp=gmdate('Y-m-d H:i:s',time()+86400);$id=self::new_campaign('پیامک آزمایشی',0,$key,'test','queued',$now,$exp);
        $t=array_merge(self::template_defaults(),array('id'=>0,'purpose'=>'general','content'=>'تست اتصال ملی‌پیامک به {academy}.'));
        $m=self::make_message(array(),array('mobile'=>$phone,'user_id'=>0,'name'=>'شماره آزمایشی'),$t);self::add_message($id,$m,$key,$now,$exp);return array('id'=>$id,'message'=>'پیامک آزمایشی در صف قرار گرفت.');
    }
    private static function eligibility($m){
        global $wpdb;if($m['expires_at']<=Support::now())return 'مهلت ارسال گذشته است.';
        if($wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('sms_optouts').' WHERE phone=%s',$m['recipient'])))return 'شماره در فهرست عدم ارسال است.';
        if(!empty($m['template_id'])&&($m['source']??'')==='auto'){$t=self::template($m['template_id']);if(!$t['active']||$t['event']==='manual')return 'قالب خودکار غیرفعال شده است.';}
        if(!empty($m['user_id'])){$p=Support::profile($m['user_id']);if(!$p||!empty($p['deleted_at'])||!$p['active'])return 'حساب گیرنده غیرفعال یا حذف شده است.';$phone=Support::phone($p['mobile'],true);if(is_wp_error($phone)||$phone!==$m['recipient'])return 'شماره پرونده تغییر کرده؛ پیش‌نمایش تازه بگیرید.';}
        if(!empty($m['enrollment_id'])){
            $ctx=json_decode($m['context_json'],true)?:array();$rows=self::contexts(array('enrollment_id'=>$m['enrollment_id']));if(!$rows)return 'ثبت‌نام، کلاس یا ترم دیگر فعال نیست.';$c=$rows[0];
            if((int)$m['user_id']!==(int)$m['student_id']){$linked=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('family_links').' WHERE student_id=%d AND family_id=%d',$m['student_id'],$m['user_id']));if(!$linked)return 'ارتباط خانواده با زبان‌آموز حذف شده است.';}
            if(($ctx['purpose']??'')==='debt'){if((int)$c['balance']<=0)return 'شهریه تسویه شده است.';if((int)$c['balance']!==(int)$ctx['balance'])return 'ماندهٔ شهریه تغییر کرده؛ پیش‌نمایش تازه لازم است.';}
            if(in_array($ctx['purpose']??'',array('schedule','enrollment'),true)&&($ctx['schedule_signature']??'')!==self::schedule_signature($c))return 'برنامه کلاس تغییر کرده؛ پیش‌نمایش تازه لازم است.';
            if(!empty($ctx['extra']['payment_id'])){$valid=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('payments')." WHERE id=%d AND status='success' AND voided_at IS NULL",$ctx['extra']['payment_id']));if(!$valid)return 'پرداخت باطل شده است.';}
            if(!empty($ctx['extra']['attendance_session_id'])){$state=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Support::table('attendance').' WHERE session_id=%d AND enrollment_id=%d',$ctx['extra']['attendance_session_id'],$m['enrollment_id']));if($state!==($ctx['extra']['attendance_raw']??''))return 'وضعیت حضور و غیاب اصلاح شده است.';$day=$wpdb->get_var($wpdb->prepare('SELECT session_date FROM '.Support::table('attendance_sessions').' WHERE id=%d',$ctx['extra']['attendance_session_id']));if(self::date_label($day)!==($ctx['extra']['session_date']??''))return 'تاریخ جلسه اصلاح شده است.';}
        }
        $hours=self::settings()['dedupe_hours'];if($hours){$exists=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('sms_messages')." WHERE recipient=%s AND content=%s AND params=%s AND status IN ('sent','unknown','sending') AND locked_at>=%s AND id<>%d LIMIT 1",$m['recipient'],$m['content'],$m['params'],gmdate('Y-m-d H:i:s',time()-$hours*3600),$m['id']));if($exists)return 'پیام یکسان به همین شماره اخیراً ارسال شده یا نتیجهٔ آن نامشخص است.';}
        return '';
    }
    private static function consume_events(){
        global $wpdb;$events=$wpdb->get_results('SELECT * FROM '.Support::table('sms_events')." WHERE state='pending' ORDER BY id LIMIT 10",ARRAY_A);
        foreach($events as$ev){try{
            if(strtotime($ev['created_at'].' UTC')<time()-self::settings()['expire_hours']*3600){$state='expired';}else{
                $context=json_decode($ev['context_json'],true)?:array();$ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.Support::table('sms_templates').' WHERE active=1 AND event=%s',$ev['event_name']));
                foreach($ids as$id){$t=self::template($id);$f=array_intersect_key($context,array_flip(array('term_id','class_id','enrollment_id','student_id')));$rows=self::contexts($f);foreach($rows as$c)if(self::matches($c,$t))self::auto_message($t,$c,$ev['event_key'],$context['extra']??array());}
                $state='done';
            }Support::checked($wpdb->update(Support::table('sms_events'),array('state'=>$state),array('id'=>$ev['id'])));
        }catch(Problem $e){Support::checked($wpdb->update(Support::table('sms_events'),array('state'=>'failed','error_message'=>$e->getMessage()),array('id'=>$ev['id'])));update_option('nasr_sms_last_error',$e->getMessage(),false);}}
    }
    private static function matches($c,$t){foreach(array('branch_id','term_id','class_id')as$k)if($t[$k]!==''&&(int)$t[$k]!==(int)$c[$k])return false;return true;}
    private static function auto_message($t,$c,$key,$extra=array(),$expires=null){
        $now=Support::now();$expires=$expires?:gmdate('Y-m-d H:i:s',time()+self::settings()['expire_hours']*3600);$key=hash('sha256','auto:'.$t['id'].':'.$key);
        $campaign=self::new_campaign($t['name'],$t['id'],$key,'auto','queued',$now,$expires);
        $destinations=self::destinations($c,$t['audience']);
        if(!$destinations)$destinations[]=array('user_id'=>0,'mobile'=>'','name'=>trim($c['first_name'].' '.$c['last_name']).' (خانوادهٔ مرتبط موجود نیست)');
        foreach($destinations as$d){try{$m=self::make_message($c,$d,$t,$extra);self::add_message($campaign,$m,$key.':'.$c['enrollment_id'].':'.$m['recipient'],$now,$expires);}catch(Problem $e){
            $message_key=hash('sha256',$key.':'.$c['enrollment_id'].':invalid:'.$d['user_id']);
            if(self::add_message($campaign,array('template_id'=>$t['id'],'user_id'=>$d['user_id'],'student_id'=>$c['student_id'],'enrollment_id'=>$c['enrollment_id'],'recipient'=>'','recipient_name'=>$d['name'],'content'=>'پیش از ارسال متوقف شد: '.$e->getMessage(),'params'=>'[]','mode'=>$t['mode'],'context_json'=>'{}'),$message_key,$now,$expires)){
                global $wpdb;$message_id=$wpdb->insert_id;self::message_update($message_id,array('status'=>'skipped','error_message'=>$e->getMessage()));
            }
            update_option('nasr_sms_last_error',$e->getMessage(),false);
        }}
    }
    private static function reminders(){
        global $wpdb;$ids=$wpdb->get_col('SELECT id FROM '.Support::table('sms_templates')." WHERE active=1 AND event IN ('class_reminder','debt_reminder','exam_reminder') ORDER BY id");if(!$ids)return;
        $cursor=(array)get_option('nasr_sms_reminder_cursor',array());$index=(int)($cursor['index']??0)%count($ids);$id=$ids[$index];$t=self::template($id);$after=(int)(($cursor['template']??0)==$id?($cursor['after']??0):0);
        $f=array_intersect_key($t,array_flip(array('branch_id','term_id','class_id')));$f['debt']=$t['event']==='debt_reminder';$rows=self::contexts($f,$after,200);
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran'));$today=$now->format('Y-m-d');
        foreach($rows as$c){
            if($today>$c['end_date'])continue;
            if($t['event']==='debt_reminder'){$start=new \DateTimeImmutable($c['start_date'],new \DateTimeZone('Asia/Tehran'));$days=(int)$start->diff($now)->format('%r%a');if($days<$t['debt_after_days'])continue;$period=intdiv($days-$t['debt_after_days'],$t['repeat_days']);self::auto_message($t,$c,'debt:'.$c['enrollment_id'].':'.$period);}
            elseif($t['event']==='class_reminder'){
                $days=explode(',',$c['class_days']?:$c['class_day']);if(!$c['start_time'])continue;
                for($i=0;$i<=ceil($t['hours_before']/24);$i++){$day=$now->modify('+'.$i.' days')->format('Y-m-d');if($day<$c['start_date']||$day>$c['end_date'])continue;$when=new \DateTimeImmutable($day.' '.$c['start_time'],new \DateTimeZone('Asia/Tehran'));$seconds=$when->getTimestamp()-$now->getTimestamp();if($seconds<=0||$seconds>$t['hours_before']*3600||!in_array(strtolower($when->format('l')),$days,true))continue;self::auto_message($t,$c,'class:'.$c['class_id'].':'.$day,array('session_date'=>self::date_label($day)),gmdate('Y-m-d H:i:s',$when->getTimestamp()));}
            }else{
                foreach(array('mid_date'=>'میان‌ترم','final_date'=>'فاینال')as$key=>$label){if(!$c[$key])continue;/* Term stores exam dates, not times: use midnight as the reference and state that in the UI. */$when=new \DateTimeImmutable($c[$key].' 00:00',new \DateTimeZone('Asia/Tehran'));$seconds=$when->getTimestamp()-$now->getTimestamp();if($seconds<=0||$seconds>$t['hours_before']*3600)continue;self::auto_message($t,$c,'exam:'.$c['class_id'].':'.$key.':'.$c[$key],array('exam_name'=>$label,'exam_date'=>self::date_label($c[$key])),gmdate('Y-m-d H:i:s',$when->getTimestamp()+86400));}
            }
        }
        update_option('nasr_sms_reminder_cursor',array('index'=>count($rows)<200?($index+1)%count($ids):$index,'template'=>count($rows)<200?0:$id,'after'=>count($rows)<200?0:(int)end($rows)['enrollment_id']),false);
    }
}
