<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require dirname(__DIR__) . '/app/Helpers/layout.php';

use App\Middleware\Auth;

Auth::requireLogin();
if ((int) (Auth::user()['id'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Geen toegang.');
}
render_header('Wegenwerken');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
.rw-intro{color:#607487;margin:0 0 17px}
.rw-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin-bottom:15px}
.rw-field{display:flex;flex-direction:column;gap:5px;font-weight:650;font-size:13px;color:#344054;min-width:170px}
.rw-field select,.rw-field input{height:42px;min-width:170px;border:1px solid #c9d7e4;background:#fff;border-radius:10px;padding:0 12px;color:#163b5e;font-size:15px}
.rw-refresh{height:42px;border:0;border-radius:10px;background:#1769aa;color:#fff;font-weight:700;padding:0 18px;cursor:pointer}
.rw-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(275px,350px);gap:15px;min-height:420px}
.rw-map{width:100%;height:min(65vh,620px);min-height:400px;border-radius:15px;overflow:hidden;border:1px solid #dae5ef;z-index:0}
.rw-side{border-radius:15px;background:#fff;border:1px solid #dae5ef;padding:13px;min-width:0}
.rw-side-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:8px}
.rw-side-head h2{font-size:16px;margin:0}
.rw-count{color:#667b8f;font-size:13px}
.rw-items{max-height:555px;overflow:auto;display:flex;flex-direction:column;gap:9px}
.rw-item{display:block;width:100%;text-align:left;background:#f7fbff;border:1px solid #e0eaf2;border-radius:10px;padding:11px;color:#15324d;cursor:pointer}
.rw-item:hover{border-color:#2f80c9;background:#eff8ff}
.rw-item strong{display:block;font-size:14px;line-height:1.4;overflow-wrap:anywhere}
.rw-item small{display:block;margin-top:5px;color:#63788b;font-size:12px;line-height:1.4}
.rw-status{font-size:13px;color:#566d82;min-height:20px;margin:0 0 10px}
.rw-note{padding:14px;border:1px solid #c9d7e4;border-radius:12px;background:#f7fbff;line-height:1.55}
.rw-note a{color:#1769aa;font-weight:650}
.rw-popup{max-width:260px;line-height:1.5}
.rw-popup strong{display:block;margin-bottom:5px}
.rw-popup small{display:block;margin-top:4px}
.rw-popup a{display:inline-block;margin-top:6px}
@media(max-width:920px){.rw-layout{grid-template-columns:1fr}.rw-map{height:52vh;min-height:335px}.rw-items{max-height:330px}}
@media(max-width:520px){.rw-field{flex:1 1 120px;min-width:0}.rw-field select,.rw-field input{min-width:0;width:100%}.rw-refresh{width:100%}}
</style>

<p class="rw-intro">Actuele en geplande verkeershinder. Testweergave voor Richard Luijten.</p>

<div class="rw-toolbar">
    <label class="rw-field">Gebied
        <select id="rw-region">
            <option value="antwerpen">Antwerpen en omgeving</option>
            <option value="gent">Gent en omgeving</option>
            <option value="zeeland">Zeeland (NDW)</option>
        </select>
    </label>
    <label class="rw-field">Periode
        <select id="rw-period">
            <option value="all">Alles</option>
            <option value="now">Nu bezig</option>
            <option value="planned">Gepland</option>
        </select>
    </label>
    <label class="rw-field">Zoeken
        <input type="search" id="rw-search" placeholder="Weg of omschrijving">
    </label>
    <button type="button" id="rw-refresh" class="rw-refresh">Vernieuwen</button>
</div>

<p class="rw-status" id="rw-status" role="status" aria-live="polite">Gegevens worden opgehaald…</p>
<div class="rw-layout">
    <div id="rw-map" class="rw-map" aria-label="Kaart van wegenwerken"></div>
    <section class="rw-side" aria-label="Lijst wegenwerken">
        <div class="rw-side-head">
            <h2>Verkeershinder</h2><span class="rw-count" id="rw-count"></span>
        </div>
        <div class="rw-items" id="rw-items"></div>
    </section>
</div>
<p class="rw-intro" style="margin-top:13px">
    Bron België: GIPOD (Digitaal Vlaanderen). De kaart is informatief; volg ter plaatse de verkeersborden.
</p>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function () {
    'use strict';
    const region = document.getElementById('rw-region');
    const period = document.getElementById('rw-period');
    const search = document.getElementById('rw-search');
    const status = document.getElementById('rw-status');
    const count = document.getElementById('rw-count');
    const list = document.getElementById('rw-items');
    const refresh = document.getElementById('rw-refresh');
    const centers = {
        antwerpen: [51.22, 4.40],
        gent: [51.055, 3.725],
        zeeland: [51.50, 3.85]
    };
    let entries = [];
    let markers = [];
    let requestNumber = 0;
    if (typeof L === 'undefined') {
        status.textContent = 'De kaartbibliotheek kon niet geladen worden.';
        return;
    }
    const map = L.map('rw-map', {scrollWheelZoom: false}).setView(centers.antwerpen, 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap-bijdragers'
    }).addTo(map);
    const layer = L.layerGroup().addTo(map);

    function fmt(value) {
        if (!value) return 'Niet opgegeven';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? String(value) :
            new Intl.DateTimeFormat('nl-BE', {dateStyle: 'short', timeStyle: 'short'}).format(date);
    }
    function addText(parent, tag, value) {
        const el = document.createElement(tag);
        el.textContent = value;
        parent.appendChild(el);
        return el;
    }
    function clear() {
        layer.clearLayers();
        list.replaceChildren();
        markers = [];
        count.textContent = '';
    }
    function draw() {
        clear();
        if (region.value === 'zeeland') {
            const note = document.createElement('div');
            note.className = 'rw-note';
            addText(note, 'strong', 'Zeeland: landelijke NDW-kaart');
            addText(note, 'p', 'De actuele meldingen kunnen voorlopig op de publieke kaart van Melvin worden bekeken.');
            const a = addText(note, 'a', 'Open NDW Melvin');
            a.href = 'https://melvin.ndw.nu/public';
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            list.appendChild(note);
            return;
        }
        const now = Date.now();
        const term = search.value.trim().toLocaleLowerCase('nl-BE');
        const filtered = entries.filter(item => {
            if (term && !(item.title + ' ' + item.details + ' ' + item.status).toLocaleLowerCase('nl-BE').includes(term)) {
                return false;
            }
            const start = Date.parse(item.start || '') || 0;
            const end = Date.parse(item.end || '') || Infinity;
            if (period.value === 'now') return start <= now && end >= now;
            if (period.value === 'planned') return start > now;
            return true;
        });
        count.textContent = filtered.length + ' meldingen';
        const bounds = [];
        filtered.forEach(item => {
            const position = [Number(item.lat), Number(item.lng)];
            if (!Number.isFinite(position[0]) || !Number.isFinite(position[1])) return;
            const point = L.circleMarker(position, {
                radius: item.severe ? 9 : 7,
                weight: 2,
                color: item.severe ? '#a12424' : '#af6918',
                fillColor: item.severe ? '#e54747' : '#f9b342',
                fillOpacity: .9
            }).addTo(layer);
            const popup = document.createElement('div');
            popup.className = 'rw-popup';
            addText(popup, 'strong', item.title || 'Verkeershinder');
            if (item.details) addText(popup, 'div', item.details);
            addText(popup, 'small', 'Van: ' + fmt(item.start));
            addText(popup, 'small', 'Tot: ' + fmt(item.end));
            if (item.status) addText(popup, 'small', 'Status: ' + item.status);
            if (item.source && item.source.startsWith('https://')) {
                const a = addText(popup, 'a', 'Bekijk broninformatie');
                a.href = item.source;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
            }
            point.bindPopup(popup);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rw-item';
            addText(button, 'strong', item.title || 'Verkeershinder');
            addText(button, 'small', fmt(item.start) + ' – ' + fmt(item.end));
            if (item.details) addText(button, 'small', item.details);
            button.addEventListener('click', () => {
                map.setView(position, 15);
                point.openPopup();
                if (window.matchMedia('(max-width: 920px)').matches) {
                    document.getElementById('rw-map').scrollIntoView({behavior:'smooth'});
                }
            });
            list.appendChild(button);
            bounds.push(position);
        });
        if (!filtered.length) {
            addText(list, 'p', 'Geen meldingen voor deze selectie.');
        }
        if (bounds.length && bounds.length < 250) {
            map.fitBounds(bounds, {padding: [25,25], maxZoom: 13});
        }
    }
    async function load() {
        const current = ++requestNumber;
        clear();
        entries = [];
        const selected = region.value;
        map.setView(centers[selected], selected === 'zeeland' ? 9 : 11);
        refresh.disabled = false;
        if (selected === 'zeeland') {
            status.textContent = 'Zeeland: de NDW-DATEX-koppeling is nog niet geactiveerd.';
            draw();
            return;
        }
        status.textContent = 'GIPOD-meldingen worden opgehaald…';
        refresh.disabled = true;
        try {
            const response = await fetch('/api/wegenwerken.php?regio=' + encodeURIComponent(selected), {
                credentials: 'same-origin', cache: 'no-store'
            });
            const result = await response.json();
            if (current !== requestNumber) return;
            if (!response.ok || !result.ok || !Array.isArray(result.items)) {
                throw new Error(result.error || 'Kon meldingen niet ophalen.');
            }
            entries = result.items;
            status.textContent = 'Bron: ' + result.source + ' · Bijgewerkt: ' + fmt(result.updated)
                + (result.limited ? ' · Let op: maximale hoeveelheid resultaten bereikt' : '');
            draw();
        } catch (error) {
            if (current !== requestNumber) return;
            status.textContent = String(error.message || error);
            addText(list, 'p', 'De gegevens zijn niet beschikbaar.');
        } finally {
            if (current === requestNumber) refresh.disabled = false;
        }
    }
    region.addEventListener('change', load);
    period.addEventListener('change', draw);
    search.addEventListener('input', draw);
    refresh.addEventListener('click', load);
    load();
    setTimeout(() => map.invalidateSize(), 200);
}());
</script>
<?php render_footer(); ?>
