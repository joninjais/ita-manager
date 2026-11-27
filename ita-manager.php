<?php
/**
 * Plugin Name: ITA Manager
 * Description: จัดการข้อมูล ITA (การประเมินคุณธรรมและความโปร่งใส) ทั้งหมดในหน้าเดียว รองรับหลายปีงบประมาณ
 * Version:     1.0.1
 * Author:      Admin
 * Text Domain: ita-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ITA_MANAGER_VERSION', '1.2.0' );
define( 'ITA_MANAGER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ITA_MANAGER_URL', plugin_dir_url( __FILE__ ) );

require_once ITA_MANAGER_PATH . 'includes/class-ita-db.php';
require_once ITA_MANAGER_PATH . 'includes/class-ita-admin.php';
require_once ITA_MANAGER_PATH . 'includes/class-ita-shortcode.php';
require_once ITA_MANAGER_PATH . 'includes/class-ita-copy-structure.php';


register_activation_hook( __FILE__, array( 'ITA_DB', 'install' ) );

function ita_manager_init() {
    ITA_DB::maybe_upgrade();
    ITA_Copy_Structure::init();
    
    $admin = new ITA_Admin();
    $admin->init();

    $shortcode = new ITA_Shortcode();
    $shortcode->init();
}
add_action( 'plugins_loaded', 'ita_manager_init' );
