<?php
/**
 * Admin-UI fuer den Updater. Standalone-Template mit Inline-CSS/JS
 * (das Projekt hat kein gemeinsames Admin-Layout-/Template-System).
 *
 * Verfuegbare Variablen: $manager, $auth, $currentSha, $channel, $csrfToken
 *
 * @var \Updater\UpdateManager $manager
 * @var \Auth $auth
 * @var string $currentSha
 * @var string $channel
 * @var string $csrfToken
 */
$shortSha = $currentSha !== '' ? substr($currentSha, 0, 7) : 'unbekannt';
$channels = \Updater\UpdateManager::CHANNELS;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System-Update</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .wrap { max-width: 760px; margin: 0 auto; }
        .topbar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .topbar h1 { color: #fff; font-size: 22px; }
        .topbar a {
            background: rgba(255,255,255,.18); color: #fff; text-decoration: none;
            padding: 9px 16px; border-radius: 8px; font-size: 14px;
        }
        .card {
            background: #fff; border-radius: 16px; padding: 28px;
            box-shadow: 0 14px 40px rgba(0,0,0,.2); margin-bottom: 22px;
        }
        .card h2 { font-size: 16px; color: #333; margin-bottom: 16px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .row:last-child { border-bottom: none; }
        .row .k { color: #777; font-size: 14px; }
        .row .v { color: #222; font-size: 14px; font-weight: 600; font-family: monospace; }
        .btn {
            background: #667eea; color: #fff; border: none; border-radius: 9px;
            padding: 12px 20px; font-size: 15px; font-weight: 600; cursor: pointer;
            transition: background .2s;
        }
        .btn:hover { background: #5568d3; }
        .btn:disabled { background: #bbb; cursor: not-allowed; }
        .btn-green { background: #2e9e5b; }
        .btn-green:hover { background: #257d49; }
        select {
            padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;
        }
        .muted { color: #888; font-size: 13px; margin-top: 8px; }
        .progress-wrap { display: none; margin-top: 16px; }
        .progress-bar { height: 14px; background: #eee; border-radius: 8px; overflow: hidden; }
        .progress-fill { height: 100%; width: 0; background: #667eea; transition: width .3s; }
        .progress-msg { font-size: 13px; color: #555; margin-top: 8px; }
        .alert { padding: 12px 14px; border-radius: 9px; font-size: 14px; margin-top: 14px; display: none; }
        .alert.show { display: block; }
        .alert-error { background: #fee; border: 1px solid #fcc; color: #c33; }
        .alert-ok { background: #efe; border: 1px solid #cfc; color: #2a7; }
        .changelog {
            background: #f8f9fb; border-radius: 9px; padding: 14px; margin-top: 14px;
            font-size: 13px; color: #444; white-space: pre-wrap; max-height: 260px; overflow: auto;
            display: none;
        }
        .mig-item { display: flex; justify-content: space-between; padding: 6px 0; font-size: 13px; }
        .badge { padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-on { background: #e3f6ea; color: #2a7; }
        .badge-off { background: #fdeaea; color: #c33; }
        .tabs { display: flex; gap: 8px; margin-bottom: 16px; }
        .tab { padding: 8px 14px; border-radius: 8px; background: #f0f0f0; cursor: pointer; font-size: 14px; }
        .tab.active { background: #667eea; color: #fff; }
        .pane { display: none; }
        .pane.active { display: block; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <h1>🔄 System-Update</h1>
        <a href="/admin/">← Administration</a>
    </div>

    <div class="tabs">
        <div class="tab active" data-pane="update">Update</div>
        <div class="tab" data-pane="migrations">Migrationen</div>
    </div>

    <div class="pane active" id="pane-update">
        <div class="card">
            <h2>Aktuelle Version</h2>
            <div class="row"><span class="k">Installierte Version (SHA)</span><span class="v" id="curSha"><?= htmlspecialchars($shortSha) ?></span></div>
            <div class="row"><span class="k">Update-Channel</span>
                <span class="v">
                    <select id="channel">
                        <?php foreach ($channels as $key => $url): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $channel === $key ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($key)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </div>
            <p class="muted">Channel wird in <code>updater/storage/updater-settings.json</code> gespeichert.</p>
        </div>

        <div class="card">
            <h2>Updates</h2>
            <button class="btn" id="btnCheck">Auf Updates prüfen</button>
            <button class="btn btn-green" id="btnInstall" style="display:none;">Update installieren</button>

            <div class="changelog" id="changelog"></div>

            <div class="progress-wrap" id="progressWrap">
                <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
                <div class="progress-msg" id="progressMsg"></div>
            </div>

            <div class="alert alert-error" id="alertError"></div>
            <div class="alert alert-ok" id="alertOk"></div>
        </div>
    </div>

    <div class="pane" id="pane-migrations">
        <div class="card">
            <h2>Migrations-Status</h2>
            <div id="migList"><p class="muted">Wird geladen …</p></div>
        </div>
    </div>
</div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const $ = (id) => document.getElementById(id);

function showAlert(type, msg) {
    const el = type === 'error' ? $('alertError') : $('alertOk');
    el.textContent = msg; el.classList.add('show');
    const other = type === 'error' ? $('alertOk') : $('alertError');
    other.classList.remove('show');
}
function clearAlerts() { $('alertError').classList.remove('show'); $('alertOk').classList.remove('show'); }

// Tabs
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
    document.querySelectorAll('.pane').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    $('pane-' + t.dataset.pane).classList.add('active');
    if (t.dataset.pane === 'migrations') loadMigrations();
}));

// Channel speichern
$('channel').addEventListener('change', async (e) => {
    const body = new URLSearchParams({ action: 'set_channel', channel: e.target.value, csrf_token: CSRF });
    const r = await fetch('update.php', { method: 'POST', body });
    if (r.ok) showAlert('ok', 'Channel auf "' + e.target.value + '" gesetzt.');
    else showAlert('error', 'Channel konnte nicht gespeichert werden.');
});

// Auf Updates pruefen
$('btnCheck').addEventListener('click', async () => {
    clearAlerts();
    $('btnCheck').disabled = true; $('btnCheck').textContent = 'Prüfe …';
    try {
        const r = await fetch('update.php?action=check');
        const d = await r.json();
        if (d.error) { showAlert('error', d.error); return; }
        if (d.has_update) {
            showAlert('ok', 'Update verfügbar: ' + (d.latest_sha ? d.latest_sha.substring(0,7) : '') +
                (d.versions_behind ? ' (' + d.versions_behind + ' Commits zurück)' : ''));
            $('btnInstall').style.display = 'inline-block';
            if (d.changelog || d.title) {
                const cl = $('changelog');
                cl.textContent = (d.title ? d.title + '\n\n' : '') + (d.changelog || '');
                cl.style.display = 'block';
            }
        } else {
            showAlert('ok', 'System ist aktuell.');
            $('btnInstall').style.display = 'none';
            $('changelog').style.display = 'none';
        }
    } catch (e) {
        showAlert('error', 'Prüfung fehlgeschlagen: ' + e.message);
    } finally {
        $('btnCheck').disabled = false; $('btnCheck').textContent = 'Auf Updates prüfen';
    }
});

// Update installieren
$('btnInstall').addEventListener('click', async () => {
    if (!confirm('Update jetzt installieren? Die Seite ist während des Updates kurz im Wartungsmodus.')) return;
    clearAlerts();
    $('btnInstall').disabled = true; $('btnCheck').disabled = true;
    $('progressWrap').style.display = 'block';

    let polling = setInterval(pollProgress, 1500);
    try {
        const body = new URLSearchParams({ action: 'install', csrf_token: CSRF });
        const r = await fetch('update.php', { method: 'POST', body });
        const d = await r.json();
        clearInterval(polling); pollProgress();
        if (d.success) {
            setProgress(100, 'Update abgeschlossen.');
            showAlert('ok', 'Update erfolgreich installiert (' + (d.sha ? d.sha.substring(0,7) : '') + '). Seite wird neu geladen …');
            setTimeout(() => location.reload(), 2500);
        } else {
            showAlert('error', 'Update fehlgeschlagen: ' + (d.error || 'Unbekannter Fehler'));
            $('btnInstall').disabled = false; $('btnCheck').disabled = false;
        }
    } catch (e) {
        clearInterval(polling);
        showAlert('error', 'Update-Request fehlgeschlagen: ' + e.message);
        $('btnInstall').disabled = false; $('btnCheck').disabled = false;
    }
});

function setProgress(pct, msg) {
    $('progressFill').style.width = pct + '%';
    $('progressMsg').textContent = msg || '';
}
async function pollProgress() {
    try {
        const r = await fetch('update.php?action=progress');
        const d = await r.json();
        setProgress(d.percent || 0, d.message || '');
    } catch (e) { /* ignore */ }
}

async function loadMigrations() {
    const el = $('migList');
    el.innerHTML = '<p class="muted">Wird geladen …</p>';
    try {
        const r = await fetch('update.php?action=migrations');
        const d = await r.json();
        if (d.error) { el.innerHTML = '<p class="muted">' + d.error + '</p>'; return; }
        if (!d.migrations || !d.migrations.length) {
            el.innerHTML = '<p class="muted">Keine Updater-Migrationen vorhanden.</p>';
            return;
        }
        el.innerHTML = d.migrations.map(m =>
            '<div class="mig-item"><span>' + m.filename + '</span>' +
            '<span class="badge ' + (m.applied ? 'badge-on">angewandt' : 'badge-off">offen') + '</span></div>'
        ).join('');
    } catch (e) {
        el.innerHTML = '<p class="muted">Fehler: ' + e.message + '</p>';
    }
}
</script>
</body>
</html>
