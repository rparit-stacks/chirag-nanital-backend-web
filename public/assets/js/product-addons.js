'use strict';

/**
 * Seller :: Product Addon Attachments (mapping-only)
 *
 * Create mode  — multi-attach: pick products, then variants, then addon groups.
 *                For every (variant × group) pair, an attachment card is rendered
 *                with its own store × item matrix. All cards submit together as
 *                `attachments[*]`.
 * Edit mode    — single (variant × group) attachment, rendered from a server
 *                -embedded initial payload.
 *
 * This UI writes only the (store, variant, group, item) mapping. Pricing,
 * cost, stock, and availability for each addon item live in `store_addon_items`
 * and are managed on the dedicated "Addon Inventory" screen — they appear here
 * as read-only hints so the seller has context while offering the addon.
 *
 * Form submission itself is delegated to the global `form.form-submit` handler.
 * AJAX rule: always use axios (never fetch).
 *
 * Globals expected: axios, base_url, panel, csrfToken, primaryColor, Swal, TomSelect.
 */

// ---------------------------------------------------------------------------
// Listing page – detach handler
// ---------------------------------------------------------------------------
document.addEventListener('click', function (event) {
    const removeBtn = event.target.closest('.delete-product-addon');
    if (!removeBtn || typeof Swal === 'undefined') return;

    const compoundId = removeBtn.getAttribute('data-id') || '';
    const [variantId, groupId] = compoundId.split('-');
    if (!variantId || !groupId) return;

    event.preventDefault();
    Swal.fire({
        title: 'Are you sure?',
        html: 'You are about to detach this add-on group from the variant across all of its stores.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: primaryColor,
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, detach',
    }).then(function (result) {
        if (!result.isConfirmed) return;
        axios
            .delete(`${base_url}/${panel}/product-addons/${variantId}/${groupId}`, {
                headers: { 'X-CSRF-TOKEN': csrfToken },
            })
            .then(function (response) {
                const data = response.data;
                if (data.success) {
                    if (window.jQuery && jQuery('.data-table').length) {
                        jQuery('.data-table').DataTable().ajax.reload(null, false);
                    }
                    return Swal.fire('Detached!', data.message, 'success');
                }
                return Swal.fire('Error!', data.message, 'error');
            })
            .catch(function () {
                Swal.fire('Error!', 'There was a problem detaching the attachment.', 'error');
            });
    });
});

// ---------------------------------------------------------------------------
// Form page
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('product-addon-form');
    if (!form) return;

    const editInitial = document.getElementById('pa-initial-payload');
    if (editInitial) {
        initEditMode(editInitial);
    } else {
        initCreateMode();
    }
});

// ===========================================================================
// EDIT MODE — single (variant, group) attachment, single matrix
// ===========================================================================
function initEditMode(initialScript) {
    const matrixRoot = document.getElementById('pa-matrix-root');
    if (!matrixRoot) return;

    let payload;
    try {
        payload = JSON.parse(initialScript.textContent);
    } catch (e) {
        console.error('Failed to parse initial payload', e);
        return;
    }

    // In edit mode, field names stay flat (no "attachments[i]." prefix).
    renderMatrixInto(matrixRoot, payload, { namePrefix: '' });
}

// ===========================================================================
// CREATE MODE — multi-attach: products × variants × groups
// ===========================================================================
function initCreateMode() {
    const dataCard = document.querySelector('[data-matrix-base]');
    if (!dataCard) return;

    const attachmentsRoot   = document.getElementById('pa-attachments-root');
    const emptyState        = document.getElementById('pa-empty-state');
    const productsSelect    = document.getElementById('pa-products-multi');
    const variantsSelect    = document.getElementById('pa-variants-multi');
    const groupsSelect      = document.getElementById('pa-groups-multi');

    const lookupProducts      = dataCard.dataset.lookupProducts;
    const lookupGroups        = dataCard.dataset.lookupGroups;
    const lookupVariantsBulk  = dataCard.dataset.lookupVariantsBulk;
    const matrixBase          = dataCard.dataset.matrixBase;

    // Cache matrix payloads by `${variantId}-${groupId}` so we don't re-fetch.
    const matrixCache = new Map();

    // --------- Pickers ---------
    let variantsTS = null;

    const productsTS = new TomSelect(productsSelect, {
        copyClassesToDropdown: false,
        dropdownParent: 'body',
        controlInput: '<input>',
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        placeholder: 'Search products…',
        load: function (query, cb) {
            axios.get(lookupProducts, { params: { q: query } })
                .then(r => cb(r.data))
                .catch(() => cb());
        },
        onChange: function (productIds) {
            refreshVariantsOptions(productIds);
        },
    });

    variantsTS = new TomSelect(variantsSelect, {
        copyClassesToDropdown: false,
        dropdownParent: 'body',
        controlInput: '<input>',
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        placeholder: 'Pick variants…',
        onChange: recomputeAttachments,
    });
    variantsTS.disable();

    new TomSelect(groupsSelect, {
        copyClassesToDropdown: false,
        dropdownParent: 'body',
        controlInput: '<input>',
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        placeholder: 'Search add-on groups…',
        load: function (query, cb) {
            axios.get(lookupGroups, { params: { q: query } })
                .then(r => cb(r.data))
                .catch(() => cb());
        },
        onChange: recomputeAttachments,
    });

    function refreshVariantsOptions(productIds) {
        if (!variantsTS) return;

        const ids = (productIds || []).filter(Boolean);
        variantsTS.clear();
        variantsTS.clearOptions();

        if (ids.length === 0) {
            variantsTS.disable();
            recomputeAttachments();
            return;
        }

        variantsTS.enable();
        axios
            .get(lookupVariantsBulk, { params: { product_ids: ids } })
            .then(function (r) {
                r.data.forEach(function (row) { variantsTS.addOption(row); });
                variantsTS.refreshOptions(false);
            });
    }

    function selectedValues(ts) {
        const val = ts.getValue();
        if (Array.isArray(val)) return val.filter(Boolean);
        return val ? String(val).split(',').filter(Boolean) : [];
    }

    // --------- Render attachment cards (variant × group Cartesian) ---------
    function recomputeAttachments() {
        const variantIds = selectedValues(variantsTS);
        const groupIds   = selectedValues(groupsFromDom());

        attachmentsRoot.innerHTML = '';

        if (variantIds.length === 0 || groupIds.length === 0) {
            attachmentsRoot.classList.add('d-none');
            emptyState.classList.remove('d-none');
            return;
        }

        emptyState.classList.add('d-none');
        attachmentsRoot.classList.remove('d-none');

        // Summary strip
        const totalPairs = variantIds.length * groupIds.length;
        const header = document.createElement('div');
        header.className = 'd-flex align-items-center gap-2 mb-3';
        header.innerHTML = `<span class="text-muted small">${totalPairs} attachment${totalPairs > 1 ? 's' : ''} queued</span>`;
        attachmentsRoot.appendChild(header);

        // Build Cartesian product
        let idx = 0;
        variantIds.forEach(function (variantId) {
            const variantOpt = variantsTS.options[variantId] || {};
            groupIds.forEach(function (groupId) {
                const groupOpt  = groupsTSGetOpt(groupId);
                const card = buildAttachmentCard(idx, variantId, groupId, variantOpt, groupOpt);
                attachmentsRoot.appendChild(card);
                idx++;
            });
        });
    }

    function groupsFromDom() {
        // groupsSelect was initialised above; its TS instance is on the element.
        return groupsSelect.tomselect;
    }
    function groupsTSGetOpt(id) {
        return groupsSelect.tomselect && groupsSelect.tomselect.options[id] || {};
    }

    function buildAttachmentCard(attachmentIdx, variantId, groupId, variantOpt, groupOpt) {
        const card = document.createElement('div');
        card.className = 'card card-sm mb-3 attachment-card';
        card.dataset.attachmentIdx = attachmentIdx;
        card.dataset.variantId = variantId;
        card.dataset.groupId = groupId;

        const titleVariant = variantOpt.text || ('#' + variantId);
        const titleGroup   = groupOpt.text   || ('#' + groupId);

        card.innerHTML = `
            <div class="card-header d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="card-title mb-0">${escapeHtml(titleVariant)} × ${escapeHtml(titleGroup)}</h4>
                    <div class="text-muted small pa-card-sub">Loading stores & items…</div>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm pa-collapse-btn" aria-label="Collapse">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M6 9l6 6l6 -6"/>
                    </svg>
                </button>
            </div>
            <div class="card-body pa-card-body">
                <input type="hidden" name="attachments[${attachmentIdx}][product_variant_id]" value="${variantId}">
                <input type="hidden" name="attachments[${attachmentIdx}][addon_group_id]"     value="${groupId}">
                <div class="pa-matrix-slot"></div>
            </div>
        `;

        // Collapse toggle
        const body = card.querySelector('.pa-card-body');
        card.querySelector('.pa-collapse-btn').addEventListener('click', function () {
            body.classList.toggle('d-none');
        });

        // Fetch or reuse cached matrix payload
        const cacheKey = `${variantId}-${groupId}`;
        const slot = card.querySelector('.pa-matrix-slot');
        const sub  = card.querySelector('.pa-card-sub');

        const render = function (payload) {
            renderMatrixInto(slot, payload, { namePrefix: `attachments[${attachmentIdx}]` });
            const stores = (payload.stores || []).length;
            const items  = (payload.items  || []).length;
            sub.textContent = `${items} item${items !== 1 ? 's' : ''} · ${stores} store${stores !== 1 ? 's' : ''}`;
        };

        if (matrixCache.has(cacheKey)) {
            render(matrixCache.get(cacheKey));
        } else {
            axios.get(`${matrixBase}/${variantId}/${groupId}`)
                .then(function (r) {
                    matrixCache.set(cacheKey, r.data);
                    render(r.data);
                })
                .catch(function () {
                    sub.textContent = 'Failed to load.';
                });
        }

        return card;
    }
}

// ===========================================================================
// Shared — render a single store × item matrix into a container.
// ===========================================================================
function renderMatrixInto(root, payload, opts) {
    const stores    = payload.stores    || [];
    const items     = payload.items     || [];
    const existing  = payload.existing  || [];
    const inventory = payload.inventory || [];
    const prefix    = opts && opts.namePrefix ? opts.namePrefix : '';

    // Build a PHP-friendly field name.
    // - Create/multi mode: prefix = "attachments[3]"  →  "attachments[3][stores][0][apply]"
    // - Edit mode (no prefix):               suffix  →  "stores[0][apply]"  (strip the leading bracket-pair)
    const nameFor = function (suffix) {
        if (prefix) return `${prefix}${suffix}`;
        // suffix always starts with "[" — strip the first "[" and its matching "]" to drop the outer array.
        // Example: "[stores][0][apply]" → "stores[0][apply]"
        return suffix.replace(/^\[([^\]]+)\]/, '$1');
    };

    // Build a quick-lookup map for store-level inventory: key = `${storeId}-${itemId}`.
    const inventoryMap = new Map();
    inventory.forEach(function (row) {
        inventoryMap.set(`${row.store_id}-${row.addon_item_id}`, row);
    });

    root.innerHTML = '';

    if (stores.length === 0) {
        root.innerHTML = '<div class="alert alert-warning">This variant is not listed in any of your stores yet.</div>';
        return;
    }
    if (items.length === 0) {
        root.innerHTML = '<div class="alert alert-info">No active items in this add-on group.</div>';
        return;
    }

    // Summary strip with counter
    const counterId = 'pa-counter-' + Math.random().toString(36).slice(2, 8);
    const summary = document.createElement('div');
    summary.className = 'd-flex flex-wrap gap-3 mb-3 align-items-center';
    summary.innerHTML = `
        <span class="text-muted small">${items.length} items × ${stores.length} stores</span>
        <span class="store-chip" id="${counterId}">0 / ${stores.length}</span>
    `;
    root.appendChild(summary);

    stores.forEach(function (store, sIdx) {
        const accordion = document.createElement('div');
        accordion.className = 'store-accordion';
        accordion.dataset.storeId = store.id;

        const hasExisting = existing.some(e => Number(e.store_id) === Number(store.id));

        const header = document.createElement('div');
        header.className = 'store-accordion-header';
        header.innerHTML = `
            <label class="form-check form-switch mb-0" onclick="event.stopPropagation();">
                <input class="form-check-input pa-apply-toggle" type="checkbox"
                       name="${nameFor(`[stores][${sIdx}][apply]`)}" value="1" ${hasExisting ? 'checked' : ''}>
                <span class="form-check-label visually-hidden">Apply</span>
            </label>
            <input type="hidden" name="${nameFor(`[stores][${sIdx}][store_id]`)}" value="${store.id}">
            <div class="flex-fill">
                <div class="fw-medium">${escapeHtml(store.title)}</div>
                <div class="text-muted small pa-store-sub">—</div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M6 9l6 6l6 -6"/>
            </svg>
        `;
        accordion.appendChild(header);

        const body = document.createElement('div');
        body.className = 'store-accordion-body collapsed';

        const table = document.createElement('table');
        table.className = 'table table-sm mb-0';
        table.innerHTML = `
            <thead>
                <tr>
                    <th style="width:1%">Offer</th>
                    <th>Item</th>
                    <th>Store price</th>
                    <th>Stock</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody></tbody>
        `;
        const tbody = table.querySelector('tbody');

        items.forEach(function (item, iIdx) {
            const offered = existing.some(
                e => Number(e.store_id) === Number(store.id) && Number(e.addon_item_id) === Number(item.id)
            );
            const inv = inventoryMap.get(`${store.id}-${item.id}`);

            const priceCell = inv
                ? formatMoney(inv.price)
                : `<span class="text-muted">Default ${formatMoney(item.price)}</span>`;
            const stockCell = inv
                ? `${inv.stock}`
                : '<span class="text-muted">Not stocked</span>';
            const statusCell = inv
                ? (inv.is_available
                    ? '<span class="badge bg-success-lt">Available</span>'
                    : '<span class="badge bg-secondary-lt">Hidden</span>')
                : '<span class="badge bg-warning-lt">Set in Inventory</span>';

            const tr = document.createElement('tr');
            tr.className = 'addon-item-compact-row';
            tr.innerHTML = `
                <td>
                    <label class="form-check form-switch mb-0">
                        <input type="hidden" name="${nameFor(`[stores][${sIdx}][items][${iIdx}][addon_item_id]`)}" value="${item.id}">
                        <input class="form-check-input pa-offer-toggle" type="checkbox"
                               data-store-id="${store.id}" data-item-id="${item.id}"
                               ${offered ? 'checked' : ''}>
                    </label>
                </td>
                <td class="fw-medium">${escapeHtml(item.title)}</td>
                <td>${priceCell}</td>
                <td>${stockCell}</td>
                <td>${statusCell}</td>
            `;

            // Only submit the addon_item_id when the offer checkbox is on.
            const offerCb = tr.querySelector('.pa-offer-toggle');
            const hiddenId = tr.querySelector('input[type=hidden]');
            const syncOffered = function () {
                hiddenId.disabled = !offerCb.checked;
            };
            offerCb.addEventListener('change', syncOffered);
            syncOffered();

            tbody.appendChild(tr);
        });

        body.appendChild(table);
        accordion.appendChild(body);
        root.appendChild(accordion);

        header.addEventListener('click', function () {
            body.classList.toggle('collapsed');
        });

        const applyCheckbox = header.querySelector('.pa-apply-toggle');
        function syncApplied() {
            const on = applyCheckbox.checked;
            accordion.classList.toggle('is-applied', on);
            accordion.classList.toggle('is-disabled', !on);
            header.querySelector('.pa-store-sub').textContent = on
                ? `${items.length} items configurable`
                : 'Not applied';
            updateCounter();
        }
        applyCheckbox.addEventListener('change', syncApplied);
        syncApplied();
    });

    function updateCounter() {
        const counter = document.getElementById(counterId);
        if (!counter) return;
        const total   = root.querySelectorAll('.store-accordion').length;
        const applied = root.querySelectorAll('.store-accordion.is-applied').length;
        counter.textContent = `${applied} / ${total}`;
    }
    updateCounter();
}

function formatMoney(value) {
    if (value === null || value === undefined || value === '') return '—';
    const num = Number(value);
    if (!isFinite(num)) return escapeHtml(String(value));
    return num.toFixed(2);
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
        return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c];
    });
}
