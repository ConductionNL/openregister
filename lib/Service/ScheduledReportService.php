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
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing and executing scheduled report exports.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)           The email-delivery leg
 *     (scheduled-report-email-delivery) added recipient resolution,
 *     attachment/oversize-fallback, and row-count helpers alongside the
 *     original Files-delivery/CRUD/due-check methods; each is a small,
 *     single-purpose private helper — splitting them into a second class
 *     would just relocate the method count behind an extra collaborator,
 *     same reasoning as ExcessiveClassComplexity below.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Owns validation, CRUD,
 *     due-computation, and single-report execution (export/deliver/notify)
 *     in one cohesive unit per design.md; splitting execution into its own
 *     class would just relocate the complexity behind an extra collaborator.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 * @spec openspec/specs/scheduled-report-jobs/spec.md
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
     * Supported delivery modes. `files` is the original, pre-email-delivery
     * behaviour and remains the default so existing reports are unaffected.
     *
     * @var string[]
     */
    public const ALLOWED_DELIVERY_MODES = ['files', 'email', 'both'];

    /**
     * Maximum number of recipient email addresses a report may configure.
     *
     * @var int
     */
    public const MAX_RECIPIENTS = 20;

    /**
     * Maximum export size, in bytes, that is attached directly to the
     * delivery email. Larger exports fall back to Files delivery plus a
     * link in the email body — SMTP servers/relays commonly cap message
     * size well under typical export sizes for large registers.
     *
     * @var int
     */
    public const MAX_EMAIL_ATTACHMENT_BYTES = 10485760;

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
     * @param IMailer               $mailer              Sends the email-delivery leg (deliveryMode email|both).
     * @param IConfig               $config              Resolves the instance's default mail sender.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI-injected dependencies — IMailer/IConfig are the
     *     two email-delivery additions on top of the original 9; each is a distinct, testable collaborator
     *     (mail transport vs. system config) rather than an arbitrary parameter split.
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
        private readonly LoggerInterface $logger,
        private readonly IMailer $mailer,
        private readonly IConfig $config
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
        $report->setDeliveryMode((string) ($data['deliveryMode'] ?? 'files'));
        $report->setRecipients(
            json_encode($this->normalizeRecipients(recipients: ($data['recipients'] ?? [])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
            'deliveryMode'       => ($data['deliveryMode'] ?? ($report->getDeliveryMode() ?? 'files')),
            'recipients'         => ($data['recipients'] ?? $report->getRecipientsArray()),
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
        $report->setDeliveryMode((string) $merged['deliveryMode']);
        $report->setRecipients(
            json_encode($this->normalizeRecipients(recipients: $merged['recipients']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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

        $deliveryMode = ($data['deliveryMode'] ?? 'files');
        if (in_array($deliveryMode, self::ALLOWED_DELIVERY_MODES, true) === false) {
            throw new InvalidArgumentException(
                sprintf('deliveryMode must be one of: %s', implode(', ', self::ALLOWED_DELIVERY_MODES))
            );
        }

        $this->validateRecipients(recipients: ($data['recipients'] ?? []));
    }//end validate()

    /**
     * Validate a recipients payload: must be an array of strings, each a
     * syntactically valid email address, capped at {@see self::MAX_RECIPIENTS}.
     * An empty/absent array is always valid — it means "default to the
     * owner's own email at run time" (see `resolveRecipients()`).
     *
     * @param mixed $recipients The raw payload value.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the payload isn't a valid recipient list.
     *
     * @spec openspec/specs/scheduled-report-jobs/spec.md
     */
    private function validateRecipients(mixed $recipients): void
    {
        if (is_array($recipients) === false) {
            throw new InvalidArgumentException('recipients must be an array of email addresses.');
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(sprintf('recipients cannot exceed %d addresses.', self::MAX_RECIPIENTS));
        }

        foreach ($recipients as $email) {
            if (is_string($email) === false) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', gettype($email)));
            }

            // Trimmed the same way normalizeRecipients() will store it —
            // incidental whitespace shouldn't fail validation only to have
            // the trimmed form pass moments later.
            if (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
            }
        }
    }//end validateRecipients()

    /**
     * Normalize an already-validated recipients payload to a de-duplicated
     * list of trimmed email strings ready for JSON storage.
     *
     * @param mixed $recipients The raw payload value (already passed {@see validateRecipients()}).
     *
     * @return string[]
     */
    private function normalizeRecipients(mixed $recipients): array
    {
        if (is_array($recipients) === false) {
            return [];
        }

        $normalized = [];
        foreach ($recipients as $email) {
            if (is_string($email) === false) {
                continue;
            }

            $trimmed = trim($email);
            if ($trimmed !== '' && in_array($trimmed, $normalized, true) === false) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }//end normalizeRecipients()

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
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
     * Execute a single scheduled report: export as the owner, deliver per
     * `deliveryMode` (Files, email, or both), notify the owner, and persist
     * the outcome. Never throws — all failures (including
     * `ExportTooLargeException`) are caught, recorded on the report, and
     * notified, so a caller (job or run-now) can iterate a batch without
     * per-report try/catch of its own (though callers SHOULD still wrap
     * this call for defence in depth — see ScheduledReportJob).
     *
     * The email leg is isolated from the Files leg: when `deliveryMode` is
     * `both` and Files delivery succeeds but email fails, the run is marked
     * `email_failed` (not `failed`) — the export WAS delivered, just not by
     * every requested channel. When `deliveryMode` is `email` only and the
     * email leg fails outright, the run is marked `failed` since nothing was
     * delivered.
     *
     * @param ScheduledReport $report The report to run.
     *
     * @return void
     *
     * @spec openspec/specs/scheduled-report-jobs/spec.md
     * @spec openspec/specs/scheduled-report-jobs/spec.md
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
            $export   = $this->runExport(report: $report, owner: $owner);
            $filename = $this->buildFilename(report: $report);
            $mode     = ($report->getDeliveryMode() ?? 'files');

            $delivered = false;
            if (in_array($mode, ['files', 'both'], true) === true) {
                $this->deliverToFiles(report: $report, owner: $owner, filename: $filename, bytes: $export['bytes']);
                $delivered = true;
            }

            $emailFailureReason = null;
            if (in_array($mode, ['email', 'both'], true) === true) {
                $emailFailureReason = $this->deliverToEmail(
                    report: $report,
                    owner: $owner,
                    filename: $filename,
                    bytes: $export['bytes'],
                    rowCount: $export['rowCount'],
                    alreadyDelivered: $delivered
                );
            }

            $this->finalizeOutcome(
                report: $report,
                filename: $filename,
                delivered: $delivered,
                emailFailureReason: $emailFailureReason
            );
        } catch (ExportTooLargeException $e) {
            $this->logger->warning(
                message: '[ScheduledReportService] Export too large',
                context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
            );
            $this->markOutcome(report: $report, status: 'failed', error: $e->getMessage());
            $this->notifyOwner(report: $report, success: false, reason: $e->getMessage(), filename: null, emailFailureReason: null);
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
            $this->notifyOwner(report: $report, success: false, reason: $e->getMessage(), filename: null, emailFailureReason: null);
        } finally {
            $this->userSession->setUser($previousUser);
        }//end try
    }//end runOne()

    /**
     * Determine and persist a run's final outcome from its two independent
     * delivery legs, then notify the owner. Extracted out of `runOne()` as
     * three mutually-exclusive early-return cases (rather than an
     * if/elseif/else chain inline) so each outcome reads as a single,
     * self-contained rule:
     *  - no email failure                       → `success`.
     *  - email failed, but Files already delivered → `email_failed` (isolated).
     *  - email failed and nothing else delivered    → `failed`.
     *
     * @param ScheduledReport $report             The report.
     * @param string          $filename           The delivery filename.
     * @param bool            $delivered          Whether the Files leg delivered this pass.
     * @param string|null     $emailFailureReason The email leg's failure reason, or null on success/not-requested.
     *
     * @return void
     */
    private function finalizeOutcome(ScheduledReport $report, string $filename, bool $delivered, ?string $emailFailureReason): void
    {
        if ($emailFailureReason === null) {
            $this->markOutcome(report: $report, status: 'success', error: null);
            $this->notifyOwner(report: $report, success: true, reason: null, filename: $filename, emailFailureReason: null);
            return;
        }

        if ($delivered === true) {
            // Files delivery (the requested Files/both leg) succeeded —
            // don't report a hard failure, but do surface the distinct
            // email problem.
            $this->markOutcome(report: $report, status: 'email_failed', error: $emailFailureReason);
            $this->notifyOwner(report: $report, success: true, reason: null, filename: $filename, emailFailureReason: $emailFailureReason);
            return;
        }

        // Email-only mode and the email leg failed — nothing was delivered.
        $this->markOutcome(report: $report, status: 'failed', error: $emailFailureReason);
        $this->notifyOwner(report: $report, success: false, reason: $emailFailureReason, filename: null, emailFailureReason: null);
    }//end finalizeOutcome()

    /**
     * Resolve register/schema and run the format-appropriate ExportService
     * method (unchanged from the original `exportBytes()` call surface —
     * still exactly one `ExportService` call per format, preserving prior
     * behaviour/tests), also deriving a best-effort object row count for the
     * email body. Row counting never risks turning an otherwise-successful
     * export into a failure: `csv`'s count is parsed from the bytes already
     * produced (no extra fetch), `excel`'s count comes from the `Spreadsheet`
     * object already returned by `exportToExcel()`, and `pdf`'s count is a
     * best-effort secondary call swallowed on any error (see
     * `bestEffortRowCount()`).
     *
     * @param ScheduledReport $report The report.
     * @param \OCP\IUser      $owner  The impersonated owner (drives RBAC-aware admin-metadata column visibility).
     *
     * @return array{bytes: string, rowCount: int}
     *
     * @throws ExportTooLargeException When the pdf row cap is exceeded.
     */
    private function runExport(ScheduledReport $report, \OCP\IUser $owner): array
    {
        $register = $this->registerMapper->find($report->getRegisterId(), _rbac: false, _multitenancy: false);
        $schema   = null;
        if ($report->getSchemaId() !== null) {
            $schema = $this->schemaMapper->find($report->getSchemaId(), _rbac: false, _multitenancy: false);
        }

        $filters = $report->getFiltersArray();

        switch ($report->getFormat()) {
            case 'csv':
                $bytes = $this->exportService->exportToCsv(register: $register, schema: $schema, filters: $filters, currentUser: $owner);
                return ['bytes' => $bytes, 'rowCount' => $this->countCsvRows(csv: $bytes)];

            case 'pdf':
                $bytes    = $this->exportService->exportToPdf(register: $register, schema: $schema, filters: $filters, currentUser: $owner);
                $rowCount = $this->bestEffortRowCount(register: $register, schema: $schema, filters: $filters, owner: $owner);
                return ['bytes' => $bytes, 'rowCount' => $rowCount];

            default:
                $spreadsheet = $this->exportService->exportToExcel(register: $register, schema: $schema, filters: $filters, currentUser: $owner);
                return [
                    'bytes'    => $this->spreadsheetToXlsxBytes(spreadsheet: $spreadsheet),
                    'rowCount' => $this->countSpreadsheetRows(spreadsheet: $spreadsheet),
                ];
        }//end switch
    }//end runExport()

    /**
     * Count data rows in CSV bytes (total non-empty lines minus the header row).
     *
     * @param string $csv The CSV content.
     *
     * @return int
     */
    private function countCsvRows(string $csv): int
    {
        $lines = array_filter(explode("\n", $csv), static fn ($line) => trim($line) !== '');

        return max(0, (count($lines) - 1));
    }//end countCsvRows()

    /**
     * Sum data-row counts (excluding each sheet's header row) across every
     * sheet in a spreadsheet.
     *
     * @param Spreadsheet $spreadsheet The spreadsheet.
     *
     * @return int
     */
    private function countSpreadsheetRows(Spreadsheet $spreadsheet): int
    {
        $rows = 0;
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows += max(0, ($sheet->getHighestRow() - 1));
        }

        return $rows;
    }//end countSpreadsheetRows()

    /**
     * Best-effort row count for a pdf export, used only for the delivery
     * email body — never allowed to turn an otherwise-successful pdf export
     * into a failure.
     *
     * @param Register            $register The register.
     * @param Schema|null         $schema   The schema, if any.
     * @param array<string,mixed> $filters  The filter map.
     * @param \OCP\IUser          $owner    The impersonated owner.
     *
     * @return int
     */
    private function bestEffortRowCount(Register $register, ?Schema $schema, array $filters, \OCP\IUser $owner): int
    {
        try {
            $spreadsheet = $this->exportService->exportToExcel(register: $register, schema: $schema, filters: $filters, currentUser: $owner);
            return $this->countSpreadsheetRows(spreadsheet: $spreadsheet);
        } catch (\Throwable $e) {
            $this->logger->debug(
                message: '[ScheduledReportService] Could not derive pdf export row count for email body',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return 0;
        }
    }//end bestEffortRowCount()

    /**
     * Write a PhpSpreadsheet Spreadsheet to raw XLSX bytes.
     *
     * @param Spreadsheet $spreadsheet The spreadsheet.
     *
     * @return string Raw XLSX bytes.
     */
    private function spreadsheetToXlsxBytes(Spreadsheet $spreadsheet): string
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
     * Deliver the export by email (deliveryMode email|both). Attaches the
     * export when it's under {@see self::MAX_EMAIL_ATTACHMENT_BYTES}; over
     * that cap, the attachment is omitted and, if it wasn't already written
     * by the Files leg, the export is written to Files as a fallback so the
     * data is never silently lost — the email body then links to it instead.
     *
     * Never throws — every failure (no resolvable recipient, Files fallback
     * failure, `IMailer::send()` failure) is caught and returned as a
     * human-readable reason string so `runOne()` can decide, based on
     * whether the Files leg already succeeded, whether this is a hard
     * failure or an isolated `email_failed` detail.
     *
     * @param ScheduledReport $report           The report.
     * @param \OCP\IUser      $owner            The owner (recipient default + display name).
     * @param string          $filename         The delivery filename.
     * @param string          $bytes            The rendered export bytes.
     * @param int             $rowCount         The best-effort exported row count, for the email body.
     * @param bool            $alreadyDelivered Whether the Files leg already ran this pass (mode `both`).
     *
     * @return string|null The failure reason, or null on success.
     *
     * @spec openspec/specs/scheduled-report-jobs/spec.md
     */
    private function deliverToEmail(
        ScheduledReport $report,
        \OCP\IUser $owner,
        string $filename,
        string $bytes,
        int $rowCount,
        bool $alreadyDelivered
    ): ?string {
        $recipients = $this->resolveRecipients(report: $report, owner: $owner);
        if (count($recipients) === 0) {
            return 'No valid recipient email address available (no recipients configured and the owner has no email on file).';
        }

        $oversize = (strlen($bytes) > self::MAX_EMAIL_ATTACHMENT_BYTES);

        if ($oversize === true && $alreadyDelivered === false) {
            try {
                $this->deliverToFiles(report: $report, owner: $owner, filename: $filename, bytes: $bytes);
            } catch (\Throwable $e) {
                return 'Export exceeds the email attachment size limit and the Files fallback failed: '.$e->getMessage();
            }
        }

        try {
            $subject = sprintf('Scheduled report "%s"', $report->getName());

            $bodyLines = $this->buildEmailBodyLines(report: $report, rowCount: $rowCount);
            if ($oversize === true) {
                $bodyLines[] = sprintf(
                    'The export (%s) exceeds the %dMB email attachment limit and was not attached. '
                    .'It has been saved to your Nextcloud Files at %s%s instead.',
                    $this->humanFileSize(bytes: strlen($bytes)),
                    (int) (self::MAX_EMAIL_ATTACHMENT_BYTES / 1024 / 1024),
                    trim((string) ($report->getDeliveryFolder() ?? 'Reports/'), '/'),
                    '/'.$filename
                );
            }

            $emailTemplate = $this->mailer->createEMailTemplate('openregister.scheduledReportDelivery', []);
            $emailTemplate->setSubject($subject);
            $emailTemplate->addHeader();
            $emailTemplate->addHeading($report->getName());
            foreach ($bodyLines as $line) {
                $emailTemplate->addBodyText($line);
            }

            $emailTemplate->addFooter();

            $message = $this->mailer->createMessage();
            $message->setTo($recipients);
            $message->setSubject($subject);
            $message->useTemplate($emailTemplate);
            $message->setFrom($this->resolveFromAddress());

            if ($oversize === false) {
                $attachment = $this->mailer->createAttachment(
                    $bytes,
                    $filename,
                    $this->mimeTypeFor(format: $report->getFormat())
                );
                $message->attach($attachment);
            }

            $this->mailer->send($message);
        } catch (\Throwable $e) {
            return 'Email delivery failed: '.$e->getMessage();
        }//end try

        return null;
    }//end deliverToEmail()

    /**
     * Resolve the email recipients for a report: the explicitly configured
     * list, or — when empty — the owner's own Nextcloud email address. An
     * owner with no email on file and no explicit recipients resolves to no
     * recipients at all (the caller treats that as an email-leg failure).
     *
     * @param ScheduledReport $report The report.
     * @param \OCP\IUser      $owner  The owner.
     *
     * @return array<string,string> Email address => display name, ready for `IMessage::setTo()`.
     *
     * @spec openspec/specs/scheduled-report-jobs/spec.md
     */
    private function resolveRecipients(ScheduledReport $report, \OCP\IUser $owner): array
    {
        $configured = $report->getRecipientsArray();
        if (count($configured) > 0) {
            $map = [];
            foreach ($configured as $email) {
                $map[$email] = $email;
            }

            return $map;
        }

        $ownerEmail = $owner->getEMailAddress();
        if ($ownerEmail === null || $ownerEmail === '') {
            return [];
        }

        return [$ownerEmail => $owner->getDisplayName()];
    }//end resolveRecipients()

    /**
     * Build the delivery email's body text lines: register/schema, period, row count.
     *
     * @param ScheduledReport $report   The report.
     * @param int             $rowCount The exported row count.
     *
     * @return string[]
     */
    private function buildEmailBodyLines(ScheduledReport $report, int $rowCount): array
    {
        $lines   = [];
        $lines[] = sprintf('Your scheduled report "%s" ran and delivered %d row(s).', $report->getName(), $rowCount);

        try {
            $register     = $this->registerMapper->find($report->getRegisterId(), _rbac: false, _multitenancy: false);
            $registerName = ($register->getTitle() ?? ('register '.$report->getRegisterId()));
        } catch (\Throwable $e) {
            $registerName = ('register '.$report->getRegisterId());
        }

        $schemaName = 'all schemas';
        if ($report->getSchemaId() !== null) {
            try {
                $schema     = $this->schemaMapper->find($report->getSchemaId(), _rbac: false, _multitenancy: false);
                $schemaName = ($schema->getTitle() ?? ('schema '.$report->getSchemaId()));
            } catch (\Throwable $e) {
                $schemaName = ('schema '.$report->getSchemaId());
            }
        }

        $lines[] = sprintf('Source: %s / %s.', $registerName, $schemaName);
        $lines[] = sprintf('Schedule: %s.', ($report->getScheduleType() ?? 'daily'));

        return $lines;
    }//end buildEmailBodyLines()

    /**
     * Resolve the instance's default outgoing mail sender, mirroring
     * `FlowActionService::runEmail()`'s `mail_from_address`/`mail_domain`
     * system-config pattern.
     *
     * @return array<string,string>
     */
    private function resolveFromAddress(): array
    {
        $from   = $this->config->getSystemValue('mail_from_address', 'no-reply');
        $domain = $this->config->getSystemValue('mail_domain', 'localhost');

        return [$from.'@'.$domain => 'OpenRegister'];
    }//end resolveFromAddress()

    /**
     * MIME type for an export format's attachment.
     *
     * @param string|null $format csv|excel|pdf.
     *
     * @return string
     */
    private function mimeTypeFor(?string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv',
            'pdf' => 'application/pdf',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }//end mimeTypeFor()

    /**
     * Human-readable byte size, e.g. "12.3MB".
     *
     * @param int $bytes The byte count.
     *
     * @return string
     */
    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1fMB', ($bytes / 1048576));
        }

        if ($bytes >= 1024) {
            return sprintf('%.1fKB', ($bytes / 1024));
        }

        return sprintf('%dB', $bytes);
    }//end humanFileSize()

    /**
     * Persist the outcome of a run.
     *
     * @param ScheduledReport $report The report.
     * @param string          $status success|failed|email_failed.
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
     * Notify the report owner of a run's outcome. Reuses the existing
     * `scheduled_report_delivered`/`scheduled_report_failed` subjects for
     * every deliveryMode — `mode` and `emailFailureReason` are passed as
     * extra, optional subject parameters so `Notifier` can render
     * email-aware wording without a new notification subject.
     *
     * @param ScheduledReport $report             The report.
     * @param bool            $success            Whether the run succeeded (or partially succeeded — see `email_failed`).
     * @param string|null     $reason             The failure reason (failure only).
     * @param string|null     $filename           The delivered filename (success only).
     * @param string|null     $emailFailureReason Set when Files succeeded but the email leg failed (mode `both`).
     *
     * @return void
     *
     * @spec openspec/specs/scheduled-report-jobs/spec.md
     */
    private function notifyOwner(
        ScheduledReport $report,
        bool $success,
        ?string $reason,
        ?string $filename,
        ?string $emailFailureReason
    ): void {
        $ownerUid = $report->getOwner();
        if ($ownerUid === null) {
            return;
        }

        try {
            $mode       = ($report->getDeliveryMode() ?? 'files');
            $subject    = 'scheduled_report_failed';
            $parameters = [
                'reportId'   => $report->getId(),
                'reportName' => $report->getName(),
                'reason'     => ($reason ?? 'Unknown error'),
            ];
            if ($success === true) {
                $subject    = 'scheduled_report_delivered';
                $parameters = [
                    'reportId'           => $report->getId(),
                    'reportName'         => $report->getName(),
                    'folder'             => $report->getDeliveryFolder(),
                    'filename'           => $filename,
                    'mode'               => $mode,
                    'emailFailureReason' => $emailFailureReason,
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
