<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/** Persistent outbox: no HTTP request is made inside an academy data transaction. */
final class SMS {
    use SMS_Data;
    use SMS_Queue;
    const HOOK='nasr_sms_tick';
    public static function defaults() {
        return array('enabled'=>false,'username'=>'','secret'=>'','sender'=>'','batch_size'=>10,'daily_limit'=>1000,'send_from'=>8,'send_until'=>21,'expire_hours'=>48,'dedupe_hours'=>24,'signature'=>'','auto_delivery'=>true);
    }
    public static function settings($private=false) {
        $v=array_merge(self::defaults(),(array)get_option('nasr_academy_sms',array()));
        if (!$private) { $v['has_secret']=!empty($v['secret']); unset($v['secret']); }
        return $v;
    }
    public static function boot() {
        add_filter('cron_schedules',function($s){$s['nasr_five_minutes']=array('interval'=>300,'display'=>'هر پنج دقیقه - پیامک نصر');return $s;});
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action(self::HOOK,array(__CLASS__,'tick'));
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_event(time()+60,'nasr_five_minutes',self::HOOK); }
    }
    public static function deactivate() { wp_clear_scheduled_hook(self::HOOK); }
    public static function schema() {
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        global $wpdb; $cc=$wpdb->get_charset_collate();
        $schemas=array(
            'sms_templates'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nname varchar(150) NOT NULL,\npurpose varchar(30) NOT NULL,\nevent varchar(30) NOT NULL DEFAULT 'manual',\nconfig longtext NOT NULL,\nactive tinyint unsigned NOT NULL DEFAULT 1,\nversion int unsigned NOT NULL DEFAULT 1,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id)",
            'sms_campaigns'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\ntitle varchar(190) NOT NULL,\ntemplate_id bigint unsigned NOT NULL DEFAULT 0,\nsource varchar(15) NOT NULL DEFAULT 'manual',\nstate varchar(15) NOT NULL DEFAULT 'draft',\nrequest_key varchar(64) NOT NULL,\ncreated_by bigint unsigned NOT NULL,\ncreated_at datetime NOT NULL,\nscheduled_at datetime NOT NULL,\nexpires_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY request_key (request_key)",
            'sms_messages'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\ncampaign_id bigint unsigned NOT NULL,\ntemplate_id bigint unsigned NOT NULL DEFAULT 0,\nuser_id bigint unsigned NOT NULL DEFAULT 0,\nstudent_id bigint unsigned NOT NULL DEFAULT 0,\nenrollment_id bigint unsigned NOT NULL DEFAULT 0,\nrecipient varchar(25) NOT NULL,\nrecipient_name varchar(190) NOT NULL,\ncontent text NOT NULL,\nparams text NOT NULL,\nmode varchar(15) NOT NULL,\nbody_id varchar(25) NOT NULL DEFAULT '',\nsender varchar(25) NOT NULL DEFAULT '',\nstatus varchar(15) NOT NULL DEFAULT 'queued',\nrec_id varchar(64) NOT NULL DEFAULT '',\ndelivery_code varchar(30) NOT NULL DEFAULT '',\ndelivery_text varchar(190) NOT NULL DEFAULT '',\ndelivery_checked_at datetime DEFAULT NULL,\nattempts smallint unsigned NOT NULL DEFAULT 0,\nerror_code varchar(40) NOT NULL DEFAULT '',\nerror_message varchar(500) NOT NULL DEFAULT '',\ncontext_json longtext NOT NULL,\ndedupe_key char(64) NOT NULL,\nscheduled_at datetime NOT NULL,\nexpires_at datetime NOT NULL,\nlocked_at datetime DEFAULT NULL,\nsent_at datetime DEFAULT NULL,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY dedupe_key (dedupe_key),\nKEY queue_status (status,scheduled_at),\nKEY campaign_id (campaign_id),\nKEY recipient_time (recipient,sent_at)",
            'sms_optouts'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nphone varchar(25) NOT NULL,\nnote varchar(300) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY phone (phone)",
            'sms_events'=>"id bigint unsigned NOT NULL AUTO_INCREMENT,\nevent_name varchar(30) NOT NULL,\nevent_key char(64) NOT NULL,\ncontext_json longtext NOT NULL,\nstate varchar(15) NOT NULL DEFAULT 'pending',\nerror_message varchar(500) NOT NULL DEFAULT '',\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY event_key (event_key),\nKEY state (state,id)");
        foreach ($schemas as $key=>$sql) {
            $table=Support::table($key);dbDelta("CREATE TABLE $table (\n$sql\n) ENGINE=InnoDB $cc;");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) { wp_die('ساخت جدول‌های مرکز پیامک نصر انجام نشد.'); }
        }
        if (!get_option('nasr_sms_seeded')) {
            foreach (self::samples() as $sample) {
                Support::checked($wpdb->insert(Support::table('sms_templates'),array('name'=>$sample['name'],'purpose'=>$sample['purpose'],'event'=>'manual','config'=>wp_json_encode(array_merge(self::template_defaults(),$sample),JSON_UNESCAPED_UNICODE),'active'=>1,'version'=>1,'updated_at'=>Support::now())));
            }
            update_option('nasr_sms_seeded',1,false);
        }
    }
    public static function routes() {
        $paths=array('config'=>array('GET'=>'config','POST'=>'save_config'),'credit'=>array('POST'=>'credit'),'templates'=>array('GET'=>'templates','POST'=>'save_template'),'templates/(?P<id>\d+)'=>array('POST'=>'save_template'),
            'preview'=>array('POST'=>'preview'),'campaigns'=>array('GET'=>'campaigns'),'campaigns/(?P<id>\d+)'=>array('GET'=>'campaign','POST'=>'campaign_action'),'messages/(?P<id>\d+)'=>array('POST'=>'message_action'),'optouts'=>array('GET'=>'optouts','POST'=>'save_optout'),'test'=>array('POST'=>'test'),'process'=>array('POST'=>'process'));
        foreach ($paths as $path=>$verbs) {
            $routes=array();foreach ($verbs as $verb=>$method) {
                $routes[]=array('methods'=>$verb,'permission_callback'=>function(){ $a=Support::access();return is_wp_error($a)?$a:(Support::staff()?true:Support::error('مرکز پیامک فقط برای مدیر و منشی قابل دسترسی است.',403));},'callback'=>function($r)use($method){return self::dispatch($method,$r);});
            }register_rest_route('nasr/v1','/sms/'.$path,$routes);
        }
    }
    private static function dispatch($method,$r) {
        global $wpdb;
        try {
            self::need(Support::staff(),'مرکز پیامک فقط برای مدیر و منشی است.',403);
            if (in_array($method,array('save_config','save_template','preview','campaign_action','save_optout','test'),true)) {
                $out=self::locked('edit',function()use($method,$r,$wpdb){
                    Support::checked($wpdb->query('START TRANSACTION'));
                    try {$out=self::$method($r);Support::checked($wpdb->query('COMMIT'));return $out;}
                    catch (\Throwable $e){$wpdb->query('ROLLBACK');wp_cache_delete('nasr_academy_sms','options');throw $e;}
                });
                // Provider I/O is after COMMIT and after releasing the edit lock.
                if($method==='test' || ($method==='campaign_action' && $r->get_param('process_now')===true && in_array($r->get_param('action'),array('queue','resume','process'),true)))return self::after_enqueue($out,$method==='test');
                return $out;
            }
            return self::$method($r);
        } catch (Problem $e) { return Support::error($e->getMessage(),$e->getCode()?:400,'nasr_sms'); }
        catch (\Throwable $e) { return Support::error('عملیات پیامک انجام نشد؛ وضعیت صف را بررسی کنید.',500,'nasr_sms'); }
    }
    private static function need($c,$m,$code=400){if(!$c)throw new Problem($m,$code);}
    private static function n($v,$min,$max){$n=Support::number($v,$min,$max);if(is_wp_error($n))throw new Problem($n->get_error_message());return $n;}
    private static function body($r){$b=$r->get_json_params();self::need(is_array($b),'ورودی معتبر نیست.');return $b;}
    private static function clean($v,$max=190){self::need(is_scalar($v) && Support::length($v)<=$max,'متن ورودی معتبر نیست.');return sanitize_textarea_field((string)$v);}
    private static function locked($name,$fn){
        global $wpdb;$key='nasr_sms_'.md5($wpdb->prefix.DB_NAME.$name);
        self::need((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))===1,'پردازش دیگری در حال اجراست؛ چند لحظه بعد تازه‌سازی کنید.',409);
        try{return $fn();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    private static function cipher($text,$encrypt=true){
        self::need(function_exists('openssl_encrypt'),'برای ذخیرهٔ امن کلید، افزونهٔ OpenSSL روی PHP لازم است.');
        $key=hash('sha256',wp_salt('auth').'nasr-sms',true);
        if($encrypt){$iv=random_bytes(12);$tag='';$c=openssl_encrypt($text,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);self::need($c!==false,'ذخیرهٔ امن کلید انجام نشد.');return base64_encode($iv.$tag.$c);}
        $raw=base64_decode($text,true);self::need($raw!==false&&strlen($raw)>=28,'کلید اتصال معتبر نیست؛ دوباره ذخیره کنید.');
        $v=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));self::need($v!==false,'کلید وردپرس تغییر کرده؛ کلید پیامک را دوباره ذخیره کنید.');return $v;
    }
    private static function config($r){
        global $wpdb;
        return array('settings'=>self::settings(),'variables'=>self::variables(),'purposes'=>self::purposes(),'events'=>self::event_names(),'last_tick'=>get_option('nasr_sms_last_tick',''),'last_error'=>get_option('nasr_sms_last_error',''),'event_errors'=>$wpdb->get_results('SELECT event_name,error_message,created_at FROM '.Support::table('sms_events')." WHERE state='failed' ORDER BY id DESC LIMIT 10",ARRAY_A),'next_tick'=>wp_next_scheduled(self::HOOK),'cron_disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON,'queue'=>self::queue_status());
    }
    private static function save_config($r){
        $b=self::body($r);$s=self::settings(true);
        foreach(array('username'=>150,'sender'=>25,'signature'=>160)as$k=>$max){if(isset($b[$k]))$s[$k]=self::clean($b[$k],$max);}
        $s['username']=trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u','',$s['username']));
        $s['sender']=Support::digits($s['sender']);self::need($s['sender']===''||preg_match('/^\d{3,25}$/D',$s['sender']),'خط فرستنده باید عددی باشد.');
        if(!empty($b['clear_secret']))$s['secret']='';
        if(isset($b['secret']) && $b['secret']!==''){self::need(is_string($b['secret'])&&strlen($b['secret'])<=1000,'کلید اتصال معتبر نیست.');$secret=trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u','',$b['secret']));$s['secret']=self::cipher($secret);}
        foreach(array('batch_size'=>array(1,20),'daily_limit'=>array(1,50000),'send_from'=>array(0,23),'send_until'=>array(1,24),'expire_hours'=>array(1,168),'dedupe_hours'=>array(0,168)) as $k=>$limits){if(isset($b[$k]))$s[$k]=self::n($b[$k],$limits[0],$limits[1]);}
        self::need($s['send_from']<$s['send_until'],'ساعت پایان ارسال باید از ساعت شروع بیشتر باشد.');
        foreach(array('enabled','auto_delivery')as$k){if(array_key_exists($k,$b))$s[$k]=!empty($b[$k]);}
        self::need(!$s['enabled']||($s['username']!==''&&$s['secret']!==''),'نام کاربری و API Key وب‌سرویس را وارد کنید.');
        update_option('nasr_academy_sms',$s,false);Support::audit('sms_settings_saved','sms',0,array('enabled'=>$s['enabled'],'credentials_changed'=>!empty($b['secret'])||!empty($b['clear_secret'])));
        return array('message'=>'تنظیمات پیامک ذخیره شد.','settings'=>self::settings());
    }
    /** Only the documented fixed HTTPS endpoint is contacted. Secrets never enter logs. */
    private static function request($method,$data=array()){
        self::need(in_array($method,array('SendSMS','BaseServiceNumber','GetCredit','GetDeliveries2'),true),'روش اتصال معتبر نیست.');
        $s=self::settings(true);self::need($s['username']!==''&&$s['secret']!=='','ابتدا مشخصات اتصال را ذخیره کنید.');
        $payload=array_merge(array('username'=>$s['username'],'password'=>self::cipher($s['secret'],false)),$data);
        $response=wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/'.$method,array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),'body'=>http_build_query($payload),'limit_response_size'=>16384));
        if(is_wp_error($response)){
            $detail='ارتباط سرور با وب‌سرویس برقرار نشد.';$raw=$response->get_error_message();
            if(preg_match('/cURL error (\d+)/i',$raw,$error))$detail='خطای ارتباط cURL '.$error[1].'. '.(array('6'=>'سرور نمی‌تواند نشانی وب‌سرویس را پیدا کند.','7'=>'اتصال خروجی هاست به وب‌سرویس برقرار نشد.','28'=>'مهلت پاسخ وب‌سرویس تمام شد.','60'=>'گواهی امن اتصال روی هاست تأیید نشد.')[$error[1]] ?? 'اتصال خروجی هاست را بررسی کنید.');
            return array('uncertain'=>true,'code'=>'transport','message'=>$detail.' پاسخ قطعی دریافت نشد؛ پیش از ارسال مجدد، پنل ملی‌پیامک را بررسی کنید.');
        }
        $http=wp_remote_retrieve_response_code($response);
        if($http<200||$http>=300)return array('uncertain'=>true,'code'=>'http_'.$http,'message'=>'پاسخ HTTP '.$http.'؛ نتیجهٔ ارسال قطعی نیست.');
        $json=json_decode(wp_remote_retrieve_body($response),true,512,JSON_BIGINT_AS_STRING);
        if(!is_array($json)||!array_key_exists('Value',$json)||!array_key_exists('RetStatus',$json))return array('uncertain'=>true,'code'=>'invalid_response','message'=>'پاسخ وب‌سرویس قابل تشخیص نیست؛ پنل را بررسی کنید.');
        return array('value'=>$json['Value'],'ret'=>(string)$json['RetStatus']);
    }
    private static function error_text($code){
        $errors=array('-110'=>'API Key وب‌سرویس را به جای رمز پنل وارد کنید.','-109'=>'IP سرور باید در IPهای مجاز ملی‌پیامک ثبت شود.','-108'=>'IP به علت ورود ناموفق مسدود شده است.','-10'=>'وجود لینک در متغیر پترن مجاز نیست.','-7'=>'خطای فرستنده؛ با پشتیبانی ملی‌پیامک تماس بگیرید.','-6'=>'خطای داخلی ملی‌پیامک.','-5'=>'تعداد یا ترتیب متغیرهای پترن تطابق ندارد.','-4'=>'کد پترن اشتباه یا تأیید نشده است.','-3'=>'خط خدماتی تعریف نشده است.','-2'=>'تعداد گیرنده مجاز نیست.','-1'=>'دسترسی وب‌سرویس فعال نیست.','0'=>'نام کاربری یا API Key نادرست است.','2'=>'اعتبار پیامکی کافی نیست.','3'=>'محدودیت ارسال روزانه.','4'=>'محدودیت حجم ارسال.','5'=>'شماره فرستنده نامعتبر است.','6'=>'سامانه ملی‌پیامک در حال به‌روزرسانی است.','7'=>'متن حاوی کلمهٔ فیلترشده است.','9'=>'این خط عمومی برای وب‌سرویس مجاز نیست.','10'=>'حساب فعال نیست.','11'=>'ارسال انجام نشد.','12'=>'مدارک حساب کامل نیست.','14'=>'متن حاوی لینک است.','15'=>'محدودیت عبارت لغو در ارسال چندمخاطبی.','16'=>'گیرنده یافت نشد.','17'=>'متن خالی است.','18'=>'شماره گیرنده نامعتبر است.','19'=>'محدودیت ساعتی ارسال.','35'=>'شماره در لیست سیاه مخابرات است؛ پترن خدماتی تأییدشده لازم است.');
        return $errors[(string)$code]??('خطای ملی‌پیامک با کد '.sanitize_text_field((string)$code));
    }
    private static function credit($r){
        $res=self::request('GetCredit');self::need(empty($res['uncertain']),$res['message']??'خطای ارتباط');
        self::need($res['ret']==='1' && is_scalar($res['value']) && is_numeric($res['value']) && (float)$res['value']>=0,self::error_text(is_scalar($res['value'])?$res['value']:'response'));
        return array('message'=>'اتصال برقرار است. موجودی بر حسب اعتبار پیامکی: '.$res['value'],'credit'=>(string)$res['value']);
    }
    public static function record_event($name,$key,$context){
        global $wpdb;if(!self::settings()['enabled'])return;
        $exists=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Support::table('sms_templates').' WHERE active=1 AND event=%s LIMIT 1',$name));if(!$exists)return;
        $table=Support::table('sms_events');$key=hash('sha256',$name.':'.$key);
        Support::checked($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (event_name,event_key,context_json,state,created_at) VALUES (%s,%s,%s,'pending',%s)",$name,$key,wp_json_encode($context,JSON_UNESCAPED_UNICODE),Support::now())));
    }
    public static function tick(){
        update_option('nasr_sms_last_cron_tick',Support::now(),false);
        return self::run_worker(0,0,false,true);
    }
    private static function process($r){return self::run_worker(0,1,false,true);}
    private static function work($campaign_id=0,$batch_cap=0,$test_now=false){
        global $wpdb;$s=self::settings();$M=Support::table('sms_messages');$C=Support::table('sms_campaigns');$now=Support::now();
        // A crashed request may already have reached the provider. Never auto-resend it.
        Support::checked($wpdb->query($wpdb->prepare("UPDATE $M SET status='unknown',error_code='interrupted',error_message=%s,updated_at=%s WHERE status='sending' AND locked_at<%s",'ارسال قطع شده؛ ابتدا پنل ملی‌پیامک را بررسی کنید.',$now,gmdate('Y-m-d H:i:s',time()-600))));
        Support::checked($wpdb->query($wpdb->prepare("UPDATE $M m JOIN $C c ON c.id=m.campaign_id SET m.status='skipped',m.error_message='مهلت ارسال گذشته است.',m.updated_at=%s WHERE m.status='queued' AND c.state='queued' AND m.expires_at<=%s",$now,$now)));
        $local=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran'));$hour=(int)$local->format('G');
        if(!$test_now && ($hour<$s['send_from'] || $hour>=$s['send_until']))return 0;
        $start=$local->setTime(0,0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $counter=(array)get_option('nasr_sms_daily_count',array());$day=$local->format('Y-m-d');
        $sent=($counter['day']??'')===$day?(int)$counter['count']:0;
        $limit=min($s['batch_size'],$batch_cap ?: $s['batch_size'],max(0,$s['daily_limit']-$sent));if(!$limit)return 0;
        $scope=$campaign_id?$wpdb->prepare(' AND c.id=%d',$campaign_id):'';
        if($test_now){self::need($campaign_id>0,'ارسال فوری فقط برای پیام آزمایشی همان درخواست مجاز است.');$scope.=" AND c.source='test'";}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.*,c.source FROM $M m JOIN $C c ON c.id=m.campaign_id WHERE m.status='queued' AND c.state='queued' AND m.scheduled_at<=%s $scope ORDER BY m.id LIMIT %d",$now,$limit),ARRAY_A);
        self::need(!$wpdb->last_error,'بازیابی صف پیامک انجام نشد.');$began=microtime(true);$attempted=0;
        foreach($rows as $m){
            if(microtime(true)-$began>20)break;
            $reason=self::eligibility($m);
            if($reason){self::message_update($m['id'],array('status'=>'skipped','error_message'=>$reason));continue;}
            $changed=$wpdb->query($wpdb->prepare("UPDATE $M m JOIN $C c ON c.id=m.campaign_id SET m.status='sending',m.attempts=m.attempts+1,m.locked_at=%s,m.updated_at=%s WHERE m.id=%d AND m.status='queued' AND c.state='queued'",$now,$now,$m['id']));
            Support::checked($changed);if($changed!==1)continue;
            $sent++;$attempted++;update_option('nasr_sms_daily_count',array('day'=>$day,'count'=>$sent),false);
            $payload=array('to'=>$m['recipient']);
            if($m['mode']==='pattern'){$payload['text']=implode(';',json_decode($m['params'],true)?:array());$payload['bodyId']=$m['body_id'];$method='BaseServiceNumber';}
            else{$payload['from']=$m['sender'];$payload['text']=$m['content'];$payload['isflash']='false';$method='SendSMS';}
            try{$res=self::request($method,$payload);}catch(\Throwable $e){self::message_update($m['id'],array('status'=>'failed','error_code'=>'config','error_message'=>'اتصال پیامک کامل نیست؛ تنظیمات را بررسی کنید.'));continue;}
            if(!empty($res['uncertain'])){self::message_update($m['id'],array('status'=>'unknown','error_code'=>$res['code'],'error_message'=>$res['message']));continue;}
            $value=is_scalar($res['value'])?(string)$res['value']:'';
            if($res['ret']==='1' && preg_match('/^[0-9]{6,25}$/D',$value)){
                self::message_update($m['id'],array('status'=>'sent','rec_id'=>$value,'sent_at'=>Support::now(),'error_code'=>'','error_message'=>'','delivery_text'=>'پذیرفته‌شده توسط ملی‌پیامک'));
            }else{
                $code=preg_match('/^-?\d{1,4}$/D',$value)?$value:$res['ret'];
                self::message_update($m['id'],array('status'=>'failed','error_code'=>$code,'error_message'=>self::error_text($code)));
            }
        }
        return $attempted;
    }
    private static function message_update($id,$data){global $wpdb;$data['updated_at']=Support::now();Support::checked($wpdb->update(Support::table('sms_messages'),$data,array('id'=>$id)));}
    private static function refresh_deliveries($id=0){
        global $wpdb;$M=Support::table('sms_messages');
        $where=$id?$wpdb->prepare('id=%d',$id):$wpdb->prepare("status='sent' AND sent_at>%s AND (delivery_checked_at IS NULL OR delivery_checked_at<%s) AND delivery_code NOT IN ('1','2','3','16','35','300','500')",gmdate('Y-m-d H:i:s',time()-7*86400),gmdate('Y-m-d H:i:s',time()-900));
        $rows=$wpdb->get_results("SELECT id,rec_id,status FROM $M WHERE $where ORDER BY COALESCE(delivery_checked_at,'1970-01-01'),id LIMIT 3",ARRAY_A);
        $labels=array('0'=>'ارسال به مخابرات','1'=>'رسیده به گوشی','2'=>'نرسیده به گوشی','3'=>'خطای مخابراتی','5'=>'خطای نامشخص','8'=>'رسیده به مخابرات','16'=>'نرسیده به مخابرات','35'=>'لیست سیاه','100'=>'نامشخص','200'=>'ارسال شده','300'=>'فیلتر شده','400'=>'در لیست ارسال','500'=>'عدم پذیرش');
        foreach($rows as$m){if(!$m['rec_id']||$m['status']!=='sent')continue;$r=self::request('GetDeliveries2',array('recId'=>$m['rec_id']));
            $code=empty($r['uncertain'])&&is_scalar($r['value'])?(string)$r['value']:'';
            $text=isset($labels[$code])&&$r['ret']==='1'?$labels[$code]:'دریافت وضعیت تحویل انجام نشد';
            self::message_update($m['id'],array('delivery_code'=>isset($labels[$code])&&$r['ret']==='1'?$code:'','delivery_text'=>$text,'delivery_checked_at'=>Support::now()));
        }
    }
}
