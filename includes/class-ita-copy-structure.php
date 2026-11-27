<?php
/**
 * ITA Manager - คัดลอกโครงสร้างจากปีงบประมาณอื่น
 * คัดลอก: ตัวชี้วัด > MOIT > รายการย่อย (ไม่รวมไฟล์/ลิงก์)
 * แสดงปุ่มเฉพาะปีงบประมาณที่ยังไม่มีข้อมูลตัวชี้วัดเท่านั้น
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITA_Copy_Structure {

    const NONCE = 'ita_copy_structure';

    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'wp_ajax_ita_copy_structure', array( __CLASS__, 'ajax_copy' ) );
        add_action( 'admin_footer', array( __CLASS__, 'print_modal' ) );
    }

    /** ตั้งค่าตารางและคอลัมน์ให้ตรงกับ ITA_DB */
    public static function config() {
        global $wpdb;
        $p = $wpdb->prefix;

        return apply_filters( 'ita_copy_structure_config', array(
            'capability'        => 'ita_manage',
            'years'             => array(
                'table' => $p . 'ita_years',
                'pk'    => 'id',
                'label' => 'year_label',
            ),
            // ลำดับสำคัญ: แม่ -> ลูก
            'levels'            => array(
                'indicators' => array(
                    'table' => $p . 'ita_indicators',
                    'pk'    => 'id',
                    'fk'    => 'year_id',
                    'order' => 'indicator_no ASC, sort_order ASC, id ASC',
                ),
                'moits'      => array(
                    'table' => $p . 'ita_moits',
                    'pk'    => 'id',
                    'fk'    => 'indicator_id',
                    'order' => 'moit_no ASC, sort_order ASC, id ASC',
                ),
                'items'      => array(
                    'table'       => $p . 'ita_items',
                    'pk'          => 'id',
                    'fk'          => 'moit_id',
                    'order'       => 'sort_order ASC, id ASC',
                    'self_parent' => 'parent_id',
                ),
            ),
            // คอลัมน์ไฟล์และลิงก์ที่จะล้างค่าเป็นค่าเริ่มต้น (ไม่คัดลอกมาด้วย)
            'file_columns'      => array(
                'file', 'file_url', 'file_path', 'file_name', 'file_id',
                'attachment_id', 'link', 'link_url', 'url', 'file_type',
            ),
            'skip_text_columns' => array( 'item_type' ),
            'timestamp_columns' => array( 'created_at', 'updated_at' ),
            'user_columns'      => array( 'created_by', 'updated_by' ),
            'page_slug'         => 'ita-manager',
        ) );
    }

    private static function can_access() {
        $cfg = self::config();
        return current_user_can( $cfg['capability'] ) || current_user_can( 'manage_options' );
    }

    /* ---------------- Core ---------------- */

    public static function copy_structure( $source_year_id, $target_year_id, $replace_year_text = true ) {
        global $wpdb;

        $cfg      = self::config();
        $map      = array( (int) $source_year_id => (int) $target_year_id );
        $counts   = array();
        $text_map = $replace_year_text ? self::build_year_text_map( $source_year_id, $target_year_id ) : array();

        $wpdb->query( 'START TRANSACTION' );
        try {
            foreach ( $cfg['levels'] as $key => $lv ) {
                $counts[ $key ] = 0;
                if ( empty( $map ) ) {
                    continue;
                }

                $cols     = self::columns( $lv['table'] );
                $ids      = implode( ',', array_map( 'intval', array_keys( $map ) ) );
                $order_by = ! empty( $lv['order'] ) ? $lv['order'] : "`{$lv['pk']}` ASC";

                $rows = $wpdb->get_results(
                    "SELECT * FROM `{$lv['table']}` WHERE `{$lv['fk']}` IN ({$ids}) ORDER BY {$order_by}",
                    ARRAY_A
                );

                $new_map    = array();
                $self_pairs = array();
                $has_self   = ! empty( $lv['self_parent'] ) && isset( $cols[ $lv['self_parent'] ] );

                foreach ( (array) $rows as $row ) {
                    $old_id            = (int) $row[ $lv['pk'] ];
                    $data              = self::prepare_row( $row, $lv, $cols, $cfg, $text_map );
                    $data[ $lv['fk'] ] = $map[ (int) $row[ $lv['fk'] ] ];

                    if ( $wpdb->insert( $lv['table'], $data ) === false ) {
                        throw new Exception( "[{$key}] " . ( $wpdb->last_error ?: 'insert failed' ) );
                    }
                    $new_map[ $old_id ] = (int) $wpdb->insert_id;

                    if ( $has_self && ! empty( $row[ $lv['self_parent'] ] ) ) {
                        $self_pairs[ $new_map[ $old_id ] ] = (int) $row[ $lv['self_parent'] ];
                    }
                }

                foreach ( $self_pairs as $new_id => $old_parent ) {
                    if ( isset( $new_map[ $old_parent ] ) ) {
                        $wpdb->update(
                            $lv['table'],
                            array( $lv['self_parent'] => $new_map[ $old_parent ] ),
                            array( $lv['pk'] => $new_id )
                        );
                    }
                }

                $counts[ $key ] = count( $new_map );
                $map            = $new_map;
            }

            $wpdb->query( 'COMMIT' );
        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'ita_copy_failed', $e->getMessage() );
        }

        return $counts;
    }

    private static function prepare_row( $row, $lv, $cols, $cfg, $text_map ) {
        $data = array();
        foreach ( $row as $col => $val ) {
            if ( $col === $lv['pk'] ) {
                continue;
            }
            $info = $cols[ $col ] ?? null;

            $is_file   = in_array( strtolower( $col ), $cfg['file_columns'], true );
            $is_parent = ! empty( $lv['self_parent'] ) && $col === $lv['self_parent'];
            if ( $is_file || $is_parent ) {
                $data[ $col ] = self::empty_value( $info );
                continue;
            }

            if ( in_array( $col, $cfg['timestamp_columns'], true ) ) {
                $data[ $col ] = current_time( 'mysql' );
                continue;
            }
            if ( in_array( $col, $cfg['user_columns'], true ) ) {
                $data[ $col ] = get_current_user_id();
                continue;
            }

            $skip_text = in_array( $col, $cfg['skip_text_columns'], true );
            if ( ! $skip_text && $text_map && is_string( $val ) && $info && preg_match( '/char|text/i', $info->Type ) ) {
                $val = strtr( $val, $text_map );
            }
            $data[ $col ] = $val;
        }
        return $data;
    }

    /** แทนที่เลขปี พ.ศ. เช่น 2568->2569, 2567->2568 พร้อมกันโดยไม่แทนซ้อน */
    private static function build_year_text_map( $src_id, $tgt_id ) {
        $src_label = (string) self::year_label( $src_id );
        $tgt_label = (string) self::year_label( $tgt_id );

        $sy = preg_match( '/(\d{4})/', $src_label, $ms ) ? (int) $ms[1] : 0;
        $ty = preg_match( '/(\d{4})/', $tgt_label, $mt ) ? (int) $mt[1] : 0;

        if ( ! $sy || ! $ty || $sy === $ty ) {
            return array();
        }

        $map = array();
        for ( $i = 0; $i <= 3; $i++ ) {
            $map[ (string) ( $sy - $i ) ] = (string) ( $ty - $i );
        }
        return $map;
    }

    private static function empty_value( $info ) {
        if ( ! $info || $info->Null === 'YES' ) {
            return null;
        }
        if ( $info->Default !== null ) {
            return $info->Default;
        }
        return preg_match( '/int|decimal|float|double/i', $info->Type ) ? 0 : '';
    }

    private static function columns( $table ) {
        global $wpdb;
        $out = array();
        foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ) as $c ) {
            $out[ $c->Field ] = $c;
        }
        if ( ! $out ) {
            throw new Exception( "ไม่พบตาราง {$table}" );
        }
        return $out;
    }

    private static function year_label( $year_id ) {
        global $wpdb;
        $y = self::config()['years'];
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT `{$y['label']}` FROM `{$y['table']}` WHERE `{$y['pk']}` = %d",
            $year_id
        ) );
    }

    /** ดึงรายการปีพร้อมนับจำนวนตัวชี้วัดของแต่ละปี */
    private static function get_years() {
        global $wpdb;
        $cfg       = self::config();
        $y         = $cfg['years'];
        $levels    = $cfg['levels'];
        $root_lv   = reset( $levels );
        $ind_table = $root_lv['table'];
        $ind_fk    = $root_lv['fk'];

        return (array) $wpdb->get_results(
            "SELECT y.`{$y['pk']}` AS id,
                    y.`{$y['label']}` AS label,
                    (SELECT COUNT(*) FROM `{$ind_table}` i WHERE i.`{$ind_fk}` = y.`{$y['pk']}`) AS root_count
             FROM `{$y['table']}` y
             ORDER BY y.`{$y['label']}` DESC, y.`{$y['pk']}` DESC"
        );
    }

    private static function count_root( $year_id ) {
        global $wpdb;
        $levels = self::config()['levels'];
        $lv     = reset( $levels );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$lv['table']}` WHERE `{$lv['fk']}` = %d",
            $year_id
        ) );
    }

    /* ---------------- AJAX ---------------- */

    public static function ajax_copy() {
        if ( ! self::can_access() ) {
            wp_send_json_error( array( 'message' => 'ไม่มีสิทธิ์ดำเนินการ' ), 403 );
        }
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $src     = absint( $_POST['source_year_id'] ?? 0 );
        $tgt     = absint( $_POST['target_year_id'] ?? 0 );
        $replace = ! empty( $_POST['replace_year_text'] );

        if ( ! $src || ! $tgt || $src === $tgt ) {
            wp_send_json_error( array( 'message' => 'กรุณาเลือกปีต้นทางและปีปลายทางให้ถูกต้อง' ) );
        }
        if ( ! self::count_root( $src ) ) {
            wp_send_json_error( array( 'message' => 'ปีต้นทางที่เลือกยังไม่มีข้อมูลตัวชี้วัดให้คัดลอก' ) );
        }
        if ( self::count_root( $tgt ) > 0 ) {
            wp_send_json_error( array( 'message' => 'ปีปลายทางมีข้อมูลอยู่แล้ว ไม่สามารถคัดลอกโครงสร้างซ้ำได้' ) );
        }

        $res = self::copy_structure( $src, $tgt, $replace );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => 'คัดลอกไม่สำเร็จ: ' . $res->get_error_message() ) );
        }

        wp_send_json_success( array(
            'counts'  => $res,
            'message' => sprintf(
                'คัดลอกสำเร็จ: ตัวชี้วัด %d ข้อ, MOIT %d ข้อ, รายการย่อย %d รายการ (ไม่รวมไฟล์และลิงก์)',
                $res['indicators'] ?? 0,
                $res['moits'] ?? 0,
                $res['items'] ?? 0
            ),
        ) );
    }

    /* ---------------- UI ---------------- */

    /** ซ่อนปุ่มไว้เป็นค่าเริ่มต้น และให้ JS แสดงเฉพาะปีที่ยังไม่มีข้อมูล */
    public static function render_button( $year_id = 0, $year_label = '' ) {
        printf(
            '<button type="button" class="button ita-copy-structure-btn" style="display:none;" data-target-year="%d" data-target-label="%s">'
            . '<span class="dashicons dashicons-admin-page" style="vertical-align:middle;margin-right:3px;"></span> คัดลอกโครงสร้างจากปีอื่น</button>',
            (int) $year_id,
            esc_attr( $year_label )
        );
    }

    public static function print_modal() {
        $cfg  = self::config();
        $page = sanitize_key( $_GET['page'] ?? '' );
        if ( $page !== $cfg['page_slug'] || ! self::can_access() ) {
            return;
        }

        $years       = self::get_years();
        $year_counts = array();
        foreach ( $years as $y ) {
            $year_counts[ (string) $y->id ] = (int) $y->root_count;
        }
        ?>
        <div id="ita-copy-modal" style="display:none;position:fixed;inset:0;z-index:100000;">
            <div class="ita-copy-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.45);"></div>
            <div style="position:relative;max-width:480px;margin:10vh auto;background:#fff;padding:24px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,.2);">
                <h2 style="margin-top:0;">คัดลอกโครงสร้างไปยังปี ITA <span class="ita-copy-target"></span></h2>
                <p>
                    <label for="ita-copy-source"><strong>เลือกปีต้นทาง</strong></label><br>
                    <select id="ita-copy-source" style="width:100%;max-width:100%;margin-top:6px;">
                        <?php foreach ( $years as $y ) :
                            $cnt = (int) $y->root_count;
                        ?>
                            <option value="<?php echo (int) $y->id; ?>"
                                    data-label="<?php echo esc_attr( $y->label ); ?>"
                                    data-count="<?php echo $cnt; ?>">
                                ITA <?php echo esc_html( $y->label ); ?><?php echo $cnt === 0 ? ' (ไม่มีข้อมูล)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label>
                        <input type="checkbox" id="ita-copy-replace" checked>
                        เปลี่ยนเลขปี พ.ศ. ในชื่อตัวชี้วัด/MOIT/รายการย่อยอัตโนมัติ (เช่น 2568 &rarr; 2569)
                    </label>
                </p>
                <div class="notice notice-info inline" style="margin:12px 0;">
                    <p>ระบบจะคัดลอก <strong>ตัวชี้วัด &gt; MOIT &gt; รายละเอียดย่อย/หัวข้อไตรมาส</strong> ทั้งหมด <strong>โดยไม่คัดลอกไฟล์แนบและลิงก์</strong></p>
                </div>
                <p style="text-align:right;margin-bottom:0;margin-top:16px;">
                    <button type="button" class="button ita-copy-cancel">ยกเลิก</button>
                    <button type="button" class="button button-primary ita-copy-submit">เริ่มคัดลอก</button>
                </p>
            </div>
        </div>
        <script>
        jQuery(function ($) {
            var $m         = $('#ita-copy-modal');
            var nonce      = '<?php echo esc_js( wp_create_nonce( self::NONCE ) ); ?>';
            var yearCounts = <?php echo wp_json_encode( $year_counts ); ?> || {};
            var activeYear = { id: '', label: '' };

            function getParam(data, key) {
                if (!data) return '';
                if (typeof data === 'string') {
                    var match = data.match(new RegExp('(?:^|&)' + key + '=([^&]*)'));
                    return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
                }
                if (window.FormData && data instanceof FormData) {
                    return String(data.get(key) || '');
                }
                if (typeof data === 'object' && data[key] !== undefined) {
                    return String(data[key]);
                }
                return '';
            }

            // แสดงปุ่มเฉพาะเมื่อปีที่เลือกมีจำนวนตัวชี้วัด = 0 เท่านั้น
            function updateCopyButtonVisibility(yearId) {
                var $btn = $('.ita-copy-structure-btn');
                var id   = String(yearId || '');
                if (!id || id === '0') {
                    $btn.hide();
                    return;
                }
                if (yearCounts[id] === 0) {
                    $btn.css('display', '');
                } else {
                    $btn.hide();
                }
            }

            // เปิดแท็บปีปลายทางเดิมอัตโนมัติหลังรีโหลดหน้าเมื่อคัดลอกเสร็จ
            var reopenYearId = sessionStorage.getItem('ita_copy_reopen_year');
            if (reopenYearId) {
                sessionStorage.removeItem('ita_copy_reopen_year');
                setTimeout(function () {
                    $('.ita-year-tab[data-year-id="' + reopenYearId + '"]').trigger('click');
                }, 150);
            }

            // จดจำแท็บปีที่คลิก และซ่อน/แสดงปุ่มทันทีตามข้อมูลที่โหลดไว้
            $(document).on('click', '.ita-year-tab', function () {
                activeYear.id    = String($(this).attr('data-year-id') || '');
                activeYear.label = String($(this).attr('data-year-label') || '');
                updateCopyButtonVisibility(activeYear.id);
            });

            // อัปเดตสถานะปุ่มแบบ Real-time เมื่อมีการโหลดข้อมูลปี เพิ่ม/ลบตัวชี้วัด หรือสร้างปีใหม่
            $(document).ajaxSuccess(function (_event, xhr, settings) {
                var action = getParam(settings && settings.data, 'action');
                var res    = xhr && xhr.responseJSON;
                if (!action || !res || !res.success) {
                    return;
                }

                if (action === 'ita_get_year_data') {
                    var yId = getParam(settings.data, 'year_id');
                    if (yId) {
                        activeYear.id = String(yId);
                        var $tab = $('.ita-year-tab[data-year-id="' + yId + '"]');
                        if ($tab.length) {
                            activeYear.label = String($tab.attr('data-year-label') || '');
                        }
                        var list = (res.data && $.isArray(res.data.indicators)) ? res.data.indicators : [];
                        yearCounts[String(yId)] = list.length;
                        updateCopyButtonVisibility(yId);
                    }
                } else if (action === 'ita_save_indicator') {
                    var saveYId = getParam(settings.data, 'year_id') || activeYear.id;
                    if (saveYId && res.data && res.data.is_new) {
                        yearCounts[String(saveYId)] = (yearCounts[String(saveYId)] || 0) + 1;
                        updateCopyButtonVisibility(saveYId);
                    }
                } else if (action === 'ita_delete_indicator') {
                    if (activeYear.id) {
                        yearCounts[activeYear.id] = Math.max(0, (yearCounts[activeYear.id] || 1) - 1);
                        updateCopyButtonVisibility(activeYear.id);
                    }
                } else if (action === 'ita_save_year') {
                    var isEdit = getParam(settings.data, 'id');
                    if (!isEdit && res.data && res.data.id) {
                        yearCounts[String(res.data.id)] = 0;
                    }
                }
            });

            function resolveTargetYear($btn) {
                if (activeYear.id && activeYear.label) {
                    return { id: activeYear.id, label: activeYear.label };
                }
                var $activeTab = $('.ita-year-tab.active, .ita-year-tab.is-active, .ita-year-tab.current').first();
                if ($activeTab.length) {
                    return {
                        id: String($activeTab.attr('data-year-id') || ''),
                        label: String($activeTab.attr('data-year-label') || '')
                    };
                }
                return {
                    id: String($btn.attr('data-target-year') || ''),
                    label: String($btn.attr('data-target-label') || '')
                };
            }

            // ซิงก์รายการปีและอัปเดตสถานะว่าปีไหนมีข้อมูลให้คัดลอกได้บ้าง
            function syncSourceOptions() {
                var $select = $('#ita-copy-source');
                $('.ita-year-tab').each(function () {
                    var id  = String($(this).attr('data-year-id') || '');
                    var lbl = String($(this).attr('data-year-label') || '');
                    var cnt = typeof yearCounts[id] === 'number' ? yearCounts[id] : 0;
                    var $opt = $select.find('option[value="' + id + '"]');
                    if (id && !$opt.length) {
                        $opt = $('<option/>', { value: id }).attr('data-label', lbl).prependTo($select);
                    }
                    if ($opt.length) {
                        $opt.attr('data-count', cnt)
                            .text('ITA ' + lbl + (cnt === 0 ? ' (ไม่มีข้อมูล)' : ''));
                    }
                });
            }

            // กรณีมีแท็บถูกเลือกอยู่แล้วตั้งแต่ตอนโหลดหน้า
            var initTarget = resolveTargetYear($('.ita-copy-structure-btn'));
            if (initTarget.id) {
                updateCopyButtonVisibility(initTarget.id);
            }

            $(document).on('click', '.ita-copy-structure-btn', function (e) {
                e.preventDefault();
                syncSourceOptions();

                var target = resolveTargetYear($(this));
                if (!target.id || target.id === '0') {
                    alert('กรุณาคลิกเลือกแท็บปีงบประมาณปลายทางก่อน');
                    return;
                }
                if ((yearCounts[target.id] || 0) > 0) {
                    $(this).hide();
                    return;
                }

                // ปิดการเลือกปีปลายทางตัวเอง และปีที่ไม่มีข้อมูล (count = 0)
                var $opts = $('#ita-copy-source option').each(function () {
                    var optId  = String($(this).val());
                    var optCnt = parseInt($(this).attr('data-count') || '0', 10);
                    $(this).prop('disabled', optId === target.id || optCnt <= 0);
                });

                var $available = $opts.not(':disabled');
                if (!$available.length) {
                    alert('ยังไม่มีปีงบประมาณที่มีข้อมูลสำหรับใช้เป็นปีต้นทาง');
                    return;
                }

                $m.data('target', target.id).find('.ita-copy-target').text(target.label);

                // เลือกปีก่อนหน้าที่มีข้อมูลให้อัตโนมัติ (เช่น 2570 -> 2569)
                var prevYear = String(parseInt(target.label, 10) - 1);
                var $prev = $available.filter(function () {
                    return String($(this).attr('data-label')) === prevYear;
                });
                ($prev.length ? $prev.first() : $available.first()).prop('selected', true);

                $m.show();
            });

            $m.on('click', '.ita-copy-cancel, .ita-copy-backdrop', function () {
                $m.hide();
            });

            $m.on('click', '.ita-copy-submit', function () {
                var $btn     = $(this);
                var srcId    = $('#ita-copy-source').val();
                var srcLabel = $('#ita-copy-source option:selected').attr('data-label') || '';
                var tgtId    = String($m.data('target') || '');
                var tgtLabel = $m.find('.ita-copy-target').text();

                if (!srcId || srcId === tgtId) {
                    alert('กรุณาเลือกปีต้นทางให้ถูกต้อง');
                    return;
                }
                if (!confirm('ยืนยันคัดลอกโครงสร้างจาก ITA ' + srcLabel + ' ไปยัง ITA ' + tgtLabel + ' ?')) {
                    return;
                }

                $btn.prop('disabled', true).text('กำลังคัดลอก...');
                $.post(ajaxurl, {
                    action: 'ita_copy_structure',
                    nonce: nonce,
                    source_year_id: srcId,
                    target_year_id: tgtId,
                    replace_year_text: $('#ita-copy-replace').is(':checked') ? 1 : 0
                }).done(function (r) {
                    alert(r && r.data && r.data.message ? r.data.message : 'เกิดข้อผิดพลาด');
                    if (r && r.success) {
                        sessionStorage.setItem('ita_copy_reopen_year', tgtId);
                        location.reload();
                    }
                }).fail(function () {
                    alert('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ');
                }).always(function () {
                    $btn.prop('disabled', false).text('เริ่มคัดลอก');
                });
            });
        });
        </script>
        <?php
    }
}
