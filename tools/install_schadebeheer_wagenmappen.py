#!/usr/bin/env python3
"""ATT Portaal: Schadebeheer wagenmappen en dag/nachtcheck-monitor.
Uitvoeren met sudo python3 /pad/naar/att_schadebeheer_install.py
Geen voertuigcheck-invoer, bestaande records of foto's worden gewijzigd.
"""
from pathlib import Path
from datetime import datetime
import hashlib
import os
import shutil
import subprocess
import sys
import tempfile
import re

root = Path('/var/www/att-address-portal')
page = root / 'public/schadebeheer.php'
checks_page = root / 'public/vehicle-checks.php'
helper = root / 'app/Helpers/att_vehicle_check_monitor.php'
endpoint = root / 'public/att-vehicle-check-monitor.php'
marker = 'ATT_VEHICLE_FOLDER_MONITOR_20261119'


def stop(msg):
    sys.exit('INSTALLATIE GESTOPT: ' + msg)

for path in (page, checks_page):
    if not path.is_file() or path.is_symlink():
        stop('Bestand ontbreekt of is een symlink: ' + str(path))
for path in (helper, endpoint):
    if path.exists():
        stop('Nieuw bestand bestaat al; niets overschreven: ' + str(path))

original_page = page.read_text(encoding='utf-8')
original_checks = checks_page.read_text(encoding='utf-8')
if marker in original_page or marker in original_checks:
    stop('De installatie lijkt al uitgevoerd; niets gewijzigd.')

expected = [
    ('$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);', original_page, 1),
    ("render_header('Schadebeheer');", original_page, 1),
    ('    <?php if ($selectedVehicle): ?>', original_page, 1),
    ('<div class="damage-detail-head">', original_page, 1),
    ('</style>', original_page, 1),
    ('href="/vehicle-checks-archive.php"', original_page, 1),
    ('href="/vehicle-checks-archive.php"', original_checks, 1),
]
for text, source, count in expected:
    if source.count(text) < count:
        stop(f'Verwachte code ontbreekt of is gewijzigd: {text}')

helper_text = r'''<?php
/** ATT_VEHICLE_FOLDER_MONITOR_20261119 - alleen lezen van voertuigchecks. */
declare(strict_types=1);

function att_vcm_schedule(): array
{
    return [
        1 => null,
        2 => ['07:00', '19:00'],
        4 => ['06:00', '18:00'],
        5 => ['06:30', '18:30'],
        7 => ['05:30', '17:30'],
        8 => ['05:30', '17:30'],
        11 => null,
    ];
}

function att_vcm_number(string $name): ?int
{
    if (preg_match('/(?:wagen|bus)\s*0*(11|[124578])\b/iu', $name, $m)) {
        return (int) $m[1];
    }
    return null;
}

function att_vcm_label(int $number): string
{
    return $number === 11 ? 'Bus 11' : 'Wagen ' . $number;
}

function att_vcm_status_label(string $s): string
{
    return [
        'ok' => '✓ Uitgevoerd',
        'pending' => 'Binnen 30 minuten',
        'upcoming' => 'Nog niet begonnen',
        'missing' => '⚠ Ontbreekt',
        'muted' => 'Ontbreekt (knipperen uit)',
    ][$s] ?? 'Onbekend';
}

/** Geeft uitsluitend de zeven afgesproken NLW-wagens terug. */
function att_vcm_monitor(PDO $pdo, array $vehicles): array
{
    $tz = new DateTimeZone('Europe/Brussels');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $schedules = att_vcm_schedule();
    $mapped = [];
    foreach ($vehicles as $vehicle) {
        $number = att_vcm_number((string) ($vehicle['vehicle_name'] ?? ''));
        if ($number === null || !array_key_exists($number, $schedules)) {
            continue;
        }
        if (!isset($mapped[$number]) || (
            (int) ($vehicle['is_active'] ?? 0) === 1 &&
            (int) ($mapped[$number]['is_active'] ?? 0) !== 1
        )) {
            $mapped[$number] = $vehicle;
        }
    }
    $state = [];
    $earliest = null;
    foreach ($schedules as $number => $hours) {
        $vehicle = $mapped[$number] ?? null;
        $id = $vehicle ? (int) $vehicle['id'] : 0;
        $entry = [
            'number' => $number,
            'id' => $id,
            'label' => att_vcm_label($number),
            'reserve' => $hours === null,
            'mapped' => $id > 0,
            'blink' => false,
            'shifts' => [],
        ];
        if ($id > 0 && $hours !== null) {
            [$dayHour, $nightHour] = $hours;
            $start = new DateTimeImmutable($today . ' ' . $dayHour, $tz);
            if ($now < $start) {
                $start = $start->modify('-1 day');
            }
            $nightStart = new DateTimeImmutable(
                $start->format('Y-m-d') . ' ' . $nightHour, $tz
            );
            $nextDayStart = $start->modify('+1 day');
            foreach ([
                'day' => [$start, $nightStart],
                'night' => [$nightStart, $nextDayStart],
            ] as $type => [$from, $until]) {
                $entry['shifts'][$type] = [
                    'start' => $from->format('Y-m-d H:i:s'),
                    'end' => $until->format('Y-m-d H:i:s'),
                    'deadline' => $from->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'count' => 0,
                    'status' => 'upcoming',
                ];
                if ($earliest === null || $from < $earliest) {
                    $earliest = $from;
                }
            }
        }
        $state[$number] = $entry;
    }

    if ($earliest === null) {
        return $state;
    }
    $ids = [];
    foreach ($state as $entry) {
        if (!$entry['reserve'] && $entry['id'] > 0) {
            $ids[] = $entry['id'];
        }
    }
    if (!$ids) {
        return $state;
    }
    $holders = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare(
        "SELECT fleet_vehicle_id, submitted_at
         FROM vehicle_checks
         WHERE fleet_vehicle_id IN ($holders)
           AND submitted_at >= ? AND submitted_at <= ?"
    );
    $q->execute(array_merge($ids, [
        $earliest->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s'),
    ]));
    $checks = [];
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $checks[(int) $row['fleet_vehicle_id']][] = (string) $row['submitted_at'];
    }
    $muteQuery = $pdo->prepare(
        'SELECT fleet_vehicle_id, shift_start FROM vehicle_check_warning_mutes WHERE shift_start >= ?'
    );
    $muteQuery->execute([$earliest->format('Y-m-d H:i:s')]);
    $muted = [];
    while ($row = $muteQuery->fetch(PDO::FETCH_ASSOC)) {
        $muted[(int) $row['fleet_vehicle_id'] . ':' . $row['shift_start']] = true;
    }
    $nowString = $now->format('Y-m-d H:i:s');
    foreach ($state as &$entry) {
        if ($entry['reserve'] || !$entry['mapped']) {
            continue;
        }
        foreach ($entry['shifts'] as &$shift) {
            foreach ($checks[$entry['id']] ?? [] as $when) {
                if ($when >= $shift['start'] && $when < $shift['end']) {
                    $shift['count']++;
                }
            }
            if ($shift['count'] > 0) {
                $shift['status'] = 'ok';
            } elseif ($nowString < $shift['start']) {
                $shift['status'] = 'upcoming';
            } elseif ($nowString < $shift['deadline']) {
                $shift['status'] = 'pending';
            } elseif (isset($muted[$entry['id'] . ':' . $shift['start']])) {
                $shift['status'] = 'muted';
            } else {
                $shift['status'] = 'missing';
                $entry['blink'] = true;
            }
        }
        unset($shift);
    }
    unset($entry);
    return $state;
}
'''

endpoint_text = r'''<?php
/** ATT_VEHICLE_FOLDER_MONITOR_20261119 - uitsluitend Admin/Developer. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Helpers/bootstrap.php';
require_once dirname(__DIR__) . '/app/Helpers/att_vehicle_check_monitor.php';
use App\Middleware\Auth;
use App\Services\PermissionService;
Auth::requireLogin();
if (!(PermissionService::isAdmin() || PermissionService::isDeveloper()) || PermissionService::isAppleReview()) {
    http_response_code(403);
    exit('Geen toegang.');
}
$pdo = db();
$q = $pdo->query(
    "SELECT f.id, f.vehicle_name, f.is_active FROM fleet_vehicles f
     JOIN organizations o ON o.id = f.organization_id
     WHERE o.slug = 'nlw' ORDER BY f.sort_order, f.id"
);
$state = att_vcm_monitor($pdo, $q->fetchAll(PDO::FETCH_ASSOC));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) csrf_token(), $token)) {
        http_response_code(403);
        exit('Ongeldige beveiligingscode.');
    }
    $id = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);
    $shiftStart = (string) ($_POST['shift_start'] ?? '');
    $allowed = false;
    foreach ($state as $entry) {
        if ($entry['id'] !== (int) $id || $entry['reserve']) {
            continue;
        }
        foreach ($entry['shifts'] as $shift) {
            if ($shift['start'] === $shiftStart && $shift['status'] === 'missing') {
                $allowed = true;
            }
        }
    }
    if (!$allowed) {
        http_response_code(409);
        exit('De waarschuwing is niet meer actief; vernieuw de pagina.');
    }
    $insert = $pdo->prepare(
        'INSERT INTO vehicle_check_warning_mutes (fleet_vehicle_id, shift_start, acknowledged_by)
         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE acknowledged_at = acknowledged_at'
    );
    $user = Auth::user();
    $insert->execute([(int) $id, $shiftStart, (int) ($user['id'] ?? 0)]);
    header('Location: /schadebeheer.php?vehicle_id=' . (int) $id, true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['vehicles' => $state], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
'''

css = r'''
/* ATT_VEHICLE_FOLDER_MONITOR_20261119 */
.att-vcm-strip {margin:14px 0 18px}
.att-vcm-strip h2 {font-size:17px;margin:0 0 9px}
.att-vcm-scroll {display:flex;gap:10px;overflow-x:auto;overscroll-behavior-x:contain;padding:4px 2px 12px;-webkit-overflow-scrolling:touch}
.att-vcm-folder {flex:0 0 136px;border:2px solid #d7e0ea;background:#f6f8fb;border-radius:12px;min-height:114px;box-sizing:border-box}
.att-vcm-folder a {display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;text-decoration:none;color:#122e4d;text-align:center;padding:12px 8px;height:100%;box-sizing:border-box}
.att-vcm-folder strong {font-size:14px}
.att-vcm-folder small {font-size:11px;line-height:1.35}
.att-vcm-folder-icon {font-size:31px;line-height:1}
.att-vcm-folder.att-vcm-blink {border-color:#c71931;background:#ffebed;animation:attVcmBlink 1.2s ease-in-out infinite}
.att-vcm-folder.att-vcm-blink .att-vcm-folder-icon {filter:grayscale(1);color:#b51529}
@keyframes attVcmBlink {0%,100%{background:#fff1f2;box-shadow:0 0 0 0 rgba(185,28,28,0)}50%{background:#ffb3bc;box-shadow:0 0 0 3px rgba(185,28,28,.3)}}
@media (prefers-reduced-motion:reduce){.att-vcm-folder.att-vcm-blink{animation:none;border-width:3px}}
.att-vcm-panel {padding:14px;margin:15px 0;border:1px solid #d9e1ec;border-radius:12px;background:#f8fafc}
.att-vcm-panel h3 {margin:0 0 8px}
.att-vcm-warning-form {margin:10px 0}
.att-vcm-warning-form button {border:1px solid #ac3244;background:#fff;color:#ad2035;border-radius:8px;padding:8px 12px;font-weight:650;cursor:pointer}
.att-vcm-check {padding:10px 0;border-top:1px solid #dce2ed;font-size:13px}
.att-vcm-pager {display:flex;justify-content:space-between;margin-top:10px}
/* Het bestaande grote tegeltjesoverzicht wordt vervangen door de horizontale mappen. */
.damage-page .damage-grid {display:none!important}
'''

folders_html = r'''
    <!-- ATT_VEHICLE_FOLDER_MONITOR_20261119 -->
    <section class="att-vcm-strip" aria-label="Schadechecks per wagen">
        <h2>📁 Schadechecks per wagen</h2>
        <div class="att-vcm-scroll" id="att-vcm-scroll">
            <?php foreach ($attVehicleMonitor as $attEntry): ?>
                <div class="att-vcm-folder <?= $attEntry['blink'] ? 'att-vcm-blink' : '' ?>"
                     data-att-vcm-id="<?= (int) $attEntry['id'] ?>"
                     data-att-vcm-number="<?= (int) $attEntry['number'] ?>">
                    <?php if ($attEntry['mapped']): ?>
                        <a href="/schadebeheer.php?vehicle_id=<?= (int) $attEntry['id'] ?>">
                    <?php else: ?>
                        <div style="padding:12px 8px;text-align:center">
                    <?php endif; ?>
                        <span class="att-vcm-folder-icon" aria-hidden="true">📁</span>
                        <strong><?= e($attEntry['label']) ?></strong>
                        <small class="att-vcm-summary">
                        <?php if (!$attEntry['mapped']): ?>
                            Niet gekoppeld
                        <?php elseif ($attEntry['reserve']): ?>
                            Reserve · niet verplicht
                        <?php else: ?>
                            Dag: <?= e(att_vcm_status_label($attEntry['shifts']['day']['status'])) ?><br>
                            Nacht: <?= e(att_vcm_status_label($attEntry['shifts']['night']['status'])) ?>
                        <?php endif; ?>
                        </small>
                    <?php if ($attEntry['mapped']): ?></a><?php else: ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <script>
    (() => {
        const root = document.getElementById('att-vcm-scroll');
        if (!root) return;
        async function refreshVehicleFolders() {
            try {
                const r = await fetch('/att-vehicle-check-monitor.php', {credentials:'same-origin',cache:'no-store'});
                if (!r.ok) return;
                const data = await r.json();
                for (const el of root.querySelectorAll('[data-att-vcm-number]')) {
                    const item = data.vehicles?.[el.dataset.attVcmNumber];
                    if (!item) continue;
                    el.classList.toggle('att-vcm-blink', !!item.blink);
                    const sum = el.querySelector('.att-vcm-summary');
                    if (sum && item.shifts.day && item.shifts.night) {
                        const fmt = status => ({ok:'✓ Uitgevoerd',pending:'Binnen 30 minuten',upcoming:'Nog niet begonnen',missing:'⚠ Ontbreekt',muted:'Ontbreekt (knipperen uit)'})[status] || status;
                        sum.textContent = `Dag: ${fmt(item.shifts.day.status)} | Nacht: ${fmt(item.shifts.night.status)}`;
                    }
                }
            } catch (_) { /* Bij netwerkuitval blijft de laatste weergave staan. */ }
        }
        setInterval(refreshVehicleFolders, 60000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshVehicleFolders();
        });
    })();
    </script>
'''

history_html = r'''
        <!-- ATT_VEHICLE_FOLDER_MONITOR_20261119: detailchecks en handmatig afzetten -->
        <?php if (isset($attVehicleMonitorById[(int) $selectedVehicle['id']])):
            $attCurrent = $attVehicleMonitorById[(int) $selectedVehicle['id']]; ?>
        <section class="att-vcm-panel" aria-label="Verplichte checks">
            <h3>Verplichte voertuigchecks</h3>
            <?php if ($attCurrent['reserve']): ?>
                <p>Reservevoertuig: geen verplichte checks.</p>
            <?php else: ?>
                <?php foreach ($attCurrent['shifts'] as $attShiftName => $attShift): ?>
                    <p><strong><?= $attShiftName === 'day' ? 'Dagploeg' : 'Nachtploeg' ?>:</strong>
                        <?= e(att_vcm_status_label($attShift['status'])) ?>
                        (start <?= e(substr($attShift['start'], 11, 5)) ?>)
                    </p>
                    <?php if ($attShift['status'] === 'missing'): ?>
                    <form class="att-vcm-warning-form" method="post" action="/att-vehicle-check-monitor.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicle['id'] ?>">
                        <input type="hidden" name="shift_start" value="<?= e($attShift['start']) ?>">
                        <button type="submit">Knipperen uitzetten – <?= $attShiftName === 'day' ? 'dagploeg' : 'nachtploeg' ?></button>
                    </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="att-vcm-panel" id="vehicle-check-history" aria-label="Historie voertuigchecks">
            <h3>Voertuigchecks – <?= e((string) $selectedVehicle['vehicle_name']) ?></h3>
            <p><?= (int) $attHistoryCount ?> check(s) opgeslagen. Nieuwste eerst; datum en tijd automatisch geregistreerd.</p>
            <?php if (!$attHistory): ?>
                <p>Er zijn nog geen voertuigchecks opgeslagen voor deze wagen.</p>
            <?php else: ?>
                <?php foreach ($attHistory as $attCheck): ?>
                    <div class="att-vcm-check">
                        <strong><?= e((new DateTimeImmutable((string)$attCheck['submitted_at']))->format('d-m-Y H:i')) ?> uur</strong>
                        · <?= e((string) $attCheck['driver_name']) ?>
                        · <?= (int) $attCheck['has_damage'] ? 'Schade gemeld' : 'Geen schade gemeld' ?>
                        · <?= (int) $attCheck['photo_count'] ?> checkfoto('s)
                        · Check #<?= (int) $attCheck['id'] ?>
                    </div>
                <?php endforeach; ?>
                <nav class="att-vcm-pager" aria-label="Bladeren door voertuigchecks">
                    <?php if ($attHistoryPage > 1): ?>
                    <a href="/schadebeheer.php?vehicle_id=<?= (int) $selectedVehicle['id'] ?>&amp;check_page=<?= $attHistoryPage - 1 ?>#vehicle-check-history">← Vorige</a>
                    <?php else: ?><span></span><?php endif; ?>
                    <?php if ($attHistoryPage * 50 < $attHistoryCount): ?>
                    <a href="/schadebeheer.php?vehicle_id=<?= (int) $selectedVehicle['id'] ?>&amp;check_page=<?= $attHistoryPage + 1 ?>#vehicle-check-history">Volgende →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
'''

php_before_render = r'''
/* ATT_VEHICLE_FOLDER_MONITOR_20261119: volledig bestaande checkopslag hergebruiken. */
$attVehicleMonitor = att_vcm_monitor($pdo, $vehicles);
$attVehicleMonitorById = [];
foreach ($attVehicleMonitor as $attEntry) {
    if ($attEntry['id'] > 0) {
        $attVehicleMonitorById[$attEntry['id']] = $attEntry;
    }
}
$attHistory = [];
$attHistoryCount = 0;
$attHistoryPage = max(1, min(100000, (int) ($_GET['check_page'] ?? 1)));
if ($selectedVehicle) {
    $attId = (int) $selectedVehicle['id'];
    $attCountStmt = $pdo->prepare('SELECT COUNT(*) FROM vehicle_checks WHERE fleet_vehicle_id = ?');
    $attCountStmt->execute([$attId]);
    $attHistoryCount = (int) $attCountStmt->fetchColumn();
    $attHistoryStmt = $pdo->prepare(
        'SELECT vc.id, vc.driver_name, vc.has_damage, vc.status, vc.submitted_at,
         (SELECT COUNT(*) FROM vehicle_check_photos cp WHERE cp.vehicle_check_id = vc.id) AS photo_count
         FROM vehicle_checks vc WHERE vc.fleet_vehicle_id = ?
         ORDER BY vc.submitted_at DESC, vc.id DESC LIMIT 50 OFFSET ?'
    );
    $attHistoryStmt->bindValue(1, $attId, PDO::PARAM_INT);
    $attHistoryStmt->bindValue(2, ($attHistoryPage - 1) * 50, PDO::PARAM_INT);
    $attHistoryStmt->execute();
    $attHistory = $attHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
}
'''

# Build new page while preserving all existing damage forms, photos and permissions.
updated_page = original_page.replace(
    "require_once dirname(__DIR__) . '/app/Helpers/layout.php';",
    "require_once dirname(__DIR__) . '/app/Helpers/layout.php';\n"
    "require_once dirname(__DIR__) . '/app/Helpers/att_vehicle_check_monitor.php';",
    1,
)
if updated_page == original_page:
    stop('Helper include niet gevonden.')
updated_page = updated_page.replace("render_header('Schadebeheer');", php_before_render + "\nrender_header('Schadebeheer');", 1)
updated_page = updated_page.replace('</style>', css + '\n</style>', 1)
updated_page = updated_page.replace('    <?php if ($selectedVehicle): ?>', folders_html + '\n    <?php if ($selectedVehicle): ?>', 1)
updated_page = updated_page.replace('<div class="damage-detail-head">', history_html + '\n        <div class="damage-detail-head">', 1)

# Remove only first visible archive navigation anchor; keep the archive endpoint/data intact.
def remove_first_archive_anchor(source):
    needle = 'href="/vehicle-checks-archive.php"'
    ix = source.index(needle)
    left = source.rfind('<a', max(0, ix - 650), ix)
    right = source.find('</a>', ix, ix + 650)
    if left == -1 or right == -1:
        stop('Archiefknop kon niet veilig worden bepaald.')
    return source[:left] + '<!-- Archiefknop verwijderd; bewaarde checks intact. -->' + source[right + 4:]

updated_page = remove_first_archive_anchor(updated_page)
updated_checks = remove_first_archive_anchor(original_checks)

# Validate with php -l before any live write.
changes = {page: updated_page, checks_page: updated_checks, helper: helper_text, endpoint: endpoint_text}
staged = {}
try:
    for target, content in changes.items():
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', suffix='.php',
                                         prefix='.att-vcm-', dir=target.parent, delete=False) as f:
            f.write(content)
            tmp = Path(f.name)
        staged[target] = tmp
        ref = target if target.is_file() else (page if target == helper else checks_page)
        stat = ref.stat()
        os.chmod(tmp, stat.st_mode & 0o7777)
        os.chown(tmp, stat.st_uid, stat.st_gid)
        lint = subprocess.run(['php', '-l', str(tmp)], text=True, capture_output=True)
        if lint.returncode:
            stop('PHP syntaxcontrole mislukt voor ' + target.name + ': ' + lint.stdout + lint.stderr)

    # Create a small additive metadata table. No old check, photo or damage rows touched.
    create_table = """<?php
require_once '/var/www/att-address-portal/app/Helpers/bootstrap.php';
try {
    $pdo = db();
    $databaseNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $localNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Brussels'));
    $dbAsLocal = new DateTimeImmutable($databaseNow, new DateTimeZone('Europe/Brussels'));
    if (abs($localNow->getTimestamp() - $dbAsLocal->getTimestamp()) > 300) {
        throw new RuntimeException('Databaseklok wijkt af van Belgische tijd: ' . $databaseNow);
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_check_warning_mutes (
       fleet_vehicle_id INT NOT NULL,
       shift_start DATETIME NOT NULL,
       acknowledged_by INT NOT NULL,
       acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       PRIMARY KEY (fleet_vehicle_id, shift_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    echo "MELDINGENTABEL GEREED\\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Databasefout: ' . $e->getMessage() . "\\n");
    exit(1);
}
"""
    run = subprocess.run(['php'], input=create_table, text=True, capture_output=True)
    if run.returncode:
        stop('Geen bestanden geïnstalleerd. ' + run.stdout + run.stderr)

    # Re-check source hashes to avoid clobbering intervening edits.
    if page.read_text(encoding='utf-8') != original_page or checks_page.read_text(encoding='utf-8') != original_checks:
        stop('Bronbestanden ondertussen gewijzigd; installatie afgebroken.')
    backup = Path('/var/backups/att-portal') / ('schadebeheer-mappen-' + datetime.now().strftime('%Y%m%d-%H%M%S'))
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    shutil.copy2(page, backup / page.name)
    shutil.copy2(checks_page, backup / checks_page.name)

    installed = []
    try:
        for target, tmp in staged.items():
            os.replace(tmp, target)
            installed.append(target)
        for target in changes:
            lint = subprocess.run(['php', '-l', str(target)], text=True, capture_output=True)
            if lint.returncode:
                raise RuntimeError(lint.stdout + lint.stderr)
    except Exception as ex:
        for target in reversed(installed):
            if target in (page, checks_page):
                shutil.copy2(backup / target.name, target)
            else:
                target.unlink(missing_ok=True)
        stop('Teruggezet na fout: ' + str(ex))

    print('GEREED: horizontale wagenmappen en checkhistorie geïnstalleerd.')
    print('GEREED: dag/nacht-monitor volgens afgesproken starttijden.')
    print('GEREED: rood knipperen na 30 minuten, handmatig afzetten, zonder push.')
    print('GEREED: aparte archiefknop verborgen, alle archiefgegevens intact.')
    print('GEREED: wijzigingen iedere minuut bijgewerkt in open Schadebeheer-pagina.')
    print('GEREED: PHP syntaxcontrole geslaagd.')
    print('BACK-UP:', backup)
    print('BELANGRIJK: verifieer in de browser dat alle 7 mappen correct zijn gekoppeld.')
finally:
    for tmp in staged.values():
        tmp.unlink(missing_ok=True)