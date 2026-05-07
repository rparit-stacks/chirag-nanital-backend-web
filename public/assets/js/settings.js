// Default location map — Leaflet + Nominatim (no Google Maps API key needed)

let defaultLocationMap;
let defaultLocationMarker;

const DEFAULT_CENTER = [20.5937, 78.9629]; // India center

function initDefaultLocationMap() {
    const latEl = document.getElementById('default-latitude');
    const lngEl = document.getElementById('default-longitude');

    const hasExisting = latEl?.value && lngEl?.value;
    const startCenter = hasExisting
        ? [parseFloat(latEl.value), parseFloat(lngEl.value)]
        : DEFAULT_CENTER;
    const startZoom = hasExisting ? 14 : 5;

    // ── Init map ────────────────────────────────────────────────────────────
    defaultLocationMap = L.map('default-location-map').setView(startCenter, startZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(defaultLocationMap);

    // ── Draggable marker ────────────────────────────────────────────────────
    defaultLocationMarker = L.marker(startCenter, { draggable: true })
        .addTo(defaultLocationMap);

    defaultLocationMarker.on('dragend', async () => {
        const { lat, lng } = defaultLocationMarker.getLatLng();
        await reverseAndShow(lat, lng);
    });

    defaultLocationMap.on('click', async e => {
        defaultLocationMarker.setLatLng(e.latlng);
        await reverseAndShow(e.latlng.lat, e.latlng.lng);
    });

    // ── Search control ──────────────────────────────────────────────────────
    const SearchControl = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const wrapper = L.DomUtil.create('div');
            wrapper.innerHTML = `
                <div style="background:#fff;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.25);padding:8px;width:280px;">
                    <input id="settings-search-input" type="text" placeholder="Search location…"
                        style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #ddd;
                               border-radius:4px;font-size:13px;outline:none;" />
                    <ul id="settings-suggestions"
                        style="list-style:none;margin:4px 0 0;padding:0;max-height:200px;overflow-y:auto;
                               border:1px solid #e5e5e5;border-radius:4px;display:none;background:#fff;
                               position:absolute;z-index:9999;width:264px;box-shadow:0 4px 12px rgba(0,0,0,.15);">
                    </ul>
                </div>`;
            L.DomEvent.disableClickPropagation(wrapper);
            L.DomEvent.disableScrollPropagation(wrapper);
            return wrapper;
        },
    });
    defaultLocationMap.addControl(new SearchControl());

    const searchInput = document.getElementById('settings-search-input');
    const suggestions = document.getElementById('settings-suggestions');
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
                `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=5`,
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
                    defaultLocationMap.setView([lat, lng], 14);
                    defaultLocationMarker.setLatLng([lat, lng]);
                    suggestions.style.display = 'none';
                    searchInput.value = '';
                    setLatLng(lat, lng);
                    showPopup(lat, lng, r.display_name);
                });
                suggestions.appendChild(li);
            });
            suggestions.style.display = 'block';
        } catch (e) {
            console.warn('Nominatim error:', e);
        }
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('#settings-suggestions') && e.target !== searchInput) {
            suggestions.style.display = 'none';
        }
    });

    // ── Manual input sync ───────────────────────────────────────────────────
    latEl?.addEventListener('change', syncMarkerFromInputs);
    lngEl?.addEventListener('change', syncMarkerFromInputs);

    // Show popup if existing location set
    if (hasExisting) {
        showPopup(startCenter[0], startCenter[1], 'Current default location');
    }
}

async function reverseAndShow(lat, lng) {
    setLatLng(lat, lng);
    try {
        const res = await fetch(
            `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`,
            { headers: { 'Accept-Language': 'en' } }
        );
        const r = await res.json();
        showPopup(lat, lng, r.display_name || `${lat.toFixed(5)}, ${lng.toFixed(5)}`);
    } catch (_) {
        showPopup(lat, lng, `${lat.toFixed(5)}, ${lng.toFixed(5)}`);
    }
}

function syncMarkerFromInputs() {
    const lat = parseFloat(document.getElementById('default-latitude').value);
    const lng = parseFloat(document.getElementById('default-longitude').value);
    if (!isNaN(lat) && !isNaN(lng)) {
        defaultLocationMarker.setLatLng([lat, lng]);
        defaultLocationMap.setView([lat, lng], 14);
        showPopup(lat, lng, 'Manual location entry');
    }
}

function setLatLng(lat, lng) {
    const latEl = document.getElementById('default-latitude');
    const lngEl = document.getElementById('default-longitude');
    if (latEl) latEl.value = lat;
    if (lngEl) lngEl.value = lng;
}

function showPopup(lat, lng, label) {
    defaultLocationMarker
        .bindPopup(`<strong>${label}</strong><br><small style="color:#888">${lat.toFixed(5)}, ${lng.toFixed(5)}</small>`)
        .openPopup();
}

// ── Boot ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('default-location-map')) {
        initDefaultLocationMap();
    }
});
