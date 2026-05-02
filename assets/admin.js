/* jshint esversion: 6 */
(function ($) {
    'use strict';

    const AJAX = itaData.ajaxUrl;
    const NONCE = itaData.nonce;

    let currentYearId = null;
    let currentYearLabel = '';
    let mediaUploader = null;

    // ─── UTILS ───────────────────────────────────────────────────────────────

    function showNotice(msg, type = 'success') {
        const $n = $('#ita-notice');
        $n.removeClass('success error').addClass(type).html(msg).fadeIn(200);
        setTimeout(() => $n.fadeOut(400), 3000);
    }

    function ajax(action, data) {
        return $.post(AJAX, Object.assign({ action, nonce: NONCE }, data));
    }

    function escHtml(str) {
        return $('<div>').text(str || '').html();
    }

    // ─── CONFIRM DELETE MODAL ─────────────────────────────────────────────────

    /**
     * แสดง modal ยืนยันลบที่ต้องพิมพ์ "ยืนยันลบ" ก่อนดำเนินการ
     * @param {string}   message  ข้อความอธิบายสิ่งที่จะถูกลบ
     * @param {Function} onConfirm callback เมื่อยืนยัน
     */
    function confirmDelete(message, onConfirm) {
        const $modal  = $('#ita-modal-confirm-delete');
        const $input  = $('#ita-confirm-delete-input');
        const $okBtn  = $('#ita-confirm-delete-ok');

        $('#ita-confirm-delete-msg').text(message);
        $input.val('').removeClass('ita-input-error');
        $okBtn.prop('disabled', true);

        $modal.fadeIn(150);
        setTimeout(() => $input.trigger('focus'), 200);

        // ปิด handler เก่า แล้วผูกใหม่ (ป้องกัน duplicate)
        $input.off('input.confirmDelete').on('input.confirmDelete', function () {
            const match = $(this).val().trim() === 'ยืนยันลบ';
            $okBtn.prop('disabled', !match);
            $(this).toggleClass('ita-input-error', $(this).val().length > 0 && !match);
        });

        $okBtn.off('click.confirmDelete').on('click.confirmDelete', function () {
            if ($input.val().trim() !== 'ยืนยันลบ') return;
            closeModal();
            onConfirm();
        });
    }

    // ─── YEAR TABS ───────────────────────────────────────────────────────────

    $(document).on('click', '.ita-year-tab', function () {
        const $btn = $(this);
        $('.ita-year-tab').removeClass('active');
        $btn.addClass('active');
        currentYearId = $btn.data('year-id');
        currentYearLabel = $btn.data('year-label');
        loadYearData(currentYearId);
    });

    function loadYearData(yearId) {
        $('#ita-empty').hide();
        $('#ita-year-content').hide();
        $('#ita-indicators-list').html('<div style="padding:20px;text-align:center;"><span class="ita-spinner"></span> กำลังโหลดข้อมูล...</div>');
        $('#ita-year-content').show();
        $('#ita-year-heading').text('ITA ปีงบประมาณ ' + currentYearLabel);

        ajax('ita_get_year_data', { year_id: yearId })
            .done(res => {
                if (res.success) {
                    renderIndicators(res.data.indicators);
                }
            });
    }

    // ─── RENDER INDICATORS ───────────────────────────────────────────────────

    function renderIndicators(indicators) {
        const $list = $('#ita-indicators-list');
        $list.empty();

        if (!indicators || indicators.length === 0) {
            $list.html('<div class="ita-no-items">ยังไม่มีตัวชี้วัด — กด "เพิ่มตัวชี้วัด" เพื่อเริ่มต้น</div>');
            return;
        }

        indicators.forEach(ind => {
            $list.append(buildIndicatorCard(ind));
        });
    }

    function buildIndicatorCard(ind) {
        const moitsHtml = (ind.moits || []).map(m => buildMoitCard(m)).join('');
        return `
        <div class="ita-indicator-card" data-indicator-id="${ind.id}">
            <div class="ita-indicator-header">
                <span class="ita-indicator-badge">ตัวชี้วัดที่ ${escHtml(String(ind.indicator_no))}</span>
                <span class="ita-indicator-name">${escHtml(ind.title)}</span>
                <span class="dashicons dashicons-arrow-down-alt2 ita-indicator-toggle"></span>
            </div>
            <div class="ita-indicator-body">
                <div class="ita-indicator-toolbar">
                    <button class="button btn-edit-indicator"
                            data-id="${ind.id}"
                            data-no="${ind.indicator_no}"
                            data-title="${escHtml(ind.title)}">
                        <span class="dashicons dashicons-edit"></span> แก้ไข
                    </button>
                    <button class="button button-primary btn-add-moit" data-indicator-id="${ind.id}">
                        <span class="dashicons dashicons-plus-alt2"></span> เพิ่ม MOIT
                    </button>
                    <button class="button btn-delete-indicator" data-id="${ind.id}" style="color:#c0392b;border-color:#c0392b;">
                        <span class="dashicons dashicons-trash"></span> ลบ
                    </button>
                </div>
                <div class="ita-moits-list">
                    ${moitsHtml || '<div class="ita-no-items">ยังไม่มี MOIT</div>'}
                </div>
            </div>
        </div>`;
    }

    function buildMoitCard(moit) {
        const itemsHtml = buildItemsTable(moit.items || [], moit.id);
        return `
        <div class="ita-moit-card" data-moit-id="${moit.id}">
            <div class="ita-moit-header">
                <span class="ita-moit-badge">MOIT ${escHtml(String(moit.moit_no))}</span>
                <span class="ita-moit-title">${escHtml(moit.title)}</span>
                <span class="dashicons dashicons-arrow-down-alt2 ita-moit-toggle"></span>
            </div>
            <div class="ita-moit-body">
                <div class="ita-moit-toolbar">
                    <button class="button btn-edit-moit"
                            data-id="${moit.id}"
                            data-no="${moit.moit_no}"
                            data-title="${escHtml(moit.title)}">
                        <span class="dashicons dashicons-edit"></span> แก้ไข
                    </button>
                    <button class="button button-primary btn-add-item" data-moit-id="${moit.id}">
                        <span class="dashicons dashicons-plus-alt2"></span> เพิ่มรายการ
                    </button>
                    <button class="button btn-delete-moit" data-id="${moit.id}" style="color:#c0392b;border-color:#c0392b;">
                        <span class="dashicons dashicons-trash"></span> ลบ
                    </button>
                </div>
                ${itemsHtml}
            </div>
        </div>`;
    }

    // คำนวณระดับการย่อหน้าจาก item_no (รองรับสูงสุด 5 ระดับ)
    function getIndentLevel(itemNo) {
        if (!itemNo) return 0;
        const dots = (itemNo.match(/\./g) || []).length;
        return Math.min(dots, 5);
    }

    function buildItemsTable(items, moitId) {
        if (!items || items.length === 0) {
            return `<div class="ita-no-items">ยังไม่มีรายการ — กด "เพิ่มรายการ" ด้านบน</div>`;
        }
        const rows = items.map((item, idx) => {
            const isSection = item.item_type === 'section';
            const isFirst   = idx === 0;
            const isLast    = idx === items.length - 1;
            const moveBtn   = `<span class="ita-sort-row">
                <span class="ita-sort-num">${item.sort_order}</span>
                <span class="ita-move-btns">
                    <button class="ita-move-up" data-id="${item.id}" data-moit-id="${moitId}" title="เลื่อนขึ้น" ${isFirst ? 'disabled' : ''}>&#9650;</button>
                    <button class="ita-move-down" data-id="${item.id}" data-moit-id="${moitId}" title="เลื่อนลง" ${isLast ? 'disabled' : ''}>&#9660;</button>
                </span>
            </span>`;

            if (isSection) {
                return `<tr class="ita-row-section" data-item-id="${item.id}">
                    <td class="ita-item-sort">${moveBtn}</td>
                    <td colspan="2" class="ita-section-cell">
                        <span class="ita-section-icon dashicons dashicons-calendar-alt"></span>
                        <strong>${escHtml(item.item_no) ? escHtml(item.item_no) + ' ' : ''}${escHtml(item.title)}</strong>
                    </td>
                    <td class="ita-item-actions">
                        <button class="button btn-edit-item"
                                data-id="${item.id}" data-moit-id="${moitId}"
                                data-type="section" data-no="${escHtml(item.item_no)}"
                                data-title="${escHtml(item.title)}"
                                data-file-id="0" data-file-url="" data-link-url=""
                                data-sort="${item.sort_order}">แก้ไข</button>
                        <button class="button btn-delete-item" data-id="${item.id}" style="color:#c0392b;">ลบ</button>
                    </td>
                </tr>`;
            }

            const indent = getIndentLevel(item.item_no);
            const fileLink = item.file_url
                ? `<a href="${escHtml(item.file_url)}" target="_blank" rel="noopener">
                       <span class="dashicons dashicons-pdf"></span> ดูไฟล์
                   </a>`
                : (item.link_url
                    ? `<a href="${escHtml(item.link_url)}" target="_blank" rel="noopener">
                           <span class="dashicons dashicons-external"></span> ลิงค์
                       </a>`
                    : '<span style="color:#ccc;">—</span>');

            return `<tr data-item-id="${item.id}" class="ita-depth-${indent}">
                <td class="ita-item-sort">${moveBtn}</td>
                <td class="ita-item-no">${escHtml(item.item_no)}</td>
                <td class="ita-item-ttl">${escHtml(item.title)}</td>
                <td class="ita-item-file">${fileLink}</td>
                <td class="ita-item-actions">
                    <button class="button btn-edit-item"
                            data-id="${item.id}"
                            data-moit-id="${moitId}"
                            data-type="item"
                            data-no="${escHtml(item.item_no)}"
                            data-title="${escHtml(item.title)}"
                            data-file-id="${item.file_id}"
                            data-file-url="${escHtml(item.file_url)}"
                            data-link-url="${escHtml(item.link_url)}"
                            data-sort="${item.sort_order}">
                        แก้ไข
                    </button>
                    <button class="button btn-delete-item" data-id="${item.id}" style="color:#c0392b;">
                        ลบ
                    </button>
                </td>
            </tr>`;
        }).join('');

        return `<table class="ita-items-table">
            <thead>
                <tr>
                    <th class="ita-item-sort">ลำดับ</th>
                    <th class="ita-item-no">ข้อที่</th>
                    <th>รายละเอียด</th>
                    <th class="ita-item-file">ไฟล์/ลิงค์</th>
                    <th class="ita-item-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>`;
    }

    // ─── COLLAPSE TOGGLES ────────────────────────────────────────────────────

    $(document).on('click', '.ita-indicator-header', function (e) {
        if ($(e.target).is('button') || $(e.target).closest('button').length) return;
        $(this).toggleClass('collapsed');
        $(this).next('.ita-indicator-body').slideToggle(200);
    });

    $(document).on('click', '.ita-moit-header', function (e) {
        if ($(e.target).is('button') || $(e.target).closest('button').length) return;
        $(this).toggleClass('collapsed');
        $(this).next('.ita-moit-body').slideToggle(200);
    });

    // ─── ADD YEAR ─────────────────────────────────────────────────────────────

    $('#btn-add-year').on('click', function () {
        $('#modal-year-title').text('เพิ่มปีงบประมาณ');
        $('#modal-year-id').val('');
        $('#modal-year-label').val('');
        $('#modal-year-sort').val(0);
        $('#ita-modal-year').fadeIn(150);
    });

    $('#modal-year-save').on('click', function () {
        const label = $.trim($('#modal-year-label').val());
        if (!label) { showNotice('กรุณากรอกปีงบประมาณ', 'error'); return; }

        const data = {
            year_label: label,
            sort_order: $('#modal-year-sort').val(),
        };
        const id = $('#modal-year-id').val();
        if (id) data.id = id;

        ajax('ita_save_year', data).done(res => {
            if (res.success) {
                const newId = res.data.id;
                // เพิ่ม/อัปเดต tab
                let $existing = $(`.ita-year-tab[data-year-id="${newId}"]`);
                if ($existing.length) {
                    $existing.text('ITA ' + label).data('year-label', label);
                } else {
                    const $btn = $(`<button class="ita-year-tab" data-year-id="${newId}" data-year-label="${escHtml(label)}">ITA ${escHtml(label)}</button>`);
                    $('#ita-year-tabs').append($btn);
                    $btn.trigger('click');
                }
                closeModal();
                showNotice('บันทึกปีงบประมาณเรียบร้อย');
            }
        });
    });

    // ─── EDIT / DELETE YEAR ──────────────────────────────────────────────────

    $('#btn-edit-year').on('click', function () {
        $('#modal-year-title').text('แก้ไขปีงบประมาณ');
        $('#modal-year-id').val(currentYearId);
        $('#modal-year-label').val(currentYearLabel);
        $('#ita-modal-year').fadeIn(150);
    });

    $('#btn-delete-year').on('click', function () {
        confirmDelete(
            `ลบปีงบประมาณ ITA ${currentYearLabel} และข้อมูลทั้งหมดในปีนี้ (ตัวชี้วัด, MOIT, รายการ)?`,
            () => {
                ajax('ita_delete_year', { id: currentYearId }).done(res => {
                    if (res.success) {
                        $(`.ita-year-tab[data-year-id="${currentYearId}"]`).remove();
                        currentYearId = null;
                        $('#ita-year-content').hide();
                        $('#ita-empty').show();
                        showNotice('ลบปีงบประมาณเรียบร้อย');
                    }
                });
            }
        );
    });

    // ─── ADD INDICATOR ───────────────────────────────────────────────────────

    $('#btn-add-indicator').on('click', function () {
        openIndicatorModal();
    });

    function openIndicatorModal(id = '', no = 1, title = '') {
        $('#modal-indicator-title').text(id ? 'แก้ไขตัวชี้วัด' : 'เพิ่มตัวชี้วัด');
        $('#modal-indicator-id').val(id);
        $('#modal-indicator-year-id').val(currentYearId);
        $('#modal-indicator-no').val(no);
        $('#modal-indicator-ttl').val(title);
        $('#ita-modal-indicator').fadeIn(150);
    }

    $(document).on('click', '.btn-edit-indicator', function () {
        openIndicatorModal($(this).data('id'), $(this).data('no'), $(this).data('title'));
    });

    $('#modal-indicator-save').on('click', function () {
        const title = $.trim($('#modal-indicator-ttl').val());
        if (!title) { showNotice('กรุณากรอกชื่อตัวชี้วัด', 'error'); return; }
        const data = {
            year_id:       currentYearId,
            indicator_no:  $('#modal-indicator-no').val(),
            title,
        };
        const id = $('#modal-indicator-id').val();
        if (id) data.id = id;
        ajax('ita_save_indicator', data)
            .done(res => {
                if (!res.success) { showNotice('บันทึกไม่สำเร็จ', 'error'); return; }
                closeModal();
                const d = res.data;
                if (d.is_new) {
                    // เพิ่ม card ใหม่ทันที ไม่ reload
                    const ind = { id: d.id, indicator_no: d.indicator_no, title: d.title, moits: [] };
                    const $list = $('#ita-indicators-list');
                    $list.find('.ita-no-items').remove();
                    const $newCard = $(buildIndicatorCard(ind));
                    $list.append($newCard);
                    scrollToEl($newCard);
                } else {
                    // อัปเดต text ใน card เดิม
                    const $c = $(`.ita-indicator-card[data-indicator-id="${d.id}"]`);
                    $c.find('.ita-indicator-badge').text(`ตัวชี้วัดที่ ${d.indicator_no}`);
                    $c.find('.ita-indicator-name').text(d.title);
                    $c.find('.btn-edit-indicator').data('no', d.indicator_no).data('title', d.title);
                }
                showNotice('บันทึกตัวชี้วัดเรียบร้อย');
            })
            .fail(() => showNotice('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error'));
    });

    $(document).on('click', '.btn-delete-indicator', function () {
        const id = $(this).data('id');
        const name = $(this).closest('.ita-indicator-card').find('.ita-indicator-name').text();
        confirmDelete(
            `ลบตัวชี้วัด "${name}" และ MOIT ทั้งหมดในนั้น?`,
            () => {
                ajax('ita_delete_indicator', { id }).done(res => {
                    if (res.success) {
                        const $card = $(`.ita-indicator-card[data-indicator-id="${id}"]`);
                        $card.remove();
                        if ($('#ita-indicators-list .ita-indicator-card').length === 0) {
                            $('#ita-indicators-list').html('<div class="ita-no-items">ยังไม่มีตัวชี้วัด — กด "เพิ่มตัวชี้วัด" เพื่อเริ่มต้น</div>');
                        }
                        showNotice('ลบตัวชี้วัดเรียบร้อย');
                    }
                });
            }
        );
    });

    // ─── ADD / EDIT MOIT ─────────────────────────────────────────────────────

    $(document).on('click', '.btn-add-moit', function () {
        openMoitModal('', 1, '', $(this).data('indicator-id'));
    });

    $(document).on('click', '.btn-edit-moit', function () {
        openMoitModal($(this).data('id'), $(this).data('no'), $(this).data('title'));
    });

    function openMoitModal(id = '', no = 1, title = '', indicatorId = '') {
        $('#modal-moit-title').text(id ? 'แก้ไข MOIT' : 'เพิ่ม MOIT');
        $('#modal-moit-id').val(id);
        if (indicatorId) $('#modal-moit-indicator-id').val(indicatorId);
        $('#modal-moit-no').val(no);
        $('#modal-moit-ttl').val(title);
        $('#ita-modal-moit').fadeIn(150);
    }

    $('#modal-moit-save').on('click', function () {
        const title = $.trim($('#modal-moit-ttl').val());
        if (!title) { showNotice('กรุณากรอกชื่อ MOIT', 'error'); return; }
        const data = {
            indicator_id: $('#modal-moit-indicator-id').val(),
            moit_no:      $('#modal-moit-no').val(),
            title,
        };
        const id = $('#modal-moit-id').val();
        if (id) data.id = id;
        ajax('ita_save_moit', data)
            .done(res => {
                if (!res.success) { showNotice('บันทึกไม่สำเร็จ', 'error'); return; }
                closeModal();
                const d = res.data;
                if (d.is_new) {
                    const moit = { id: d.id, indicator_id: d.indicator_id, moit_no: d.moit_no, title: d.title, items: [] };
                    const $indCard = $(`.ita-indicator-card[data-indicator-id="${d.indicator_id}"]`);
                    // เปิด indicator body ถ้าปิดอยู่
                    ensureOpen($indCard.find('.ita-indicator-header'));
                    const $moitsList = $indCard.find('.ita-moits-list');
                    $moitsList.find('.ita-no-items').remove();
                    const $newMoit = $(buildMoitCard(moit));
                    $moitsList.append($newMoit);
                    scrollToEl($newMoit);
                } else {
                    const $c = $(`.ita-moit-card[data-moit-id="${d.id}"]`);
                    $c.find('.ita-moit-badge').text(`MOIT ${d.moit_no}`);
                    $c.find('.ita-moit-title').text(d.title);
                    $c.find('.btn-edit-moit').data('no', d.moit_no).data('title', d.title);
                }
                showNotice('บันทึก MOIT เรียบร้อย');
            })
            .fail(() => showNotice('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error'));
    });

    $(document).on('click', '.btn-delete-moit', function () {
        const moitId = $(this).data('id');
        const name   = $(this).closest('.ita-moit-card').find('.ita-moit-title').text();
        confirmDelete(
            `ลบ MOIT "${name}" และรายการทั้งหมดในนั้น?`,
            () => {
                ajax('ita_delete_moit', { id: moitId }).done(res => {
                    if (res.success) {
                        const $card = $(`.ita-moit-card[data-moit-id="${moitId}"]`);
                        const $list = $card.closest('.ita-moits-list');
                        $card.remove();
                        if ($list.children('.ita-moit-card').length === 0) {
                            $list.html('<div class="ita-no-items">ยังไม่มี MOIT</div>');
                        }
                        showNotice('ลบ MOIT เรียบร้อย');
                    }
                });
            }
        );
    });

    // ─── ADD / EDIT ITEM ─────────────────────────────────────────────────────

    $(document).on('click', '.btn-add-item', function () {
        const moitId = $(this).data('moit-id');
        // หา max sort_order จากรายการในตารางของ MOIT นั้น
        let maxSort = 0;
        $(`.ita-moit-card[data-moit-id="${moitId}"] .ita-sort-num`).each(function () {
            const v = parseInt($(this).text(), 10);
            if (v > maxSort) maxSort = v;
        });
        openItemModal({ moit_id: moitId, sort: maxSort + 1 });
    });

    $(document).on('click', '.btn-edit-item', function () {
        const $btn = $(this);
        openItemModal({
            id:        $btn.data('id'),
            moit_id:   $btn.data('moit-id'),
            item_type: $btn.data('type') || 'item',
            item_no:   $btn.data('no'),
            title:     $btn.data('title'),
            file_id:   $btn.data('file-id'),
            file_url:  $btn.data('file-url'),
            link_url:  $btn.data('link-url'),
            sort:      $btn.data('sort'),
        });
    });

    function setItemTypeUI(type) {
        if (type === 'section') {
            $('input[name="modal-item-type"][value="section"]').prop('checked', true);
            $('.modal-item-file-rows').hide();
            $('#modal-item-no').attr('placeholder', 'เช่น ไตรมาส 3, รอบ 6 เดือน');
        } else {
            $('input[name="modal-item-type"][value="item"]').prop('checked', true);
            $('.modal-item-file-rows').show();
            $('#modal-item-no').attr('placeholder', 'เช่น 10.1, 10.2.1');
        }
    }

    $('input[name="modal-item-type"]').on('change', function () {
        setItemTypeUI($(this).val());
    });

    function openItemModal(d) {
        $('#modal-item-title').text(d.id ? 'แก้ไขรายการ' : 'เพิ่มรายการ');
        $('#modal-item-id').val(d.id || '');
        $('#modal-item-moit-id').val(d.moit_id || '');
        $('#modal-item-no').val(d.item_no || '');
        $('#modal-item-ttl').val(d.title || '');
        $('#modal-item-file-id').val(d.file_id || 0);
        $('#modal-item-file-url').val(d.file_url || '');
        $('#modal-item-link').val(d.link_url || '');
        $('#modal-item-sort').val(d.sort || 0);
        setItemTypeUI(d.item_type || 'item');
        updateFilePreview(d.file_url || '');
        $('#ita-modal-item').fadeIn(150);
    }

    function updateFilePreview(url) {
        if (url) {
            const name = url.split('/').pop().split('?')[0];
            $('#ita-file-preview-name').text(name);
            $('#ita-file-preview').show();
            $('#btn-clear-file').show();
        } else {
            $('#ita-file-preview').hide();
            $('#btn-clear-file').hide();
        }
    }

    // WP Media Uploader
    $('#btn-select-media').on('click', function () {
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media({
            title: 'เลือกไฟล์สำหรับ ITA',
            button: { text: 'ใช้ไฟล์นี้' },
            multiple: false,
        });
        mediaUploader.on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#modal-item-file-id').val(attachment.id);
            $('#modal-item-file-url').val(attachment.url);
            updateFilePreview(attachment.url);
        });
        mediaUploader.open();
    });

    $('#btn-clear-file').on('click', function () {
        $('#modal-item-file-id').val(0);
        $('#modal-item-file-url').val('');
        updateFilePreview('');
    });

    $('#modal-item-save').on('click', function () {
        const itemType = $('input[name="modal-item-type"]:checked').val() || 'item';
        const itemNo   = $.trim($('#modal-item-no').val());
        const title    = $.trim($('#modal-item-ttl').val());
        if (itemType === 'section' && !itemNo && !title) {
            showNotice('กรุณากรอก ข้อที่ หรือ รายละเอียด', 'error'); return;
        }
        if (itemType === 'item' && !title) {
            showNotice('กรุณากรอกรายละเอียด', 'error'); return;
        }
        const data = {
            moit_id:    $('#modal-item-moit-id').val(),
            item_type:  itemType,
            item_no:    itemNo,
            title:      title,
            file_id:    $('#modal-item-file-id').val(),
            file_url:   $('#modal-item-file-url').val(),
            link_url:   $('#modal-item-link').val(),
            sort_order: $('#modal-item-sort').val(),
        };
        const id = $('#modal-item-id').val();
        if (id) data.id = id;
        ajax('ita_save_item', data)
            .done(res => {
                if (res.success) {
                    closeModal();
                    refreshMoitItems(res.data.moit_id, res.data.items);
                    showNotice('บันทึกรายการเรียบร้อย');
                } else {
                    const msg = (res.data && res.data.message) ? res.data.message : 'บันทึกไม่สำเร็จ';
                    showNotice(msg, 'error');
                }
            })
            .fail(() => showNotice('เกิดข้อผิดพลาด กรุณาลองใหม่', 'error'));
    });

    $(document).on('click', '.ita-move-up, .ita-move-down', function () {
        const $btn    = $(this);
        if ($btn.prop('disabled')) return;
        const moitId  = $btn.data('moit-id');
        const itemId  = $btn.data('id');
        const $tbody  = $btn.closest('tbody');
        const $rows   = $tbody.find('tr[data-item-id]');
        const $row    = $btn.closest('tr');
        const idx     = $rows.index($row);
        const isUp    = $btn.hasClass('ita-move-up');

        // สลับ DOM ทันที ก่อน AJAX
        if (isUp && idx > 0) {
            $rows.eq(idx - 1).before($row);
        } else if (!isUp && idx < $rows.length - 1) {
            $rows.eq(idx + 1).after($row);
        }

        // เก็บลำดับ id ใหม่
        const ids = [];
        $tbody.find('tr[data-item-id]').each(function () {
            ids.push($(this).data('item-id'));
        });

        ajax('ita_reorder_items', { moit_id: moitId, ids }).done(res => {
            if (res.success) {
                refreshMoitItems(res.data.moit_id, res.data.items);
            }
        });
    });

    $(document).on('click', '.btn-delete-item', function () {
        if (!confirm('ยืนยันลบรายการนี้?')) return;
        const moitId = $(this).closest('[data-moit-id]').data('moit-id');
        ajax('ita_delete_item', { id: $(this).data('id'), moit_id: moitId }).done(res => {
            if (res.success) {
                refreshMoitItems(res.data.moit_id, res.data.items);
                showNotice('ลบรายการเรียบร้อย');
            }
        });
    });

    // อัปเดตตาราง items ของ MOIT เดียว โดยไม่กระทบส่วนอื่น
    function refreshMoitItems(moitId, items) {
        const $card = $(`.ita-moit-card[data-moit-id="${moitId}"]`);
        if (!$card.length) return;
        // เปิด moit body ถ้าปิดอยู่
        ensureOpen($card.find('.ita-moit-header'));
        $card.find('.ita-items-table, .ita-no-items').remove();
        const $newTable = $(buildItemsTable(items, moitId));
        $card.find('.ita-moit-body').append($newTable);
        scrollToEl($newTable);
    }

    // ─── PANEL HELPERS ──────────────────────────────────────────────────────

    // เปิด panel ถ้าปิดอยู่ ($header = .ita-indicator-header หรือ .ita-moit-header)
    function ensureOpen($header) {
        if ($header.hasClass('collapsed')) {
            $header.removeClass('collapsed');
            $header.next().stop(true, true).show();
        }
    }

    // scroll ไปยัง element (หลัง animation เล็กน้อย)
    function scrollToEl($el) {
        if (!$el.length) return;
        setTimeout(function () {
            $el[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 120);
    }

    // ─── CLOSE MODAL ─────────────────────────────────────────────────────────

    function closeModal() {
        $('.ita-modal').fadeOut(150);
    }
    $(document).on('click', '.ita-modal-close', closeModal);
    $(document).on('click', '.ita-modal', function (e) {
        if ($(e.target).is('.ita-modal')) closeModal();
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    // ─── AUTO LOAD FIRST YEAR ────────────────────────────────────────────────

    const $lastTab = $('.ita-year-tab').last();
    if ($lastTab.length) {
        $lastTab.trigger('click');
    }

})(jQuery);
