#!/usr/bin/env python3
"""ATT Schadebeheer: compacte mappen en slechts één geopend dossier."""
from pathlib import Path
from datetime import datetime
import os, shutil, subprocess, sys, tempfile

page=Path('/var/www/att-address-portal/public/schadebeheer.php')
marker='ATT_VCM_COMPACT_ACCORDION_20261120'
def stop(message):
    sys.exit('STOP: '+message)
if not page.is_file() or page.is_symlink():
    stop('Schadebeheerbestand niet gevonden.')
before=page.read_bytes()
try: source=before.decode('utf-8')
except UnicodeDecodeError: stop('Onverwachte bestandscodering.')
if marker in source: stop('Compacte bediening is al geïnstalleerd.')
if 'ATT_VEHICLE_FOLDER_MONITOR_20261119' not in source:
    stop('De bestaande wagenmapfunctie ontbreekt.')
anchors={
'style':'</style>',
'start':'    <?php if ($selectedVehicle): ?>',
'end':'    <?php else: ?>\n\n        <div class="damage-header">',
'script':"    <script>\n    (() => {\n        const root = document.getElementById('att-vcm-scroll');",
}
for name,needle in anchors.items():
    if source.count(needle)!=1:
        stop('Onverwachte paginastructuur bij '+name+'. Niets gewijzigd.')
css=r"""
/* ATT_VCM_COMPACT_ACCORDION_20261120 */
.att-vcm-strip{margin:8px 0 12px!important}
.att-vcm-strip h2{font-size:15px!important;margin:0 0 7px!important}
.att-vcm-scroll{display:flex!important;gap:7px!important;overflow-x:auto!important;overflow-y:hidden!important;padding:3px 1px 9px!important}
.att-vcm-folder{position:relative!important;flex:0 0 76px!important;width:76px!important;height:76px!important;min-height:76px!important;box-sizing:border-box!important;border-radius:9px!important;overflow:hidden!important}
.att-vcm-folder>a,.att-vcm-folder>div{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:4px!important;padding:5px 3px!important;height:100%!important;box-sizing:border-box!important;text-align:center!important}
.att-vcm-folder-icon{font-size:21px!important;line-height:1!important}
.att-vcm-folder strong{font-size:11px!important;line-height:1.15!important;white-space:nowrap!important}
.att-vcm-folder .att-vcm-summary{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;margin:-1px!important;padding:0!important;border:0!important}
.att-vcm-folder.att-vcm-selected:not(.att-vcm-blink){border-color:#0d61a5!important;background:#e6f3ff!important;box-shadow:inset 0 0 0 1px #0d61a5!important}
#att-vcm-detail[hidden]{display:none!important}
"""
js=r"""
    <!-- ATT_VCM_COMPACT_ACCORDION_20261120 -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const row=document.getElementById('att-vcm-scroll');
        const detail=document.getElementById('att-vcm-detail');
        if(!row||!detail)return;
        const current=[...row.querySelectorAll('.att-vcm-folder')]
            .find(el=>el.dataset.attVcmId===detail.dataset.vehicleId);
        function expanded(on){
            if(!current)return;
            current.classList.toggle('att-vcm-selected',on);
            const link=current.querySelector('a');
            if(link)link.setAttribute('aria-expanded',on?'true':'false');
        }
        expanded(true);
        function close(){
            if(detail.hidden)return;
            detail.hidden=true;
            expanded(false);
            try{history.replaceState(history.state,'','/schadebeheer.php')}
            catch(_){}
        }
        document.addEventListener('click',ev=>{
            const folder=ev.target.closest('.att-vcm-folder');
            if(folder){
                if(folder===current&&!detail.hidden){
                    if(ev.target.closest('a'))ev.preventDefault();
                    close();
                }
                return;
            }
            if(ev.target.closest('#att-vcm-detail'))return;
            if(ev.target.closest('a,button,input,select,textarea,label,summary,form,[role="button"]'))return;
            close();
        });
    });
    </script>
"""
after=source.replace('</style>',css+'\n</style>',1)
after=after.replace(anchors['start'],
    anchors['start']+'\n    <section id="att-vcm-detail" data-vehicle-id="<?= (int) $selectedVehicle[\'id\'] ?>">',1)
after=after.replace(anchors['end'],'    </section>\n\n'+anchors['end'],1)
after=after.replace(anchors['script'],js+'\n'+anchors['script'],1)
if after.count(marker)!=2: stop('Onvolledige aanpassing.')
temp=None
try:
    with tempfile.NamedTemporaryFile('w',encoding='utf-8',suffix='.php',
        prefix='.att-compact-',dir=page.parent,delete=False) as handle:
        temp=Path(handle.name)
        handle.write(after)
    st=page.stat()
    os.chown(temp,st.st_uid,st.st_gid)
    os.chmod(temp,st.st_mode & 0o7777)
    lint=subprocess.run(['php','-l',str(temp)],capture_output=True,text=True)
    if lint.returncode: stop('PHP-fout: '+lint.stdout+lint.stderr)
    if page.read_bytes()!=before: stop('Bestand tussentijds gewijzigd.')
    backup=Path('/var/backups/att-portal')/('schadebeheer-compact-'+datetime.now().strftime('%Y%m%d-%H%M%S'))
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    shutil.copy2(page,backup/page.name)
    os.replace(temp,page)
    temp=None
    lint=subprocess.run(['php','-l',str(page)],capture_output=True,text=True)
    if lint.returncode:
        shutil.copy2(backup/page.name,page)
        stop('PHP-fout na installatie. Origineel teruggezet.')
    print('GEREED: alle wagenmappen compact (76 x 76 px).')
    print('GEREED: maximaal één map open; tik op dezelfde of ernaast sluit.')
    print('GEREED: andere map toont alleen dat voertuig.')
    print('GEREED: bestaande checks, meldingen en schade behouden.')
    print('PHP-controle: OK')
    print('BACK-UP:',backup)
finally:
    if temp is not None: temp.unlink(missing_ok=True)
