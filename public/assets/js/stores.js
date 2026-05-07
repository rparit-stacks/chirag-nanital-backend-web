// Store location map — Leaflet + Nominatim (no Google Maps API key needed)

let map, marker;
let zonePolygons = [];

const DEFAULT_CENTER = [20.5937, 78.9629]; // India center

async function initMap() {
    const latEl = document.getElementById('latitude');
    const lngEl = document.getElementById('longitude');

    const hasExisting = latEl?.value && lngEl?.value;
    const startCenter = hasExisting
        ? [parseFloat(latEl.value), parseFloat(lngEl.value)]
        : DEFAULT_CENTER;
    const startZoom = hasExisting ? 16 : 5;

    // ── Init map ────────────────────────────────────────────────────────────
    map = L.map('map').setView(startCenter, startZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    // ── Draggable marker ────────────────────────────────────────────────────
    marker = L.marker(startCenter, { draggable: true }).addTo(map);

    marker.on('dragend', async () => {
        const { lat, lng } = marker.getLatLng();
        await reverseGeocode(lat, lng);
    });

    map.on('click', async e => {
        marker.setLatLng(e.latlng);
        await reverseGeocode(e.latlng.lat, e.latlng.lng);
    });

    // ── Search control (Nominatim) ──────────────────────────────────────────
    const SearchControl = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const wrapper = L.DomUtil.create('div');
            wrapper.innerHTML = `
                <div style="background:#fff;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.25);padding:8px;width:290px;">
                    <p style="margin:0 0 6px;font-size:12px;color:#555;font-weight:600;">Search or click on map to set location</p>
                    <input id="store-search-input" type="text" placeholder="Type city, address…"
                        style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #ddd;
                               border-radius:4px;font-size:13px;outline:none;" />
                    <ul id="store-suggestions"
                        style="list-style:none;margin:4px 0 0;padding:0;max-height:200px;overflow-y:auto;
                               border:1px solid #e5e5e5;border-radius:4px;display:none;background:#fff;
                               position:absolute;z-index:9999;width:274px;box-shadow:0 4px 12px rgba(0,0,0,.15);">
                    </ul>
                </div>`;
            L.DomEvent.disableClickPropagation(wrapper);
            L.DomEvent.disableScrollPropagation(wrapper);
            return wrapper;
        },
    });
    map.addControl(new SearchControl());

    const searchInput = document.getElementById('store-search-input');
    const suggestions = document.getElementById('store-suggestions');
    let searchTimer;

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        if (q.length < 3) { suggestions.style.display = 'none'; return; }
        searchTimer = setTimeout(() => nominatimSearch(q), 400);
    });

    async function nominatimSearch(q) {
        try {
            const res = await fetch(
                `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=6&addressdetails=1`,
                { headers: { 'Accept-Language': 'en' } }
            );
            const results = await res.json();
            suggestions.innerHTML = '';
            if (!results.length) { suggestions.style.display = 'none'; return; }

            results.forEach(r => {
                const li = document.createElement('li');
                li.textContent = r.display_name;
                li.style.cssText = 'padding:8px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0;';
                li.addEventListener('mouseenter', () => li.style.background = '#f5f5f5');
                li.addEventListener('mouseleave', () => li.style.background = '');
                li.addEventListener('click', async () => {
                    const lat = parseFloat(r.lat), lng = parseFloat(r.lon);
                    map.setView([lat, lng], 16);
                    marker.setLatLng([lat, lng]);
                    suggestions.style.display = 'none';
                    searchInput.value = '';
                    await fillFormFields(lat, lng, r);
                });
                suggestions.appendChild(li);
            });
            suggestions.style.display = 'block';
        } catch (e) {
            console.warn('Nominatim search error:', e);
        }
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('#store-suggestions') && e.target !== searchInput) {
            suggestions.style.display = 'none';
        }
    });

    // ── Delivery zones overlay ──────────────────────────────────────────────
    loadDeliveryZones();

    // Hide legacy autocomplete container
    const legacyContainer = document.getElementById('autocomplete-container');
    if (legacyContainer) legacyContainer.style.display = 'none';
}

// Reverse geocode a lat/lng and fill form fields
async function reverseGeocode(lat, lng) {
    setLatLng(lat, lng);
    try {
        const res = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`,
            { headers: { 'Accept-Language': 'en' } }
        );
        const r = await res.json();
        await fillFormFields(lat, lng, r);
    } catch (e) {
        console.error('Reverse geocode failed:', e);
        marker.bindPopup(`${lat.toFixed(5)}, ${lng.toFixed(5)}`).openPopup();
    }
}

// Fill all form fields from a Nominatim result object
async function fillFormFields(lat, lng, r) {
    setLatLng(lat, lng);
    const addr = r.address || {};
    const city = addr.city || addr.town || addr.village || addr.municipality || '';
    const state = addr.state || '';
    const country = addr.country || '';
    const postcode = addr.postcode || '';
    const street = [addr.house_number, addr.road].filter(Boolean).join(' ');
    const formatted = r.display_name || `${lat}, ${lng}`;

    setField('city', city);
    setField('state', state);
    setField('zipcode', postcode);
    setField('landmark', street);
    setField('address', formatted);

    if (country) {
        const sel = document.getElementById('select-countries');
        if (sel?.tomselect) loadCountryAndSetValue(sel.tomselect, country);
    }

    marker.bindPopup(
        `<strong>${city || 'Selected location'}</strong><br>
         <span style="font-size:12px;color:#555">${formatted}</span><br>
         <small style="color:#999">Drag pin to fine-tune</small>`
    ).openPopup();
}

function setLatLng(lat, lng) {
    setField('latitude', lat);
    setField('longitude', lng);
}

function setField(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val ?? '';
}

// Draw all delivery zones on map (read-only reference)
async function loadDeliveryZones() {
    try {
        const res = await fetch('/api/delivery-zone?per_page=500', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        const items = Array.isArray(json?.data?.data) ? json.data.data
            : Array.isArray(json?.data) ? json.data : [];

        zonePolygons.forEach(p => map.removeLayer(p));
        zonePolygons = [];

        items.forEach(zone => {
            if (!Array.isArray(zone.boundary_json) || zone.boundary_json.length < 3) return;
            const latlngs = zone.boundary_json
                .map(pt => [parseFloat(pt.lat), parseFloat(pt.lng)])
                .filter(p => !isNaN(p[0]) && !isNaN(p[1]));
            if (latlngs.length < 3) return;

            const poly = L.polygon(latlngs, {
                color: '#0ea5e9', weight: 1.5, opacity: 0.8,
                fillColor: '#0ea5e9', fillOpacity: 0.08,
            }).addTo(map);
            poly.bindPopup(`<strong>${zone.name || 'Delivery Zone'}</strong>`);
            zonePolygons.push(poly);
        });
    } catch (e) {
        console.warn('Could not load delivery zones:', e);
    }
}

// ── Boot ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Only init map if #map element exists on this page
    if (document.getElementById('map')) {
        initMap();
    }

    // DataTable filters — only on stores list page
    if (typeof $ !== 'undefined' && $('#stores-table').length) {
        const storesTable = $('#stores-table').DataTable();
        $('#verificationStatus, #visibilityStatus').on('change', function () {
            storesTable.ajax.reload(null, false);
        });
        $('#stores-table').on('preXhr.dt', function (e, settings, data) {
            data.verification_status = $('#verificationStatus').val();
            data.visibility_status = $('#visibilityStatus').val();
        });
    }
});
