<?php
/**
 * Plugin Name: سامانه آموزشگاه نصر | Nasr Academy
 * Description: پنل فارسی آموزشگاه؛ شعبه، ترم، کلاس، حضور و غیاب، شهریه و پرداخت اینترنتی، گزارش‌ها، چاپ و مرکز پیامک.
 * Version: 1.6.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Nasr Academy
 * License: GPL-2.0-or-later
 * Text Domain: nasr-academy
 */
defined('ABSPATH') || exit;
if (defined('NASR_ACADEMY_VERSION')) { return; }
define('NASR_ACADEMY_VERSION', '1.6.1');
define('NASR_ACADEMY_FILE', __FILE__);
define('NASR_ACADEMY_DIR', plugin_dir_path(__FILE__));
define('NASR_ACADEMY_URL', plugin_dir_url(__FILE__));
if (isset($_GET['nasr_pwa']) && is_string($_GET['nasr_pwa']) && in_array($_GET['nasr_pwa'],array('app','manifest','worker'),true) && !defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE',true); }
require_once NASR_ACADEMY_DIR . 'includes/class-support.php';
require_once NASR_ACADEMY_DIR . 'includes/class-mobile.php';
require_once NASR_ACADEMY_DIR . 'includes/class-payment-gateway.php';
require_once NASR_ACADEMY_DIR . 'includes/class-payments.php';
require_once NASR_ACADEMY_DIR . 'includes/class-install.php';
require_once NASR_ACADEMY_DIR . 'includes/trait-reports.php';
require_once NASR_ACADEMY_DIR . 'includes/class-preferences.php';
require_once NASR_ACADEMY_DIR . 'includes/trait-sms-data.php';
require_once NASR_ACADEMY_DIR . 'includes/trait-sms-queue.php';
require_once NASR_ACADEMY_DIR . 'includes/class-sms.php';
require_once NASR_ACADEMY_DIR . 'includes/trait-payroll.php';
require_once NASR_ACADEMY_DIR . 'includes/trait-trash.php';
require_once NASR_ACADEMY_DIR . 'includes/class-api.php';
require_once NASR_ACADEMY_DIR . 'includes/class-password-recovery.php';
require_once NASR_ACADEMY_DIR . 'includes/class-portal.php';
require_once NASR_ACADEMY_DIR . 'includes/class-webapp.php';
require_once NASR_ACADEMY_DIR . 'includes/class-releases.php';
register_deactivation_hook(__FILE__,array('Nasr\\Academy\\SMS','deactivate'));
register_activation_hook(__FILE__, array('Nasr\\Academy\\Install', 'activate'));
add_action('plugins_loaded', function () {
    if (get_option('nasr_academy_version') !== NASR_ACADEMY_VERSION) {
        \Nasr\Academy\Install::schema();
    }
    remove_action('plugins_loaded',array('NasrWebApp\\App','boot'),20);
    \Nasr\Academy\WebApp::boot();
    \Nasr\Academy\Releases::boot();
    \Nasr\Academy\Payments::boot();
    \Nasr\Academy\Mobile::boot();
    \Nasr\Academy\SMS::boot();
    \Nasr\Academy\API::boot();
    \Nasr\Academy\Password_Recovery::boot();
    \Nasr\Academy\Portal::boot();
});
