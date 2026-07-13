<?php

/**
 * OpenRegister Scheduled Report Service
 *
 * Validation, ownership-scoped CRUD, due-computation, and execution for
 * ScheduledReport rows — composes ExportService, FileService-equivalent
 * Files delivery, and Nextcloud notifications into recurring report
 * delivery (ADR-Leaf-First).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing and executing scheduled report exports.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Owns validation, CRUD,
 *     due-computation, and single-report execution (export/deliver/notify)
 *     in one cohesive unit per design.md; splitting execution into its own
 *     class would just relocate the complexity behind an extra collaborator.
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */
class ScheduledReportService
{

    /**
     * Export formats a scheduled report may use — a deliberate subset of
     * `ExportService`'s supported set (excludes `json`; see design.md).
     *
     * @var string[]
     */
    public const ALLOWED_FORMATS = ['csv', 'excel', 'pdf'];

    /**
     * Supported schedule cadences (no cron expressions — see design.md).
     *
     * @var string[]
     */
    public const ALLOWED_SCHEDULE_TYPES = ['daily', 'weekly', 'monthly'];

    /**
     * Elapsed-period thresholds in seconds, keyed by schedule type.
     *
     * @var array<string,int>
     */
    private const PERIOD_SECONDS = [
        'daily'   => 86400,
        'weekly'  => 604800,
        'monthly' => 2592000,
    ];

    /**
     * Constructor.
     *
     * @param ScheduledReportMapper $mapper              Scheduled report mapper.
     * @param RegisterMapper        $registerMapper      Register lookup (create/update validation).
     * @param SchemaMapper          $schemaMapper        Schema lookup (create/update validation).
     * @param ExportService         $exportService       The export engine this feature composes.
     * @param IRootFolder           $rootFolder          Files root, for owner-folder delivery.
     * @param IUserManager          $userManager         Resolves the owner IUser for impersonation.
     * @param IUserSession          $userSession         Session, swapped to the owner during a run.
     * @param IManager              $notificationManager Notification dispatch.
     * @param LoggerInterface       $logger              Logger.
     */
    public function __construct(
        private readonly ScheduledReportMapper $mapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ExportService $exportService,
        private readonly IRootFolder $rootFolder,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly IManager $notificationManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Find a scheduled report by id.
     *
     * @param int $id The scheduled report id.
     *
     * @return ScheduledReport
     *
     * @throws DoesNotExistException When no row matches.
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function find(int $id): ScheduledReport
    {
        return $this->mapper->find(id: $id);
    }//end find()

    /**
     * List the reports owned by a user.
     *
     * @param string $ownerUid The owning uid.
     *
     * @return ScheduledReport[]
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function findForOwner(string $ownerUid): array
    {
        return $this->mapper->findByOwner(owner: $ownerUid);
    }//end findForOwner()

    /**
     * List every scheduled report (admin listing).
     *
     * @return ScheduledReport[]
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function findAllForAdmin(): array
    {
        return $this->mapper->findAll();
    }//end findAllForAdmin()

    /**
     * Create a scheduled report owned by the given user.
     *
     * @param array<string,mixed> $data     Request payload.
     * @param string              $ownerUid The owning uid (always the caller — self-service creation).
     *
     * @return ScheduledReport
     *
     * @throws InvalidArgumentException When validation fails.
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function create(array $data, string $ownerUid): ScheduledReport
    {
        $this->validate(data: $data);

        $now = new DateTime();

        $report = new ScheduledReport();
        $report->setOwner($ownerUid);
        $report->setName((string) $data['name']);
        $report->setRegisterId((int) $data['registerId']);
        $report->setSchemaId($this->coerceNullableInt(value: ($data['schemaId'] ?? null)));
        $report->setFilters(json_encode(($data['filters'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $report->setFormat((string) $data['format']);
        $report->setScheduleType((string) $data['scheduleType']);
        $report->setScheduleHour((int) ($data['scheduleHour'] ?? 0));
        $report->setScheduleDayOfWeek($this->coerceNullableInt(value: ($data['scheduleDayOfWeek'] ?? null)));
        $report->setScheduleDayOfMonth($this->coerceNullableInt(value: ($data['scheduleDayOfMonth'] ?? null)));
        $report->setDeliveryFolder((string) ($data['deliveryFolder'] ?? 'Reports/'));
        $report->setEnabled(true);
        $report->setLastRunAt(null);
        $report->setLastStatus(null);
        $report->setLastError(null);
        $report->setCreatedAt($now);
        $report->setUpdatedAt($now);

        return $this->mapper->insert(entity: $report);
    }//end create()

    /**
     * Update an existing scheduled report. Only the owner or an admin may update.
     *
     * @param int                 $id            The scheduled report id.
     * @param array<string,mixed> $data          Fields to update (same shape as create()).
     * @param string              $callerUid     The calling user's uid.
     * @param bool                $callerIsAdmin Whether the caller is a Nextcloud administrator.
     *
     * @return ScheduledReport
     *
     * @throws DoesNotExistException When no row matches.
     * @throws RuntimeException When the caller does not own the row and is not an admin.
     * @throws InvalidArgumentException When validation fails.
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function update(int $id, array $data, string $callerUid, bool $callerIsAdmin): ScheduledReport
    {
        $report = $this->mapper->find(id: $id);
        $this->assertOwnerOrAdmin(report: $report, callerUid: $callerUid, callerIsAdmin: $callerIsAdmin);

        // Merge with existing values so a partial update doesn't blow away
        // fields the caller didn't send.
        $schemaId = $report->getSchemaId();
        if (array_key_exists('schemaId', $data) === true) {
            $schemaId = $data['schemaId'];
        }

        $scheduleDayOfWeek = $report->getScheduleDayOfWeek();
        if (array_key_exists('scheduleDayOfWeek', $data) === true) {
            $scheduleDayOfWeek = $data['scheduleDayOfWeek'];
        }

        $scheduleDayOfMonth = $report->getScheduleDayOfMonth();
        if (array_key_exists('scheduleDayOfMonth', $data) === true) {
            $scheduleDayOfMonth = $data['scheduleDayOfMonth'];
        }

        $merged = [
            'name'               => ($data['name'] ?? $report->getName()),
            'registerId'         => ($data['registerId'] ?? $report->getRegisterId()),
            'schemaId'           => $schemaId,
            'filters'            => ($data['filters'] ?? $report->getFiltersArray()),
            'format'             => ($data['format'] ?? $report->getFormat()),
            'scheduleType'       => ($data['scheduleType'] ?? $report->getScheduleType()),
            'scheduleHour'       => ($data['scheduleHour'] ?? $report->getScheduleHour()),
            'scheduleDayOfWeek'  => $scheduleDayOfWeek,
            'scheduleDayOfMonth' => $scheduleDayOfMonth,
            'deliveryFolder'     => ($data['deliveryFolder'] ?? $report->getDeliveryFolder()),
        ];
        $this->validate(data: $merged);

        $report->setName((string) $merged['name']);
        $report->setRegisterId((int) $merged['registerId']);
        $report->setSchemaId($this->coerceNullableInt(value: $merged['schemaId']));
        $report->setFilters(json_encode($merged['filters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $report->setFormat((string) $merged['format']);
        $report->setScheduleType((string) $merged['scheduleType']);
        $report->setScheduleHour((int) $merged['scheduleHour']);
        $report->setScheduleDayOfWeek($this->coerceNullableInt(value: $merged['scheduleDayOfWeek']));
        $report->setScheduleDayOfMonth($this->coerceNullableInt(value: $merged['scheduleDayOfMonth']));
        $report->setDeliveryFolder((string) $merged['deliveryFolder']);
        if (array_key_exists('enabled', $data) === true) {
            $report->setEnabled((bool) $data['enabled']);
        }

        $report->setUpdatedAt(new DateTime());

        return $this->mapper->update(entity: $report);
    }//end update()

    /**
     * Delete a scheduled report. Only the owner or an admin may delete.
     *
     * @param int    $id            The scheduled report id.
     * @param string $callerUid     The calling user's uid.
     * @param bool   $callerIsAdmin Whether the caller is a Nextcloud administrator.
     *
     * @return void
     *
     * @throws DoesNotExistException When no row matches.
     * @throws RuntimeException When the caller does not own the row and is not an admin.
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function delete(int $id, string $callerUid, bool $callerIsAdmin): void
    {
        $report = $this->mapper->find(id: $id);
        $this->assertOwnerOrAdmin(report: $report, callerUid: $callerUid, callerIsAdmin: $callerIsAdmin);
        $this->mapper->delete(entity: $report);
    }//end delete()

    /**
     * Assert the caller owns the report or is an admin.
     *
     * @param ScheduledReport $report        The report.
     * @param string          $callerUid     The calling user's uid.
     * @param bool            $callerIsAdmin Whether the caller is an admin.
     *
     * @return void
     *
     * @throws RuntimeException When the assertion fails.
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function assertOwnerOrAdmin(ScheduledReport $report, string $callerUid, bool $callerIsAdmin): void
    {
        if ($callerIsAdmin === true) {
            return;
        }

        if ($report->getOwner() === $callerUid) {
            return;
        }

        throw new RuntimeException('You do not have permission to access this scheduled report.');
    }//end assertOwnerOrAdmin()

    /**
     * Validate a create/update payload. Register/schema existence is checked
     * via the caller's own request-scoped RBAC/multi-tenancy session (not an
     * explicit owner parameter) — configuration-time validation intentionally
     * uses the same access lens as every other read the caller performs.
     *
     * @param array<string,mixed> $data The payload.
     *
     * @return void
     *
     * @throws InvalidArgumentException When any field is invalid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function validate(array $data): void
    {
        if (empty($data['name']) === true) {
            throw new InvalidArgumentException('A name is required.');
        }

        if (empty($data['registerId']) === true) {
            throw new InvalidArgumentException('A registerId is required.');
        }

        try {
            $this->registerMapper->find($data['registerId'], _multitenancy: true);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Register not found or not accessible.');
        }

        $schemaId = $this->coerceNullableInt(value: ($data['schemaId'] ?? null));
        if ($schemaId !== null) {
            try {
                $this->schemaMapper->find($schemaId, _multitenancy: true);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('Schema not found or not accessible.');
            }
        }

        $format = ($data['format'] ?? null);
        if (in_array($format, self::ALLOWED_FORMATS, true) === false) {
            throw new InvalidArgumentException(
                sprintf('format must be one of: %s', implode(', ', self::ALLOWED_FORMATS))
            );
        }

        if ($format === 'csv' && $schemaId === null) {
            throw new InvalidArgumentException('CSV export requires a specific schemaId.');
        }

        $scheduleType = ($data['scheduleType'] ?? null);
        if (in_array($scheduleType, self::ALLOWED_SCHEDULE_TYPES, true) === false) {
            throw new InvalidArgumentException(
                sprintf('scheduleType must be one of: %s', implode(', ', self::ALLOWED_SCHEDULE_TYPES))
            );
        }

        $scheduleHour = (int) ($data['scheduleHour'] ?? 0);
        if ($scheduleHour < 0 || $scheduleHour > 23) {
            throw new InvalidArgumentException('scheduleHour must be between 0 and 23.');
        }

        if ($scheduleType === 'weekly') {
            $dayOfWeek = $this->coerceNullableInt(value: ($data['scheduleDayOfWeek'] ?? null));
            if ($dayOfWeek === null || $dayOfWeek < 0 || $dayOfWeek > 6) {
                throw new InvalidArgumentException('scheduleDayOfWeek (0-6) is required when scheduleType is weekly.');
            }
        }

        if ($scheduleType === 'monthly') {
            $dayOfMonth = $this->coerceNullableInt(value: ($data['scheduleDayOfMonth'] ?? null));
            if ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 28) {
                throw new InvalidArgumentException('scheduleDayOfMonth (1-28) is required when scheduleType is monthly.');
            }
        }
    }//end validate()

    /**
     * Coerce a value to a nullable int. Empty string and null both become null.
     *
     * @param mixed $value Input.
     *
     * @return ?int
     */
    private function coerceNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) === false) {
            return null;
        }

        return (int) $value;
    }//end coerceNullableInt()

    /**
     * Whether a report is due to run now.
     *
     * Catch-up-safe elapsed-period check per design.md: due when never run,
     * or when at least a full schedule period has elapsed since the last run.
     * Disabled reports are never due.
     *
     * @param ScheduledReport   $report The report to check.
     * @param DateTimeInterface $now    The reference time.
     *
     * @return bool
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function isDue(ScheduledReport $report, DateTimeInterface $now): bool
    {
        if ($report->getEnabled() !== true) {
            return false;
        }

        $lastRunAt = $report->getLastRunAt();
        if ($lastRunAt === null) {
            return true;
        }

        $periodSeconds = (self::PERIOD_SECONDS[$report->getScheduleType()] ?? self::PERIOD_SECONDS['daily']);
        $elapsed       = ($now->getTimestamp() - $lastRunAt->getTimestamp());

        return $elapsed >= $periodSeconds;
    }//end isDue()

    /**
     * Execute a single scheduled report: export as the owner, deliver to
     * Files, notify the owner, and persist the outcome. Never throws — all
     * failures (including `ExportTooLargeException`) are caught, recorded on
     * the report, and notified, so a caller (job or run-now) can iterate a
     * batch without per-report try/catch of its own (though callers SHOULD
     * still wrap this call for defence in depth — see ScheduledReportJob).
     *
     * @param ScheduledReport $report The report to run.
     *
     * @return void
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function runOne(ScheduledReport $report): void
    {
        $ownerUid = $report->getOwner();
        $owner    = null;
        if ($ownerUid !== null) {
            $owner = $this->userManager->get($ownerUid);
        }

        if ($owner === null) {
            $this->logger->warning(
                message: '[ScheduledReportService] Owner account not found — skipping run',
                context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'owner' => $ownerUid]
            );
            $this->markOutcome(report: $report, status: 'failed', error: 'Owner account no longer exists.');
            return;
        }

        $previousUser = $this->userSession->getUser();
        $this->userSession->setUser($owner);

        try {
            $bytes    = $this->exportBytes(report: $report, owner: $owner);
            $filename = $this->buildFilename(report: $report);
            $this->deliverToFiles(report: $report, owner: $owner, filename: $filename, bytes: $bytes);
            $this->markOutcome(report: $report, status: 'success', error: null);
            $this->notifyOwner(report: $report, success: true, reason: null, filename: $filename);
        } catch (ExportTooLargeException $e) {
            $this->logger->warning(
                message: '[ScheduledReportService] Export too large',
                context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
            );
            $this->markOutcome(report: $report, status: 'failed', error: $e->getMessage());
            $this->notifyOwner(report: $report, success: false, reason: $e->getMessage(), filename: null);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[ScheduledReportService] Scheduled report run failed',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'reportId' => $report->getId(),
                    'error'    => $e->getMessage(),
                ]
            );
            $this->markOutcome(report: $report, status: 'failed', error: $e->getMessage());
            $this->notifyOwner(report: $report, success: false, reason: $e->getMessage(), filename: null);
        } finally {
            $this->userSession->setUser($previousUser);
        }//end try
    }//end runOne()

    /**
     * Resolve register/schema and run the format-appropriate ExportService method.
     *
     * @param ScheduledReport $report The report.
     * @param \OCP\IUser      $owner  The impersonated owner (drives RBAC-aware admin-metadata column visibility).
     *
     * @return string Raw export bytes.
     *
     * @throws ExportTooLargeException When the pdf row cap is exceeded.
     */
    private function exportBytes(ScheduledReport $report, \OCP\IUser $owner): string
    {
        $register = $this->registerMapper->find($report->getRegisterId(), _rbac: false, _multitenancy: false);
        $schema   = null;
        if ($report->getSchemaId() !== null) {
            $schema = $this->schemaMapper->find($report->getSchemaId(), _rbac: false, _multitenancy: false);
        }

        $filters = $report->getFiltersArray();

        return match ($report->getFormat()) {
            'csv' => $this->exportService->exportToCsv(register: $register, schema: $schema, filters: $filters, currentUser: $owner),
            'pdf' => $this->exportService->exportToPdf(register: $register, schema: $schema, filters: $filters, currentUser: $owner),
            default => $this->spreadsheetToXlsxBytes(
                spreadsheet: $this->exportService->exportToExcel(register: $register, schema: $schema, filters: $filters, currentUser: $owner)
            ),
        };
    }//end exportBytes()

    /**
     * Write a PhpSpreadsheet Spreadsheet to raw XLSX bytes.
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet The spreadsheet.
     *
     * @return string Raw XLSX bytes.
     */
    private function spreadsheetToXlsxBytes(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }//end spreadsheetToXlsxBytes()

    /**
     * Build the dated delivery filename for a report.
     *
     * @param ScheduledReport $report The report.
     *
     * @return string
     */
    private function buildFilename(ScheduledReport $report): string
    {
        $extension = match ($report->getFormat()) {
            'csv' => 'csv',
            'pdf' => 'pdf',
            default => 'xlsx',
        };

        $slug = $this->slugify(value: (string) $report->getName());
        $date = (new DateTime())->format('Y-m-d');

        return sprintf('%s_%s.%s', $slug, $date, $extension);
    }//end buildFilename()

    /**
     * Slugify a value for use in a filename.
     *
     * @param string $value The value.
     *
     * @return string
     */
    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            return 'report';
        }

        return $slug;
    }//end slugify()

    /**
     * Deliver rendered bytes into the owner's Files, under the configured
     * (sanitized) delivery folder. Mirrors `ReportRenderJob::writeToFiles()`'s
     * ownership + path-traversal hardening.
     *
     * @param ScheduledReport $report   The report.
     * @param \OCP\IUser      $owner    The owner.
     * @param string          $filename The delivery filename.
     * @param string          $bytes    The rendered bytes.
     *
     * @return void
     *
     * @throws RuntimeException When the delivery folder is rejected or the user folder is unavailable.
     */
    private function deliverToFiles(ScheduledReport $report, \OCP\IUser $owner, string $filename, string $bytes): void
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder(userId: $owner->getUID());
        } catch (NotFoundException $e) {
            throw new RuntimeException('Owner Nextcloud Files folder unavailable: '.$e->getMessage());
        }

        $folderPath = trim((string) ($report->getDeliveryFolder() ?? 'Reports/'), '/');
        if ($folderPath === '') {
            $folderPath = 'Reports';
        }

        if (str_contains($folderPath, '..') === true) {
            throw new RuntimeException('Delivery folder contains a path-traversal segment and was rejected.');
        }

        if ($userFolder->nodeExists(path: $folderPath) === false) {
            $userFolder->newFolder(path: $folderPath);
        }

        $folder = $userFolder->get(path: $folderPath);
        if ($folder->nodeExists(path: $filename) === true) {
            $folder->get(path: $filename)->putContent(data: $bytes);
            return;
        }

        $folder->newFile(path: $filename, content: $bytes);
    }//end deliverToFiles()

    /**
     * Persist the outcome of a run.
     *
     * @param ScheduledReport $report The report.
     * @param string          $status success|failed.
     * @param string|null     $error  The failure reason, if any.
     *
     * @return void
     */
    private function markOutcome(ScheduledReport $report, string $status, ?string $error): void
    {
        $report->setLastRunAt(new DateTime());
        $report->setLastStatus($status);
        $report->setLastError($error);
        $report->setUpdatedAt(new DateTime());

        try {
            $this->mapper->update(entity: $report);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[ScheduledReportService] Failed to persist run outcome',
                context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
            );
        }
    }//end markOutcome()

    /**
     * Notify the report owner of a run's outcome.
     *
     * @param ScheduledReport $report   The report.
     * @param bool            $success  Whether the run succeeded.
     * @param string|null     $reason   The failure reason (failure only).
     * @param string|null     $filename The delivered filename (success only).
     *
     * @return void
     */
    private function notifyOwner(ScheduledReport $report, bool $success, ?string $reason, ?string $filename): void
    {
        $ownerUid = $report->getOwner();
        if ($ownerUid === null) {
            return;
        }

        try {
            $subject    = 'scheduled_report_failed';
            $parameters = [
                'reportId'   => $report->getId(),
                'reportName' => $report->getName(),
                'reason'     => ($reason ?? 'Unknown error'),
            ];
            if ($success === true) {
                $subject    = 'scheduled_report_delivered';
                $parameters = [
                    'reportId'   => $report->getId(),
                    'reportName' => $report->getName(),
                    'folder'     => $report->getDeliveryFolder(),
                    'filename'   => $filename,
                ];
            }

            $notification = $this->notificationManager->createNotification();
            $notification->setApp('openregister')
                ->setUser($ownerUid)
                ->setDateTime(new DateTime())
                ->setObject(type: 'scheduled_report', id: (string) $report->getId())
                ->setSubject(subject: $subject, parameters: $parameters);

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[ScheduledReportService] Failed to send outcome notification',
                context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
            );
        }//end try
    }//end notifyOwner()
}//end class
