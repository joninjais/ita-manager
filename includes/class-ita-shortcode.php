<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITA_Shortcode {

    public function init() {
        add_shortcode( 'ita_display', array( $this, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        // Elementor: โหลด assets ใน editor preview ด้วย
        add_action( 'elementor/frontend/after_register_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        wp_register_style(
            'ita-public-css',
            ITA_MANAGER_URL . 'assets/public.css',
            array( 'dashicons' ),
            ITA_MANAGER_VERSION
        );
        wp_register_script(
            'ita-public-js',
            ITA_MANAGER_URL . 'assets/public.js',
            array(),
            ITA_MANAGER_VERSION,
            true  // โหลด footer
        );
    }

    /**
     * Shortcode: [ita_display]
     * ตัวเลือก: year="2569" (เลือกเฉพาะปี ถ้าไม่ระบุจะแสดงทุกปีเป็น tab)
     */
    public function render( $atts ) {
        $atts = shortcode_atts( array(
            'year' => '',
        ), $atts, 'ita_display' );

        $years = ITA_DB::get_years();
        if ( empty( $years ) ) {
            return '<p class="ita-no-data">ยังไม่มีข้อมูล ITA</p>';
        }

        // กรองปีถ้าระบุ
        if ( $atts['year'] ) {
            $years = array_filter( $years, function ( $y ) use ( $atts ) {
                return $y->year_label === sanitize_text_field( $atts['year'] );
            } );
        }

        ob_start();
        wp_enqueue_style( 'ita-public-css' );
        wp_enqueue_script( 'ita-public-js' );
        if ( count( $years ) === 1 ) {
            $year = reset( $years );
            $this->render_year_content( $year );
        } else {
            $this->render_tabbed( $years );
        }
        return ob_get_clean();
    }

    private function render_tabbed( $years ) {
        // หา year_label สูงสุด (ปีงบประมาณล่าสุด) เพื่อ active tab นั้นก่อน
        $latest_id = null;
        $max_label = null;
        foreach ( $years as $year ) {
            if ( $max_label === null || $year->year_label > $max_label ) {
                $max_label = $year->year_label;
                $latest_id = $year->id;
            }
        }
        ?>
        <div class="ita-container">
            <div class="ita-tab-nav" style="justify-content: center; display: flex;" role="tablist">
                <?php foreach ( $years as $year ) :
                    $is_active = ( $year->id === $latest_id );
                ?>
                <button class="ita-tab-btn<?php echo $is_active ? ' active' : ''; ?>"
                        data-target="ita-year-<?php echo esc_attr( $year->id ); ?>"
                        role="tab"
                        aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                    ITA <?php echo esc_html( $year->year_label ); ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ( $years as $year ) :
                $is_active = ( $year->id === $latest_id );
            ?>
            <div class="ita-tab-panel<?php echo $is_active ? ' active' : ''; ?>"
                 id="ita-year-<?php echo esc_attr( $year->id ); ?>"
                 role="tabpanel">
                <?php $this->render_year_content( $year ); ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
    }

    private function render_year_content( $year ) {
        $indicators = ITA_DB::get_full_year_data( $year->id );
        if ( empty( $indicators ) ) {
            echo '<p class="ita-no-data">ยังไม่มีข้อมูลสำหรับปีนี้</p>';
            return;
        }
        foreach ( $indicators as $ind ) :
        ?>
        <div class="ita-indicator">
            <div class="ita-indicator-hdr">
                <span class="ita-ind-badge">ตัวชี้วัดที่ <?php echo esc_html( $ind->indicator_no ); ?></span>
                <span class="ita-ind-title"><?php echo esc_html( $ind->title ); ?></span>
                <span class="ita-ind-arrow">&#9660;</span>
            </div>
            <div class="ita-indicator-bdy">
                <?php foreach ( $ind->moits as $moit ) : ?>
                <div class="ita-moit">
                    <div class="ita-moit-hdr">
                        <span class="ita-moit-badge">MOIT <?php echo esc_html( $moit->moit_no ); ?></span>
                        <span class="ita-moit-ttl"><?php echo esc_html( $moit->title ); ?></span>
                        <span class="ita-ind-arrow">&#9660;</span>
                    </div>
                    <div class="ita-moit-bdy">
                        <?php if ( empty( $moit->items ) ) : ?>
                        <p class="ita-no-data">ยังไม่มีรายการ</p>
                        <?php else : ?>
                        <ul class="ita-item-list">
                            <?php foreach ( $moit->items as $item ) :
                                $is_section = ( $item->item_type === 'section' );
                                $has_link   = ! $is_section && ( ! empty( $item->file_url ) || ! empty( $item->link_url ) );
                                $url        = ! empty( $item->file_url ) ? $item->file_url : ( $item->link_url ?? '' );
                                $is_pdf     = $url && preg_match( '/\.pdf$/i', parse_url( $url, PHP_URL_PATH ) );
                                $dots       = substr_count( $item->item_no, '.' );
                                $indent     = $is_section ? 0 : min( $dots, 5 );
                            ?>
                            <?php if ( $is_section ) : ?>
                            <li class="ita-item ita-item--section">
                                <span class="ita-section-label">
                                    <span class="dashicons dashicons-calendar-alt" style="font-size:15px;vertical-align:middle;margin-right:5px;color:#0073aa;"></span>
                                    <?php if ( ! empty( $item->item_no ) ) : ?>
                                    <strong><?php echo esc_html( $item->item_no ); ?></strong>
                                    <?php endif; ?>
                                    <?php echo esc_html( $item->title ); ?>
                                </span>
                            </li>
                            <?php else : ?>
                            <li class="ita-item ita-item--indent-<?php echo esc_attr( $indent ); ?><?php echo $has_link ? ' ita-item--linked' : ''; ?>">
                                <?php if ( ! empty( $item->item_no ) ) : ?>
                                <span class="ita-item-num"><?php echo esc_html( $item->item_no ); ?>.</span>
                                <?php endif; ?>
                                <span class="ita-item-desc"><?php echo esc_html( $item->title ); ?></span>
                                <?php if ( $has_link ) : ?>
                                <a class="ita-item-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php if ( $is_pdf ) : ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:3px"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5z"/><path d="M9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg>ดู PDF
                                    <?php else : ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:3px"><path d="M19 19H5V5h7V3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>เปิดลิงค์
                                    <?php endif; ?>
                                </a>
                                <?php endif; ?>
                            </li>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        endforeach;
        ?>
        <?php
    }
}
