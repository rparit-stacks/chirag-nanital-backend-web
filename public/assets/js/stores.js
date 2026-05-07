// Leaflet + Nominatim (reverse geocoding) — no Google Maps API key required
let map;
let marker;
let zonePolygons = [];

const DEFAULT_CENTER = [40.749933, -73.98633];

async function initMap() {
    const existingLat = document.getElementById('latitude')?.value;
    const existingLng = document.getElementById('longitude')?.value;
    let startCenter = DEFAULT_CENTER;
    let startZoom = 13;
    if (existingLat && existingLng) {
        startCenter = [parseFloat(existingLat), parseFloat(existingLng)];
        startZoom = 16;
    }

    map = L.map('map').setView(startCenter, startZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    // ── Draggable marker ────────────────────────────────────────────────────
    marker = L.marker(startCenter, { draggable: true }).addTo(map);
    marker.on('dragend', async () => {
        const { lat, lng } = marker.getLatLng();
        await handleLocationSelection(lat, lng);
    });

    // ── Map click to move marker ────────────────────────────────────────────
    map.on('click', async e => {
        marker.setLatLng(e.latlng);
        await handleLocationSelection(e.latlng.lat, e.latlng.lng);
    });

    // ── Search box (Nominatim forward geocoding) ────────────────────────────
    const searchContainer = document.createElement('div');
    searchContainer.innerHTML = `
        <div id="store-search-card" style="background:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.3);margin:10px;padding:10px;min-width:300px;">
            <p style="margin:0 0 8px;font-weight:bold;color:#333;font-size:14px;">Search for a place or click on map:</p>
            <input id="store-search-input" type="text" placeholder="Type to search…"
                style="width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;" />
            <ul id="store-suggestions" style="list-style:none;margin:4px 0 0;padding:0;max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;display:none;background:#fff;"></ul>
        </div>`;

    const searchControl = L.control({ position: 'topleft' });
    searchControl.onAdd = () => searchContainer;
    searchControl.addTo(map);

    const searchInput = document.getElementById('store-search-input');
    const suggestionsList = document.getElementById('store-suggestions');
    let searchTimer;

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        if (q.length < 3) { suggestionsList.style.display = 'none'; return; }
        searchTimer = setTimeout(() => fetchSuggestions(q), 400);
    });

    async function fetchSuggestions(q) {
        try {
            const res = await fetch(
                `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=5&addressdetails=1`,
                { headers: { 'Accept-Language': 'en' } }
            );
            const results = await res.json();
            suggestionsList.innerHTML = '';
            if (!results.length) { suggestionsList.style.display = 'none'; return; }
            results.forEach(r => {
                const li = document.createElement('li');
                li.textContent = r.display_name;
                li.style.cssText = 'padding:6px 8px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0;';
                li.addEventListener('mouseenter', () => li.style.background = '#f5f5f5');
                li.addEventListener('mouseleave', () => li.style.background = '');
                li.addEventListener('click', async () => {
                    const lat = parseFloat(r.lat);
                    const lng = parseFloat(r.lon);
                    map.setView([lat, lng], 16);
                    marker.setLatLng([lat, lng]);
                    searchInput.value = r.display_name;
                    suggestionsList.style.display = 'none';
                    await handleLocationSelection(lat, lng, r);
                });
                suggestionsList.appendChild(li);
            });
            suggestionsList.style.display = 'block';
        } catch (e) {
            console.warn('Nominatim search error:', e);
        }
    }

    document.addEventListener('click', e => {
        if (!searchContainer.contains(e.target)) suggestionsList.style.display = 'none';
    });

    // ── Draw delivery zones ─────────────────────────────────────────────────
    renderDeliveryZonesOnMap().catch(e => console.warn('Delivery zones render error:', e));

    // Hide old autocomplete container if present
    const existingContainer = document.getElementById('autocomplete-container');
    if (existingContainer) existingContainer.style.display = 'none';
}

async function handleLocationSelection(lat, lng, nominatimResult = null) {
    document.getElementById('latitude').value = lat;
    document.getElementById('longitude').value = lng;

    let addressData = {};
    let formattedAddress = '';

    if (nominatimResult) {
        // Data already available from forward geocode
        const addr = nominatimResult.address || {};
        addressData = {
            city: addr.city || addr.town || addr.village || addr.municipality || '',
            state: addr.state || '',
            country: addr.country || '',
            postal_code: addr.postcode || '',
            street: addr.road || '',
            street_number: addr.house_number || '',
        };
        formattedAddress = nominatimResult.display_name;
    } else {
        // Reverse geocode via Nominatim
        try {
            const res = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`,
                { headers: { 'Accept-Language': 'en' } }
            );
            const r = await res.json();
            formattedAddress = r.display_name || `${lat}, ${lng}`;
            const addr = r.address || {};
            addressData = {
                city: addr.city || addr.town || addr.village || addr.municipality || '',
                state: addr.state || '',
                country: addr.country || '',
                postal_code: addr.postcode || '',
                street: addr.road || '',
                street_number: addr.house_number || '',
            };
        } catch (e) {
            console.error('Reverse geocode error:', e);
            formattedAddress = `${lat}, ${lng}`;
        }
    }

    // Fill country via TomSelect if available
    if (addressData.country) {
        const selectCountries = document.getElementById('select-countries');
        if (selectCountries?.tomselect) {
            loadCountryAndSetValue(selectCountries.tomselect, addressData.country);
        }
    }

    const streetFull = [addressData.street_number, addressData.street].filter(Boolean).join(' ');

    if (document.getElementById('city')) document.getElementById('city').value = addressData.city;
    if (document.getElementById('state')) document.getElementById('state').value = addressData.state;
    if (document.getElementById('zipcode')) document.getElementById('zipcode').value = addressData.postal_code;
    if (document.getElementById('landmark')) document.getElementById('landmark').value = streetFull;
    if (document.getElementById('address')) document.getElementById('address').value = formattedAddress;

    // Show popup on marker
    marker.bindPopup(`<strong>${addressData.city || 'Selected Location'}</strong><br>${formattedAddress}<br><small style="color:#666">Drag marker to adjust position</small>`)
        .openPopup();
}

async function renderDeliveryZonesOnMap() {
    zonePolygons.forEach(p => map.removeLayer(p));
    zonePolygons = [];

    const res = await fetch('/api/delivery-zone?per_page=500', { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('Failed to load delivery zones');
    const json = await res.json();
    const items = (json?.data?.data && Array.isArray(json.data.data)) ? json.data.data
        : (Array.isArray(json.data) ? json.data : []);
    if (!items.length) return;

    items.forEach(zone => {
        if (!Array.isArray(zone.boundary_json) || zone.boundary_json.length < 3) return;
        const latlngs = zone.boundary_json
            .map(pt => [parseFloat(pt.lat), parseFloat(pt.lng)])
            .filter(p => !isNaN(p[0]) && !isNaN(p[1]));
        if (latlngs.length < 3) return;

        const polygon = L.polygon(latlngs, {
            color: '#0066ff', weight: 2, opacity: 0.8,
            fillColor: '#1a73e8', fillOpacity: 0.08,
        }).addTo(map);

        polygon.bindPopup(`<strong>${zone.name || 'Service Zone'}</strong>`);
        zonePolygons.push(polygon);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initMap();

    const storesTable = $('#stores-table').DataTable();
    $('#verificationStatus, #visibilityStatus').on('change', function () {
        storesTable.ajax.reload(null, false);
    });
    $('#stores-table').on('preXhr.dt', function (e, settings, data) {
        data.verification_status = $('#verificationStatus').val();
        data.visibility_status = $('#visibilityStatus').val();
    });
});
