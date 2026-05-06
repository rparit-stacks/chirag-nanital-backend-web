'use strict';

/**
 * Seller :: Addon Groups
 *
 * Wires the listing page (delete handler) and the single-page create/edit
 * form (dynamic item rows + selection-type chip highlight).
 *
 * The form submit itself is handled by the global `form.form-submit`
 * listener in public/assets/js/custom.js (axios + toast + datatable reload
 * + redirect_url navigation). Do NOT add a submit listener here.
 *
 * Globals expected: base_url, panel, handleDelete (from custom.js).
 */

// ---------------------------------------------------------------------------
// Listing page – delete handler
// ---------------------------------------------------------------------------
document.addEventListener('click', function (event) {
    if (typeof handleDelete === 'function') {
        handleDelete(
            event,
            '.delete-addon-group',
            `/${panel}/addon-groups/`,
            'You are about to delete this Add-on Group and all of its items.'
        );
    }
});

// ---------------------------------------------------------------------------
// Form page – dynamic item rows + selection-type chip highlight
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('addon-group-form');
    if (!form) {
        return; // Not on the form page.
    }

    const wrapper    = document.getElementById('addon-items-wrapper');
    const emptyState = document.getElementById('addon-empty-state');
    const addBtn     = document.getElementById('addon-add-item');
    const template   = document.getElementById('addon-item-template');

    let nextIndex = wrapper.querySelectorAll('.addon-item-row').length;

    function refreshEmptyState() {
        const hasRows = wrapper.querySelectorAll('.addon-item-row').length > 0;
        emptyState.style.display = hasRows ? 'none' : 'block';
    }

    function renumberRows() {
        wrapper.querySelectorAll('.addon-item-row').forEach(function (row, i) {
            const handle = row.querySelector('.addon-item-handle');
            if (handle) {
                handle.textContent = (i + 1);
            }
        });
        refreshEmptyState();
    }

    function addRow() {
        const html = template.innerHTML.replaceAll('__INDEX__', nextIndex++);
        const tmp  = document.createElement('div');
        tmp.innerHTML = html.trim();
        wrapper.appendChild(tmp.firstElementChild);
        renumberRows();
    }

    if (addBtn) {
        addBtn.addEventListener('click', addRow);
    }

    wrapper.addEventListener('click', function (e) {
        const removeBtn = e.target.closest('.addon-item-remove');
        if (!removeBtn) return;
        const row = removeBtn.closest('.addon-item-row');
        if (row) row.remove();
        renumberRows();
    });

    // Selection-type chip highlight
    document.querySelectorAll('.selection-type-input').forEach(function (input) {
        input.addEventListener('change', function () {
            document.querySelectorAll('.selection-type-card').forEach(function (c) {
                c.classList.remove('is-selected');
            });
            if (this.checked) {
                this.closest('.selection-type-card').classList.add('is-selected');
            }
        });
    });

    refreshEmptyState();
});
