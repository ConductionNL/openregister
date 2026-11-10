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
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Kernel\Contracts\ReportCaller;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportUpdate;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
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
class ScheduledReportService implements ScheduledReportsInterface {

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
		'daily' => 86400,
		'weekly' => 604800,
		'monthly' => 2592000,
	];

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var ScheduledReportMapperInterface
	 */
	private readonly ScheduledReportMapperInterface $mapper;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var ExportService
	 */
	private readonly ExportService $exportService;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var IRootFolder
	 */
	private readonly IRootFolder $rootFolder;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var IManager
	 */
	private readonly IManager $notificationManager;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var IMailer
	 */
	private readonly IMailer $mailer;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var IConfig
	 */
	private readonly IConfig $config;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var ExportProfileService|null
	 */
	private readonly ?ExportProfileService $profileService;

	/**
	 * Collaborator, assigned from its bundle in the constructor.
	 *
	 * @var ExportRunRecorder|null
	 */
	private readonly ?ExportRunRecorder $exportRuns;

	/**
	 * Constructor.
	 *
	 * @param ScheduledReportStores    $stores    Report, register and schema stores.
	 * @param ScheduledReportExporters $exporters Export engine, profile runner and run recorder.
	 * @param ScheduledReportDelivery  $delivery  Owner-facing services and the mail transport.
	 * @param LoggerInterface          $logger    Logger.
	 */
	public function __construct(
		ScheduledReportStores $stores,
		ScheduledReportExporters $exporters,
		ScheduledReportDelivery $delivery,
		LoggerInterface $logger,
	) {
		$this->mapper = $stores->mapper;
		$this->registerMapper = $stores->registerMapper;
		$this->schemaMapper = $stores->schemaMapper;
		$this->exportService = $exporters->exportService;
		$this->rootFolder = $delivery->owner->rootFolder;
		$this->userManager = $delivery->owner->userManager;
		$this->userSession = $delivery->owner->userSession;
		$this->notificationManager = $delivery->owner->notificationManager;
		$this->logger = $logger;
		$this->mailer = $delivery->mailer;
		$this->config = $delivery->config;
		$this->profileService = $exporters->profileService;
		$this->exportRuns = $exporters->exportRuns;
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
	public function find(int $id): ScheduledReport {
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
	public function findForOwner(string $ownerUid): array {
		return $this->mapper->findByOwner(owner: $ownerUid);
	}//end findForOwner()

	/**
	 * List every scheduled report (admin listing).
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findAllForAdmin(): array {
		return $this->mapper->findAll();
	}//end findAllForAdmin()

	/**
	 * Create a scheduled report owned by the given user.
	 *
	 * @param array<string,mixed> $data Request payload.
	 * @param string $ownerUid The owning uid (always the caller — self-service creation).
	 *
	 * @return ScheduledReport
	 *
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function create(array $data, string $ownerUid): ScheduledReport {
		$this->validate(data: $data);

		$now = new DateTime();

		$report = new ScheduledReport();
		$report->setOwner($ownerUid);
		$report->setName((string)$data['name']);
		$report->setRegisterId((int)$data['registerId']);
		$report->setSchemaId($this->coerceNullableInt(value: ($data['schemaId'] ?? null)));
		$report->setFilters(json_encode(($data['filters'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$report->setFormat((string)$data['format']);
		$report->setProfileId($this->coerceNullableInt(value: ($data['profileId'] ?? null)));
		$report->setScheduleType((string)$data['scheduleType']);
		$report->setScheduleHour((int)($data['scheduleHour'] ?? 0));
		$report->setScheduleDayOfWeek($this->coerceNullableInt(value: ($data['scheduleDayOfWeek'] ?? null)));
		$report->setScheduleDayOfMonth($this->coerceNullableInt(value: ($data['scheduleDayOfMonth'] ?? null)));
		$report->setDeliveryFolder((string)($data['deliveryFolder'] ?? 'Reports/'));
		$report->setDeliveryMode((string)($data['deliveryMode'] ?? 'files'));
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
	 * @param ScheduledReportUpdate $update The report id, the fields to update and who asks.
	 *
	 * @return ScheduledReport
	 *
	 * @throws DoesNotExistException When no row matches.
	 * @throws RuntimeException When the caller does not own the row and is not an admin.
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function applyUpdate(ScheduledReportUpdate $update): ScheduledReport {
		$report = $this->mapper->find(id: $update->id);
		$this->assertMayAccess(report: $report, caller: $update->caller);

		$this->applyFields(report: $report, data: $update->data);
		$report->setUpdatedAt(new DateTime());

		return $this->mapper->update(entity: $report);
	}//end applyUpdate()

	/**
	 * Merge the update payload over the stored report, validate it and set the fields.
	 *
	 * @param ScheduledReport     $report The stored report.
	 * @param array<string,mixed> $data   The update payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When validation fails.
	 */
	private function applyFields(ScheduledReport $report, array $data): void {
		// Merge with existing values so a partial update doesn't blow away
		// fields the caller didn't send.
		$merged = $this->mergedPayload(report: $report, data: $data);
		$this->validate(data: $merged);

		$report->setName((string)$merged['name']);
		$report->setRegisterId((int)$merged['registerId']);
		$report->setSchemaId($this->coerceNullableInt(value: $merged['schemaId']));
		$report->setFilters(json_encode($merged['filters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$report->setFormat((string)$merged['format']);
		if (array_key_exists('profileId', $data) === true) {
			$report->setProfileId($this->coerceNullableInt(value: $data['profileId']));
		}

		$report->setScheduleType((string)$merged['scheduleType']);
		$report->setScheduleHour((int)$merged['scheduleHour']);
		$report->setScheduleDayOfWeek($this->coerceNullableInt(value: $merged['scheduleDayOfWeek']));
		$report->setScheduleDayOfMonth($this->coerceNullableInt(value: $merged['scheduleDayOfMonth']));
		$report->setDeliveryFolder((string)$merged['deliveryFolder']);
		$report->setDeliveryMode((string)$merged['deliveryMode']);
		$report->setRecipients(
			json_encode($this->normalizeRecipients(recipients: $merged['recipients']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		if (array_key_exists('enabled', $data) === true) {
			$report->setEnabled((bool)$data['enabled']);
		}
	}//end applyFields()

	/**
	 * The stored report's fields overlaid with the ones the update sends.
	 *
	 * The schema and the two schedule days take an explicit null from the update;
	 * every other field keeps its stored value when the update sends null or nothing.
	 *
	 * @param ScheduledReport     $report The stored report.
	 * @param array<string,mixed> $data   The update payload.
	 *
	 * @return array<string,mixed> The merged payload, in the shape create() validates.
	 */
	private function mergedPayload(ScheduledReport $report, array $data): array {
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

		return [
			'name' => ($data['name'] ?? $report->getName()),
			'registerId' => ($data['registerId'] ?? $report->getRegisterId()),
			'schemaId' => $schemaId,
			'filters' => ($data['filters'] ?? $report->getFiltersArray()),
			'format' => ($data['format'] ?? $report->getFormat()),
			'scheduleType' => ($data['scheduleType'] ?? $report->getScheduleType()),
			'scheduleHour' => ($data['scheduleHour'] ?? $report->getScheduleHour()),
			'scheduleDayOfWeek' => $scheduleDayOfWeek,
			'scheduleDayOfMonth' => $scheduleDayOfMonth,
			'deliveryFolder' => ($data['deliveryFolder'] ?? $report->getDeliveryFolder()),
			'deliveryMode' => ($data['deliveryMode'] ?? ($report->getDeliveryMode() ?? 'files')),
			'recipients' => ($data['recipients'] ?? $report->getRecipientsArray()),
		];
	}//end mergedPayload()

	/**
	 * Delete a scheduled report. Only the owner or an admin may delete.
	 *
	 * @param int          $id     The scheduled report id.
	 * @param ReportCaller $caller Who asks.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When no row matches.
	 * @throws RuntimeException When the caller does not own the row and is not an admin.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function deleteAs(int $id, ReportCaller $caller): void {
		$report = $this->mapper->find(id: $id);
		$this->assertMayAccess(report: $report, caller: $caller);
		$this->mapper->delete(entity: $report);
	}//end deleteAs()

	/**
	 * Assert the caller owns the report or is an admin.
	 *
	 * @param ScheduledReportInterface $report The report.
	 * @param ReportCaller             $caller Who asks.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the assertion fails.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function assertMayAccess(ScheduledReportInterface $report, ReportCaller $caller): void {
		if ($caller->isAdmin === true) {
			return;
		}

		if ($report->getOwner() === $caller->uid) {
			return;
		}

		throw new RuntimeException('You do not have permission to access this scheduled report.');
	}//end assertMayAccess()

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
	 */
	private function validate(array $data): void {
		$this->assertRequiredFields(data: $data);
		$this->assertRegisterAccessible(registerId: $data['registerId']);
		$schemaId = $this->accessibleSchemaId(data: $data);
		$this->assertFormat(format: ($data['format'] ?? null), schemaId: $schemaId);

		$scheduleType = ($data['scheduleType'] ?? null);
		$this->assertScheduleType(scheduleType: $scheduleType);
		$this->assertScheduleHour(data: $data);
		$this->assertWeeklyDay(scheduleType: $scheduleType, data: $data);
		$this->assertMonthlyDay(scheduleType: $scheduleType, data: $data);

		$this->assertDeliveryMode(deliveryMode: ($data['deliveryMode'] ?? 'files'));
		$this->validateRecipients(recipients: ($data['recipients'] ?? []));
	}//end validate()

	/**
	 * Refuse a payload without a name or a register.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either is empty.
	 */
	private function assertRequiredFields(array $data): void {
		if (empty($data['name']) === true) {
			throw new InvalidArgumentException('A name is required.');
		}

		if (empty($data['registerId']) === true) {
			throw new InvalidArgumentException('A registerId is required.');
		}
	}//end assertRequiredFields()

	/**
	 * Refuse a register the caller cannot read.
	 *
	 * @param mixed $registerId The register id.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the register cannot be found with multi-tenancy on.
	 */
	private function assertRegisterAccessible(mixed $registerId): void {
		try {
			$this->registerMapper->find($registerId, _multitenancy: true);
		} catch (\Throwable $e) {
			throw new InvalidArgumentException('Register not found or not accessible.');
		}
	}//end assertRegisterAccessible()

	/**
	 * The payload's schema id, refused when given and the caller cannot read it.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return int|null The schema id, or null when none is given.
	 *
	 * @throws InvalidArgumentException When the schema cannot be found with multi-tenancy on.
	 */
	private function accessibleSchemaId(array $data): ?int {
		$schemaId = $this->coerceNullableInt(value: ($data['schemaId'] ?? null));
		if ($schemaId !== null) {
			try {
				$this->schemaMapper->find($schemaId, _multitenancy: true);
			} catch (\Throwable $e) {
				throw new InvalidArgumentException('Schema not found or not accessible.');
			}
		}

		return $schemaId;
	}//end accessibleSchemaId()

	/**
	 * Refuse an unknown format, and CSV without a schema.
	 *
	 * @param mixed    $format   The format.
	 * @param int|null $schemaId The schema id.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the format is not allowed here.
	 */
	private function assertFormat(mixed $format, ?int $schemaId): void {
		if (in_array($format, self::ALLOWED_FORMATS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('format must be one of: %s', implode(', ', self::ALLOWED_FORMATS))
			);
		}

		if ($format === 'csv' && $schemaId === null) {
			throw new InvalidArgumentException('CSV export requires a specific schemaId.');
		}
	}//end assertFormat()

	/**
	 * Refuse an unknown schedule type.
	 *
	 * @param mixed $scheduleType The schedule type.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not allowed.
	 */
	private function assertScheduleType(mixed $scheduleType): void {
		if (in_array($scheduleType, self::ALLOWED_SCHEDULE_TYPES, true) === false) {
			throw new InvalidArgumentException(
				sprintf('scheduleType must be one of: %s', implode(', ', self::ALLOWED_SCHEDULE_TYPES))
			);
		}
	}//end assertScheduleType()

	/**
	 * Refuse an hour outside 0-23.
	 *
	 * @param array<string,mixed> $data The payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the hour is out of range.
	 */
	private function assertScheduleHour(array $data): void {
		$scheduleHour = (int)($data['scheduleHour'] ?? 0);
		if ($scheduleHour < 0 || $scheduleHour > 23) {
			throw new InvalidArgumentException('scheduleHour must be between 0 and 23.');
		}
	}//end assertScheduleHour()

	/**
	 * Refuse a weekly schedule without a day of the week in 0-6.
	 *
	 * @param mixed               $scheduleType The schedule type.
	 * @param array<string,mixed> $data         The payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the day is missing or out of range.
	 */
	private function assertWeeklyDay(mixed $scheduleType, array $data): void {
		if ($scheduleType !== 'weekly') {
			return;
		}

		$dayOfWeek = $this->coerceNullableInt(value: ($data['scheduleDayOfWeek'] ?? null));
		if ($dayOfWeek === null || $dayOfWeek < 0 || $dayOfWeek > 6) {
			throw new InvalidArgumentException('scheduleDayOfWeek (0-6) is required when scheduleType is weekly.');
		}
	}//end assertWeeklyDay()

	/**
	 * Refuse a monthly schedule without a day of the month in 1-28.
	 *
	 * @param mixed               $scheduleType The schedule type.
	 * @param array<string,mixed> $data         The payload.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the day is missing or out of range.
	 */
	private function assertMonthlyDay(mixed $scheduleType, array $data): void {
		if ($scheduleType !== 'monthly') {
			return;
		}

		$dayOfMonth = $this->coerceNullableInt(value: ($data['scheduleDayOfMonth'] ?? null));
		if ($dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 28) {
			throw new InvalidArgumentException('scheduleDayOfMonth (1-28) is required when scheduleType is monthly.');
		}
	}//end assertMonthlyDay()

	/**
	 * Refuse an unknown delivery mode.
	 *
	 * @param mixed $deliveryMode The delivery mode.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not allowed.
	 */
	private function assertDeliveryMode(mixed $deliveryMode): void {
		if (in_array($deliveryMode, self::ALLOWED_DELIVERY_MODES, true) === false) {
			throw new InvalidArgumentException(
				sprintf('deliveryMode must be one of: %s', implode(', ', self::ALLOWED_DELIVERY_MODES))
			);
		}
	}//end assertDeliveryMode()

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
	private function validateRecipients(mixed $recipients): void {
		if (is_array($recipients) === false) {
			throw new InvalidArgumentException('recipients must be an array of email addresses.');
		}

		if (count($recipients) > self::MAX_RECIPIENTS) {
			throw new InvalidArgumentException(sprintf('recipients cannot exceed %d addresses.', self::MAX_RECIPIENTS));
		}

		foreach ($recipients as $email) {
			$this->assertRecipientAddress(email: $email);
		}
	}//end validateRecipients()

	/**
	 * Refuse a recipient that is not a string holding a valid email address.
	 *
	 * @param mixed $email One recipient.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not a valid email address.
	 */
	private function assertRecipientAddress(mixed $email): void {
		if (is_string($email) === false) {
			throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', gettype($email)));
		}

		// Trimmed the same way normalizeRecipients() will store it —
		// incidental whitespace shouldn't fail validation only to have
		// the trimmed form pass moments later.
		if (filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
			throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
		}
	}//end assertRecipientAddress()

	/**
	 * Normalize an already-validated recipients payload to a de-duplicated
	 * list of trimmed email strings ready for JSON storage.
	 *
	 * @param mixed $recipients The raw payload value (already passed {@see validateRecipients()}).
	 *
	 * @return string[]
	 */
	private function normalizeRecipients(mixed $recipients): array {
		if (is_array($recipients) === false) {
			return [];
		}

		return $this->uniqueTrimmedStrings(values: $recipients);
	}//end normalizeRecipients()

	/**
	 * The string values, trimmed, without empties and duplicates, in order.
	 *
	 * @param array $values The values.
	 *
	 * @return string[]
	 */
	private function uniqueTrimmedStrings(array $values): array {
		$normalized = [];
		foreach ($values as $email) {
			if (is_string($email) === false) {
				continue;
			}

			$trimmed = trim($email);
			if ($trimmed !== '' && in_array($trimmed, $normalized, true) === false) {
				$normalized[] = $trimmed;
			}
		}

		return $normalized;
	}//end uniqueTrimmedStrings()

	/**
	 * Coerce a value to a nullable int. Empty string and null both become null.
	 *
	 * @param mixed $value Input.
	 *
	 * @return ?int
	 */
	private function coerceNullableInt(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end coerceNullableInt()

	/**
	 * Whether a report is due to run now.
	 *
	 * Catch-up-safe elapsed-period check per design.md: due when never run,
	 * or when at least a full schedule period has elapsed since the last run.
	 * Disabled reports are never due.
	 *
	 * @param ScheduledReportInterface $report The report to check.
	 * @param DateTimeInterface $now The reference time.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function isDue(ScheduledReportInterface $report, DateTimeInterface $now): bool {
		if ($report->getEnabled() !== true) {
			return false;
		}

		$lastRunAt = $report->getLastRunAt();
		if ($lastRunAt === null) {
			return true;
		}

		$periodSeconds = (self::PERIOD_SECONDS[$report->getScheduleType()] ?? self::PERIOD_SECONDS['daily']);
		$elapsed = ($now->getTimestamp() - $lastRunAt->getTimestamp());

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
	 * @param ScheduledReportInterface $report The report to run.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the report is not a stored ScheduledReport row.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function runOne(ScheduledReportInterface $report): void {
		if (($report instanceof ScheduledReport) === false) {
			throw new InvalidArgumentException('Only a stored ScheduledReport row can run.');
		}

		$ownerUid = $report->getOwner();
		$owner = null;
		if ($ownerUid !== null) {
			$owner = $this->userManager->get($ownerUid);
		}

		if ($owner === null) {
			$this->logger->warning(
				message: '[ScheduledReportService] Owner account not found — skipping run',
				context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'owner' => $ownerUid]
			);
			$this->markFailed(report: $report, error: 'Owner account no longer exists.');
			return;
		}

		$previousUser = $this->userSession->getUser();
		$this->userSession->setUser($owner);

		try {
			$run = new ScheduledReportRun(report: $report, owner: $owner);
			$run->export = $this->runExport(report: $report, owner: $owner);
			$run->filename = $this->buildFilename(report: $report);
			$mode = ($report->getDeliveryMode() ?? 'files');

			if (in_array($mode, ['files', 'both'], true) === true) {
				$run->file = $this->deliverToFiles(run: $run);
				$run->delivered = true;
			}

			// Record the run. Until this existed, a scheduled report wrote a
			// copy of the register into somebody's Files and this platform
			// then knew nothing about it: not who held it, not how many rows
			// it carried, and not when it should stop existing. The file is
			// what the sweep deletes; the row is what an administrator is
			// asked about, and it outlives the file.
			$this->recordRun(run: $run);

			if (in_array($mode, ['email', 'both'], true) === true) {
				$run->emailFailureReason = $this->deliverToEmail(run: $run);
			}

			$this->finalizeOutcome(run: $run);
		} catch (ExportTooLargeException $e) {
			$this->logger->warning(
				message: '[ScheduledReportService] Export too large',
				context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
			);
			$this->markFailed(report: $report, error: $e->getMessage());
			$this->notifyFailed(report: $report, reason: $e->getMessage());
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ScheduledReportService] Scheduled report run failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'reportId' => $report->getId(),
					'error' => $e->getMessage(),
				]
			);
			$this->markFailed(report: $report, error: $e->getMessage());
			$this->notifyFailed(report: $report, reason: $e->getMessage());
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
	 * @param ScheduledReportRun $run The run, after both legs.
	 *
	 * @return void
	 */
	private function finalizeOutcome(ScheduledReportRun $run): void {
		$report = $run->report;
		$emailFailureReason = $run->emailFailureReason;
		if ($emailFailureReason === null) {
			$this->markSucceeded(report: $report);
			$this->notifyDelivered(run: $run);
			return;
		}

		if ($run->delivered === true) {
			// Files delivery (the requested Files/both leg) succeeded —
			// don't report a hard failure, but do surface the distinct
			// email problem.
			$this->markEmailFailed(report: $report, error: $emailFailureReason);
			$this->notifyDelivered(run: $run);
			return;
		}

		// Email-only mode and the email leg failed — nothing was delivered.
		$this->markFailed(report: $report, error: $emailFailureReason);
		$this->notifyFailed(report: $report, reason: $emailFailureReason);
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
	 * @param \OCP\IUser $owner The impersonated owner (drives RBAC-aware admin-metadata column visibility).
	 *
	 * @return array{bytes: string, rowCount: int}
	 *
	 * @throws ExportTooLargeException When the pdf row cap is exceeded.
	 */
	private function runExport(ScheduledReport $report, \OCP\IUser $owner): array {
		if ($report->getProfileId() !== null) {
			return $this->runProfileExport(report: $report, owner: $owner);
		}

		$register = $this->registerMapper->find($report->getRegisterId(), _rbac: false, _multitenancy: false);
		$schema = null;
		if ($report->getSchemaId() !== null) {
			$schema = $this->schemaMapper->find($report->getSchemaId(), _rbac: false, _multitenancy: false);
		}

		$filters = $report->getFiltersArray();

		switch ($report->getFormat()) {
			case 'csv':
				$bytes = $this->exportService->exportToCsv(register: $register, schema: $schema, filters: $filters, currentUser: $owner);
				return ['bytes' => $bytes, 'rowCount' => $this->countCsvRows(csv: $bytes)];
			case 'pdf':
				return $this->exportPdf(
					query: new ScheduledReportExportQuery(register: $register, schema: $schema, filters: $filters, owner: $owner)
				);
			default:
				$spreadsheet = $this->exportService->exportToExcel(register: $register, schema: $schema, filters: $filters, currentUser: $owner);
				return [
					'bytes' => $this->spreadsheetToXlsxBytes(spreadsheet: $spreadsheet),
					'rowCount' => $this->countSpreadsheetRows(spreadsheet: $spreadsheet),
				];
		}//end switch
	}//end runExport()

	/**
	 * Run the export profile this schedule names, as its owner.
	 *
	 * The owner's access is what the file holds, because `runOne()` has already
	 * put the owner in the session and the profile service resolves the export
	 * verb against the uid it is handed. A schedule owned by somebody who may
	 * read half the register produces that half.
	 *
	 * REFUSES LOUDLY WHEN THE PROFILE SERVICE IS ABSENT. The dependency is
	 * optional only so that callers built before this change still construct,
	 * and a schedule that names a profile it cannot run must fail rather than
	 * quietly export something else.
	 *
	 * @param ScheduledReport $report The report.
	 * @param \OCP\IUser $owner The impersonated owner.
	 *
	 * @return array{bytes: string, rowCount: int} The file and its row count.
	 *
	 * @throws RuntimeException When the profile cannot be run.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function runProfileExport(ScheduledReport $report, \OCP\IUser $owner): array {
		if ($this->profileService === null) {
			throw new RuntimeException(
				'This scheduled report names export profile ' . (string)$report->getProfileId()
				. ', and the export profile service is not available to run it.'
			);
		}

		$profile = $this->profileService->find(id: (int)$report->getProfileId());
		$written = $this->profileService->run(profile: $profile, actorUid: $owner->getUID());

		return ['bytes' => $written['bytes'], 'rowCount' => $written['rowCount']];
	}//end runProfileExport()

	/**
	 * Count data rows in CSV bytes (total non-empty lines minus the header row).
	 *
	 * @param string $csv The CSV content.
	 *
	 * @return int
	 */
	private function countCsvRows(string $csv): int {
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
	private function countSpreadsheetRows(Spreadsheet $spreadsheet): int {
		$rows = 0;
		foreach ($spreadsheet->getAllSheets() as $sheet) {
			$rows += max(0, ($sheet->getHighestRow() - 1));
		}

		return $rows;
	}//end countSpreadsheetRows()

	/**
	 * Render a pdf export, with a best-effort row count for the email body.
	 *
	 * @param ScheduledReportExportQuery $query What to export, and as whom.
	 *
	 * @return array{bytes: string, rowCount: int}
	 *
	 * @throws ExportTooLargeException When the pdf row cap is exceeded.
	 */
	private function exportPdf(ScheduledReportExportQuery $query): array {
		$bytes = $this->exportService->exportToPdf(
			register: $query->register,
			schema: $query->schema,
			filters: $query->filters,
			currentUser: $query->owner
		);

		return ['bytes' => $bytes, 'rowCount' => $this->bestEffortRowCount(query: $query)];
	}//end exportPdf()

	/**
	 * Best-effort row count for a pdf export, used only for the delivery
	 * email body — never allowed to turn an otherwise-successful pdf export
	 * into a failure.
	 *
	 * @param ScheduledReportExportQuery $query What was exported, and as whom.
	 *
	 * @return int
	 */
	private function bestEffortRowCount(ScheduledReportExportQuery $query): int {
		try {
			$spreadsheet = $this->exportService->exportToExcel(
				register: $query->register,
				schema: $query->schema,
				filters: $query->filters,
				currentUser: $query->owner
			);
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
	private function spreadsheetToXlsxBytes(Spreadsheet $spreadsheet): string {
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
	private function buildFilename(ScheduledReport $report): string {
		$extension = match ($report->getFormat()) {
			'csv' => 'csv',
			'pdf' => 'pdf',
			default => 'xlsx',
		};

		// A profile writes its own format, and the name has to say so: a file
		// called .xlsx holding csv bytes is the kind of thing a receiving system
		// opens once and never trusts again.
		if ($report->getProfileId() !== null && $this->profileService !== null) {
			try {
				$extension = ($this->profileService->find(id: (int)$report->getProfileId())->getFormat() ?? 'csv');
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ScheduledReportService] Could not read the profile format for the filename',
					context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
				);
			}
		}

		$slug = $this->slugify(value: (string)$report->getName());
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
	private function slugify(string $value): string {
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
	 * @param ScheduledReportRun $run The run, with its export and filename.
	 *
	 * @return \OCP\Files\Node|null The file it wrote, so the export run can name it. Without a file id
	 *                              the sweep has nothing to delete and the retention is a label on a row.
	 *
	 * @throws RuntimeException When the delivery folder is rejected or the user folder is unavailable.
	 */
	private function deliverToFiles(ScheduledReportRun $run): ?\OCP\Files\Node {
		$filename = $run->filename;
		try {
			$userFolder = $this->rootFolder->getUserFolder(userId: $run->owner->getUID());
		} catch (NotFoundException $e) {
			throw new RuntimeException('Owner Nextcloud Files folder unavailable: ' . $e->getMessage());
		}

		$folderPath = trim((string)($run->report->getDeliveryFolder() ?? 'Reports/'), '/');
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
			$existing = $folder->get(path: $filename);
			$existing->putContent(data: $run->bytes());

			// The node is RETURNED rather than discarded so the run can name
			// the file it produced. Without a file id the sweep has nothing to
			// delete, and the retention would be a label on a row.
			return $existing;
		}

		return $folder->newFile(path: $filename, content: $run->bytes());
	}//end deliverToFiles()

	/**
	 * Record what this run produced.
	 *
	 * Never throws. A report that delivered its file and then failed to write
	 * its own record should not be reported as a failed report: the copy is
	 * out there either way, and a swallowed record is a gap in the area rather
	 * than a lost delivery. The gap is logged.
	 *
	 * @param ScheduledReportRun $run The run, with its export and the file it wrote, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	private function recordRun(ScheduledReportRun $run): void {
		if ($this->exportRuns === null) {
			return;
		}

		$report = $run->report;
		$fileId = null;
		$filePath = null;
		if ($run->file !== null) {
			$fileId = $run->file->getId();
			$filePath = $run->file->getPath();
		}

		try {
			$this->exportRuns->record(
				origin: new ExportRunOrigin(
					source: 'scheduled-report',
					actor: $run->owner->getUID(),
					profile: (string)($report->getName() ?? ('report-' . (string)$report->getId()))
				),
				content: new ExportRunContent(
					format: (string)($report->getFormat() ?? 'csv'),
					rowCount: (int)($run->export['rowCount'] ?? 0),
					registerName: $this->nullableString(value: $report->getRegisterId()),
					schemaName: $this->nullableString(value: $report->getSchemaId())
				),
				file: new ExportRunFile(
					filename: $run->filename,
					fileId: $fileId,
					filePath: $filePath
				),
				retentionSeconds: $this->retentionFor(report: $report)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ScheduledReportService] The report delivered but its export run was not recorded',
				context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
			);
		}
	}//end recordRun()

	/**
	 * The retention a run of this report is produced under.
	 *
	 * A report that runs a profile copies the profile's retention, null
	 * meaning the profile keeps its files. A report without a profile keeps
	 * the recorder's default. Read once, at production: the run stores it, so
	 * a later profile edit does not move this file's deadline.
	 *
	 * @param ScheduledReport $report The report.
	 *
	 * @return int|null Seconds, or null to keep the file.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-export-expires-and-the-row-outlives-the-file
	 */
	private function retentionFor(ScheduledReport $report): ?int {
		if ($report->getProfileId() === null || $this->profileService === null) {
			return ExportRunRecorder::DEFAULT_RETENTION_SECONDS;
		}

		try {
			return $this->profileService->find(id: (int)$report->getProfileId())->getRetentionSeconds();
		} catch (\Throwable $e) {
			// The profile ran a moment ago; if it cannot be read now, the safe
			// direction is the default expiry, never keeping a copy forever.
			return ExportRunRecorder::DEFAULT_RETENTION_SECONDS;
		}
	}//end retentionFor()

	/**
	 * An identifier as a string, or null when it is absent.
	 *
	 * @param mixed $value The identifier.
	 *
	 * @return string|null The identifier.
	 */
	private function nullableString($value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end nullableString()

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
	 * @param ScheduledReportRun $run The run; its Files leg already ran when `delivered` is set (mode `both`).
	 *
	 * @return string|null The failure reason, or null on success.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function deliverToEmail(ScheduledReportRun $run): ?string {
		$recipients = $this->resolveRecipients(report: $run->report, owner: $run->owner);
		if (count($recipients) === 0) {
			return 'No valid recipient email address available (no recipients configured and the owner has no email on file).';
		}

		if ($this->isOversize(run: $run) === true && $run->delivered === false) {
			try {
				$this->deliverToFiles(run: $run);
			} catch (\Throwable $e) {
				return 'Export exceeds the email attachment size limit and the Files fallback failed: ' . $e->getMessage();
			}
		}

		try {
			$this->mailer->send($this->composeEmail(run: $run, recipients: $recipients));
		} catch (\Throwable $e) {
			return 'Email delivery failed: ' . $e->getMessage();
		}

		return null;
	}//end deliverToEmail()

	/**
	 * Whether the export is too large to attach to an email.
	 *
	 * @param ScheduledReportRun $run The run.
	 *
	 * @return bool
	 */
	private function isOversize(ScheduledReportRun $run): bool {
		return (strlen($run->bytes()) > self::MAX_EMAIL_ATTACHMENT_BYTES);
	}//end isOversize()

	/**
	 * The delivery email for a run: subject, body and, when it fits, the export attached.
	 *
	 * @param ScheduledReportRun   $run        The run.
	 * @param array<string,string> $recipients Email address => display name.
	 *
	 * @return \OCP\Mail\IMessage The message, ready to send.
	 */
	private function composeEmail(ScheduledReportRun $run, array $recipients): \OCP\Mail\IMessage {
		$report = $run->report;
		$subject = sprintf('Scheduled report "%s"', $report->getName());

		$emailTemplate = $this->mailer->createEMailTemplate('openregister.scheduledReportDelivery', []);
		$emailTemplate->setSubject($subject);
		$emailTemplate->addHeader();
		$emailTemplate->addHeading($report->getName());
		foreach ($this->emailBodyLines(run: $run) as $line) {
			$emailTemplate->addBodyText($line);
		}

		$emailTemplate->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo($recipients);
		$message->setSubject($subject);
		$message->useTemplate($emailTemplate);
		$message->setFrom($this->resolveFromAddress());

		if ($this->isOversize(run: $run) === false) {
			$attachment = $this->mailer->createAttachment(
				$run->bytes(),
				$run->filename,
				$this->mimeTypeFor(format: $report->getFormat())
			);
			$message->attach($attachment);
		}

		return $message;
	}//end composeEmail()

	/**
	 * The email body lines, with a note on where an oversize export was saved instead.
	 *
	 * @param ScheduledReportRun $run The run.
	 *
	 * @return array<int,string>
	 */
	private function emailBodyLines(ScheduledReportRun $run): array {
		$bodyLines = $this->buildEmailBodyLines(report: $run->report, rowCount: $run->export['rowCount']);
		if ($this->isOversize(run: $run) === true) {
			$bodyLines[] = sprintf(
				'The export (%s) exceeds the %dMB email attachment limit and was not attached. '
				. 'It has been saved to your Nextcloud Files at %s%s instead.',
				$this->humanFileSize(bytes: strlen($run->bytes())),
				(int)(self::MAX_EMAIL_ATTACHMENT_BYTES / 1024 / 1024),
				trim((string)($run->report->getDeliveryFolder() ?? 'Reports/'), '/'),
				'/' . $run->filename
			);
		}

		return $bodyLines;
	}//end emailBodyLines()

	/**
	 * Resolve the email recipients for a report: the explicitly configured
	 * list, or — when empty — the owner's own Nextcloud email address. An
	 * owner with no email on file and no explicit recipients resolves to no
	 * recipients at all (the caller treats that as an email-leg failure).
	 *
	 * @param ScheduledReport $report The report.
	 * @param \OCP\IUser $owner The owner.
	 *
	 * @return array<string,string> Email address => display name, ready for `IMessage::setTo()`.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function resolveRecipients(ScheduledReport $report, \OCP\IUser $owner): array {
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
	 * @param ScheduledReport $report The report.
	 * @param int $rowCount The exported row count.
	 *
	 * @return string[]
	 */
	private function buildEmailBodyLines(ScheduledReport $report, int $rowCount): array {
		$lines = [];
		$lines[] = sprintf('Your scheduled report "%s" ran and delivered %d row(s).', $report->getName(), $rowCount);

		try {
			$register = $this->registerMapper->find($report->getRegisterId(), _rbac: false, _multitenancy: false);
			$registerName = ($register->getTitle() ?? ('register ' . $report->getRegisterId()));
		} catch (\Throwable $e) {
			$registerName = ('register ' . $report->getRegisterId());
		}

		$schemaName = 'all schemas';
		if ($report->getSchemaId() !== null) {
			try {
				$schema = $this->schemaMapper->find($report->getSchemaId(), _rbac: false, _multitenancy: false);
				$schemaName = ($schema->getTitle() ?? ('schema ' . $report->getSchemaId()));
			} catch (\Throwable $e) {
				$schemaName = ('schema ' . $report->getSchemaId());
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
	private function resolveFromAddress(): array {
		$from = $this->config->getSystemValue('mail_from_address', 'no-reply');
		$domain = $this->config->getSystemValue('mail_domain', 'localhost');

		return [$from . '@' . $domain => 'OpenRegister'];
	}//end resolveFromAddress()

	/**
	 * MIME type for an export format's attachment.
	 *
	 * @param string|null $format csv|excel|pdf.
	 *
	 * @return string
	 */
	private function mimeTypeFor(?string $format): string {
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
	private function humanFileSize(int $bytes): string {
		if ($bytes >= 1048576) {
			return sprintf('%.1fMB', ($bytes / 1048576));
		}

		if ($bytes >= 1024) {
			return sprintf('%.1fKB', ($bytes / 1024));
		}

		return sprintf('%dB', $bytes);
	}//end humanFileSize()

	/**
	 * Persist that a run delivered everything it was asked to.
	 *
	 * @param ScheduledReport $report The report.
	 *
	 * @return void
	 */
	private function markSucceeded(ScheduledReport $report): void {
		$report->setLastStatus('success');
		$report->setLastError(null);
		$this->saveOutcome(report: $report);
	}//end markSucceeded()

	/**
	 * Persist that a run failed: nothing was delivered.
	 *
	 * @param ScheduledReport $report The report.
	 * @param string|null     $error  The failure reason.
	 *
	 * @return void
	 */
	private function markFailed(ScheduledReport $report, ?string $error): void {
		$report->setLastStatus('failed');
		$report->setLastError($error);
		$this->saveOutcome(report: $report);
	}//end markFailed()

	/**
	 * Persist that the Files leg delivered but the email leg failed.
	 *
	 * @param ScheduledReport $report The report.
	 * @param string|null     $error  Why the email leg failed.
	 *
	 * @return void
	 */
	private function markEmailFailed(ScheduledReport $report, ?string $error): void {
		$report->setLastStatus('email_failed');
		$report->setLastError($error);
		$this->saveOutcome(report: $report);
	}//end markEmailFailed()

	/**
	 * Stamp and persist the outcome already set on the report; a failure to
	 * persist is logged, never thrown.
	 *
	 * @param ScheduledReport $report The report, carrying its status and error.
	 *
	 * @return void
	 */
	private function saveOutcome(ScheduledReport $report): void {
		$report->setLastRunAt(new DateTime());
		$report->setUpdatedAt(new DateTime());

		try {
			$this->mapper->update(entity: $report);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[ScheduledReportService] Failed to persist run outcome',
				context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
			);
		}
	}//end saveOutcome()

	/**
	 * Notify the report owner that a run failed.
	 *
	 * @param ScheduledReport $report The report.
	 * @param string|null     $reason The failure reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function notifyFailed(ScheduledReport $report, ?string $reason): void {
		$this->notifyOwner(
			report: $report,
			notice: new ScheduledReportNotice(
				subject: 'scheduled_report_failed',
				parameters: [
					'reportId' => $report->getId(),
					'reportName' => $report->getName(),
					'reason' => ($reason ?? 'Unknown error'),
				]
			)
		);
	}//end notifyFailed()

	/**
	 * Notify the report owner that a run delivered. `mode` and `emailFailureReason` are
	 * optional subject parameters so `Notifier` can render email-aware wording without a
	 * new notification subject.
	 *
	 * @param ScheduledReportRun $run The run: its report, delivered filename, and the email leg's failure
	 *                                reason (set when Files succeeded but the email leg failed, mode `both`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function notifyDelivered(ScheduledReportRun $run): void {
		$report = $run->report;
		$this->notifyOwner(
			report: $report,
			notice: new ScheduledReportNotice(
				subject: 'scheduled_report_delivered',
				parameters: [
					'reportId' => $report->getId(),
					'reportName' => $report->getName(),
					'folder' => $report->getDeliveryFolder(),
					'filename' => $run->filename,
					'mode' => ($report->getDeliveryMode() ?? 'files'),
					'emailFailureReason' => $run->emailFailureReason,
				]
			)
		);
	}//end notifyDelivered()

	/**
	 * Send the report owner a notification; a failure to notify is logged, never thrown.
	 *
	 * @param ScheduledReport       $report The report.
	 * @param ScheduledReportNotice $notice The notification subject and its parameters.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function notifyOwner(ScheduledReport $report, ScheduledReportNotice $notice): void {
		$ownerUid = $report->getOwner();
		if ($ownerUid === null) {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('openregister')
				->setUser($ownerUid)
				->setDateTime(new DateTime())
				->setObject(type: 'scheduled_report', id: (string)$report->getId())
				->setSubject(subject: $notice->subject, parameters: $notice->parameters);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ScheduledReportService] Failed to send outcome notification',
				context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $report->getId(), 'error' => $e->getMessage()]
			);
		}
	}//end notifyOwner()
}//end class
