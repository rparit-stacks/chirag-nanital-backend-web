'use strict';

/**
 * Seller panel — Store Addon Inventory: listing page.
 *
 * Responsibilities:
 *  - Inject addon_group_id + store_id filters into the datatable ajax payload.
 *  - Wire the single-row "edit" modal (fetch row + populate on show.bs.modal).
 *  - Cascade addon_group -> addon_item inside the edit modal.
 *  - Pre-fill price / cost from catalog defaults OR existing inventory snapshot.
 *  - Reload the datatable via the global #refresh button / reset filters button.
 *  - Delegate delete clicks to the global handleDelete helper.
 *
 * The bulk "create" flow now lives on its own page at
 * /seller/store-addon-items/create — see store-addon-items-bulk.js.
 */
$(document).ready(function () {
    const tableId = 'store-addon-items-table';
    const $table = $('#' + tableId);

    if (!$table.length) {
        return;
    }

    // Inject filters into every ajax request.
    $table.on('preXhr.dt', function (e, settings, data) {
        data.addon_group_id = $('#filter-addon-group').val();
        data.store_id = $('#filter-store').val();
    });

    // Reload the table when a filter changes.
    $('#filter-addon-group, #filter-store').on('change', function () {
        if ($.fn.DataTable.isDataTable('#' + tableId)) {
            $('#' + tableId).DataTable().ajax.reload(null, false);
        }
    });

    // Clear filters.
    $('#reset-filters').on('click', function () {
        $('#filter-addon-group').val('');
        $('#filter-store').val('');
        if ($.fn.DataTable.isDataTable('#' + tableId)) {
            $('#' + tableId).DataTable().ajax.reload(null, false);
        }
    });

    // ---------- Edit modal (single row) ----------

    // Cascade: addon group -> items (edit modal).
    $('#addon-group-select').on('change', function () {
        const groupId = $(this).val();
        const $items = $('#addon-item-select');
        $items.empty().append(new Option('Loading…', ''));

        if (!groupId) {
            $items.empty().append(new Option('Select', ''));
            return;
        }

        fetchItemsForGroup(groupId).then(function (rows) {
            $items.empty().append(new Option('Select', ''));
            rows.forEach(function (row) {
                const opt = new Option(row.title, row.id);
                opt.dataset.price = row.price ?? '';
                opt.dataset.cost  = row.cost ?? '';
                $items.append(opt);
            });
        }).catch(function () {
            $items.empty().append(new Option('Select', ''));
        });
    });

    // When the seller picks an addon item in the edit modal, prefill the inputs.
    // Priority:
    //   1. If there's already a store_addon_items row for (store, addon_item),
    //      show its stock / price / cost / is_available — the submit will UPSERT
    //      so the seller can edit the existing row.
    //   2. Otherwise fall back to the addon item's catalog defaults (price/cost).
    $('#addon-item-select').on('change', function () {
        const $opt = $(this).find(':selected');
        const itemId = $opt.val();
        if (!itemId) {
            return;
        }
        const storeId = $('#store-addon-item-modal select[name="store_id"]').val();
        const $modal  = $('#store-addon-item-modal');

        const applyDefaults = function () {
            applyItemDefaultsToInputs(
                $opt.data('price'),
                $opt.data('cost'),
                $modal.find('input[name="price"]'),
                $modal.find('input[name="cost"]'),
            );
        };

        if (!storeId) {
            applyDefaults();
            return;
        }

        fetchInventoryState(storeId, itemId).then(function (state) {
            if (state && state.exists) {
                $modal.find('input[name="price"]').val(state.price ?? '');
                $modal.find('input[name="cost"]').val(state.cost ?? '');
                $modal.find('input[name="stock"]').val(state.stock ?? 0);
                $modal.find('input[name="is_available"]').prop('checked', !!state.is_available);
            } else {
                applyDefaults();
            }
        }).catch(applyDefaults);
    });
});

/**
 * Populate the single-row edit modal when opened from an "Edit" row button.
 */
document.addEventListener('show.bs.modal', function (event) {
    if (event.target.id !== 'store-addon-item-modal') {
        return;
    }

    const triggerButton = event.relatedTarget;
    const rowId = triggerButton ? triggerButton.getAttribute('data-id') : null;
    const form = event.target.querySelector('form.form-submit');
    if (!form) {
        return;
    }

    const modalTitle = event.target.querySelector('.modal-title');
    const submitButton = event.target.querySelector('button[type="submit"]');

    const resetForm = function () {
        form.reset();
        const methodInput = form.querySelector('input[name="_method"]');
        if (methodInput) methodInput.parentNode.removeChild(methodInput);

        const itemsSelect = form.querySelector('#addon-item-select');
        if (itemsSelect) {
            itemsSelect.innerHTML = '';
            itemsSelect.appendChild(new Option('Select', ''));
        }
    };

    if (!rowId) {
        // Edit modal without a row id is not supported (create uses the bulk page).
        resetForm();
        return;
    }

    axios.get(`${base_url}/${panel}/store-addon-items/${rowId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(function (response) {
            const data = response.data?.data || {};
            resetForm();

            form.querySelector('select[name="store_id"]').value = data.store_id || '';

            const groupSelect = form.querySelector('#addon-group-select');
            if (groupSelect) {
                groupSelect.value = data.addon_group_id || '';
            }

            const itemSelect = form.querySelector('#addon-item-select');
            if (itemSelect) {
                itemSelect.innerHTML = '';
                itemSelect.appendChild(new Option('Select', ''));
                if (data.addon_item_id) {
                    const opt = new Option(data.addon_item_title || '', data.addon_item_id, true, true);
                    itemSelect.appendChild(opt);
                }
            }

            form.querySelector('input[name="price"]').value = data.price ?? '';
            form.querySelector('input[name="cost"]').value = data.cost ?? '';
            form.querySelector('input[name="stock"]').value = data.stock ?? 0;
            form.querySelector('input[name="is_available"]').checked = !!data.is_available;

            // Append a method-spoofing field to POST to /{id} as update.
            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'POST';
            form.appendChild(method);

            form.setAttribute('action', `${base_url}/${panel}/store-addon-items/${rowId}`);
            if (modalTitle) modalTitle.textContent = form.dataset.editTitle || 'Edit';
            if (submitButton) submitButton.textContent = form.dataset.editSubmit || 'Update';
        })
        .catch(function (err) {
            console.error('Failed to load store addon item', err);
        });
});

document.addEventListener('click', function (event) {
    handleDelete(
        event,
        '.delete-store-addon-item',
        `/${panel}/store-addon-items/`,
        'You are about to delete this store addon inventory row.'
    );
});

// -------------------- helpers --------------------

/**
 * Fetch addon items that belong to a given seller-owned addon group.
 * Returns an array of { id, title, price, cost, addon_group_id } objects.
 */
function fetchItemsForGroup(groupId) {
    return axios
        .get(`${base_url}/${panel}/store-addon-items/lookup/groups/${groupId}/items`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (response) {
            return response.data?.data || [];
        });
}

/**
 * Fetch the current inventory state for a (store, addon_item) pair.
 * Returns an object { exists: bool, id?, price?, cost?, stock?, is_available? }.
 * When no row is stocked yet the server responds with { exists: false }.
 */
function fetchInventoryState(storeId, itemId) {
    return axios
        .get(`${base_url}/${panel}/store-addon-items/lookup/state`, {
            params: { store_id: storeId, addon_item_id: itemId },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (response) {
            return response.data?.data || { exists: false };
        });
}

/**
 * Push the addon item's catalog default price / cost into the form's inputs.
 * Empty / undefined defaults are ignored.
 */
function applyItemDefaultsToInputs(priceRaw, costRaw, $priceInput, $costInput) {
    if (priceRaw !== undefined && priceRaw !== null && priceRaw !== '') {
        $priceInput.val(priceRaw);
    }
    if (costRaw !== undefined && costRaw !== null && costRaw !== '') {
        $costInput.val(costRaw);
    } else {
        // No catalog cost -> leave the input empty (cost is nullable).
        $costInput.val('');
    }
}
