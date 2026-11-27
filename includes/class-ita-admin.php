<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITA_Admin {

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_help_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_users_assets' ) );

        // Grant custom capability to allowed users
        add_filter( 'user_has_cap', array( $this, 'grant_ita_cap' ), 10, 3 );

        // AJAX – logged-in users only
        $ajax_actions = array(
            'ita_save_year', 'ita_delete_year',
            'ita_save_indicator', 'ita_delete_indicator',
            'ita_save_moit', 'ita_delete_moit',
            'ita_save_item', 'ita_delete_item',
            'ita_reorder_items',
            'ita_get_year_data',
            'ita_upload_file',
        );
        foreach ( $ajax_actions as $action ) {
            add_action( "wp_ajax_{$action}", array( $this, $action ) );
        }

        // AJAX – admin only (user management)
        add_action( 'wp_ajax_ita_save_allowed_users', array( $this, 'ita_save_allowed_users' ) );
        add_action( 'wp_ajax_ita_clear_delete_log',   array( $this, 'ita_clear_delete_log' ) );

        // Log page assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_log_assets' ) );
    }

    /**
     * Dynamically grant the 'ita_manage' capability to:
     *   1. Any user who already has 'manage_options' (administrators)
     *   2. Any user whose ID is in the ita_allowed_users option
     */
    public function grant_ita_cap( $allcaps, $caps, $args ) {
        if ( ! empty( $allcaps['manage_options'] ) ) {
            $allcaps['ita_manage'] = true;
            return $allcaps;
        }
        $user_id = isset( $args[1] ) ? intval( $args[1] ) : 0;
        if ( $user_id && in_array( $user_id, self::get_allowed_users(), true ) ) {
            $allcaps['ita_manage'] = true;
        }
        return $allcaps;
    }

    /**
     * Return the list of allowed user IDs (non-admin users).
     */
    public static function get_allowed_users() {
        return array_map( 'intval', (array) get_option( 'ita_allowed_users', array() ) );
    }

    public function add_menu() {
        add_menu_page(
            'ITA Manager',
            'ITA Manager',
            'ita_manage',
            'ita-manager',
            array( $this, 'render_page' ),
            'dashicons-shield-alt',
            30
        );
        add_submenu_page(
            'ita-manager',
            'คู่มือการใช้งาน ITA Manager',
            '<span class="dashicons dashicons-book-alt" style="font-size:14px;vertical-align:text-top;"></span> คู่มือ',
            'ita_manage',
            'ita-manager-help',
            array( $this, 'render_help_page' )
        );
        add_submenu_page(
            'ita-manager',
            'จัดการผู้ใช้งาน ITA Manager',
            '<span class="dashicons dashicons-groups" style="font-size:14px;vertical-align:text-top;"></span> ผู้ใช้งาน',
            'manage_options',
            'ita-manager-users',
            array( $this, 'render_users_page' )
        );
        add_submenu_page(
            'ita-manager',
            'ประวัติการลบข้อมูล ITA',
            '<span class="dashicons dashicons-backup" style="font-size:14px;vertical-align:text-top;"></span> ประวัติการลบ',
            'ita_manage',
            'ita-manager-log',
            array( $this, 'render_log_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_ita-manager' ) {
            return;
        }
        wp_enqueue_media(); // สำหรับ WP Media Uploader
        wp_enqueue_style(
            'ita-admin-css',
            ITA_MANAGER_URL . 'assets/admin.css',
            array(),
            ITA_MANAGER_VERSION
        );
        wp_enqueue_script(
            'ita-admin-js',
            ITA_MANAGER_URL . 'assets/admin.js',
            array( 'jquery', 'wp-util' ),
            ITA_MANAGER_VERSION,
            true
        );
        wp_localize_script( 'ita-admin-js', 'itaData', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ita_manager_nonce' ),
        ) );
    }

    // ─── RENDER PAGE ─────────────────────────────────────────────────────────

    public function render_page() {
        $years      = ITA_DB::get_years();
        $readme_url = admin_url( 'admin.php?page=ita-manager-help' );
        ?>
        <div class="wrap ita-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-shield-alt" style="font-size:30px;vertical-align:middle;margin-right:8px;"></span>
                ITA Manager – จัดการข้อมูลการประเมินคุณธรรมและความโปร่งใส
            </h1>
            <a href="<?php echo esc_url( $readme_url ); ?>"
               target="_blank"
               class="page-title-action"
               title="คู่มือการใช้งาน ITA Manager">
                <span class="dashicons dashicons-book-alt" style="font-size:14px;vertical-align:middle;margin-right:4px;"></span>
                คู่มือการใช้งาน
            </a>
            <hr class="wp-header-end">

            <div id="ita-notice" class="ita-notice" style="display:none;"></div>

            <!-- ส่วนเลือกปี / เพิ่มปี -->
            <div class="ita-year-bar">
                <div class="ita-year-tabs" id="ita-year-tabs">
                    <?php foreach ( $years as $year ) : ?>
                    <button class="ita-year-tab"
                            data-year-id="<?php echo esc_attr( $year->id ); ?>"
                            data-year-label="<?php echo esc_attr( $year->year_label ); ?>">
                        ITA <?php echo esc_html( $year->year_label ); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <button class="button ita-btn-add-year" id="btn-add-year">
                    <span class="dashicons dashicons-plus-alt2"></span> เพิ่มปีงบประมาณ
                </button>
            </div>

            <!-- Modal เพิ่ม/แก้ไขปี -->
            <div id="ita-modal-year" class="ita-modal" style="display:none;">
                <div class="ita-modal-content">
                    <h2 id="modal-year-title">เพิ่มปีงบประมาณ</h2>
                    <input type="hidden" id="modal-year-id" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="modal-year-label">ปีงบประมาณ (พ.ศ.)</label></th>
                            <td><input type="text" id="modal-year-label" class="regular-text" placeholder="เช่น 2569" maxlength="10"></td>
                        </tr>
                        <tr>
                            <th><label for="modal-year-sort">ลำดับการแสดง</label></th>
                            <td><input type="number" id="modal-year-sort" class="small-text" value="0"></td>
                        </tr>
                    </table>
                    <div class="ita-modal-footer">
                        <button class="button button-primary" id="modal-year-save">บันทึก</button>
                        <button class="button ita-modal-close">ยกเลิก</button>
                    </div>
                </div>
            </div>

            <!-- พื้นที่แสดงข้อมูล ITA ของปีที่เลือก -->
            <div id="ita-content-area">
                <div class="ita-empty-state" id="ita-empty">
                    <span class="dashicons dashicons-info-outline" style="font-size:48px;color:#ccc;"></span>
                    <p>เลือกปีงบประมาณด้านบน หรือเพิ่มปีใหม่</p>
                </div>

                <div id="ita-year-content" style="display:none;">

                    <div class="ita-year-header">
                        <h2 id="ita-year-heading"></h2>
                        <div class="ita-year-actions">                           
                            <?php ITA_Copy_Structure::render_button(); ?>  
                            <button class="button ita-btn-edit-year" id="btn-edit-year">
                                <span class="dashicons dashicons-edit"></span> แก้ไขปี
                            </button>
                            <button class="button ita-btn-delete-year button-secondary" id="btn-delete-year" style="color:#c0392b;border-color:#c0392b;">
                                <span class="dashicons dashicons-trash"></span> ลบปีนี้
                            </button>
                            <button class="button button-primary" id="btn-add-indicator">
                                <span class="dashicons dashicons-plus-alt2"></span> เพิ่มตัวชี้วัด
                            </button>
                        </div>
                    </div>

                    <div id="ita-indicators-list">
                        <!-- render โดย JS -->
                    </div>

                </div>
            </div>
        </div>

        <!-- Modal ตัวชี้วัด -->
        <div id="ita-modal-indicator" class="ita-modal" style="display:none;">
            <div class="ita-modal-content">
                <h2 id="modal-indicator-title">เพิ่มตัวชี้วัด</h2>
                <input type="hidden" id="modal-indicator-id" value="">
                <input type="hidden" id="modal-indicator-year-id" value="">
                <table class="form-table">
                    <tr>
                        <th><label for="modal-indicator-no">ลำดับที่</label></th>
                        <td><input type="number" id="modal-indicator-no" class="small-text" value="1" min="1"></td>
                    </tr>
                    <tr>
                        <th><label for="modal-indicator-ttl">ชื่อตัวชี้วัด</label></th>
                        <td><input type="text" id="modal-indicator-ttl" class="large-text" placeholder="เช่น การเปิดเผยข้อมูล"></td>
                    </tr>
                </table>
                <div class="ita-modal-footer">
                    <button class="button button-primary" id="modal-indicator-save">บันทึก</button>
                    <button class="button ita-modal-close">ยกเลิก</button>
                </div>
            </div>
        </div>

        <!-- Modal MOIT -->
        <div id="ita-modal-moit" class="ita-modal" style="display:none;">
            <div class="ita-modal-content">
                <h2 id="modal-moit-title">เพิ่ม MOIT</h2>
                <input type="hidden" id="modal-moit-id" value="">
                <input type="hidden" id="modal-moit-indicator-id" value="">
                <table class="form-table">
                    <tr>
                        <th><label for="modal-moit-no">หมายเลข MOIT</label></th>
                        <td><input type="number" id="modal-moit-no" class="small-text" value="1" min="1"></td>
                    </tr>
                    <tr>
                        <th><label for="modal-moit-ttl">ชื่อ MOIT</label></th>
                        <td><textarea id="modal-moit-ttl" class="large-text" rows="3" placeholder="เช่น หน่วยงานมีการวางระบบโดยการกำหนดมาตรการ..."></textarea></td>
                    </tr>
                </table>
                <div class="ita-modal-footer">
                    <button class="button button-primary" id="modal-moit-save">บันทึก</button>
                    <button class="button ita-modal-close">ยกเลิก</button>
                </div>
            </div>
        </div>

        <!-- Modal Item -->
        <div id="ita-modal-item" class="ita-modal" style="display:none;">
            <div class="ita-modal-content ita-modal-wide">
                <h2 id="modal-item-title">เพิ่มรายการ</h2>
                <input type="hidden" id="modal-item-id" value="">
                <input type="hidden" id="modal-item-moit-id" value="">
                <input type="hidden" id="modal-item-file-id" value="0">
                <table class="form-table">
                    <tr>
                        <th><label>ประเภท</label></th>
                        <td>
                            <label style="margin-right:20px;cursor:pointer;">
                                <input type="radio" name="modal-item-type" id="modal-item-type-item" value="item" checked>
                                <strong>รายการปกติ</strong> <span class="description">(มีเลขข้อ + เนื้อหา + ไฟล์)</span>
                            </label>
                            <label style="cursor:pointer;">
                                <input type="radio" name="modal-item-type" id="modal-item-type-section" value="section">
                                <strong>หัวข้อ / ช่วงเวลา</strong> <span class="description">(เช่น ไตรมาส 3, รอบ 6 เดือน)</span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="modal-item-no">ข้อที่ / ลำดับ</label></th>
                        <td>
                            <input type="text" id="modal-item-no" class="regular-text" placeholder="เช่น 10.1, 10.2.1, ไตรมาส 3">
                            <p class="description">รองรับตัวเลขซ้อน (10.2.1) และข้อความ (ไตรมาส 3)</p>
                        </td>
                    </tr>
                    <tr id="modal-item-title-row">
                        <th><label for="modal-item-ttl">รายละเอียด</label></th>
                        <td><textarea id="modal-item-ttl" class="large-text" rows="3" placeholder="คำอธิบายรายการนี้..."></textarea></td>
                    </tr>
                    <tr class="modal-item-file-rows">
                        <th><label>ไฟล์แนบ (PDF/รูป)</label></th>
                        <td>
                            <div class="ita-file-row">
                                <input type="text" id="modal-item-file-url" class="large-text" placeholder="URL ไฟล์ หรือเลือกจาก Media Library">
                                <button type="button" class="button" id="btn-select-media">
                                    <span class="dashicons dashicons-paperclip"></span> เลือกไฟล์
                                </button>
                                <button type="button" class="button" id="btn-clear-file" style="display:none;">ล้าง</button>
                            </div>
                            <div id="ita-file-preview" style="margin-top:6px;display:none;">
                                <span class="dashicons dashicons-media-default"></span>
                                <span id="ita-file-preview-name"></span>
                            </div>
                        </td>
                    </tr>
                    <tr class="modal-item-file-rows">
                        <th><label for="modal-item-link">ลิงค์ภายนอก</label></th>
                        <td>
                            <input type="url" id="modal-item-link" class="large-text" placeholder="https://...">
                            <p class="description">ถ้ามีทั้งไฟล์และลิงค์ ระบบจะใช้ไฟล์ก่อน</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="modal-item-sort">ลำดับ</label></th>
                        <td><input type="number" id="modal-item-sort" class="small-text" value="0"></td>
                    </tr>
                </table>
                <div class="ita-modal-footer">
                    <button class="button button-primary" id="modal-item-save">บันทึก</button>
                    <button class="button ita-modal-close">ยกเลิก</button>
                </div>
            </div>
        </div>

        <!-- Modal ยืนยันการลบ (พิมพ์ "ยืนยันลบ") -->
        <div id="ita-modal-confirm-delete" class="ita-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ita-confirm-delete-title">
            <div class="ita-modal-content" style="max-width:420px;">
                <h2 id="ita-confirm-delete-title" style="color:#c0392b;">
                    <span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:6px;"></span>
                    ยืนยันการลบ
                </h2>
                <p id="ita-confirm-delete-msg" style="margin:12px 0 16px;"></p>
                <p style="margin:0 0 8px;">
                    พิมพ์ <strong style="color:#c0392b;">ยืนยันลบ</strong> เพื่อดำเนินการต่อ:
                </p>
                <input type="text"
                       id="ita-confirm-delete-input"
                       class="regular-text"
                       placeholder="พิมพ์ ยืนยันลบ"
                       autocomplete="off"
                       style="width:100%;">
                <div class="ita-modal-footer" style="margin-top:16px;">
                    <button class="button button-primary" id="ita-confirm-delete-ok"
                            style="background:#c0392b;border-color:#a93226;color:#fff;"
                            disabled>
                        <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span> ลบ
                    </button>
                    <button class="button ita-modal-close">ยกเลิก</button>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── AJAX HANDLERS ───────────────────────────────────────────────────────

    private function verify_nonce() {
        if ( ! check_ajax_referer( 'ita_manager_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }
        if ( ! current_user_can( 'ita_manage' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
    }

    public function ita_save_year() {
        $this->verify_nonce();
        $id = ITA_DB::save_year( $_POST );
        wp_send_json_success( array( 'id' => $id ) );
    }

    public function ita_delete_year() {
        $this->verify_nonce();
        ITA_DB::delete_year( intval( $_POST['id'] ) );
        wp_send_json_success();
    }

    public function ita_save_indicator() {
        $this->verify_nonce();
        $is_new = empty( $_POST['id'] );
        $id     = ITA_DB::save_indicator( $_POST );
        wp_send_json_success( array(
            'id'           => $id,
            'is_new'       => $is_new,
            'indicator_no' => intval( $_POST['indicator_no'] ?? 0 ),
            'title'        => sanitize_text_field( $_POST['title'] ?? '' ),
        ) );
    }

    public function ita_delete_indicator() {
        $this->verify_nonce();
        ITA_DB::delete_indicator( intval( $_POST['id'] ) );
        wp_send_json_success();
    }

    public function ita_save_moit() {
        $this->verify_nonce();
        $is_new = empty( $_POST['id'] );
        $id     = ITA_DB::save_moit( $_POST );
        wp_send_json_success( array(
            'id'           => $id,
            'is_new'       => $is_new,
            'indicator_id' => intval( $_POST['indicator_id'] ?? 0 ),
            'moit_no'      => intval( $_POST['moit_no'] ?? 0 ),
            'title'        => wp_kses_post( $_POST['title'] ?? '' ),
        ) );
    }

    public function ita_delete_moit() {
        $this->verify_nonce();
        ITA_DB::delete_moit( intval( $_POST['id'] ) );
        wp_send_json_success();
    }

    public function ita_save_item() {
        $this->verify_nonce();
        $id      = ITA_DB::save_item( $_POST );
        $moit_id = intval( $_POST['moit_id'] ?? 0 );
        wp_send_json_success( array(
            'id'      => $id,
            'moit_id' => $moit_id,
            'items'   => ITA_DB::get_items( $moit_id ),
        ) );
    }

    public function ita_delete_item() {
        $this->verify_nonce();
        ITA_DB::delete_item( intval( $_POST['id'] ) );
        $moit_id = intval( $_POST['moit_id'] ?? 0 );
        wp_send_json_success( array(
            'moit_id' => $moit_id,
            'items'   => $moit_id ? ITA_DB::get_items( $moit_id ) : array(),
        ) );
    }

    public function ita_get_year_data() {
        $this->verify_nonce();
        $year_id = intval( $_POST['year_id'] );
        $data    = ITA_DB::get_full_year_data( $year_id );
        wp_send_json_success( array( 'indicators' => $data ) );
    }

    public function ita_reorder_items() {
        $this->verify_nonce();
        $moit_id = intval( $_POST['moit_id'] ?? 0 );
        $ids     = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
        if ( ! $moit_id || empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Invalid data.' ) );
        }
        global $wpdb;
        foreach ( $ids as $order => $id ) {
            $wpdb->update(
                "{$wpdb->prefix}ita_items",
                array( 'sort_order' => $order + 1 ),
                array( 'id' => $id, 'moit_id' => $moit_id )
            );
        }
        wp_send_json_success( array(
            'moit_id' => $moit_id,
            'items'   => ITA_DB::get_items( $moit_id ),
        ) );
    }

    public function ita_upload_file() {
        // handled by WP Media Uploader in JS — not needed as server-side
        wp_send_json_success();
    }

    /**
     * AJAX: Save allowed user list (admin only)
     */
    public function ita_save_allowed_users() {
        if ( ! check_ajax_referer( 'ita_users_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        $raw_ids = isset( $_POST['user_ids'] ) ? (array) $_POST['user_ids'] : array();
        $ids     = array_values( array_filter( array_map( 'intval', $raw_ids ) ) );
        update_option( 'ita_allowed_users', $ids );
        wp_send_json_success( array( 'count' => count( $ids ) ) );
    }

    // ─── USERS MANAGEMENT PAGE ───────────────────────────────────────────────

    public function enqueue_users_assets( $hook ) {
        if ( $hook !== 'ita-manager_page_ita-manager-users' ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', '
            .ita-users-wrap { max-width: 860px; }
            .ita-user-list { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin-top: 20px; overflow: hidden; }
            .ita-user-list table { width: 100%; border-collapse: collapse; }
            .ita-user-list thead th { background: #2271b1; color: #fff; padding: 10px 14px; font-size: 13px; font-weight: 600; text-align: left; }
            .ita-user-list tbody tr { border-bottom: 1px solid #f0f0f0; transition: background .15s; }
            .ita-user-list tbody tr:hover { background: #f0f6fc; }
            .ita-user-list tbody tr.is-admin { background: #f9fafb; color: #8c8f94; }
            .ita-user-list td { padding: 10px 14px; vertical-align: middle; font-size: 13px; }
            .ita-user-list td.col-cb { width: 40px; text-align: center; }
            .ita-user-list .ita-avatar { border-radius: 50%; vertical-align: middle; margin-right: 8px; }
            .ita-user-list .role-badge { display:inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #e5e7eb; color: #374151; }
            .ita-user-list .role-badge.administrator { background: #fef3c7; color: #92400e; }
            .ita-save-bar { margin-top: 16px; display: flex; align-items: center; gap: 12px; }
            .ita-save-notice { font-size: 13px; color: #2e7d32; display: none; }
        ' );
        wp_add_inline_script( 'jquery', '
        jQuery(function($){
            $("#ita-save-users").on("click", function(){
                var ids = [];
                $(".ita-user-cb:checked").each(function(){ ids.push($(this).val()); });
                var btn = $(this).prop("disabled", true).text("กำลังบันทึก...");
                $.post(ajaxurl, {
                    action: "ita_save_allowed_users",
                    nonce: $("#ita-users-nonce").val(),
                    user_ids: ids
                }, function(res){
                    btn.prop("disabled", false).text("บันทึก");
                    if(res.success){
                        $("#ita-save-notice").text("บันทึกแล้ว — ผู้ใช้งาน " + res.data.count + " คน (ไม่นับ Admin)").fadeIn().delay(3000).fadeOut();
                    }
                });
            });
        });
        ' );
    }

    public function render_users_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }
        $allowed  = self::get_allowed_users();
        $all_users = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500 ) );
        ?>
        <div class="wrap ita-users-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-groups" style="font-size:28px;vertical-align:middle;margin-right:8px;"></span>
                จัดการผู้ใช้งาน ITA Manager
            </h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ita-manager' ) ); ?>" class="page-title-action">&larr; กลับหน้าหลัก</a>
            <hr class="wp-header-end">
            <p class="description" style="margin-top:12px;">
                เลือก User ที่ต้องการให้เข้าถึงและแก้ไขข้อมูล ITA Manager ได้ (Admin ได้รับสิทธิ์โดยอัตโนมัติ)
            </p>
            <input type="hidden" id="ita-users-nonce" value="<?php echo esc_attr( wp_create_nonce( 'ita_users_nonce' ) ); ?>">
            <div class="ita-user-list">
                <table>
                    <thead>
                        <tr>
                            <th class="col-cb">อนุญาต</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>ชื่อผู้ใช้ (Username)</th>
                            <th>อีเมล</th>
                            <th>บทบาท</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $all_users as $user ) :
                        $is_admin  = in_array( 'administrator', (array) $user->roles, true );
                        $is_allowed = $is_admin || in_array( $user->ID, $allowed, true );
                        $roles_th  = array(
                            'administrator' => 'ผู้ดูแลระบบ',
                            'editor'        => 'บรรณาธิการ',
                            'author'        => 'ผู้เขียน',
                            'contributor'   => 'ผู้มีส่วนร่วม',
                            'subscriber'    => 'สมาชิก',
                        );
                        $role_key  = ! empty( $user->roles[0] ) ? $user->roles[0] : '';
                        $role_name = $roles_th[ $role_key ] ?? esc_html( ucfirst( $role_key ) );
                    ?>
                        <tr class="<?php echo $is_admin ? 'is-admin' : ''; ?>">
                            <td class="col-cb">
                                <?php if ( $is_admin ) : ?>
                                    <input type="checkbox" checked disabled title="Admin ได้รับสิทธิ์โดยอัตโนมัติ">
                                <?php else : ?>
                                    <input type="checkbox"
                                           class="ita-user-cb"
                                           value="<?php echo esc_attr( $user->ID ); ?>"
                                           <?php checked( $is_allowed ); ?>>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo get_avatar( $user->ID, 28, '', '', array( 'class' => 'ita-avatar' ) ); ?>
                                <?php echo esc_html( $user->display_name ); ?>
                            </td>
                            <td><?php echo esc_html( $user->user_login ); ?></td>
                            <td><?php echo esc_html( $user->user_email ); ?></td>
                            <td><span class="role-badge <?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $role_name ); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="ita-save-bar">
                <button id="ita-save-users" class="button button-primary">บันทึก</button>
                <span id="ita-save-notice" class="ita-save-notice"></span>
            </div>
        </div>
        <?php
    }

    // ─── DELETE LOG PAGE ───────────────────────────────────────────────────────

    public function enqueue_log_assets( $hook ) {
        if ( $hook !== 'ita-manager_page_ita-manager-log' ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', '
            .ita-log-wrap { max-width: 1100px; }
            .ita-log-filter { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin:16px 0 12px; }
            .ita-log-total { color:#646970; font-size:13px; margin-left:6px; }
            .ita-log-table td { vertical-align:middle; font-size:13px; }
            .ita-type-badge { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:600; white-space:nowrap; }
            .ita-type-year      { background:#dbeafe; color:#1e40af; }
            .ita-type-indicator { background:#dcfce7; color:#166534; }
            .ita-type-moit      { background:#fef3c7; color:#92400e; }
            .ita-type-item      { background:#f3e8ff; color:#6b21a8; }
            .ita-log-json { background:#1e293b; color:#e2e8f0; padding:12px 16px; border-radius:6px;
                            font-size:12px; line-height:1.6; overflow-x:auto; white-space:pre;
                            margin-top:6px; max-height:280px; overflow-y:auto; }
            .ita-clear-log-bar { margin:12px 0 4px; }
        ' );
        wp_add_inline_script( 'jquery', '
        jQuery(function($){
            $(document).on("click", ".ita-log-json-btn", function(){
                var id  = $(this).data("id");
                var pre = $("#ita-log-json-" + id);
                pre.slideToggle(150);
                $(this).text(pre.is(":hidden") ? "ดู JSON" : "ซ่อน");
            });
            $("#btn-clear-log").on("click", function(){
                if (!confirm("ยืนยันล้างประวัติการลบทั้งหมด?\nข้อมูลประวัติจะถูกลบถาวร ไม่สามารถกู้คืนได้")) return;
                $(this).prop("disabled", true).text("กำลังล้าง...");
                $.post(ajaxurl, { action: "ita_clear_delete_log", nonce: $("#ita-log-nonce").val() },
                    function(res){ if (res.success) location.reload(); });
            });
        });
        ' );
    }

    public function ita_clear_delete_log() {
        if ( ! check_ajax_referer( 'ita_log_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
        ITA_DB::clear_delete_logs();
        wp_send_json_success();
    }

    public function render_log_page() {
        if ( ! current_user_can( 'ita_manage' ) ) {
            wp_die( 'Permission denied.' );
        }
        $type     = sanitize_key( $_GET['log_type']    ?? '' );
        $search   = sanitize_text_field( $_GET['log_search'] ?? '' );
        $paged    = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page = 25;
        $filter   = array( 'type' => $type, 'search' => $search );
        $total    = ITA_DB::count_delete_logs( $filter );
        $logs     = ITA_DB::get_delete_logs( array_merge( $filter, array( 'page' => $paged, 'per_page' => $per_page ) ) );
        $pages    = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        $type_labels = array(
            'year'      => 'ปีงบประมาณ',
            'indicator' => 'ตัวชี้วัด',
            'moit'      => 'MOIT',
            'item'      => 'รายการย่อย',
        );
        $base_url = admin_url( 'admin.php?page=ita-manager-log' );
        ?>
        <div class="wrap ita-log-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-backup" style="font-size:28px;vertical-align:middle;margin-right:8px;"></span>
                ประวัติการลบข้อมูล ITA
            </h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ita-manager' ) ); ?>" class="page-title-action">&larr; กลับหน้าหลัก</a>
            <hr class="wp-header-end">

            <?php if ( current_user_can( 'manage_options' ) && $total > 0 ) : ?>
            <div class="ita-clear-log-bar">
                <button id="btn-clear-log" class="button" style="color:#c0392b;border-color:#c0392b;">
                    <span class="dashicons dashicons-trash" style="font-size:14px;vertical-align:middle;"></span>
                    ล้างประวัติทั้งหมด
                </button>
                <input type="hidden" id="ita-log-nonce" value="<?php echo esc_attr( wp_create_nonce( 'ita_log_nonce' ) ); ?>">
            </div>
            <?php endif; ?>

            <form method="get" class="ita-log-filter">
                <input type="hidden" name="page" value="ita-manager-log">
                <select name="log_type">
                    <option value="">ทุกประเภท</option>
                    <?php foreach ( $type_labels as $k => $v ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $type, $k ); ?>><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="log_search"
                       value="<?php echo esc_attr( $search ); ?>"
                       placeholder="ค้นหาชื่อ / บริบท..."
                       class="regular-text">
                <button type="submit" class="button">ค้นหา</button>
                <?php if ( $type || $search ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">ล้างตัวกรอง</a>
                <?php endif; ?>
                <span class="ita-log-total">ทั้งหมด <?php echo number_format( $total ); ?> รายการ</span>
            </form>

            <?php if ( empty( $logs ) ) : ?>
                <div style="text-align:center;padding:48px 20px;">
                    <span class="dashicons dashicons-backup" style="font-size:48px;color:#ccc;display:block;margin-bottom:12px;"></span>
                    <p style="color:#646970;">ยังไม่มีประวัติการลบ</p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped ita-log-table" style="margin-top:0;">
                    <thead>
                        <tr>
                            <th style="width:130px;">วันที่ / เวลา</th>
                            <th style="width:110px;">ผู้ดำเนินการ</th>
                            <th style="width:85px;">ประเภท</th>
                            <th>ชื่อ / หัวข้อ</th>
                            <th>บริบท</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $logs as $log ) :
                        $user      = get_userdata( intval( $log->deleted_by ) );
                        $user_name = $user ? esc_html( $user->display_name ) : '&mdash;';
                        $tl        = $type_labels[ $log->record_type ] ?? esc_html( $log->record_type );
                        $at        = wp_date( 'd/m/Y H:i', strtotime( $log->deleted_at ) );
                        $pretty    = wp_json_encode(
                            json_decode( $log->record_data ),
                            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                        );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $at ); ?></td>
                            <td><?php echo $user_name; ?></td>
                            <td><span class="ita-type-badge ita-type-<?php echo esc_attr( $log->record_type ); ?>"><?php echo esc_html( $tl ); ?></span></td>
                            <td><?php echo esc_html( $log->record_label ?: '—' ); ?></td>
                            <td><?php echo esc_html( $log->context ?: '—' ); ?></td>
                            <td>
                                <button type="button"
                                        class="button button-small ita-log-json-btn"
                                        data-id="<?php echo esc_attr( $log->id ); ?>">ดู JSON</button>
                                <pre class="ita-log-json"
                                     id="ita-log-json-<?php echo esc_attr( $log->id ); ?>"
                                     style="display:none;"><?php echo esc_html( $pretty ); ?></pre>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                <div class="tablenav bottom" style="margin-top:12px;">
                    <div class="tablenav-pages">
                        <?php
                        $paged_base = add_query_arg(
                            array_filter( array( 'log_type' => $type, 'log_search' => $search ) ),
                            $base_url
                        );
                        echo paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%', $paged_base ),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ) );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─── HELP PAGE ────────────────────────────────────────────────────────────

    public function enqueue_help_assets( $hook ) {
        if ( $hook !== 'ita-manager_page_ita-manager-help' ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', $this->help_page_css() );
    }

    public function render_help_page() {
        if ( ! current_user_can( 'ita_manage' ) ) {
            wp_die( 'Permission denied.' );
        }
        $readme_path = ITA_MANAGER_PATH . 'README.md';
        $content     = file_exists( $readme_path )
            ? file_get_contents( $readme_path )
            : '';
        ?>
        <div class="wrap ita-help-wrap">
            <h1>
                <span class="dashicons dashicons-book-alt" style="font-size:28px;vertical-align:middle;margin-right:8px;"></span>
                คู่มือการใช้งาน ITA Manager
            </h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ita-manager' ) ); ?>" class="page-title-action">
                &larr; กลับหน้าหลัก
            </a>
            <hr class="wp-header-end">
            <div class="ita-help-body">
                <?php echo $this->parse_markdown( $content ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </div>
        <?php
    }

    private function parse_markdown( $md ) {
        if ( empty( $md ) ) {
            return '<p>ไม่พบไฟล์ README.md</p>';
        }

        $html  = '';
        $lines = explode( "\n", $md );
        $i     = 0;
        $total = count( $lines );

        // state flags
        $in_code  = false;
        $in_table = false;
        $in_ul    = false;
        $in_ol    = false;
        $code_buf = array();

        while ( $i < $total ) {
            $line = rtrim( $lines[ $i ] );

            // ── fenced code block ────────────────────────────────────────
            if ( preg_match( '/^```(.*)$/', $line, $m ) ) {
                if ( ! $in_code ) {
                    $in_code  = true;
                    $code_buf = array();
                    $lang     = trim( $m[1] );
                } else {
                    $in_code = false;
                    $code    = implode( "\n", $code_buf );
                    $html   .= '<pre class="ita-code-block"><code>'
                             . esc_html( $code )
                             . "</code></pre>\n";
                }
                $i++;
                continue;
            }
            if ( $in_code ) {
                $code_buf[] = $line;
                $i++;
                continue;
            }

            // ── heading ─────────────────────────────────────────────────
            if ( preg_match( '/^(#{1,4})\s+(.+)$/', $line, $m ) ) {
                $html = $this->close_list( $html, $in_ul, $in_table, $in_ol );
                $in_ul = $in_ol = $in_table = false;
                $lvl   = strlen( $m[1] );
                $lvl   = min( $lvl + 1, 5 ); // h1→h2, h2→h3 etc.
                $text  = $this->inline_md( $m[2] );
                $html .= "<h{$lvl} class='ita-h{$lvl}'>{$text}</h{$lvl}>\n";
                $i++;
                continue;
            }

            // ── horizontal rule ─────────────────────────────────────────
            if ( preg_match( '/^[-*_]{3,}$/', $line ) ) {
                $html = $this->close_list( $html, $in_ul, $in_table, $in_ol );
                $in_ul = $in_ol = $in_table = false;
                $html .= "<hr class='ita-hr'>\n";
                $i++;
                continue;
            }

            // ── table ────────────────────────────────────────────────────
            if ( str_contains( $line, '|' ) && preg_match( '/^\|/', $line ) ) {
                // separator row
                if ( preg_match( '/^[|:\s-]+$/', $line ) ) {
                    $i++;
                    continue;
                }
                if ( ! $in_table ) {
                    $html    .= "<table class='ita-table'>\n";
                    $in_table = true;
                    $is_head  = true; // first row = thead
                } else {
                    $is_head = false;
                }
                $cells = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
                $tag   = $is_head ? 'th' : 'td';
                $row   = '';
                foreach ( $cells as $cell ) {
                    $row .= "<{$tag}>" . $this->inline_md( $cell ) . "</{$tag}>";
                }
                $html .= "<tr>{$row}</tr>\n";
                $i++;
                continue;
            } elseif ( $in_table ) {
                $html    .= "</table>\n";
                $in_table = false;
            }

            // ── unordered list ───────────────────────────────────────────
            if ( preg_match( '/^(\s*)[-*+]\s+(.+)$/', $line, $m ) ) {
                if ( ! $in_ul ) {
                    $html .= "<ul class='ita-ul'>\n";
                    $in_ul = true;
                    if ( $in_ol ) { $html .= "</ol>\n"; $in_ol = false; }
                }
                $html .= '<li>' . $this->inline_md( $m[2] ) . "</li>\n";
                $i++;
                continue;
            } elseif ( $in_ul && ! preg_match( '/^\s+/', $line ) ) {
                $html .= "</ul>\n";
                $in_ul = false;
            }

            // ── ordered list ─────────────────────────────────────────────
            if ( preg_match( '/^\d+\.\s+(.+)$/', $line, $m ) ) {
                if ( ! $in_ol ) {
                    $html .= "<ol class='ita-ol'>\n";
                    $in_ol = true;
                    if ( $in_ul ) { $html .= "</ul>\n"; $in_ul = false; }
                }
                $html .= '<li>' . $this->inline_md( $m[1] ) . "</li>\n";
                $i++;
                continue;
            } elseif ( $in_ol && ! preg_match( '/^\s+/', $line ) ) {
                $html .= "</ol>\n";
                $in_ol = false;
            }

            // ── empty line ───────────────────────────────────────────────
            if ( trim( $line ) === '' ) {
                $i++;
                continue;
            }

            // ── paragraph ────────────────────────────────────────────────
            $html .= '<p>' . $this->inline_md( $line ) . "</p>\n";
            $i++;
        }

        // close open blocks
        if ( $in_ul )    { $html .= "</ul>\n"; }
        if ( $in_ol )    { $html .= "</ol>\n"; }
        if ( $in_table ) { $html .= "</table>\n"; }
        if ( $in_code )  { $html .= '<pre class="ita-code-block"><code>' . esc_html( implode( "\n", $code_buf ) ) . "</code></pre>\n"; }

        return $html;
    }

    private function close_list( $html, $in_ul, $in_table, $in_ol ) {
        if ( $in_ul )    { $html .= "</ul>\n"; }
        if ( $in_ol )    { $html .= "</ol>\n"; }
        if ( $in_table ) { $html .= "</table>\n"; }
        return $html;
    }

    private function inline_md( $text ) {
        // inline code
        $text = preg_replace_callback( '/`([^`]+)`/', function( $m ) {
            return '<code class="ita-inline-code">' . esc_html( $m[1] ) . '</code>';
        }, $text );
        // bold
        $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
        // italic
        $text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
        // link
        $text = preg_replace_callback( '/\[([^\]]+)\]\(([^\)]+)\)/', function( $m ) {
            return '<a href="' . esc_url( $m[2] ) . '">' . esc_html( $m[1] ) . '</a>';
        }, $text );
        return $text;
    }

    private function help_page_css() {
        return '
        .ita-help-wrap { max-width: 960px; }
        .ita-help-body { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 32px 40px; margin-top: 20px; line-height: 1.75; color: #1d2327; }
        .ita-help-body .ita-h2 { font-size: 1.6em; border-bottom: 2px solid #2271b1; padding-bottom: 6px; margin: 32px 0 12px; color: #2271b1; }
        .ita-help-body .ita-h3 { font-size: 1.2em; margin: 24px 0 8px; color: #1d2327; border-left: 4px solid #2271b1; padding-left: 10px; }
        .ita-help-body .ita-h4 { font-size: 1.05em; margin: 18px 0 6px; color: #135e96; }
        .ita-help-body .ita-h5 { font-size: 1em; margin: 14px 0 4px; color: #135e96; }
        .ita-help-body .ita-hr { border: none; border-top: 1px solid #e5e7eb; margin: 28px 0; }
        .ita-help-body .ita-ul, .ita-help-body .ita-ol { margin: 8px 0 12px 24px; }
        .ita-help-body .ita-ul li, .ita-help-body .ita-ol li { margin-bottom: 4px; }
        .ita-help-body .ita-table { border-collapse: collapse; width: 100%; margin: 12px 0 20px; font-size: 0.92em; }
        .ita-help-body .ita-table th { background: #2271b1; color: #fff; padding: 8px 12px; text-align: left; font-weight: 600; }
        .ita-help-body .ita-table td { padding: 7px 12px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .ita-help-body .ita-table tr:nth-child(even) td { background: #f8f9fa; }
        .ita-help-body .ita-code-block { background: #1e293b; color: #e2e8f0; padding: 16px 20px; border-radius: 6px; overflow-x: auto; font-size: 0.88em; line-height: 1.6; margin: 12px 0 20px; }
        .ita-help-body .ita-inline-code { background: #f1f5f9; color: #c0392b; padding: 2px 5px; border-radius: 3px; font-size: 0.9em; }
        .ita-help-body p { margin: 0 0 12px; }
        ';
    }
}
