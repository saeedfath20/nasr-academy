<?php
namespace Nasr\Academy;
defined('ABSPATH') || exit;

final class Install {
    public static function activate($network_wide = false) {
        if (is_multisite() && $network_wide) { wp_die('سامانه نصر را برای هر سایت به‌صورت جداگانه فعال کنید؛ فعال‌سازی شبکه‌ای پشتیبانی نمی‌شود.'); }
        self::schema();
        if (!get_option('nasr_academy_page_id')) {
            $id = wp_insert_post(array('post_title'=>'سامانه آموزشگاه نصر','post_name'=>'nasr-panel','post_type'=>'page','post_status'=>'publish','post_content'=>'[nasr_academy]'),true);
            if (!is_wp_error($id)) { update_option('nasr_academy_page_id',$id,false); }
        }
    }
    public static function schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $schemas = array(
            'people' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
user_id bigint unsigned NOT NULL,
kind varchar(20) NOT NULL,
secretary_access tinyint unsigned NOT NULL DEFAULT 0,
student_code bigint unsigned DEFAULT NULL,
national_id varchar(20) DEFAULT NULL,
first_name varchar(100) NOT NULL,
last_name varchar(100) NOT NULL,
gender varchar(12) NOT NULL DEFAULT '',
first_name_en varchar(100) NOT NULL DEFAULT '',
last_name_en varchar(100) NOT NULL DEFAULT '',
birth_certificate varchar(50) NOT NULL DEFAULT '',
mobile varchar(25) NOT NULL DEFAULT '',
landline varchar(25) NOT NULL DEFAULT '',
address text NOT NULL,
education varchar(150) NOT NULL DEFAULT '',
birth_date date DEFAULT NULL,
birth_year smallint unsigned DEFAULT NULL,
father_name varchar(150) NOT NULL DEFAULT '',
extra_phone varchar(25) NOT NULL DEFAULT '',
active tinyint unsigned NOT NULL DEFAULT 1,
deleted_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_id (user_id),
UNIQUE KEY student_code (student_code),
UNIQUE KEY national_id (national_id),
KEY kind_active (kind,active)",
            'sequences' => "name varchar(50) NOT NULL,
value bigint unsigned NOT NULL,
PRIMARY KEY  (name)",
            'sync_receipts' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
user_id bigint unsigned NOT NULL,
request_id varchar(64) NOT NULL,
role varchar(20) NOT NULL,
route varchar(120) NOT NULL,
body_hash char(64) NOT NULL,
response_json longtext NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_request (user_id,request_id)",
            'branches' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
name varchar(150) NOT NULL,
active tinyint unsigned NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
UNIQUE KEY name (name)",
            'payroll_adjustments' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
teacher_id bigint unsigned NOT NULL,
term_id bigint unsigned NOT NULL,
rows_json longtext NOT NULL,
addition bigint unsigned NOT NULL DEFAULT 0,
deduction bigint unsigned NOT NULL DEFAULT 0,
version int unsigned NOT NULL DEFAULT 1,
updated_at datetime NOT NULL,
updated_by bigint unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY teacher_term (teacher_id,term_id)",
            'teacher_rates' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
teacher_id bigint unsigned NOT NULL,
term_id bigint unsigned NOT NULL,
hourly_rate bigint unsigned NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY teacher_term (teacher_id,term_id)",
            'attendance_sessions' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
class_id bigint unsigned NOT NULL,
session_no smallint unsigned NOT NULL,
session_date date NOT NULL,
version int unsigned NOT NULL DEFAULT 1,
updated_by bigint unsigned NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY class_session (class_id,session_no)",
            'attendance' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
session_id bigint unsigned NOT NULL,
enrollment_id bigint unsigned NOT NULL,
status varchar(15) NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY session_enrollment (session_id,enrollment_id),
KEY enrollment_id (enrollment_id)",
            'terms' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
branch_id bigint unsigned DEFAULT NULL,
code varchar(60) NOT NULL,
name varchar(190) NOT NULL,
start_date date NOT NULL,
end_date date NOT NULL,
mid_date date DEFAULT NULL,
final_date date DEFAULT NULL,
active tinyint unsigned NOT NULL DEFAULT 1,
deleted_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY code (code),
KEY branch_start (branch_id,start_date)",
            'classes' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
term_id bigint unsigned NOT NULL,
teacher_id bigint unsigned NOT NULL,
name varchar(190) NOT NULL,
base_fee bigint unsigned NOT NULL DEFAULT 0,
extras_fee bigint unsigned NOT NULL DEFAULT 0,
mid_max decimal(5,1) NOT NULL DEFAULT 20.0,
final_max decimal(5,1) NOT NULL DEFAULT 40.0,
schedule varchar(250) NOT NULL DEFAULT '',
class_day varchar(20) NOT NULL DEFAULT '',
class_days varchar(100) NOT NULL DEFAULT '',
start_time char(5) NOT NULL DEFAULT '',
duration smallint unsigned DEFAULT NULL,
sessions_planned smallint unsigned DEFAULT NULL,
sessions_held smallint unsigned DEFAULT NULL,
late_minutes int unsigned NOT NULL DEFAULT 0,
hourly_rate bigint unsigned DEFAULT NULL,
room varchar(100) NOT NULL DEFAULT '',
notes text NOT NULL,
active tinyint unsigned NOT NULL DEFAULT 1,
deleted_at datetime DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY teacher_term (teacher_id,term_id),
KEY term_id (term_id)",
            'enrollments' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
class_id bigint unsigned NOT NULL,
student_id bigint unsigned NOT NULL,
base_fee bigint unsigned NOT NULL,
extras_fee bigint unsigned NOT NULL,
discount decimal(5,1) NOT NULL DEFAULT 0,
discount_scope varchar(10) NOT NULL DEFAULT 'base',
net_fee bigint unsigned NOT NULL,
snack_stationery_fee bigint unsigned NOT NULL DEFAULT 0,
private_fee bigint unsigned NOT NULL DEFAULT 0,
other_fee bigint unsigned NOT NULL DEFAULT 0,
finance_version int unsigned NOT NULL DEFAULT 0,
active tinyint unsigned NOT NULL DEFAULT 1,
deleted_at datetime DEFAULT NULL,
listening decimal(5,1) DEFAULT NULL,
speaking decimal(5,1) DEFAULT NULL,
reading decimal(5,1) DEFAULT NULL,
writing decimal(5,1) DEFAULT NULL,
midterm decimal(5,1) DEFAULT NULL,
final_exam decimal(5,1) DEFAULT NULL,
feedback text NOT NULL,
grade_version int unsigned NOT NULL DEFAULT 0,
grade_updated_at datetime DEFAULT NULL,
grade_updated_by bigint unsigned DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY class_student (class_id,student_id),
KEY student_id (student_id)",
            'family_links' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
family_id bigint unsigned NOT NULL,
student_id bigint unsigned NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY family_student (family_id,student_id),
KEY student_id (student_id)",
            'payments' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
enrollment_id bigint unsigned NOT NULL,
amount bigint unsigned NOT NULL,
status varchar(15) NOT NULL,
method varchar(25) NOT NULL DEFAULT 'manual',
reference varchar(120) NOT NULL DEFAULT '',
paid_on date NOT NULL,
notes text NOT NULL,
request_key varchar(64) NOT NULL,
created_by bigint unsigned NOT NULL,
created_at datetime NOT NULL,
voided_at datetime DEFAULT NULL,
void_reason text NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_key (request_key),
KEY enrollment_status (enrollment_id,status)",
            'payment_transactions' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
token char(64) NOT NULL,
request_key varchar(64) NOT NULL,
enrollment_id bigint unsigned NOT NULL,
student_id bigint unsigned NOT NULL,
gateway varchar(25) NOT NULL,
amount bigint unsigned NOT NULL,
currency varchar(10) NOT NULL,
status varchar(20) NOT NULL,
provider_order_id varchar(190) NOT NULL DEFAULT '',
provider_token varchar(500) NOT NULL DEFAULT '',
reference varchar(190) NOT NULL DEFAULT '',
redirect_url text NOT NULL,
request_json longtext NOT NULL,
response_json longtext NOT NULL,
callback_json longtext NOT NULL,
error_message varchar(500) NOT NULL DEFAULT '',
payment_id bigint unsigned NOT NULL DEFAULT 0,
created_by bigint unsigned NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
paid_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token (token),
UNIQUE KEY request_key (request_key),
KEY enrollment_status (enrollment_id,status),
KEY gateway_status (gateway,status),
KEY gateway_order (gateway,provider_order_id(100)),
KEY payment_id (payment_id)",
            'audit' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
actor_id bigint unsigned NOT NULL,
action varchar(60) NOT NULL,
entity varchar(30) NOT NULL,
entity_id bigint unsigned NOT NULL DEFAULT 0,
details longtext NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY entity_id (entity,entity_id)",
        );
        foreach ($schemas as $name=>$schema) {
            $table = Support::table($name);
            dbDelta("CREATE TABLE $table (\n$schema\n) ENGINE=InnoDB $cc;");
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                wp_die('ساخت جدول سامانه نصر انجام نشد. دسترسی پایگاه داده را بررسی کنید.');
            }
        }
        // Do not mark an upgrade complete if a required new column was not added.
        foreach (array('people','terms','classes','enrollments') as $name) {
            if (!$wpdb->get_var("SHOW COLUMNS FROM ".Support::table($name)." LIKE 'deleted_at'")) {
                wp_die('به‌روزرسانی جدول‌های سامانه نصر کامل نشد. مجوز تغییر ساختار پایگاه داده را بررسی کنید.');
            }
        }
        if (!$wpdb->get_var("SHOW COLUMNS FROM ".Support::table('classes')." LIKE 'class_days'")) {
            wp_die('افزودن روزهای هفتگی کلاس انجام نشد. مجوز تغییر ساختار پایگاه داده را بررسی کنید.');
        }
        foreach (array('people'=>array('gender','first_name_en','last_name_en'),'terms'=>array('branch_id'),'classes'=>array('sessions_planned','sessions_held','late_minutes','hourly_rate'),'enrollments'=>array('snack_stationery_fee','private_fee','other_fee','finance_version')) as $name=>$columns) {
            foreach ($columns as $column) {
                if (!$wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM '.Support::table($name).' LIKE %s',$column))) {
                    wp_die('به‌روزرسانی فیلدهای جنسیت، شعبه، نام انگلیسی یا جلسات کامل نشد. مجوز تغییر ساختار پایگاه داده را بررسی کنید.');
                }
            }
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(Support::table('payment_transactions')))) !== Support::table('payment_transactions')) {
            wp_die('ساخت جدول تراکنش‌های پرداخت انجام نشد. دسترسی پایگاه داده را بررسی کنید.');
        }
        Support::checked($wpdb->query('UPDATE '.Support::table('classes')." SET class_days=class_day WHERE class_days='' AND class_day IN ('friday','saturday','sunday','monday','tuesday','wednesday','thursday')"));
        Support::checked($wpdb->query('INSERT IGNORE INTO ' . Support::table('sequences') . " (name,value) VALUES ('student',9999)"));
        add_role('nasr_teacher','معلم نصر',array('read'=>true,'nasr_access'=>true));
        add_role('nasr_student','زبان‌آموز نصر',array('read'=>true,'nasr_access'=>true));
        add_role('nasr_family','خانواده نصر',array('read'=>true,'nasr_access'=>true));
        add_role('nasr_manager','مدیر آموزشگاه نصر',array('read'=>true,'nasr_access'=>true,'nasr_manage'=>true));
        add_role('nasr_secretary','منشی نصر',array('read'=>true,'nasr_access'=>true));
        $admin = get_role('administrator');
        if ($admin) { $admin->add_cap('nasr_manage'); $admin->add_cap('nasr_access'); }
        if (!get_option('nasr_class_rates_migrated')) {
            Support::checked($wpdb->query('UPDATE '.Support::table('classes').' c JOIN '.Support::table('teacher_rates').' r ON r.teacher_id=c.teacher_id AND r.term_id=c.term_id SET c.hourly_rate=r.hourly_rate WHERE c.hourly_rate IS NULL'));
            update_option('nasr_class_rates_migrated',1,false);
        }
        SMS::schema();
        update_option('nasr_academy_version', NASR_ACADEMY_VERSION, false);
    }
}
