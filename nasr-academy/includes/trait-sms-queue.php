<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

/** Durable, locked sends plus read-only reasons for an outbox waiting to run. */
trait SMS_Queue {
    private static function queue_status($campaign_id=0){
        global $wpdb;$s=self::settings();$now=Support::now();
        $local=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran'));$hour=(int)$local->format('G');
        $counter=(array)get_option('nasr_sms_daily_count',array());$used=($counter['day']??'')===$local->format('Y-m-d')?(int)$counter['count']:0;
        $scope=$campaign_id?$wpdb->prepare(' WHERE c.id=%d',$campaign_id):'';
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) total,COALESCE(SUM(m.status='queued'),0) waiting,COALESCE(SUM(m.status='queued' AND c.state='queued' AND m.scheduled_at<=%s AND m.expires_at>%s),0) due,COALESCE(SUM(m.status='queued' AND c.state='draft'),0) drafts,COALESCE(SUM(m.status='queued' AND c.state='paused'),0) paused,COALESCE(SUM(m.status='queued' AND c.state='queued' AND m.scheduled_at>%s),0) scheduled,COALESCE(SUM(m.status='queued' AND c.state='queued' AND m.expires_at<=%s),0) expired FROM ".Support::table('sms_messages').' m JOIN '.Support::table('sms_campaigns')." c ON c.id=m.campaign_id $scope",$now,$now,$now,$now),ARRAY_A);
        self::need(!$wpdb->last_error,'بازیابی وضعیت صف پیامک انجام نشد.');$out=array_map('intval',$row);
        $out['tehran_time']=$local->format('Y-m-d H:i');$out['window']=$s['send_from'].':00 تا '.$s['send_until'].':00';$out['remaining_today']=max(0,$s['daily_limit']-$used);
        $out['block_code']='';$out['block_reason']='';
        if(!$s['enabled']){$out['block_code']='disabled';$out['block_reason']='ارسال پیامک در تنظیمات غیرفعال است.';}
        elseif($s['username']==='' || !$s['has_secret']){$out['block_code']='credentials';$out['block_reason']='نام کاربری یا API Key ذخیره نشده است.';}
        elseif($out['remaining_today']===0){$out['block_code']='quota';$out['block_reason']='سقف تلاش ارسال امروز پر شده است؛ سقف را تغییر دهید یا فردا دوباره پردازش کنید.';}
        elseif($hour<$s['send_from'] || $hour>=$s['send_until']){$out['block_code']='hours';$out['block_reason']='اکنون خارج از ساعت مجاز ارسال است: '.$out['window'].' به وقت تهران.';}
        $out['message']=$out['block_reason'] ?: ($out['due']?'پیام آمادهٔ ارسال وجود دارد؛ پردازش صف اکنون یا پردازش خودکار این صفحه آن را ارسال می‌کند.':($out['scheduled']?'زمان ارسال پیام‌های زمان‌بندی‌شده هنوز نرسیده است.':($out['drafts']?'پیش‌نمایش‌ها هنوز برای ارسال تأیید نشده‌اند.':($out['paused']?'صف متوقف است؛ «ادامهٔ صف» را بزنید.':($out['expired']?'مهلت بعضی پیام‌ها گذشته؛ با پردازش صف وضعیت آن‌ها مشخص می‌شود.':'پیام آمادهٔ ارسال در صف نیست.')))));
        $next=wp_next_scheduled(self::HOOK);$out['cron_warning']='';
        if(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)$out['cron_warning']='زمان‌بند داخلی وردپرس غیرفعال است. برای ارسال بدون باز بودن این صفحه، زمان‌بند هاست باید wp-cron.php را اجرا کند.';
        elseif(!$next || $next<time()-600)$out['cron_warning']='نوبت زمان‌بند وردپرس عقب افتاده است؛ اجرای wp-cron.php و اتصال داخلی هاست را بررسی کنید.';
        $out['last_tick']=get_option('nasr_sms_last_tick','');$out['last_cron_tick']=get_option('nasr_sms_last_cron_tick','');
        $out['pending_events']=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Support::table('sms_events')." WHERE state='pending'");
        return $out;
    }
    private static function waiting_reason($m,$state,$queue){
        if($m['status']!=='queued')return '';
        if($state==='draft')return 'پیش‌نمایش تأیید نشده؛ هنوز به وب‌سرویس ارسال نشده است.';
        if($state==='paused')return 'ارسال این مجموعه متوقف شده است؛ ادامهٔ صف را بزنید.';
        if($state==='cancelled')return 'ارسال این مجموعه لغو شده است.';
        if($m['expires_at']<=Support::now())return 'مهلت ارسال گذشته است؛ پیش‌نمایش تازه بسازید.';
        if($m['scheduled_at']>Support::now())return 'هنوز زمان برنامه‌ریزی‌شدهٔ این پیام نرسیده است.';
        return $queue['block_reason'] ?: 'هنوز به وب‌سرویس ارسال نشده؛ آمادهٔ پردازش است. اگر وضعیت تغییر نکرد، «پردازش صف اکنون» را بزنید.';
    }
    private static function run_worker($campaign_id=0,$batch_cap=0,$test_now=false,$events=false){
        $result=array('attempted'=>0,'last_error'=>'','busy'=>false);
        try{$result['attempted']=(int)self::locked('worker',function()use($campaign_id,$batch_cap,$test_now,$events){
            update_option('nasr_sms_last_tick',Support::now(),false);$s=self::settings();
            update_option('nasr_sms_last_error','',false);if(!$s['enabled'])return 0;
            if($events){self::consume_events();self::reminders();}
            $count=self::work($campaign_id,$batch_cap,$test_now);
            if($events && !$batch_cap && $s['auto_delivery'])self::refresh_deliveries();
            return $count;
        });}catch(Problem $e){if($e->getCode()===409)$result['busy']=true;else $result['last_error']=$e->getMessage();}
        catch(\Throwable $e){$result['last_error']='پردازش صف کامل نشد؛ اتصال و پایگاه داده را بررسی کنید.';}
        if($result['last_error'])update_option('nasr_sms_last_error',$result['last_error'],false);
        $result['queue']=self::queue_status($campaign_id);
        $result['message']=$result['last_error'] ?: ($result['busy']?'پردازش دیگری در حال اجراست؛ نتیجهٔ آن به‌زودی تازه می‌شود.':($result['attempted']?'نتیجهٔ پردازش '.$result['attempted'].' پیام در گزارش ارسال ثبت شد.':$result['queue']['message']));
        return $result;
    }
    private static function after_enqueue($out,$test_now){
        global $wpdb;$out['processing']=self::run_worker((int)$out['id'],1,$test_now,false);
        $out['message'].=' '.$out['processing']['message'];
        if($test_now){
            $m=$wpdb->get_row($wpdb->prepare('SELECT id,status,rec_id,error_message FROM '.Support::table('sms_messages').' WHERE campaign_id=%d ORDER BY id LIMIT 1',$out['id']),ARRAY_A);
            if($m){$out['test_result']=$m;
                if($m['status']==='sent')$out['message']='پیام آزمایشی توسط ملی‌پیامک پذیرفته شد؛ شناسهٔ پیگیری: '.$m['rec_id'];
                elseif(in_array($m['status'],array('failed','unknown','skipped'),true))$out['message']='نتیجهٔ پیام آزمایشی: '.$m['error_message'];
            }
        }
        return $out;
    }
}
