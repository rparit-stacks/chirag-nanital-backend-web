// Leaflet + Leaflet.draw — no Google Maps API key required
let map;
let drawnPolygon = null;
let originalPolygonLayer = null;
let otherZoneLayers = [];
let drawControl;
let featureGroup;

const DEFAULT_CENTER = [40.749933, -73.98633];

function initMap() {
    // Read existing center from hidden inputs
    const centerLatInput = document.getElementById('center-latitude');
    const centerLngInput = document.getElementById('center-longitude');
    let startCenter = DEFAULT_CENTER;
    if (centerLatInput.value && centerLngInput.value) {
        startCenter = [parseFloat(centerLatInput.value), parseFloat(centerLngInput.value)];
    }

    map = L.map('map').setView(startCenter, 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    // ── Search box (Nominatim) ──────────────────────────────────────────────
    const searchContainer = document.createElement('div');
    searchContainer.style.cssText = 'position:relative;';
    searchContainer.innerHTML = `
        <div style="background:#fff;border-radius:5px;box-shadow:0 2px 8px rgba(0,0,0,.3);padding:8px;min-width:280px;">
            <p style="margin:0 0 6px;font-weight:bold;font-size:14px;">Search for a place:</p>
            <input id="osm-search-input" type="text" placeholder="Type to search…"
                style="width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #ccc;border-radius:4px;font-size:13px;" />
            <ul id="osm-suggestions" style="list-style:none;margin:4px 0 0;padding:0;max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;display:none;background:#fff;"></ul>
        </div>`;

    const searchControl = L.control({ position: 'topleft' });
    searchControl.onAdd = () => searchContainer;
    searchControl.addTo(map);

    let searchTimer;
    const searchInput = document.getElementById('osm-search-input');
    const suggestionsList = document.getElementById('osm-suggestions');

    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = searchInput.value.trim();
        if (q.length < 3) { suggestionsList.style.display = 'none'; return; }
        searchTimer = setTimeout(() => fetchSuggestions(q), 400);
    });

    async function fetchSuggestions(q) {
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&limit=5`, {
                headers: { 'Accept-Language': 'en' }
            });
            const results = await res.json();
            suggestionsList.innerHTML = '';
            if (!results.length) { suggestionsList.style.display = 'none'; return; }
            results.forEach(r => {
                const li = document.createElement('li');
                li.textContent = r.display_name;
                li.style.cssText = 'padding:6px 8px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0;';
                li.addEventListener('mouseenter', () => li.style.background = '#f5f5f5');
                li.addEventListener('mouseleave', () => li.style.background = '');
                li.addEventListener('click', () => {
                    map.setView([parseFloat(r.lat), parseFloat(r.lon)], 16);
                    searchInput.value = r.display_name;
                    suggestionsList.style.display = 'none';
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

    // ── Leaflet.draw setup ──────────────────────────────────────────────────
    featureGroup = new L.FeatureGroup().addTo(map);

    drawControl = new L.Control.Draw({
        edit: { featureGroup },
        draw: {
            polygon: {
                shapeOptions: { color: '#FF0000', fillColor: '#FF0000', fillOpacity: 0.2 },
                allowIntersection: false,
            },
            polyline: false, rectangle: false, circle: false,
            marker: false, circlemarker: false,
        },
    });
    map.addControl(drawControl);

    // ── Restore existing polygon ────────────────────────────────────────────
    const boundaryJsonInput = document.getElementById('boundary-json');
    if (boundaryJsonInput.value) {
        try {
            const pathArr = JSON.parse(boundaryJsonInput.value);
            if (Array.isArray(pathArr) && pathArr.length >= 3) {
                const latlngs = pathArr.map(c => [c.lat, c.lng]);
                originalPolygonLayer = L.polygon(latlngs, {
                    color: '#FF0000', fillColor: '#FF0000', fillOpacity: 0.2,
                }).addTo(featureGroup);
                drawnPolygon = originalPolygonLayer;
                updateBoundaryInput(drawnPolygon);
                map.fitBounds(drawnPolygon.getBounds());
            }
        } catch (e) { /* ignore */ }
    }

    // ── Draw events ─────────────────────────────────────────────────────────
    map.on(L.Draw.Event.CREATED, e => {
        if (drawnPolygon) featureGroup.removeLayer(drawnPolygon);
        drawnPolygon = e.layer;
        featureGroup.addLayer(drawnPolygon);
        updateBoundaryInput(drawnPolygon);
    });

    map.on(L.Draw.Event.EDITED, () => {
        featureGroup.eachLayer(layer => {
            if (layer instanceof L.Polygon) {
                drawnPolygon = layer;
                updateBoundaryInput(drawnPolygon);
            }
        });
    });

    map.on(L.Draw.Event.DELETED, () => {
        drawnPolygon = null;
        document.getElementById('boundary-json').value = '';
        document.getElementById('center-latitude').value = '';
        document.getElementById('center-longitude').value = '';
        document.getElementById('radius-km').value = '';
    });

    // ── Render other delivery zones ─────────────────────────────────────────
    renderOtherDeliveryZonesOnForm().catch(e => console.warn('Other zones error:', e));

    // ── Buttons ─────────────────────────────────────────────────────────────
    document.getElementById('clear-last')?.addEventListener('click', () => {
        if (drawnPolygon) {
            featureGroup.removeLayer(drawnPolygon);
            drawnPolygon = null;
            document.getElementById('boundary-json').value = '';
            document.getElementById('center-latitude').value = '';
            document.getElementById('center-longitude').value = '';
            document.getElementById('radius-km').value = '';
        }
    });

    document.getElementById('reset-zone')?.addEventListener('click', () => {
        if (originalPolygonLayer) {
            if (drawnPolygon) featureGroup.removeLayer(drawnPolygon);
            const origLatLngs = originalPolygonLayer.getLatLngs()[0].map(ll => [ll.lat, ll.lng]);
            drawnPolygon = L.polygon(origLatLngs, {
                color: '#FF0000', fillColor: '#FF0000', fillOpacity: 0.2,
            });
            featureGroup.addLayer(drawnPolygon);
            updateBoundaryInput(drawnPolygon);
            map.fitBounds(drawnPolygon.getBounds());
        }
    });
}

async function renderOtherDeliveryZonesOnForm() {
    otherZoneLayers.forEach(l => map.removeLayer(l));
    otherZoneLayers = [];

    const currentZoneIdEl = document.getElementById('current-zone-id');
    const currentZoneId = currentZoneIdEl ? parseInt(currentZoneIdEl.value) : null;

    const res = await fetch('/api/delivery-zone?per_page=500', { headers: { Accept: 'application/json' } });
    if (!res.ok) return;
    const json = await res.json();
    const items = (json?.data?.data && Array.isArray(json.data.data)) ? json.data.data
        : (Array.isArray(json.data) ? json.data : []);

    items.forEach(zone => {
        if (currentZoneId && zone.id === currentZoneId) return;
        if (!Array.isArray(zone.boundary_json) || zone.boundary_json.length < 3) return;
        const latlngs = zone.boundary_json
            .map(pt => [parseFloat(pt.lat), parseFloat(pt.lng)])
            .filter(p => !isNaN(p[0]) && !isNaN(p[1]));
        if (latlngs.length < 3) return;
        const layer = L.polygon(latlngs, {
            color: '#0066ff', weight: 2, opacity: 0.8,
            fillColor: '#1a73e8', fillOpacity: 0.08, interactive: false,
        }).addTo(map);
        otherZoneLayers.push(layer);
    });
}

function updateBoundaryInput(polygon) {
    const latlngs = polygon.getLatLngs()[0];
    const path = latlngs.map(ll => ({ lat: ll.lat, lng: ll.lng }));
    document.getElementById('boundary-json').value = JSON.stringify(path);

    const center = getPolygonCentroid(path);
    if (center) {
        document.getElementById('center-latitude').value = center.lat;
        document.getElementById('center-longitude').value = center.lng;
    }
    document.getElementById('radius-km').value = getMaxRadiusKm(center, path).toFixed(3);
}

function getPolygonCentroid(path) {
    if (!path.length) return null;
    let lat = 0, lng = 0;
    path.forEach(p => { lat += p.lat; lng += p.lng; });
    return { lat: lat / path.length, lng: lng / path.length };
}

function getMaxRadiusKm(center, path) {
    let max = 0;
    path.forEach(p => { const d = haversineDistance(center, p); if (d > max) max = d; });
    return max;
}

function haversineDistance(a, b) {
    const R = 6371;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const sinLat = Math.sin(dLat / 2);
    const sinLng = Math.sin(dLng / 2);
    const x = sinLat * sinLat + sinLng * sinLng * Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat));
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
}

function toRad(deg) { return deg * Math.PI / 180; }

document.addEventListener('DOMContentLoaded', () => {
    initMap();
    document.addEventListener('click', e => {
        handleDelete(e, '.delete-delivery-zone', `/${panel}/delivery-zones/`, 'You are about to delete this Zone.');
    });
});
