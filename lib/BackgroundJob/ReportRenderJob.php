<?php

/**
 * Scheduled report render job.
 *
 * Daily TimedJob that walks every dashboard object in the `reports`
 * register, computes whether it's due for re-rendering based on the
 * `schedule.intervalSec` field, renders to the configured `delivery.format`,
 * and drops the result into the configured Files folder (default
 * `/Reports/<dashboard-slug>/`).
 *
 * Email delivery is deferred to Phase 2b (needs notification template
 * work).
 *
 * Operator overrides:
 *   - `rapportage_scheduled_renders_enabled` (bool, default: true) — kill switch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Reporting\ReportRenderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Daily scheduled-report renderer.
 */
class ReportRenderJob extends TimedJob
{

    /**
     * Daily cadence — runs once every 24 hours.
     *
     * @var int
     */
    private const RUN_INTERVAL_SECONDS = 86400;

    /**
     * App identifier.
     *
     * @var string
     */
    private const APP_ID = 'openregister';

    /**
     * App-config kill-switch key.
     *
     * @var string
     */
    private const CONFIG_KEY_ENABLED = 'rapportage_scheduled_renders_enabled';

    /**
     * Slug of the operator-imported `reports` register.
     *
     * @var string
     */
    private const REPORTS_REGISTER_SLUG = 'reports';

    /**
     * Constructor.
     *
     * @param ITimeFactory        $time           Time factory.
     * @param IAppConfig          $appConfig      App-config reader.
     * @param ReportRenderService $renderService  Render composer.
     * @param RegisterMapper      $registerMapper Register lookup.
     * @param MagicMapper         $objectMapper   Object loader.
     * @param IRootFolder         $rootFolder     Files root.
     * @param LoggerInterface     $logger         Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IAppConfig $appConfig,
        private readonly ReportRenderService $renderService,
        private readonly RegisterMapper $registerMapper,
        private readonly MagicMapper $objectMapper,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);

    }//end __construct()

    /**
     * Drive the scheduled renders.
     *
     * @param mixed $argument Job arguments (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $enabled = filter_var(
            $this->appConfig->getValueString(
                app: self::APP_ID,
                key: self::CONFIG_KEY_ENABLED,
                default: 'true'
            ),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($enabled === false) {
            $this->logger->info(
                message: '[ReportRenderJob] Scheduled renders disabled (kill switch on), skipping',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        try {
            $register = $this->loadReportsRegister();
        } catch (\Throwable $e) {
            $this->logger->info(
                message: '[ReportRenderJob] No `reports` register found — bundle not imported, skipping',
                context: ['error' => $e->getMessage()]
            );
            return;
        }

        $candidates = $this->loadDashboards(register: $register);
        $now        = new DateTime();
        $rendered   = 0;
        $skipped    = 0;
        foreach ($candidates as $dashboard) {
            $payload  = $dashboard->getObject() ?? [];
            $schedule = ($payload['schedule'] ?? null);
            if ($this->shouldRender(payload: $payload, schedule: $schedule, now: $now) === false) {
                $skipped++;
                continue;
            }

            try {
                $this->renderAndDeliver(dashboard: $dashboard, payload: $payload);
                $rendered++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    message: '[ReportRenderJob] Render failed for dashboard',
                    context: [
                        'dashboard' => ($payload['titel'] ?? $dashboard->getUuid()),
                        'error'     => $e->getMessage(),
                    ]
                );
            }
        }//end foreach

        $this->logger->info(
            message: '[ReportRenderJob] Scheduled-render pass complete',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'candidates' => count($candidates),
                'rendered'   => $rendered,
                'skipped'    => $skipped,
            ]
        );

    }//end run()

    /**
     * Should the dashboard be rendered now?
     *
     * @param array<string, mixed>      $payload  Dashboard data.
     * @param array<string, mixed>|null $schedule Schedule descriptor.
     * @param DateTime                  $now      Reference time.
     *
     * @return bool
     */
    private function shouldRender(array $payload, $schedule, DateTime $now): bool
    {
        if (is_array($schedule) === false) {
            return false;
        }

        $active = filter_var($schedule['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($active === false) {
            return false;
        }

        $intervalSec = (int) ($schedule['intervalSec'] ?? 0);
        if ($intervalSec <= 0) {
            return false;
        }

        $lastRendered = ($payload['lastRenderedAt'] ?? null);
        if (is_string($lastRendered) === true && $lastRendered !== '') {
            try {
                $last = new DateTime($lastRendered);
                $diff = ($now->getTimestamp() - $last->getTimestamp());
                return $diff >= $intervalSec;
            } catch (\Throwable $e) {
                // Malformed timestamp — fall through to render.
                return true;
            }
        }

        // Never rendered → fire now.
        return true;

    }//end shouldRender()

    /**
     * Render + deliver a single dashboard.
     *
     * @param ObjectEntity         $dashboard Dashboard entity.
     * @param array<string, mixed> $payload   Dashboard data.
     *
     * @return void
     */
    private function renderAndDeliver(ObjectEntity $dashboard, array $payload): void
    {
        $delivery = ($payload['delivery'] ?? []);
        $format   = (string) ($delivery['format'] ?? 'xlsx');
        if (in_array(needle: $format, haystack: ReportRenderService::FORMATS, strict: true) === false) {
            $format = 'xlsx';
        }

        $rendered = $this->renderService->render(
            dashboard: $dashboard,
            format: $format
        );

        $channel = (string) ($delivery['channel'] ?? 'files');
        if ($channel === 'files' || $channel === 'both') {
            $this->writeToFiles(
                dashboard: $dashboard,
                payload: $payload,
                rendered: $rendered,
                delivery: is_array($delivery) === true ? $delivery : []
            );
        }

        // Email channel is Phase 2b.
    }//end renderAndDeliver()

    /**
     * Write the rendered bytes to the operator-configured Files folder.
     *
     * Default folder: `/Reports/<dashboard-slug>/<filename>` under the
     * dashboard owner's home. When the dashboard's `delivery.filesFolder`
     * is set, that path is used instead.
     *
     * @param ObjectEntity         $dashboard Dashboard entity.
     * @param array<string, mixed> $payload   Dashboard data.
     * @param array<string, mixed> $rendered  ReportRenderService::render output.
     * @param array<string, mixed> $delivery  Delivery descriptor.
     *
     * @return void
     */
    private function writeToFiles(ObjectEntity $dashboard, array $payload, array $rendered, array $delivery): void
    {
        // SECURITY: refuse to fall back to 'admin' when the dashboard
        // owner is null. The previous default dropped attacker-controlled
        // bytes (rendered HTML/PDF derived from a user-writable
        // ObjectEntity) into admin's Files share, where admin would see
        // them on next login — a phishing/redirect persistence vector.
        // Without an owner we have no honest "who runs this job", so
        // skip delivery and surface the misconfiguration in logs.
        $owner = $dashboard->getOwner();
        if ($owner === null || $owner === '') {
            $this->logger->warning(
                message: '[ReportRenderJob] Dashboard owner missing — skipping Files delivery',
                context: ['dashboardUuid' => $dashboard->getUuid()]
            );
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $owner);
        } catch (NotFoundException $e) {
            $this->logger->warning(
                message: '[ReportRenderJob] User folder unavailable, skipping delivery',
                context: ['owner' => $owner, 'error' => $e->getMessage()]
            );
            return;
        }

        $folderPath = (string) ($delivery['filesFolder'] ?? '');
        if ($folderPath === '') {
            $slug       = $this->slugify(value: (string) ($payload['titel'] ?? 'dashboard'));
            $folderPath = sprintf('Reports/%s', $slug);
        }

        // SECURITY: $delivery['filesFolder'] comes from the dashboard
        // payload (user-controlled JSON). Restrict to a relative path
        // under the owner's home — strip leading slashes and reject any
        // segment that escapes the home root (`..`) or addresses an
        // absolute filesystem path.
        $folderPath = ltrim($folderPath, '/');
        if ($folderPath === '' || str_contains($folderPath, '..') === true) {
            $this->logger->warning(
                message: '[ReportRenderJob] Rejected delivery folder containing path traversal',
                context: ['folder' => $folderPath, 'owner' => $owner]
            );
            return;
        }

        if ($userFolder->nodeExists(path: $folderPath) === false) {
            $userFolder->newFolder(path: $folderPath);
        }

        $folder   = $userFolder->get(path: $folderPath);
        $filename = (string) $rendered['filename'];
        if ($folder->nodeExists(path: $filename) === true) {
            $existing = $folder->get(path: $filename);
            $existing->putContent(data: (string) $rendered['bytes']);
            return;
        }

        $folder->newFile(path: $filename, content: (string) $rendered['bytes']);

    }//end writeToFiles()

    /**
     * Resolve the `reports` register.
     *
     * @return Register
     */
    private function loadReportsRegister(): Register
    {
        return $this->registerMapper->find(
            self::REPORTS_REGISTER_SLUG,
            _rbac: false,
            _multitenancy: false
        );

    }//end loadReportsRegister()

    /**
     * Load every dashboard object in the reports register.
     *
     * @param Register $register Reports register.
     *
     * @return ObjectEntity[]
     */
    private function loadDashboards(Register $register): array
    {
        // The dashboard schema is the only one in the reports register;
        // we walk by-register so a future "report" schema (Phase 3
        // template variants) is also covered.
        try {
            return $this->objectMapper->findAll(
                limit: 200,
                offset: 0,
                filters: ['register' => $register->getId()],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[ReportRenderJob] Failed to enumerate dashboards',
                context: ['error' => $e->getMessage()]
            );
            return [];
        }

    }//end loadDashboards()

    /**
     * Slugify a title for a folder path.
     *
     * @param string $value Title.
     *
     * @return string Slug.
     */
    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'dashboard';
        }

        return $slug;

    }//end slugify()
}//end class
