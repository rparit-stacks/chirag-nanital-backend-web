// Delivery Zone map — Leaflet + Leaflet.draw + Nominatim (no Google Maps API key needed)

let map, featureGroup, drawControl;
let drawnPolygon = null;
let originalPolygonLayer = null;
let otherZoneLayers = [];

const DEFAULT_CENTER = [20.5937, 78.9629]; // India center as sensible default

function initMap() {
    const centerLatEl = document.getElementById('center-latitude');
    const centerLngEl = document.getElementById('center-longitude');

    let startCenter = DEFAULT_CENTER;
    if (centerLatEl?.value && centerLngEl?.value) {
        startCenter = [parseFloat(centerLatEl.value), parseFloat(centerLngEl.value)];
    }

    // ── Init map ────────────────────────────────────────────────────────────
    map = L.map('map').setView(startCenter, 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    // ── Search control (Nominatim) ──────────────────────────────────────────
    const SearchControl = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const wrapper = L.DomUtil.create('div');
            wrapper.innerHTML = `
                <div style="background:#fff;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.25);padding:8px;width:270px;">
                    <input id="osm-search-input" type="text" placeholder="Search location…"
                        style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #ddd;
                               border-radius:4px;font-size:13px;outline:none;" />
                    <ul id="osm-suggestions"
                        style="list-style:none;margin:4px 0 0;padding:0;max-height:200px;overflow-y:auto;
                               border:1px solid #e5e5e5;border-radius:4px;display:none;background:#fff;
                               position:absolute;z-index:9999;width:254px;box-shadow:0 4px 12px rgba(0,0,0,.15);">
                    </ul>
                </div>`;
            L.DomEvent.disableClickPropagation(wrapper);
            L.DomEvent.disableScrollPropagation(wrapper);
            return wrapper;
        },
    });
    map.addControl(new SearchControl());

    // Wire search after DOM is updated
    const searchInput = document.getElementById('osm-search-input');
    const suggestions = document.getElementById('osm-suggestions');
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
                li.addEventListener('click', () => {
                    map.setView([parseFloat(r.lat), parseFloat(r.lon)], 14);
                    searchInput.value = '';
                    suggestions.style.display = 'none';
                });
                suggestions.appendChild(li);
            });
            suggestions.style.display = 'block';
        } catch (e) {
            console.warn('Nominatim error:', e);
        }
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('#osm-suggestions') && e.target !== searchInput) {
            suggestions.style.display = 'none';
        }
    });

    // ── Leaflet.draw ────────────────────────────────────────────────────────
    featureGroup = new L.FeatureGroup().addTo(map);

    drawControl = new L.Control.Draw({
        edit: { featureGroup },
        draw: {
            polygon: {
                shapeOptions: { color: '#4f46e5', fillColor: '#4f46e5', fillOpacity: 0.2 },
                allowIntersection: false,
                showArea: true,
            },
            polyline: false, rectangle: false, circle: false,
            marker: false, circlemarker: false,
        },
    });
    map.addControl(drawControl);

    // ── Restore existing polygon ────────────────────────────────────────────
    const boundaryJsonEl = document.getElementById('boundary-json');
    if (boundaryJsonEl?.value) {
        try {
            const pathArr = JSON.parse(boundaryJsonEl.value);
            if (Array.isArray(pathArr) && pathArr.length >= 3) {
                const latlngs = pathArr.map(c => [c.lat, c.lng]);
                originalPolygonLayer = L.polygon(latlngs, {
                    color: '#4f46e5', fillColor: '#4f46e5', fillOpacity: 0.2,
                }).addTo(featureGroup);
                drawnPolygon = originalPolygonLayer;
                syncHiddenFields(drawnPolygon);
                map.fitBounds(drawnPolygon.getBounds(), { padding: [40, 40] });
            }
        } catch (_) { /* ignore bad JSON */ }
    }

    // ── Draw / Edit / Delete events ─────────────────────────────────────────
    map.on(L.Draw.Event.CREATED, e => {
        if (drawnPolygon) featureGroup.removeLayer(drawnPolygon);
        drawnPolygon = e.layer;
        featureGroup.addLayer(drawnPolygon);
        syncHiddenFields(drawnPolygon);
    });

    map.on(L.Draw.Event.EDITED, () => {
        featureGroup.eachLayer(layer => {
            if (layer instanceof L.Polygon) {
                drawnPolygon = layer;
                syncHiddenFields(drawnPolygon);
            }
        });
    });

    map.on(L.Draw.Event.DELETED, () => {
        drawnPolygon = null;
        clearHiddenFields();
    });

    // ── Other delivery zones (read-only blue overlay) ───────────────────────
    loadOtherZones();

    // ── Toolbar buttons ─────────────────────────────────────────────────────
    document.getElementById('clear-last')?.addEventListener('click', () => {
        if (!drawnPolygon) return;
        featureGroup.removeLayer(drawnPolygon);
        drawnPolygon = null;
        clearHiddenFields();
    });

    document.getElementById('reset-zone')?.addEventListener('click', () => {
        if (!originalPolygonLayer) return;
        if (drawnPolygon) featureGroup.removeLayer(drawnPolygon);
        const origLatLngs = originalPolygonLayer.getLatLngs()[0].map(ll => [ll.lat, ll.lng]);
        drawnPolygon = L.polygon(origLatLngs, {
            color: '#4f46e5', fillColor: '#4f46e5', fillOpacity: 0.2,
        });
        featureGroup.addLayer(drawnPolygon);
        syncHiddenFields(drawnPolygon);
        map.fitBounds(drawnPolygon.getBounds(), { padding: [40, 40] });
    });
}

// ── Load other zones as non-interactive blue polygons ──────────────────────
async function loadOtherZones() {
    try {
        const res = await fetch('/api/delivery-zone?per_page=500', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const json = await res.json();
        const items = Array.isArray(json?.data?.data) ? json.data.data
            : Array.isArray(json?.data) ? json.data : [];

        const currentZoneId = parseInt(document.getElementById('current-zone-id')?.value || '0');

        otherZoneLayers.forEach(l => map.removeLayer(l));
        otherZoneLayers = [];

        items.forEach(zone => {
            if (currentZoneId && zone.id === currentZoneId) return;
            if (!Array.isArray(zone.boundary_json) || zone.boundary_json.length < 3) return;

            const latlngs = zone.boundary_json
                .map(pt => [parseFloat(pt.lat), parseFloat(pt.lng)])
                .filter(p => !isNaN(p[0]) && !isNaN(p[1]));
            if (latlngs.length < 3) return;

            const layer = L.polygon(latlngs, {
                color: '#0ea5e9', weight: 1.5, opacity: 0.7,
                fillColor: '#0ea5e9', fillOpacity: 0.08,
                interactive: false,
            }).addTo(map);
            otherZoneLayers.push(layer);
        });
    } catch (e) {
        console.warn('Could not load other zones:', e);
    }
}

// ── Sync polygon data → hidden form fields ─────────────────────────────────
function syncHiddenFields(polygon) {
    const latlngs = polygon.getLatLngs()[0];
    const path = latlngs.map(ll => ({ lat: ll.lat, lng: ll.lng }));

    document.getElementById('boundary-json').value = JSON.stringify(path);

    const center = centroid(path);
    document.getElementById('center-latitude').value = center.lat;
    document.getElementById('center-longitude').value = center.lng;
    document.getElementById('radius-km').value = maxRadius(center, path).toFixed(4);
}

function clearHiddenFields() {
    ['boundary-json', 'center-latitude', 'center-longitude', 'radius-km']
        .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
}

function centroid(path) {
    const sum = path.reduce((acc, p) => ({ lat: acc.lat + p.lat, lng: acc.lng + p.lng }), { lat: 0, lng: 0 });
    return { lat: sum.lat / path.length, lng: sum.lng / path.length };
}

function maxRadius(center, path) {
    return path.reduce((max, p) => Math.max(max, haversine(center, p)), 0);
}

function haversine(a, b) {
    const R = 6371, rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
}

// ── Boot ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    document.addEventListener('click', e => {
        handleDelete(e, '.delete-delivery-zone', `/${panel}/delivery-zones/`, 'You are about to delete this Zone.');
    });
});
