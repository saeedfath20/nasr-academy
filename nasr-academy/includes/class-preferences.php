<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Preferences {
    public static function defaults() {
        return array('font_family'=>'NasrFont','font_size'=>16,'small_font_size'=>15,'heading_size'=>24,'menu_font_size'=>17,'table_font_size'=>17,'font_weight'=>'400','line_height'=>1.9,'density'=>'normal',
            'color_primary'=>'#315eab','color_sidebar'=>'#172a46','color_background'=>'#f5f6fa','color_text'=>'#26324a','color_accent'=>'#21836b','color_border'=>'#e9edf3',
            'print_paper'=>'A4','print_orientation'=>'landscape','print_margin'=>10,'print_font_size'=>12,'print_padding'=>5,'print_academy'=>true,'print_context'=>true,'print_date'=>true,'print_summary'=>true,'print_notes'=>false,'print_zebra'=>false,'print_footer'=>'','print_columns'=>array());
    }
    public static function get() {
        $raw=get_option('nasr_academy_preferences',array());
        $v=array_merge(self::defaults(),is_array($raw)?$raw:array());$catalog=self::catalog();
        foreach($v['print_columns'] as $type=>$keys){
            $keys=array_map(function($k)use($type){if($type==='teacher_pay'){return array('rate_range'=>'hourly_rate','planned'=>'sessions_planned','held'=>'sessions_held')[$k] ?? $k;}return $k==='hourly_rate'?'rate_range':$k;},(array)$keys);
            $keys=array_values(array_intersect($keys,array_keys($catalog[$type] ?? array())));
            if($keys)$v['print_columns'][$type]=$keys;else unset($v['print_columns'][$type]);
        }return $v;
    }
    public static function catalog() {
        $base=array('branch_name'=>'شعبه','term_name'=>'ترم','class_name'=>'کلاس','student_code'=>'کد زبان‌آموز','student_name'=>'نام فارسی','english_name'=>'نام انگلیسی');
        $student_base=array_merge($base,array('student_name'=>'نام و نام خانوادگی فارسی','english_name'=>'نام و نام خانوادگی انگلیسی'));
        $classes=array('branch_name'=>'شعبه','term_name'=>'ترم','term_code'=>'کد ترم','class_name'=>'کلاس','teacher_name'=>'معلم','schedule'=>'روز و ساعت','students'=>'تعداد زبان‌آموز','sessions_planned'=>'جلسات برنامه‌ریزی‌شده','sessions_held'=>'جلسات برگزارشده','duration'=>'مدت جلسه');
        $comprehensive=array('row_number'=>'ردیف','student_code'=>'کد دانش‌آموزی','student_name'=>'نام و نام خانوادگی','mobile'=>'شماره تلفن','tuition_amount'=>'مبلغ شهریه','payment_dates'=>'تاریخ','payment_receipt_method'=>'شماره فیش و نحوه پرداخت','book_copy_amount'=>'مبلغ کتاب و کپی','previous_debt'=>'بدهی ترم قبل','snack_stationery_fee'=>'خوراکی');
        $grades=array('row_number'=>'ردیف','student_code'=>'کد زبان‌آموز','student_name'=>'نام و نام خانوادگی','listening'=>'Listening','speaking'=>'Speaking','reading'=>'Reading','writing'=>'Writing','classwork'=>'Classwork','midterm'=>'Midterm','final_exam'=>'Final Exam','total'=>'Total Score','grade_status'=>'Grade Status');
        $payroll=array('branch_name'=>'شعبه','term_name'=>'ترم','term_code'=>'کد ترم','teacher_name'=>'معلم','rate_range'=>'نرخ ساعتی کلاس‌ها (تومان)','class_count'=>'تعداد کلاس','planned'=>'جلسات برنامه‌ریزی‌شده','held'=>'جلسات برگزارشده','hours'=>'ساعت تدریس','late_minutes'=>'دقایق کسورات','gross'=>'ناخالص','deduction'=>'کسورات','teaching_net'=>'پس از تأخیر','salary_addition'=>'اضافه‌حقوق','salary_deduction'=>'کاهش‌حقوق','net'=>'حقوق نهایی','calculation_status'=>'وضعیت محاسبه');
        return array(
            'comprehensive_class'=>$comprehensive,
            'annual'=>self::annual_columns(),
            'class_grades'=>$grades,
            'student_grades'=>self::student_grade_columns(),
            'ungraded_students'=>array('class_name'=>'نام کلاس','teacher_name'=>'نام معلم','student_name'=>'نام زبان‌آموز'),
            'attendance'=>array('english_name'=>'Name','sessions'=>'Sessions (20 columns)'),
            'classes'=>$classes,'teacher_classes'=>array_diff_key($classes,array('branch_name'=>1,'term_name'=>1,'term_code'=>1,'teacher_name'=>1)),'students'=>$student_base+self::student_registration_columns(),
            'new_students'=>array_diff_key($student_base,array('class_name'=>1))+self::student_registration_columns(),
            'growth'=>array('student_code'=>'کد زبان‌آموز','student_name'=>'نام فارسی','english_name'=>'نام انگلیسی','movement'=>'وضعیت'),
            'payments'=>$base+array('paid_on'=>'تاریخ پرداخت','amount'=>'مبلغ','status'=>'وضعیت','reference'=>'پیگیری','removed'=>'سابقه حذف‌شده'),
            'unpaid'=>$base+array('snack_stationery_fee'=>'خوراکی تحریر','private_fee'=>'خصوصی','other_fee'=>'سایر هزینه‌ها','net_fee'=>'شهریه','paid'=>'پرداخت','balance'=>'مانده','removed'=>'سابقه حذف‌شده'),
            'student_payments'=>array_diff_key($base,array('class_name'=>1))+array('snack_stationery_fee'=>'خوراکی تحریر','private_fee'=>'خصوصی','other_fee'=>'سایر هزینه‌ها','net_fee'=>'شهریه','paid'=>'پرداخت','balance'=>'مانده','credit'=>'بستانکاری'),
            'teacher_pay'=>array('class_name'=>'نام کلاس','room'=>'اتاق','schedule'=>'روز و ساعت کلاس','sessions_planned'=>'جلسات برنامه‌ریزی‌شده','sessions_held'=>'جلسات برگزارشده','duration'=>'دقیقه هر جلسه','hours'=>'ساعت کل','hourly_rate'=>'حق‌التدریس ساعتی','late_minutes'=>'تأخیر (دقیقه)','gross'=>'حق‌التدریس ناخالص','deduction'=>'کسورات تأخیر','teaching_net'=>'حق‌التدریس قابل محاسبه','calculation_status'=>'وضعیت'),'teacher_history'=>$payroll,
            'class_roster'=>array('student_name'=>'نام فارسی','english_name'=>'نام انگلیسی','student_code'=>'کد','status'=>'وضعیت','classwork'=>'Classwork','midterm'=>'Midterm','final_exam'=>'Final Exam','total'=>'Total Score','snack_stationery_fee'=>'خوراکی تحریر','private_fee'=>'خصوصی','other_fee'=>'سایر هزینه‌ها','net_fee'=>'شهریه','balance'=>'مانده'));
    }
    public static function student_grade_columns() {
        return array('class_name'=>'Class','teacher_name'=>'Teacher','listening'=>'Listening / 10','speaking'=>'Speaking / 10','reading'=>'Reading / 10','writing'=>'Writing / 10',
            'classwork'=>'Classwork / 40','midterm'=>'Midterm','final_exam'=>'Final Exam','total'=>'Total Score','grade_status'=>'Grade Status','enrollment_status'=>'Enrollment Status','feedback'=>'Teacher Feedback');
    }
    public static function annual_columns() {
        return array('term_name'=>'ترم','term_code'=>'کد ترم','branch_name'=>'شعبه','start_date'=>'شروع ترم','end_date'=>'پایان ترم',
            'class_count'=>'تعداد کلاس','enrollment_count'=>'تعداد ثبت‌نام','net_fee'=>'شهریه و هزینه‌ها','paid'=>'پرداخت موفق','balance'=>'مانده بدهی','credit'=>'بستانکاری',
            'teaching_net'=>'حق‌التدریس قابل محاسبه پس از تأخیر','salary_addition'=>'اضافه‌حقوق','salary_deduction'=>'کاهش‌حقوق','net'=>'حقوق نهایی','calculation_status'=>'وضعیت حقوق');
    }
    public static function student_registration_columns() {
        return array('first_name'=>'نام فارسی','last_name'=>'نام خانوادگی فارسی','first_name_en'=>'نام انگلیسی','last_name_en'=>'نام خانوادگی انگلیسی',
            'national_id'=>'کد ملی','birth_certificate'=>'شماره شناسنامه','gender'=>'جنسیت','mobile'=>'شماره تماس ۱','landline'=>'شماره تماس ۲','extra_phone'=>'شماره تماس ۳',
            'education'=>'مدرک تحصیلی','birth_date'=>'تاریخ تولد','birth_year'=>'سال تولد (پرونده قدیمی)','father_name'=>'نام پدر','address'=>'آدرس','email'=>'ایمیل','username'=>'نام کاربری','account_status'=>'وضعیت حساب');
    }
    public static function save($raw) {
        if (!is_array($raw)) { throw new Problem('تنظیمات ظاهر و چاپ معتبر نیست.'); }
        $v=self::get();
        foreach (array('font_size'=>array(14,24,0),'small_font_size'=>array(12,22,0),'heading_size'=>array(18,40,0),'menu_font_size'=>array(14,24,0),'table_font_size'=>array(12,24,0),'line_height'=>array(1.2,2.5,1),'print_margin'=>array(3,25,0),'print_font_size'=>array(9,22,0),'print_padding'=>array(2,12,0)) as $key=>$limits) {
            if (!array_key_exists($key,$raw)) { continue; }
            $n=Support::number($raw[$key],$limits[0],$limits[1],$limits[2]);
            if (is_wp_error($n)) { throw new Problem($key.': '.$n->get_error_message()); } $v[$key]=$n;
        }
        foreach (array('font_family'=>array('NasrFont','Tahoma','Arial'),'font_weight'=>array('400','500','700'),'density'=>array('normal','compact'),'print_paper'=>array('A4','A5','Letter'),'print_orientation'=>array('landscape','portrait')) as $key=>$allowed) {
            if (!isset($raw[$key])) { continue; }
            if (!is_scalar($raw[$key]) || !in_array((string)$raw[$key],$allowed,true)) { throw new Problem('گزینهٔ ظاهر یا چاپ معتبر نیست.'); } $v[$key]=(string)$raw[$key];
        }
        foreach (array('color_primary','color_sidebar','color_background','color_text','color_accent','color_border') as $key) {
            if (!isset($raw[$key])) { continue; }
            if (!is_string($raw[$key]) || !preg_match('/^#[0-9a-fA-F]{6}$/D',$raw[$key])) { throw new Problem('رنگ را به شکل #315eab وارد کنید.'); } $v[$key]=$raw[$key];
        }
        foreach (array('print_academy','print_context','print_date','print_summary','print_notes','print_zebra') as $key) { if (array_key_exists($key,$raw)) { $v[$key]=!empty($raw[$key]); } }
        if (isset($raw['print_footer'])) {
            if (!is_scalar($raw['print_footer']) || Support::length($raw['print_footer'])>300) { throw new Problem('پاورقی چاپ حداکثر ۳۰۰ نویسه باشد.'); }
            $v['print_footer']=sanitize_text_field($raw['print_footer']);
        }
        if (array_key_exists('print_columns',$raw)) {
            if (!is_array($raw['print_columns'])) { throw new Problem('ستون‌های چاپ معتبر نیست.'); }
            $v['print_columns']=array(); $catalog=self::catalog();
            foreach ($raw['print_columns'] as $report=>$keys) {
                if (!isset($catalog[$report]) || !is_array($keys) || !$keys || count(array_filter($keys,'is_string'))!==count($keys) || array_diff($keys,array_keys($catalog[$report]))) { throw new Problem('برای هر گزارش حداقل یک ستون معتبر انتخاب کنید.'); }
                $v['print_columns'][$report]=array_values(array_unique($keys));
            }
        }
        update_option('nasr_academy_preferences',$v,false);
        return $v;
    }
    public static function css($v=null) {
        $v=$v ?: self::get();
        return '#nasr-app{--navy:'.$v['color_sidebar'].';--blue:'.$v['color_primary'].';--green:'.$v['color_accent'].';--ink:'.$v['color_text'].';--line:'.$v['color_border'].';--nasr-bg:'.$v['color_background'].';--nasr-font-scale:'.($v['font_size']/16).';--nasr-small:'.$v['small_font_size'].'px;--nasr-heading:'.$v['heading_size'].'px;--nasr-menu:'.$v['menu_font_size'].'px;--nasr-table:'.$v['table_font_size'].'px;font-family:'.$v['font_family'].',Tahoma,Arial,sans-serif;font-size:'.$v['font_size'].'px;line-height:'.$v['line_height'].';font-weight:'.$v['font_weight'].'}body.nasr-portal{background:'.$v['color_background'].'!important}';
    }
}
