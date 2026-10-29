#!/usr/bin/env python3
"""ATT Portaal: server-side birthday notifications read state.
Run on the ATT portal VPS from /var/www/att-address-portal using sudo.
"""
from pathlib import Path
from datetime import datetime
import shutil
import subprocess
import sys

root = Path('/var/www/att-address-portal')
api_path = root / 'public/api/birthday-status.php'
js_path = root / 'public/assets/mail-live.js'
if not api_path.is_file() or not js_path.is_file():
    sys.exit('STOP: verjaardag-bestanden ontbreken; niets aangepast.')

api = api_path.read_text(encoding='utf-8')
js = js_path.read_text(encoding='utf-8')
marker = 'ATT_BIRTHDAY_DISMISS_20261029_V1'
if marker in api or marker in js:
    sys.exit('STOP: deze correctie lijkt al geïnstalleerd; niets gewijzigd.')

def once(source, old, new, name):
    if source.count(old) != 1:
        raise ValueError(f'STOP: invoegpunt {name} niet uniek ({source.count(old)}); niets aangepast.')
    return source.replace(old, new, 1)

api = once(api, "try {\n    $today = new DateTimeImmutable(", '''/* ATT_BIRTHDAY_DISMISS_20261029_V1 */
if (empty($_SESSION['att_birthday_dismiss_token'])) {
    $_SESSION['att_birthday_dismiss_token'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $expected = (string) ($_SESSION['att_birthday_dismiss_token'] ?? '');
    $provided = (string) ($_SERVER['HTTP_X_ATT_BIRTHDAY_TOKEN'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige sessie.']);
        exit;
    }
    $birthdayUserId = filter_var(
        $_POST['birthday_user_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($birthdayUserId === false || $birthdayUserId === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige melding.']);
        exit;
    }
    try {
        $todayDate = (new DateTimeImmutable(
            'now', new DateTimeZone('Europe/Brussels')
        ))->format('Y-m-d');
        $save = db()->prepare(
            'INSERT IGNORE INTO att_birthday_notification_reads
                (reader_user_id, birthday_user_id, notification_date)
             VALUES (:reader_user_id, :birthday_user_id, :notification_date)'
        );
        $save->execute([
            'reader_user_id' => $userId,
            'birthday_user_id' => $birthdayUserId,
            'notification_date' => $todayDate,
        ]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $exception) {
        error_log('ATT verjaardag gelezen opslaan: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Opslaan mislukt.']);
    }
    exit;
}

try {
    $today = new DateTimeImmutable(''', 'API POST handler')

api = once(api, '         ORDER BY display_name, u.id"', '''           AND NOT EXISTS (
               SELECT 1
               FROM att_birthday_notification_reads br
               WHERE br.reader_user_id = :reader_user_id
                 AND br.birthday_user_id = u.id
                 AND br.notification_date = :notification_date
           )
         ORDER BY display_name, u.id"''', 'API GET filter')
api = once(api, "        'leap_day' => $includeLeapDay ? 1 : 0,", "        'leap_day' => $includeLeapDay ? 1 : 0,\n        'reader_user_id' => $userId,\n        'notification_date' => $today->format('Y-m-d'),", 'API GET parameters')
api = once(api, "        'count' => count($items),", "        'count' => count($items),\n        'csrf_token' => (string) $_SESSION['att_birthday_dismiss_token'],", 'API GET token')

js = once(js, '    let latestNotificationCounts = null;', '''    let latestNotificationCounts = null;
    /* ATT_BIRTHDAY_DISMISS_20261029_V1 */
    let birthdayDismissToken = '';
    const birthdayDismissPending = new Set();
    let birthdayToastSuppressed = false;

    function repaintBirthdayNotifications() {
        const bell = document.getElementById('notification-button');
        if (bell) {
            bell.classList.toggle(
                'att-birthday-active', birthdayItems.length > 0
            );
        }
        if (latestNotificationCounts !== null) {
            updateNotificationPanel(latestNotificationCounts);
        }
    }

    async function dismissBirthdayNotification(birthdayUserId) {
        if (!Number.isSafeInteger(birthdayUserId) || birthdayUserId < 1
            || birthdayDismissPending.has(birthdayUserId)) return;
        const original = birthdayItems.find(
            row => Number(row.user_id) === birthdayUserId
        );
        if (!original || !birthdayDismissToken) return;

        birthdayDismissPending.add(birthdayUserId);
        birthdayItems = birthdayItems.filter(
            row => Number(row.user_id) !== birthdayUserId
        );
        birthdaySignature = JSON.stringify(birthdayItems);
        birthdayToastSuppressed = true;
        document.getElementById('att-birthday-toast')?.remove();
        repaintBirthdayNotifications();

        try {
            const response = await fetch('/api/birthday-status.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-ATT-BIRTHDAY-TOKEN': birthdayDismissToken,
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({
                    birthday_user_id: String(birthdayUserId)
                })
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error('Opslaan mislukt');
        } catch (error) {
            birthdayDismissPending.delete(birthdayUserId);
            birthdayItems.push(original);
            birthdaySignature = JSON.stringify(birthdayItems);
            repaintBirthdayNotifications();
            window.alert('Verjaardagsmelding kon niet als gelezen worden opgeslagen.');
            return;
        }
        birthdayDismissPending.delete(birthdayUserId);
        await checkBirthdayStatus();
    }

    function clickedBirthdayNotice(event) {
        const target = event.target instanceof Element ? event.target : null;
        const item = target?.closest('.att-birthday-item[data-birthday-user-id]');
        if (!item) return;
        if (event.type === 'keydown') {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
        }
        void dismissBirthdayNotification(Number(item.dataset.birthdayUserId));
    }
    document.addEventListener('click', clickedBirthdayNotice);
    document.addEventListener('keydown', clickedBirthdayNotice);
''', 'JS status en klikhandler')

js = once(js, '    function showBirthdayToast(rows) {\n        if (!rows.length) {', '    function showBirthdayToast(rows) {\n        if (birthdayToastSuppressed || !rows.length) {', 'JS toast stop')
js = once(js, '            const rows = data.items;', '''            birthdayDismissToken = String(data.csrf_token || '');
            const rows = data.items.filter(
                item => !birthdayDismissPending.has(Number(item.user_id))
            );''', 'JS GET status')
js = once(js, "'<div class=\"notification-item att-birthday-item\">'", ''''<div class="notification-item att-birthday-item"'
                    + ' role="button" tabindex="0"'
                    + ' data-birthday-user-id="'
                    + Number(birthday.user_id)
                    + '" title="Als gelezen markeren">' ''', 'JS verjaardagskaart')
js = once(js, '            .notification-item.att-birthday-item {', '''            .notification-item.att-birthday-item {
                cursor: pointer;
            }
            .notification-item.att-birthday-item:focus-visible {
                outline: 3px solid #a16207;
                outline-offset: 2px;
            }
            .notification-item.att-birthday-item {''', 'JS stijl')

stamp = datetime.now().strftime('%Y%m%d-%H%M%S')
backup_dir = root / '.att-birthday-backups'
backup_dir.mkdir(mode=0o700, exist_ok=True)
backups = []
try:
    for path in (api_path, js_path):
        backup = backup_dir / (path.name + '.bak-birthday-' + stamp)
        shutil.copy2(path, backup)
        backups.append((path, backup))

    # Lint before replacing either live file.
    tmp_api = backup_dir / (api_path.name + '.birthday-test-' + stamp)
    tmp_js = backup_dir / (js_path.stem + '.birthday-test-' + stamp + js_path.suffix)
    tmp_api.write_text(api, encoding='utf-8')
    tmp_js.write_text(js, encoding='utf-8')
    try:
        check = subprocess.run(['php', '-l', str(tmp_api)], capture_output=True, text=True)
        if check.returncode != 0:
            raise RuntimeError('PHP syntaxcontrole mislukt:\n' + check.stdout + check.stderr)
        if shutil.which('node'):
            check = subprocess.run(['node', '--check', str(tmp_js)], capture_output=True, text=True)
            if check.returncode != 0:
                raise RuntimeError('JavaScript syntaxcontrole mislukt:\n' + check.stdout + check.stderr)
        migration = r'''require "/var/www/att-address-portal/app/Helpers/bootstrap.php";
$created = db()->exec("CREATE TABLE IF NOT EXISTS att_birthday_notification_reads (
 reader_user_id BIGINT UNSIGNED NOT NULL,
 birthday_user_id BIGINT UNSIGNED NOT NULL,
 notification_date DATE NOT NULL,
 read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (reader_user_id, birthday_user_id, notification_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if ($created === false) { throw new RuntimeException("Tabel aanmaken mislukt"); }
echo "Databasetabel gereed\n";'''
        check = subprocess.run(['php', '-r', migration], capture_output=True, text=True)
        if check.returncode != 0 or 'Databasetabel gereed' not in check.stdout:
            raise RuntimeError('Databasecontrole/migratie mislukt; nog niets live gewijzigd:\n' + check.stdout + check.stderr)
        print(check.stdout.strip())
        shutil.copy2(tmp_api, api_path)
        shutil.copy2(tmp_js, js_path)
    finally:
        tmp_api.unlink(missing_ok=True)
        tmp_js.unlink(missing_ok=True)
except Exception as exc:
    for path, backup in backups:
        shutil.copy2(backup, path)
    sys.exit(f'{exc}\nOude bestanden hersteld; controleer de uitvoer.')

print('OK: klikbare verjaardagsmeldingen en opslag zijn ingesteld.')
for _, backup in backups:
    print('Back-up:', backup)
print('Vernieuw de ATT Portaal-app volledig en test de melding.')