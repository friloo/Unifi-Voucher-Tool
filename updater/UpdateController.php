<?php
namespace Updater;

/**
 * UpdateController – bedient die Admin-Seite /admin/update sowie deren
 * AJAX-Actions (check, install, progress, set_channel, migrations).
 *
 * Nutzt die vorhandenen Projekt-Klassen \Database und \Auth per Injection.
 * Die eigentliche Logik liegt im UpdateManager; dieser Controller ist nur
 * die duenne Auslieferungs-/Routing-Schicht.
 */
class UpdateController
{
    /** @var \Database */
    private $db;
    /** @var \Auth */
    private $auth;
    /** @var UpdateManager */
    private $manager;
    /** @var AuditLogger */
    private $audit;

    public function __construct(\Database $db, \Auth $auth)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->audit = new AuditLogger($db);
        $this->manager = UpdaterFactory::create($db, $this->audit);
    }

    public function handle(): void
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        switch ($action) {
            case 'check':       $this->actionCheck(); break;
            case 'install':     $this->actionInstall(); break;
            case 'progress':    $this->actionProgress(); break;
            case 'set_channel': $this->actionSetChannel(); break;
            case 'migrations':  $this->actionMigrations(); break;
            case 'run_migrations': $this->actionRunMigrations(); break;
            default:            $this->renderPage();
        }
    }

    // ------------------------------------------------------------- AJAX-Actions

    private function actionCheck(): void
    {
        try {
            $this->json($this->manager->checkForUpdates());
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    private function actionInstall(): void
    {
        if (!$this->auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->json(['error' => 'Ungültiges Sicherheits-Token'], 403);
            return;
        }
        // Session-Lock freigeben, damit parallele Progress-Polls nicht blockieren.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(300);
        $userId = $_SESSION['user_id'] ?? null;
        $result = $this->manager->installUpdate($userId !== null ? (int)$userId : null);
        $this->json($result, $result['success'] ? 200 : 500);
    }

    private function actionProgress(): void
    {
        // Kein CSRF noetig (read-only). Session-Lock sofort freigeben.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->json($this->manager->getProgress());
    }

    private function actionSetChannel(): void
    {
        if (!$this->auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->json(['error' => 'Ungültiges Sicherheits-Token'], 403);
            return;
        }
        $channel = $_POST['channel'] ?? 'stable';
        UpdaterFactory::saveChannel($channel);
        $this->json(['success' => true, 'channel' => $channel]);
    }

    private function actionMigrations(): void
    {
        try {
            $runner = new MigrationRunner(
                $this->db->getConnection(),
                __DIR__ . '/migrations',
                __DIR__ . '/storage'
            );
            $this->json(['migrations' => $runner->status()]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function actionRunMigrations(): void
    {
        if (!$this->auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->json(['error' => 'Ungültiges Sicherheits-Token'], 403);
            return;
        }
        try {
            $runner = new MigrationRunner(
                $this->db->getConnection(),
                __DIR__ . '/migrations',
                __DIR__ . '/storage'
            );
            $applied = $runner->runPending(true);
            if ($this->audit) {
                $this->audit->log('migrations_run', ['applied' => $applied], $_SESSION['user_id'] ?? null);
            }
            $this->json(['success' => true, 'applied' => $applied, 'migrations' => $runner->status()]);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    // ------------------------------------------------------------------- Render

    private function renderPage(): void
    {
        $manager = $this->manager;
        $auth = $this->auth;
        $currentSha = $manager->getCurrentVersion();
        $channel = $manager->getChannel();
        $csrfToken = $auth->getCsrfToken();
        require __DIR__ . '/templates/update.php';
    }

    private function json($data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
