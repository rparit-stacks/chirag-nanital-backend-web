document.addEventListener('DOMContentLoaded', () => {
    const audienceEl = document.getElementById('audience_type');
    const targetTypeEl = document.getElementById('target_type');
    const usersEl = document.getElementById('select-users');
    const targetTypeWrapper = document.getElementById('target_type_wrapper');
    const targetIdWrapper = document.getElementById('target_id_wrapper');

    const typeMap = {
        seller: 'seller',
        rider: 'rider',
        customer: 'customer'
    };

    const toggleDisplay = (el, show) => {
        if (el) el.style.display = show ? '' : 'none';
    };

    const syncFormVisibility = () => {
        const audienceType = audienceEl?.value || 'customer';
        const targetType = targetTypeEl?.value;

        usersEl?.setAttribute('data-type', typeMap[audienceType] || 'customer');

        const isCustomer = audienceType === 'customer';

        // Toggle sections
        toggleDisplay(targetTypeWrapper, isCustomer);
        toggleDisplay(targetIdWrapper, isCustomer && !!targetType);
        initTomSelect();
        initTargetTomSelect();
    };

    audienceEl?.addEventListener('change', syncFormVisibility);
    targetTypeEl?.addEventListener('change', syncFormVisibility);

    syncFormVisibility();
});

let tomSelectUserInstance = null;
let tomSelectTargetInstance = null;

const getZoneIds = () => {
    const zoneEl = document.getElementById('select-zones');
    if (!zoneEl) return [];

    let values = [];

    if (zoneEl.tomselect) {
        values = zoneEl.tomselect.getValue();
    } else if (zoneEl.value) {
        values = zoneEl.value.split(',');
    }

    return (Array.isArray(values) ? values : [values])
        .flatMap(v => String(v).split(',')) // handles "14,1"
        .map(v => parseInt(v, 10))
        .filter(Number.isInteger);
};

const initTomSelect = () => {
    const userEl = document.getElementById('select-users');
    if (!userEl) return;

    if (tomSelectUserInstance) {
        tomSelectUserInstance.clear();
        tomSelectUserInstance.destroy();
    }

    tomSelectUserInstance = new TomSelect(userEl, {
        copyClassesToDropdown: false,
        dropdownParent: 'body',
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        placeholder: 'User name',

        load: (query, callback) => {
            if (!query) return callback();

            axios.get(`${base_url}/admin/users/search`, {
                params: {
                    search: query,
                    type: userEl.dataset.type || 'customer',
                    zone_ids: getZoneIds()
                }
            })
                .then(res => callback(res.data))
                .catch(() => callback());
        }
    });
};

const initTargetTomSelect = () => {
    const targetTypeMap = {
        product: {
            title: 'Product',
            url: '/api/products/search'
        },
        featured_section: {
            title: 'Featured Section',
            url: '/api/featured-sections/search'
        },
        brand: {
            title: 'Brand',
            url: '/api/brands/search'
        },
        category: {
            title: 'Category',
            url: '/api/categories/search'
        },
        store: {
            title: 'Store',
            url: '/api/stores/search'
        },
    };
    const targetEl = document.getElementById('target_id');
    const targetTypeEl = document.getElementById('target_type');
    if (!targetEl) return;

    if (tomSelectTargetInstance) {
        tomSelectTargetInstance.clear();
        tomSelectTargetInstance.destroy();
    }


    tomSelectTargetInstance = new TomSelect(targetEl, {
        copyClassesToDropdown: false,
        dropdownParent: 'body',
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        placeholder: targetTypeMap[targetTypeEl.value] ? targetTypeMap[targetTypeEl.value].title : 'Select Target',

        load: (query, callback) => {
            if (!query) return callback();

            axios.get(`${base_url}${targetTypeMap[targetTypeEl.value].url}`, {
                params: {
                    search: query,
                    zone_ids: getZoneIds()
                }
            })
                .then(res => callback(res.data))
                .catch(() => callback());
        }
    });
};
// Reset Users TomSelect when Zones selection changes
document.addEventListener('DOMContentLoaded', () => {
    const zoneEl = document.getElementById('select-zones');
    if (!zoneEl) return;

    const resetTomSelect = (instance) => {
        if (!instance) return;

        try {
            instance.clear();
            instance.clearOptions();
        } catch (e) {
            console.warn('TomSelect reset failed:', e);
        }
    };

    zoneEl.addEventListener('change', () => {
        [tomSelectUserInstance, tomSelectTargetInstance].forEach(resetTomSelect);
    });
});
