<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITA_DB {

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = array();

        // ตาราง: ปีงบประมาณ
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ita_years (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            year_label  VARCHAR(20)  NOT NULL COMMENT 'เช่น 2569',
            sort_order  INT          NOT NULL DEFAULT 0
        ) $charset;";

        // ตาราง: ตัวชี้วัด
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ita_indicators (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            year_id         INT UNSIGNED NOT NULL,
            indicator_no    INT          NOT NULL,
            title           VARCHAR(500) NOT NULL,
            sort_order      INT          NOT NULL DEFAULT 0,
            INDEX idx_year (year_id)
        ) $charset;";

        // ตาราง: MOIT
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ita_moits (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            indicator_id INT UNSIGNED NOT NULL,
            moit_no      INT          NOT NULL,
            title        TEXT         NOT NULL,
            sort_order   INT          NOT NULL DEFAULT 0,
            INDEX idx_indicator (indicator_id)
        ) $charset;";

        // ตาราง: รายการย่อย (เอกสาร/ลิงค์)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ita_items (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            moit_id    INT UNSIGNED NOT NULL,
            item_type  VARCHAR(20)  NOT NULL DEFAULT 'item' COMMENT 'item | section',
            item_no    VARCHAR(200) NOT NULL DEFAULT '',
            title      TEXT         NOT NULL,
            file_id    INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'WP attachment ID',
            file_url   VARCHAR(700) NOT NULL DEFAULT '',
            link_url   VARCHAR(700) NOT NULL DEFAULT '',
            sort_order INT          NOT NULL DEFAULT 0,
            INDEX idx_moit (moit_id)
        ) $charset;";

        // ตาราง: ประวัติการลบ
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ita_delete_log (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            deleted_at   DATETIME        NOT NULL,
            deleted_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            record_type  VARCHAR(20)     NOT NULL,
            record_id    INT UNSIGNED    NOT NULL,
            record_label VARCHAR(500)    NOT NULL DEFAULT '',
            context      VARCHAR(700)    NOT NULL DEFAULT '',
            record_data  LONGTEXT        NOT NULL,
            INDEX idx_type_at (record_type, deleted_at),
            INDEX idx_deleted_at (deleted_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }

        self::maybe_upgrade();
        update_option( 'ita_manager_db_version', ITA_MANAGER_VERSION );
    }

    public static function maybe_upgrade() {
        global $wpdb;
        $cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}ita_items", 0 );
        // เพิ่ม column item_type ถ้ายังไม่มี (migration เก่า)
        if ( ! in_array( 'item_type', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}ita_items
                ADD COLUMN item_type VARCHAR(20) NOT NULL DEFAULT 'item'
                AFTER moit_id" );
        }
        // ขยาย item_no จาก VARCHAR(20) เป็น VARCHAR(200)
        $row = $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}ita_items LIKE 'item_no'" );
        if ( $row && stripos( $row->Type, 'varchar(20)' ) !== false ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}ita_items
                MODIFY COLUMN item_no VARCHAR(200) NOT NULL DEFAULT ''" );
        }
        // สร้างตาราง delete_log ถ้ายังไม่มี (upgrade จาก < 1.2.0)
        $log_table = $wpdb->prefix . 'ita_delete_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) !== $log_table ) {
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( "CREATE TABLE IF NOT EXISTS {$log_table} (
                id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                deleted_at   DATETIME        NOT NULL,
                deleted_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                record_type  VARCHAR(20)     NOT NULL,
                record_id    INT UNSIGNED    NOT NULL,
                record_label VARCHAR(500)    NOT NULL DEFAULT '',
                context      VARCHAR(700)    NOT NULL DEFAULT '',
                record_data  LONGTEXT        NOT NULL,
                INDEX idx_type_at (record_type, deleted_at),
                INDEX idx_deleted_at (deleted_at)
            ) $charset;" );
        }
    }

    // ─── YEAR ────────────────────────────────────────────────────────────────

    public static function get_years() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ita_years ORDER BY sort_order ASC, year_label DESC"
        );
    }

    public static function save_year( $data ) {
        global $wpdb;
        $sanitized = array(
            'year_label' => sanitize_text_field( $data['year_label'] ),
            'sort_order' => intval( $data['sort_order'] ?? 0 ),
        );
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( "{$wpdb->prefix}ita_years", $sanitized, array( 'id' => intval( $data['id'] ) ) );
            return intval( $data['id'] );
        }
        $wpdb->insert( "{$wpdb->prefix}ita_years", $sanitized );
        return $wpdb->insert_id;
    }

    public static function delete_year( $id ) {
        global $wpdb;
        $id   = intval( $id );
        $year = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_years WHERE id = %d", $id ) );
        if ( $year ) {
            $ind_ids    = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ita_indicators WHERE year_id = %d", $id
            ) );
            $moit_count = 0;
            $item_count = 0;
            foreach ( $ind_ids as $ind_id ) {
                $m_ids       = $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ita_moits WHERE indicator_id = %d", $ind_id
                ) );
                $moit_count += count( $m_ids );
                foreach ( $m_ids as $m_id ) {
                    $item_count += (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}ita_items WHERE moit_id = %d", $m_id
                    ) );
                }
            }
            self::log_delete( 'year', $id, array(
                'year_label'       => $year->year_label,
                'sort_order'       => $year->sort_order,
                'indicators_count' => count( $ind_ids ),
                'moits_count'      => $moit_count,
                'items_count'      => $item_count,
            ), "ปีงบ {$year->year_label}" );
        }
        // Cascade delete (inline, บันทึก log เฉพาะ year ไม่ log ลูกซ้ำ)
        $ind_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ita_indicators WHERE year_id = %d", $id
        ) );
        foreach ( $ind_ids as $ind_id ) {
            $moit_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ita_moits WHERE indicator_id = %d", $ind_id
            ) );
            foreach ( $moit_ids as $moit_id ) {
                $wpdb->delete( "{$wpdb->prefix}ita_items", array( 'moit_id' => intval( $moit_id ) ) );
            }
            $wpdb->delete( "{$wpdb->prefix}ita_moits", array( 'indicator_id' => intval( $ind_id ) ) );
        }
        $wpdb->delete( "{$wpdb->prefix}ita_indicators", array( 'year_id' => $id ) );
        $wpdb->delete( "{$wpdb->prefix}ita_years",      array( 'id'      => $id ) );
    }

    // ─── INDICATOR ───────────────────────────────────────────────────────────

    public static function get_indicators( $year_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ita_indicators WHERE year_id = %d ORDER BY indicator_no ASC",
            intval( $year_id )
        ) );
    }

    public static function save_indicator( $data ) {
        global $wpdb;
        $sanitized = array(
            'year_id'      => intval( $data['year_id'] ),
            'indicator_no' => intval( $data['indicator_no'] ),
            'title'        => sanitize_text_field( $data['title'] ),
            'sort_order'   => intval( $data['sort_order'] ?? 0 ),
        );
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( "{$wpdb->prefix}ita_indicators", $sanitized, array( 'id' => intval( $data['id'] ) ) );
            return intval( $data['id'] );
        }
        $wpdb->insert( "{$wpdb->prefix}ita_indicators", $sanitized );
        return $wpdb->insert_id;
    }

    public static function delete_indicator( $id ) {
        global $wpdb;
        $id  = intval( $id );
        $ind = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_indicators WHERE id = %d", $id ) );
        if ( $ind ) {
            $year       = $wpdb->get_row( $wpdb->prepare( "SELECT year_label FROM {$wpdb->prefix}ita_years WHERE id = %d", $ind->year_id ) );
            $year_label = $year ? $year->year_label : '';
            $moit_ids   = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ita_moits WHERE indicator_id = %d", $id
            ) );
            $item_count = 0;
            foreach ( $moit_ids as $m_id ) {
                $item_count += (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ita_items WHERE moit_id = %d", $m_id
                ) );
            }
            $context = $year_label ? "ปีงบ {$year_label}" : "year_id:{$ind->year_id}";
            self::log_delete( 'indicator', $id, array(
                'indicator_no' => $ind->indicator_no,
                'title'        => $ind->title,
                'year_id'      => $ind->year_id,
                'year_label'   => $year_label,
                'moits_count'  => count( $moit_ids ),
                'items_count'  => $item_count,
            ), $context );
        }
        // Cascade delete (inline)
        $moit_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ita_moits WHERE indicator_id = %d", $id
        ) );
        foreach ( $moit_ids as $moit_id ) {
            $wpdb->delete( "{$wpdb->prefix}ita_items", array( 'moit_id' => intval( $moit_id ) ) );
        }
        $wpdb->delete( "{$wpdb->prefix}ita_moits",      array( 'indicator_id' => $id ) );
        $wpdb->delete( "{$wpdb->prefix}ita_indicators", array( 'id'           => $id ) );
    }

    // ─── MOIT ────────────────────────────────────────────────────────────────

    public static function get_moits( $indicator_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ita_moits WHERE indicator_id = %d ORDER BY moit_no ASC",
            intval( $indicator_id )
        ) );
    }

    public static function save_moit( $data ) {
        global $wpdb;
        $sanitized = array(
            'indicator_id' => intval( $data['indicator_id'] ),
            'moit_no'      => intval( $data['moit_no'] ),
            'title'        => wp_kses_post( $data['title'] ),
            'sort_order'   => intval( $data['sort_order'] ?? 0 ),
        );
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( "{$wpdb->prefix}ita_moits", $sanitized, array( 'id' => intval( $data['id'] ) ) );
            return intval( $data['id'] );
        }
        $wpdb->insert( "{$wpdb->prefix}ita_moits", $sanitized );
        return $wpdb->insert_id;
    }

    public static function delete_moit( $id ) {
        global $wpdb;
        $id   = intval( $id );
        $moit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_moits WHERE id = %d", $id ) );
        if ( $moit ) {
            $ind        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_indicators WHERE id = %d", $moit->indicator_id ) );
            $year_label = '';
            $ind_no     = '';
            if ( $ind ) {
                $ind_no     = $ind->indicator_no;
                $year       = $wpdb->get_row( $wpdb->prepare( "SELECT year_label FROM {$wpdb->prefix}ita_years WHERE id = %d", $ind->year_id ) );
                $year_label = $year ? $year->year_label : '';
            }
            $item_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ita_items WHERE moit_id = %d", $id
            ) );
            $context = ( $year_label ? "ปีงบ {$year_label} > " : '' )
                     . ( $ind_no ? "ตัวชี้วัด {$ind_no}" : "indicator_id:{$moit->indicator_id}" );
            self::log_delete( 'moit', $id, array(
                'moit_no'      => $moit->moit_no,
                'title'        => wp_strip_all_tags( $moit->title ),
                'indicator_id' => $moit->indicator_id,
                'indicator_no' => $ind_no,
                'year_label'   => $year_label,
                'items_count'  => $item_count,
            ), $context );
        }
        $wpdb->delete( "{$wpdb->prefix}ita_items", array( 'moit_id' => $id ) );
        $wpdb->delete( "{$wpdb->prefix}ita_moits", array( 'id'      => $id ) );
    }

    // ─── ITEMS ───────────────────────────────────────────────────────────────

    public static function get_items( $moit_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ita_items WHERE moit_id = %d ORDER BY sort_order ASC, id ASC",
            intval( $moit_id )
        ) );
    }

    public static function save_item( $data ) {
        global $wpdb;
        $raw  = $data['item_type'] ?? 'item';
        $type = in_array( $raw, array( 'item', 'section' ), true ) ? $raw : 'item';
        $sanitized = array(
            'moit_id'    => intval( $data['moit_id'] ),
            'item_type'  => $type,
            'item_no'    => sanitize_text_field( $data['item_no'] ?? '' ),
            'title'      => wp_kses_post( $data['title'] ),
            'file_id'    => intval( $data['file_id'] ?? 0 ),
            'file_url'   => esc_url_raw( $data['file_url'] ?? '' ),
            'link_url'   => esc_url_raw( $data['link_url'] ?? '' ),
            'sort_order' => intval( $data['sort_order'] ?? 0 ),
        );
        // section ไม่มีไฟล์/ลิงค์
        if ( $type === 'section' ) {
            $sanitized['file_id']  = 0;
            $sanitized['file_url'] = '';
            $sanitized['link_url'] = '';
        }
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( "{$wpdb->prefix}ita_items", $sanitized, array( 'id' => intval( $data['id'] ) ) );
            return intval( $data['id'] );
        }
        $wpdb->insert( "{$wpdb->prefix}ita_items", $sanitized );
        return $wpdb->insert_id;
    }

    public static function delete_item( $id ) {
        global $wpdb;
        $id   = intval( $id );
        $item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_items WHERE id = %d", $id ) );
        if ( $item ) {
            $context = '';
            $moit    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_moits WHERE id = %d", $item->moit_id ) );
            if ( $moit ) {
                $ind = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ita_indicators WHERE id = %d", $moit->indicator_id ) );
                if ( $ind ) {
                    $year       = $wpdb->get_row( $wpdb->prepare( "SELECT year_label FROM {$wpdb->prefix}ita_years WHERE id = %d", $ind->year_id ) );
                    $year_label = $year ? $year->year_label : '';
                    $context    = ( $year_label ? "ปีงบ {$year_label} > " : '' )
                                . "ตัวชี้วัด {$ind->indicator_no} > MOIT {$moit->moit_no}";
                }
            }
            self::log_delete( 'item', $id, array(
                'item_type' => $item->item_type,
                'item_no'   => $item->item_no,
                'title'     => wp_strip_all_tags( $item->title ),
                'moit_id'   => $item->moit_id,
                'file_url'  => $item->file_url,
                'link_url'  => $item->link_url,
            ), $context );
        }
        $wpdb->delete( "{$wpdb->prefix}ita_items", array( 'id' => $id ) );
    }

    // ─── FULL DATA FOR YEAR ───────────────────────────────────────────────────

    public static function get_full_year_data( $year_id ) {
        $indicators = self::get_indicators( $year_id );
        foreach ( $indicators as $indicator ) {
            $indicator->moits = self::get_moits( $indicator->id );
            foreach ( $indicator->moits as $moit ) {
                $moit->items = self::get_items( $moit->id );
            }
        }
        return $indicators;
    }

    // ─── DELETE LOG ──────────────────────────────────────────────────────────

    public static function log_delete( $type, $record_id, $data, $context = '' ) {
        global $wpdb;
        switch ( $type ) {
            case 'year':
                $label = $data['year_label'] ?? '';
                break;
            case 'indicator':
                $label = 'ตัวชี้วัด ' . ( $data['indicator_no'] ?? '' ) . ': ' . wp_strip_all_tags( $data['title'] ?? '' );
                break;
            case 'moit':
                $label = 'MOIT ' . ( $data['moit_no'] ?? '' ) . ': ' . wp_strip_all_tags( $data['title'] ?? '' );
                break;
            case 'item':
                $no    = $data['item_no'] ?? '';
                $label = ( $no ? "{$no} " : '' ) . wp_strip_all_tags( $data['title'] ?? '' );
                break;
            default:
                $label = '';
        }
        $wpdb->insert(
            "{$wpdb->prefix}ita_delete_log",
            array(
                'deleted_at'   => current_time( 'mysql' ),
                'deleted_by'   => get_current_user_id(),
                'record_type'  => sanitize_key( $type ),
                'record_id'    => intval( $record_id ),
                'record_label' => mb_substr( $label, 0, 500 ),
                'context'      => mb_substr( $context, 0, 700 ),
                'record_data'  => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
            )
        );
    }

    public static function get_delete_logs( $args = array() ) {
        global $wpdb;
        $args  = wp_parse_args( $args, array(
            'type'     => '',
            'search'   => '',
            'page'     => 1,
            'per_page' => 25,
        ) );
        $where  = array();
        $values = array();
        if ( $args['type'] ) {
            $where[]  = 'record_type = %s';
            $values[] = sanitize_key( $args['type'] );
        }
        if ( $args['search'] ) {
            $where[]  = '(record_label LIKE %s OR context LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $like;
            $values[] = $like;
        }
        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $limit     = max( 1, intval( $args['per_page'] ) );
        $offset    = ( max( 1, intval( $args['page'] ) ) - 1 ) * $limit;
        $values[]  = $limit;
        $values[]  = $offset;
        $sql = "SELECT * FROM {$wpdb->prefix}ita_delete_log {$where_sql} ORDER BY deleted_at DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
    }

    public static function count_delete_logs( $args = array() ) {
        global $wpdb;
        $where  = array();
        $values = array();
        if ( ! empty( $args['type'] ) ) {
            $where[]  = 'record_type = %s';
            $values[] = sanitize_key( $args['type'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(record_label LIKE %s OR context LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $like;
            $values[] = $like;
        }
        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT COUNT(*) FROM {$wpdb->prefix}ita_delete_log {$where_sql}";
        if ( $values ) {
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
        }
        return (int) $wpdb->get_var( $sql );
    }

    public static function clear_delete_logs() {
        global $wpdb;
        return $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}ita_delete_log" );
    }
}
