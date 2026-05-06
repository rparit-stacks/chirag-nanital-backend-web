'use strict';

/**
 * Seller panel — Store Addon Inventory: bulk add page.
 *
 * Full-page form that lets a seller attach a shared set of (addon_item, price,
 * cost, stock, is_available) rows to one or many of their stores in a single
 * submit. Same values broadcast across every selected store; per-store
 * refinements happen afterwards via the single-row edit modal on the listing.
 *
 * Responsibilities:
 *  - Multi-store selection with select-all / clear helpers.
 *  - Dynamic addon-item rows (group -> item cascade, default price/cost from catalog).
 *  - Flag every row that would UPDATE existing inventory for any of the
 *    selected stores and annotate with the per-store matrix of existing rows.
 *  - Duplicate-item guard across rows.
 *  - Live "Will affect N store × M item = K rows" impact hint.
 */
(function () {
    const form = document.getElementById('sai-bulk-form');
    if (!form) {
        return;
    }

    const stateMatrixUrl    = form.dataset.stateMatrixUrl;
    const itemsForGroupBase = form.dataset.itemsForGroupBase;
    const statusAllLabel    = form.dataset.statusAllLabel || 'Updates existing inventory';
    const statusSomeTpl     = form.dataset.statusSomeTemplate || 'Updates {hits} of {total} stores';

    const $form = $(form);
    const $rows = $('#sai-rows');
    const $rowTpl = document.getElementById('sai-row-template');
    const $emptyTpl = document.getElementById('sai-empty-row');
    const $impactHint = $('#sai-impact-hint');
    const $storeCount = $('#sai-store-count');
    const $saveBtn = $('#sai-save-btn');

    const impactEmptyLabel = $impactHint.data('emptyLabel') || '';
    const impactTemplate   = $impactHint.data('templateLabel') || 'Will save {stores} × {items} = {total} rows';

    // Cached matrix: { [store_id]: { [addon_item_id]: { id, price, cost, stock, is_available } } }
    let inventoryMatrix = {};

    initEmptyState();
    appendRow();
    bindStoreToggles();
    bindRowControls();
    updateImpactHint();

    // -------------------------------------------------------------------
    // Stores
    // -------------------------------------------------------------------

    function bindStoreToggles() {
        $('#sai-select-all-stores').on('click', function () {
            $('.sai-store-checkbox').prop('checked', true).trigger('change');
        });
        $('#sai-clear-stores').on('click', function () {
            $('.sai-store-checkbox').prop('checked', false).trigger('change');
        });

        $(document).on('change', '.sai-store-checkbox', function () {
            updateStoreCount();
            refreshMatrixAndRows();
            updateImpactHint();
        });

        updateStoreCount();
    }

    function selectedStoreIds() {
        return $('.sai-store-checkbox:checked')
            .toArray()
            .map((el) => parseInt(el.value, 10))
            .filter(Boolean);
    }

    function updateStoreCount() {
        $storeCount.text(selectedStoreIds().length);
    }

    // -------------------------------------------------------------------
    // Rows
    // -------------------------------------------------------------------

    function bindRowControls() {
        $('#sai-add-row').on('click', function () {
            const $row = appendRow();
            if ($row && $row.length) {
                $row.get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                $row.find('.sai-row-group').trigger('focus');
            }
        });

        // Remove row. When only one row is left, clear it instead so the form always has at least one slot.
        $rows.on('click', '.sai-remove-row', function () {
            const $all = $rows.find('.sai-row');
            if ($all.length <= 1) {
                clearRow($(this).closest('.sai-row'));
            } else {
                $(this).closest('.sai-row').remove();
                renumberRows();
            }
            updateDuplicateLocks();
            refreshMatrixAndRows();
            updateImpactHint();
            toggleEmptyRow();
        });

        // Group change -> load items for that group into the row's item select.
        $rows.on('change', '.sai-row-group', function () {
            const $row = $(this).closest('.sai-row');
            const $items = $row.find('.sai-row-item');
            const groupId = $(this).val();

            hideRowStatus($row);
            $items.empty().append(new Option('Loading…', ''));

            if (!groupId) {
                $items.empty().append(new Option('Select', ''));
                updateDuplicateLocks();
                refreshMatrixAndRows();
                updateImpactHint();
                return;
            }

            fetchItemsForGroup(groupId).then(function (items) {
                $items.empty().append(new Option('Select', ''));
                items.forEach(function (row) {
                    const opt = new Option(row.title, row.id);
                    opt.dataset.price = row.price ?? '';
                    opt.dataset.cost  = row.cost ?? '';
                    $items.append(opt);
                });
                updateDuplicateLocks();
                refreshMatrixAndRows();
                updateImpactHint();
            }).catch(function () {
                $items.empty().append(new Option('Select', ''));
                updateDuplicateLocks();
                updateImpactHint();
            });
        });

        // Item change -> prefill price/cost from catalog defaults; refresh status badge.
        $rows.on('change', '.sai-row-item', function () {
            const $row = $(this).closest('.sai-row');
            const $opt = $(this).find(':selected');
            const itemId = $opt.val();

            applyCatalogDefaults($row, $opt);
            updateDuplicateLocks();

            if (!itemId) {
                hideRowStatus($row);
                updateImpactHint();
                return;
            }

            refreshMatrixAndRows();
            updateImpactHint();
        });

        // Any manual override of price/cost/stock/available shouldn't re-pull the matrix
        // but should nudge the impact hint if row validity changes.
        $rows.on('input change', '.sai-row-price, .sai-row-cost, .sai-row-stock, .sai-row-available', function () {
            updateImpactHint();
        });
    }

    function appendRow() {
        removeEmptyRow();

        if (!$rowTpl) {
            return $();
        }
        const index = $rows.find('.sai-row').length;
        const html = $rowTpl.innerHTML.replace(/__INDEX__/g, String(index));
        const wrapper = document.createElement('tbody');
        wrapper.innerHTML = html.trim();
        const node = wrapper.firstChild;
        $rows.append(node);

        updateDuplicateLocks();
        return $(node);
    }

    function clearRow($row) {
        $row.find('.sai-row-group').val('');
        $row.find('.sai-row-item').empty().append(new Option('Select', ''));
        $row.find('.sai-row-price').val('');
        $row.find('.sai-row-cost').val('');
        $row.find('.sai-row-stock').val(0);
        $row.find('.sai-row-available').prop('checked', true);
        hideRowStatus($row);
    }

    function renumberRows() {
        $rows.find('.sai-row').each(function (i) {
            $(this).find('.sai-row-item').attr('name', `items[${i}][addon_item_id]`);
            $(this).find('.sai-row-price').attr('name', `items[${i}][price]`);
            $(this).find('.sai-row-cost').attr('name', `items[${i}][cost]`);
            $(this).find('.sai-row-stock').attr('name', `items[${i}][stock]`);
            $(this).find('.sai-row-available').attr('name', `items[${i}][is_available]`);
        });
    }

    function initEmptyState() {
        if ($emptyTpl) {
            $rows.data('emptyHtml', $emptyTpl.innerHTML.trim());
        }
    }

    function toggleEmptyRow() {
        const hasRealRows = $rows.find('.sai-row').length > 0;
        if (hasRealRows) {
            removeEmptyRow();
        } else if ($rows.data('emptyHtml')) {
            $rows.html($rows.data('emptyHtml'));
        }
    }

    function removeEmptyRow() {
        $rows.find('.sai-empty').remove();
    }

    // -------------------------------------------------------------------
    // Duplicate locks (no item can appear twice across rows)
    // -------------------------------------------------------------------

    function updateDuplicateLocks() {
        const $selects = $rows.find('.sai-row-item');
        const picked = new Set();
        $selects.each(function () {
            const val = $(this).val();
            if (val) picked.add(String(val));
        });
        $selects.each(function () {
            const $select = $(this);
            const currentValue = String($select.val() || '');
            $select.find('option').each(function () {
                const optValue = String(this.value || '');
                if (optValue === '' || optValue === currentValue) {
                    this.disabled = false;
                    return;
                }
                this.disabled = picked.has(optValue);
            });
        });
    }

    // -------------------------------------------------------------------
    // Matrix: look up existing (store × item) rows so we can flag updates
    // -------------------------------------------------------------------

    function refreshMatrixAndRows() {
        const storeIds = selectedStoreIds();
        const itemIds = collectSelectedItemIds();

        if (storeIds.length === 0 || itemIds.length === 0) {
            inventoryMatrix = {};
            applyMatrixToRows();
            return;
        }

        axios.get(stateMatrixUrl, {
            params: { store_ids: storeIds, addon_item_ids: itemIds },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                inventoryMatrix = response.data?.data?.matrix || {};
                applyMatrixToRows();
            })
            .catch(function () {
                inventoryMatrix = {};
                applyMatrixToRows();
            });
    }

    function collectSelectedItemIds() {
        const ids = [];
        $rows.find('.sai-row-item').each(function () {
            const v = parseInt($(this).val(), 10);
            if (v) ids.push(v);
        });
        return ids;
    }

    function applyMatrixToRows() {
        const storeIds = selectedStoreIds();

        $rows.find('.sai-row').each(function () {
            const $row = $(this);
            const itemId = parseInt($row.find('.sai-row-item').val(), 10);

            if (!itemId) {
                hideRowStatus($row);
                return;
            }

            // A row "updates existing" when at least one selected store already
            // has a store_addon_items row for this addon_item.
            const hits = storeIds.filter((sid) => {
                const bucket = inventoryMatrix[sid];
                return bucket && Object.prototype.hasOwnProperty.call(bucket, itemId);
            });

            if (hits.length === 0) {
                hideRowStatus($row);
                return;
            }

            // Pre-fill from the first existing row so the seller sees what's already stocked.
            const first = inventoryMatrix[hits[0]][itemId];
            if (first) {
                $row.find('.sai-row-price').val(first.price ?? '');
                $row.find('.sai-row-cost').val(first.cost ?? '');
                $row.find('.sai-row-stock').val(first.stock ?? 0);
                $row.find('.sai-row-available').prop('checked', !!first.is_available);
            }

            showRowStatus($row, hits.length, storeIds.length);
        });
    }

    function showRowStatus($row, hits, totalStores) {
        const $status = $row.find('.sai-row-status');
        const $text = $row.find('.sai-row-status-text');
        if (hits < totalStores) {
            $text.text(
                statusSomeTpl
                    .replace('{hits}', hits)
                    .replace('{total}', totalStores),
            );
        } else {
            $text.text(statusAllLabel);
        }
        $status.prop('hidden', false);
        $row.addClass('sai-row-has-existing');
    }

    function hideRowStatus($row) {
        $row.find('.sai-row-status').prop('hidden', true);
        $row.removeClass('sai-row-has-existing');
    }

    // -------------------------------------------------------------------
    // Impact hint + save guard
    // -------------------------------------------------------------------

    function updateImpactHint() {
        const storeCount = selectedStoreIds().length;
        const itemCount  = collectSelectedItemIds().length;
        const total = storeCount * itemCount;

        if (storeCount === 0 || itemCount === 0) {
            if (impactEmptyLabel) {
                $impactHint.text(impactEmptyLabel);
            }
            $saveBtn.prop('disabled', $('.sai-store-checkbox').length === 0);
            return;
        }

        const rendered = impactTemplate
            .replace('{stores}', storeCount)
            .replace('{items}', itemCount)
            .replace('{total}', total);
        $impactHint.text(rendered);
        $saveBtn.prop('disabled', false);
    }

    // -------------------------------------------------------------------
    // Helpers — catalog defaults + lookups
    // -------------------------------------------------------------------

    function applyCatalogDefaults($row, $opt) {
        const priceRaw = $opt.data('price');
        const costRaw = $opt.data('cost');

        if (priceRaw !== undefined && priceRaw !== null && priceRaw !== '') {
            $row.find('.sai-row-price').val(priceRaw);
        }
        if (costRaw !== undefined && costRaw !== null && costRaw !== '') {
            $row.find('.sai-row-cost').val(costRaw);
        } else {
            $row.find('.sai-row-cost').val('');
        }
    }

    function fetchItemsForGroup(groupId) {
        return axios
            .get(`${itemsForGroupBase}/${groupId}/items`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then((response) => response.data?.data || []);
    }
})();
