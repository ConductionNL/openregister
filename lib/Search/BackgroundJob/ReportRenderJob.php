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
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Search\BackgroundJob\Support\DashboardRenderRun;
use OCA\OpenRegister\Search\BackgroundJob\Support\ReportRenderDelivery;
use OCA\OpenRegister\Search\BackgroundJob\Support\ReportRenderPlatform;
use OCA\OpenRegister\Search\BackgroundJob\Support\ReportRenderSources;
use OCA\OpenRegister\Search\BackgroundJob\Support\ScheduledRenderPassRun;
use OCA\OpenRegister\Search\Service\Reporting\ReportRenderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

/**
 * Daily scheduled-report renderer.
 *
 * RBAC / multi-tenancy posture — written down deliberately, not implied
 * (openregister#2282):
 *
 * This is a system job. It runs from cron with NO user session, so there is no
 * principal for RBAC to evaluate and no organisation for multi-tenancy to scope
 * to. An RBAC-applying read from here does not "fail safe" — it returns nothing,
 * which is exactly the silent-zero this job shipped with. The job therefore reads
 * with `_rbac`/`_multitenancy` explicitly DISABLED on every hop:
 *
 *   - `loadReportsRegister()` — `RegisterMapper::find(..., _rbac: false, _multitenancy: false)`
 *   - `loadDashboards()`      — `SchemaMapper::findMultiple(..., _rbac: false, _multitenancy: false)`
 *                               and the reserved `_rbac`/`_multitenancy` query
 *                               filters on the object read.
 *
 * Access control is NOT abandoned, it is enforced at DELIVERY instead of at read.
 * `writeToFiles()` resolves the target home from `$dashboard->getOwner()` — the
 * dashboard object's own owner — refuses to deliver at all when that owner is
 * null (rather than falling back to `admin`), and confines the path to a
 * traversal-checked relative folder under that owner's home. A rendered report
 * can therefore only ever land in the Files tree of the user the dashboard
 * already belongs to. Widening the READ does not widen who receives the OUTPUT.
 *
 * This deliberately does NOT add `_rbac`/`_multitenancy` parameters to
 * `MagicMapper::findAll()`. The central mapper already exposes the opt-out as
 * first-class reserved query params (`MagicSearchHandler::getReservedParams()`,
 * consumed at `buildFilteredQuery()`), and `SchemaDeletionService` already reads
 * this way for the same system-level reason. Using the existing idiom leaves the
 * mapper's RBAC contract untouched.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Injecting SchemaMapper to
 * resolve the reports register's schemas takes this class from 12 to 13 coupled
 * types, one over the threshold. Measured, not assumed: phpmd exits 0 on the
 * pre-fix file and 2 on this one, with CouplingBetweenObjects as the only
 * finding. The dependency is required — without it the job cannot name a schema,
 * and `MagicMapper::findAll()` returns [] without one, which is openregister#2282.
 */
class ReportRenderJob extends TimedJob {

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
	 * Maximum dashboards read per schema in one pass.
	 *
	 * @var int
	 */
	private const DASHBOARD_SCAN_LIMIT = 200;

	/**
	 * Object loader.
	 *
	 * @var MagicTableRowReaderInterface
	 */
	private readonly MagicTableRowReaderInterface $objectMapper;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time     Time factory.
	 * @param ReportRenderSources  $sources  Register, schema and object lookup.
	 * @param ReportRenderDelivery $delivery Render composer and Files root.
	 * @param ReportRenderPlatform $platform App-config reader and logger.
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ReportRenderSources $sources,
		private readonly ReportRenderDelivery $delivery,
		private readonly ReportRenderPlatform $platform,
	) {
		parent::__construct(time: $time);
		$this->objectMapper = $sources->objectMapper;
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
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	protected function run($argument): void {
		if ($this->scheduledRendersEnabled() === false) {
			$this->platform->logger->info(
				message: '[ReportRenderJob] Scheduled renders disabled (kill switch on), skipping',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		$register = $this->findReportsRegister();
		if ($register !== null) {
			$this->renderDueDashboards(register: $register);
		}
	}//end run()

	/**
	 * Whether scheduled renders are switched on (the kill switch is off).
	 *
	 * @return bool
	 */
	private function scheduledRendersEnabled(): bool {
		return filter_var(
			$this->platform->appConfig->getValueString(
				app: self::APP_ID,
				key: self::CONFIG_KEY_ENABLED,
				default: 'true'
			),
			FILTER_VALIDATE_BOOLEAN
		);
	}//end scheduledRendersEnabled()

	/**
	 * The `reports` register, or null (logged) when the bundle is not imported.
	 *
	 * @return Register|null
	 */
	private function findReportsRegister(): ?Register {
		try {
			return $this->loadReportsRegister();
		} catch (\Throwable $e) {
			$this->platform->logger->info(
				message: '[ReportRenderJob] No `reports` register found — bundle not imported, skipping',
				context: ['error' => $e->getMessage()]
			);
			return null;
		}
	}//end findReportsRegister()

	/**
	 * Render every due dashboard of the reports register and log the pass.
	 *
	 * @param Register $register Reports register.
	 *
	 * @return void
	 */
	private function renderDueDashboards(Register $register): void {
		$candidates = $this->loadDashboards(register: $register);
		$pass = new ScheduledRenderPassRun(now: new DateTime());
		foreach ($candidates as $dashboard) {
			$this->renderIfDue(dashboard: $dashboard, pass: $pass);
		}

		$this->logPassComplete(pass: $pass, candidates: count($candidates));
	}//end renderDueDashboards()

	/**
	 * Log the counts of a finished pass.
	 *
	 * @param ScheduledRenderPassRun $pass       The finished pass.
	 * @param int                    $candidates How many dashboards the pass looked at.
	 *
	 * @return void
	 */
	private function logPassComplete(ScheduledRenderPassRun $pass, int $candidates): void {
		$this->platform->logger->info(
			message: '[ReportRenderJob] Scheduled-render pass complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'candidates' => $candidates,
				'rendered' => $pass->rendered,
				'skipped' => $pass->skipped,
			]
		);
	}//end logPassComplete()

	/**
	 * Render and deliver one dashboard when it is due, counting it in the pass.
	 *
	 * @param ObjectEntity           $dashboard The dashboard object.
	 * @param ScheduledRenderPassRun $pass      The pass it is part of.
	 *
	 * @return void
	 */
	private function renderIfDue(ObjectEntity $dashboard, ScheduledRenderPassRun $pass): void {
		$payload = $dashboard->getObject() ?? [];
		$dashboardRun = new DashboardRenderRun(dashboard: $dashboard, payload: $payload);
		if ($this->shouldRender(run: $dashboardRun, now: $pass->now) === false) {
			$pass->skipped++;
			return;
		}

		try {
			$this->renderAndDeliver(run: $dashboardRun);
			$pass->rendered++;
		} catch (\Throwable $e) {
			$this->logRenderFailure(run: $dashboardRun, error: $e);
		}
	}//end renderIfDue()

	/**
	 * Log a dashboard whose render or delivery failed.
	 *
	 * @param DashboardRenderRun $run   The dashboard and its data.
	 * @param \Throwable         $error The failure.
	 *
	 * @return void
	 */
	private function logRenderFailure(DashboardRenderRun $run, \Throwable $error): void {
		$this->platform->logger->warning(
			message: '[ReportRenderJob] Render failed for dashboard',
			context: [
				'dashboard' => ($run->payload['titel'] ?? $run->dashboard->getUuid()),
				'error' => $error->getMessage(),
			]
		);
	}//end logRenderFailure()

	/**
	 * Should the dashboard be rendered now?
	 *
	 * @param DashboardRenderRun $run The dashboard and its data.
	 * @param DateTime           $now Reference time.
	 *
	 * @return bool
	 */
	private function shouldRender(DashboardRenderRun $run, DateTime $now): bool {
		$intervalSec = self::scheduledIntervalOf(run: $run);
		if ($intervalSec <= 0) {
			return false;
		}

		// Never rendered, or a malformed timestamp → fire now.
		$last = self::lastRenderedAt(run: $run);
		if ($last === null) {
			return true;
		}

		$diff = ($now->getTimestamp() - $last->getTimestamp());
		return $diff >= $intervalSec;
	}//end shouldRender()

	/**
	 * The render interval of an active schedule, in seconds; 0 without an active schedule.
	 *
	 * @param DashboardRenderRun $run The dashboard and its data.
	 *
	 * @return int
	 */
	private static function scheduledIntervalOf(DashboardRenderRun $run): int {
		$schedule = $run->schedule();
		if (is_array($schedule) === false) {
			return 0;
		}

		$active = filter_var($schedule['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
		if ($active === false) {
			return 0;
		}

		return (int)($schedule['intervalSec'] ?? 0);
	}//end scheduledIntervalOf()

	/**
	 * When the dashboard was last rendered; null when never or unreadable.
	 *
	 * @param DashboardRenderRun $run The dashboard and its data.
	 *
	 * @return DateTime|null
	 */
	private static function lastRenderedAt(DashboardRenderRun $run): ?DateTime {
		$lastRendered = ($run->payload['lastRenderedAt'] ?? null);
		if (is_string($lastRendered) === false || $lastRendered === '') {
			return null;
		}

		try {
			return new DateTime($lastRendered);
		} catch (\Throwable $e) {
			// Malformed timestamp — fall through to render.
			return null;
		}
	}//end lastRenderedAt()

	/**
	 * Render + deliver a single dashboard.
	 *
	 * @param DashboardRenderRun $run The dashboard and its data.
	 *
	 * @return void
	 */
	private function renderAndDeliver(DashboardRenderRun $run): void {
		$delivery = $run->delivery();
		$format = (string)($delivery['format'] ?? 'xlsx');
		if (in_array(needle: $format, haystack: ReportRenderService::FORMATS, strict: true) === false) {
			$format = 'xlsx';
		}

		$rendered = $this->delivery->renderService->render(
			dashboard: $run->dashboard,
			format: $format
		);

		$channel = (string)($delivery['channel'] ?? 'files');
		if ($channel === 'files' || $channel === 'both') {
			$this->writeToFiles(run: $run, rendered: $rendered);
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
	 * @param DashboardRenderRun   $run      The dashboard, its data and delivery descriptor.
	 * @param array<string, mixed> $rendered ReportRenderService::render output.
	 *
	 * @return void
	 */
	private function writeToFiles(DashboardRenderRun $run, array $rendered): void {
		$userFolder = $this->ownerHome(run: $run);
		if ($userFolder === null) {
			return;
		}

		$folderPath = $this->safeDeliveryFolder(run: $run);
		if ($folderPath !== null) {
			$folder = self::deliveryFolder(userFolder: $userFolder, folderPath: $folderPath);
			$this->writeRendered(folder: $folder, rendered: $rendered);
		}
	}//end writeToFiles()

	/**
	 * The dashboard owner the files are delivered to, or null (logged) when it is missing.
	 *
	 * SECURITY: refuse to fall back to 'admin' when the dashboard
	 * owner is null. The previous default dropped attacker-controlled
	 * bytes (rendered HTML/PDF derived from a user-writable
	 * ObjectEntity) into admin's Files share, where admin would see
	 * them on next login — a phishing/redirect persistence vector.
	 * Without an owner we have no honest "who runs this job", so
	 * skip delivery and surface the misconfiguration in logs.
	 *
	 * @param DashboardRenderRun $run The dashboard and its data.
	 *
	 * @return string|null
	 */
	private function deliveryOwner(DashboardRenderRun $run): ?string {
		$owner = $run->dashboard->getOwner();
		if ($owner === null || $owner === '') {
			$this->platform->logger->warning(
				message: '[ReportRenderJob] Dashboard owner missing — skipping Files delivery',
				context: ['dashboardUuid' => $run->dashboard->getUuid()]
			);
			return null;
		}

		return $owner;
	}//end deliveryOwner()

	/**
	 * The dashboard owner's home folder, or null (logged) when the owner is
	 * missing or their home is unavailable.
	 *
	 * @param DashboardRenderRun $run The dashboard and its data.
	 *
	 * @return Folder|null
	 */
	private function ownerHome(DashboardRenderRun $run): ?Folder {
		$owner = $this->deliveryOwner(run: $run);
		if ($owner === null) {
			return null;
		}

		try {
			return $this->delivery->rootFolder->getUserFolder(userId: $owner);
		} catch (NotFoundException $e) {
			$this->platform->logger->warning(
				message: '[ReportRenderJob] User folder unavailable, skipping delivery',
				context: ['owner' => $owner, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end ownerHome()

	/**
	 * The delivery folder relative to the owner's home, or null (logged) when it is unsafe.
	 *
	 * SECURITY: $delivery['filesFolder'] comes from the dashboard
	 * payload (user-controlled JSON). Restrict to a relative path
	 * under the owner's home — strip leading slashes and reject any
	 * segment that escapes the home root (`..`) or addresses an
	 * absolute filesystem path.
	 *
	 * @param DashboardRenderRun $run The dashboard, its data and delivery descriptor.
	 *
	 * @return string|null
	 */
	private function safeDeliveryFolder(DashboardRenderRun $run): ?string {
		$folderPath = ltrim($this->deliveryFolderPath(run: $run), '/');
		if ($folderPath === '' || str_contains($folderPath, '..') === true) {
			$this->platform->logger->warning(
				message: '[ReportRenderJob] Rejected delivery folder containing path traversal',
				context: ['folder' => $folderPath, 'owner' => $run->dashboard->getOwner()]
			);
			return null;
		}

		return $folderPath;
	}//end safeDeliveryFolder()

	/**
	 * The configured delivery folder, or `Reports/<dashboard-slug>` when none is set.
	 *
	 * @param DashboardRenderRun $run The dashboard, its data and delivery descriptor.
	 *
	 * @return string
	 */
	private function deliveryFolderPath(DashboardRenderRun $run): string {
		$folderPath = (string)($run->delivery()['filesFolder'] ?? '');
		if ($folderPath === '') {
			$slug = $this->slugify(value: (string)($run->payload['titel'] ?? 'dashboard'));
			$folderPath = sprintf('Reports/%s', $slug);
		}

		return $folderPath;
	}//end deliveryFolderPath()

	/**
	 * The delivery folder under the owner's home, created when it does not exist.
	 *
	 * @param Folder $userFolder The owner's home folder.
	 * @param string $folderPath The delivery folder, relative to the home.
	 *
	 * @return Node
	 */
	private static function deliveryFolder(Folder $userFolder, string $folderPath): Node {
		if ($userFolder->nodeExists(path: $folderPath) === false) {
			$userFolder->newFolder(path: $folderPath);
		}

		return $userFolder->get(path: $folderPath);
	}//end deliveryFolder()

	/**
	 * Write the rendered bytes into the delivery folder, overwriting an existing file.
	 *
	 * @param Node                 $folder   The delivery folder.
	 * @param array<string, mixed> $rendered ReportRenderService::render output.
	 *
	 * @return void
	 */
	private function writeRendered(Node $folder, array $rendered): void {
		$filename = (string)$rendered['filename'];
		if ($folder->nodeExists(path: $filename) === true) {
			$existing = $folder->get(path: $filename);
			$existing->putContent(data: (string)$rendered['bytes']);
			return;
		}

		$folder->newFile(path: $filename, content: (string)$rendered['bytes']);
	}//end writeRendered()

	/**
	 * Resolve the `reports` register.
	 *
	 * @return Register
	 */
	private function loadReportsRegister(): Register {
		return $this->sources->registerMapper->find(
			self::REPORTS_REGISTER_SLUG,
			_rbac: false,
			_multitenancy: false
		);

	}//end loadReportsRegister()

	/**
	 * Load every dashboard object in the reports register.
	 *
	 * The dashboard schema is the only one in the reports register; we walk
	 * by-register so a future "report" schema (Phase 3 template variants) is
	 * also covered.
	 *
	 * openregister#2282 — this method used to call
	 * `MagicMapper::findAll(filters: ['register' => …], _rbac: false, _multitenancy: false)`
	 * and returned `[]` on EVERY run, by two independent routes:
	 *
	 *   1. `MagicMapper::findAll()` has no `$_rbac` and no `$_multitenancy`
	 *      parameter (`MagicMapper::find()` does, which is where the call shape
	 *      was copied from). On PHP 8 an unknown named argument raises `\Error`,
	 *      the `catch (\Throwable)` below swallowed it, and the job logged a
	 *      warning nobody read.
	 *   2. Even with the bogus named arguments removed, `findAll()` opens with a
	 *      `$register === null || $schema === null` guard and early-returns `[]`.
	 *      The register id was passed inside `filters`, not as `register:`, so
	 *      the guard fired regardless.
	 *
	 * The fix resolves the register's schemas and reads each register+schema
	 * table directly. `_rbac`/`_multitenancy` are passed as the reserved query
	 * filters the search handler already understands
	 * (`MagicSearchHandler::getReservedParams()`), which is the same shape
	 * `SchemaDeletionService::auditObjectsBeforeDeletion()` uses for the same
	 * system-level reason — so no change to `MagicMapper`'s RBAC contract is
	 * required. See the class docblock for the posture and why.
	 *
	 * @param Register $register Reports register.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	private function loadDashboards(Register $register): array {
		$schemas = $this->sources->schemaMapper->findMultiple(
			ids: $register->getSchemas(),
			_rbac: false,
			_multitenancy: false
		);

		if (empty($schemas) === true) {
			$this->platform->logger->warning(
				message: '[ReportRenderJob] Reports register has no resolvable schemas',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'registerId' => $register->getId(),
					'schemaRefs' => $register->getSchemas(),
				]
			);
			return [];
		}

		$dashboards = [];
		foreach ($schemas as $schema) {
			try {
				$found = $this->objectMapper->findAllInRegisterSchemaTable(
					table: new RegisterSchema(register: $register, schema: $schema),
					listing: new ListQuery(
						limit: self::DASHBOARD_SCAN_LIMIT,
						offset: 0,
						// Reserved query params — see the class docblock.
						filters: ['_rbac' => false, '_multitenancy' => false]
					)
				);
			} catch (\Throwable $e) {
				$this->platform->logger->warning(
					message: '[ReportRenderJob] Failed to enumerate dashboards for schema',
					context: [
						'registerId' => $register->getId(),
						'schemaId' => $schema->getId(),
						'error' => $e->getMessage(),
					]
				);
				continue;
			}//end try

			foreach ($found as $entity) {
				$dashboards[] = $entity;
			}
		}//end foreach

		return $dashboards;
	}//end loadDashboards()

	/**
	 * Slugify a title for a folder path.
	 *
	 * @param string $value Title.
	 *
	 * @return string Slug.
	 */
	private function slugify(string $value): string {
		$slug = strtolower(trim($value));
		$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
		$slug = trim($slug, '-');
		if ($slug === '') {
			return 'dashboard';
		}

		return $slug;
	}//end slugify()
}//end class
