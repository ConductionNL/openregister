<?php

/**
 * AnalyticsLinkService — Tier-2 analytics (NC Analytics) integration
 * service.
 *
 * Composes the {@see \OCA\OpenRegister\Db\AnalyticsLinkMapper} with NC
 * Analytics' own `OCA\Analytics\Service\ReportService` (lazy-resolved via
 * `\OCP\Server::get()` behind a `class_exists` guard so OR boots cleanly
 * when Analytics is not installed) to expose the Tier-2 surface:
 *
 *   - linkReport(target, reportId)
 *       — link an existing report
 *   - createAndLinkReport(target, name, type)
 *       — create a new NC Analytics report and link it
 *   - unlinkReport(uuid, reportId)
 *       — remove a link (the report itself stays in NC Analytics)
 *   - getLinkedReports(uuid)
 *       — list linked reports, refreshing cached title/type/subheader/
 *       modified when the row is older than 24h
 *   - getAvailableReports(?search)
 *       — picker source listing the current user's reports
 *
 * Replaces the Tier-1 `AnalyticsProvider`'s `[or:{uuid}]` report-name
 * marker convention with a proper persistence layer so links survive
 * report renames + don't pollute the report title. When Analytics is
 * unavailable, the cached link-row values are returned unchanged so
 * historical references survive even after Analytics is uninstalled
 * (ADR-019 AD-23 graceful degradation).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\AnalyticsLinkMapperInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AnalyticsLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     IAppManager + IUserSession + logger + the lazily-resolved NC
 *     Analytics ReportService. Each dependency is required for one of the
 *     Tier-2 flows (link, create, unlink, list, picker, cache refresh,
 *     graceful degradation).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The create+link path
 *     and cache-refresh hydration inflate the count; re-implementing NC
 *     Analytics' write path would be worse.
 * @SuppressWarnings(PHPMD.StaticAccess)             `\OCP\Server::get()`
 *     is the documented way to late-resolve an optional peer app's
 *     services behind a class_exists guard (ADR-019).
 * @SuppressWarnings(PHPMD.MissingImport)            NC Analytics classes
 *     are referenced FQN-only on purpose — importing them would break
 *     autoloading when Analytics is not installed.
 */
class AnalyticsLinkService implements AnalyticsLinkServiceInterface {
	/**
	 * NC Analytics app id.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'analytics';

	/**
	 * NC Analytics ReportService FQCN (late-bound).
	 *
	 * @var string
	 */
	private const REPORT_SERVICE = '\OCA\Analytics\Service\ReportService';

	/**
	 * Cache-refresh staleness threshold in seconds (24h).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 86400;

	/**
	 * NC Analytics group/folder report type (DATASET_TYPE_GROUP = 0).
	 *
	 * @var int
	 */
	private const REPORT_TYPE_GROUP = 0;

	/**
	 * Constructor.
	 *
	 * @param AnalyticsLinkMapperInterface $analyticsLinkMapper Persistence for link rows.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface $container App container NC Analytics' ReportService is resolved from.
	 */
	public function __construct(
		private readonly AnalyticsLinkMapperInterface $analyticsLinkMapper,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether NC Analytics is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isAnalyticsAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
	}//end isAnalyticsAvailable()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		return $user->getUID();
	}//end requireUid()

	/**
	 * Lazily resolve NC Analytics' ReportService behind a class_exists
	 * guard so OR boots cleanly without the Analytics app.
	 *
	 * Returns null when Analytics is absent or the class can't be
	 * resolved, so callers degrade gracefully (ADR-019 AD-23).
	 *
	 * @return object|null The ReportService instance or null.
	 */
	private function resolveReportService(): ?object {
		if ($this->isAnalyticsAvailable() === false) {
			return null;
		}

		if (class_exists(self::REPORT_SERVICE) === false) {
			return null;
		}

		try {
			return $this->container->get(self::REPORT_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug('AnalyticsLinkService: ReportService unavailable: ' . $e->getMessage());
			return null;
		}
	}//end resolveReportService()

	/**
	 * Link an existing NC Analytics report to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Report
	 * metadata (title/type/subheader/created/modified) is cached at link
	 * time.
	 *
	 * @param LinkTarget $target   The object to link to.
	 * @param int        $reportId NC Analytics report id.
	 *
	 * @return AnalyticsLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing report (404),
	 *                   duplicate (409), Analytics unavailable (503).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkReport(LinkTarget $target, int $reportId): AnalyticsLink {
		$uid = $this->requireUid();
		$this->assertReportLinkable(target: $target, reportId: $reportId);

		$info = $this->fetchReportInfo(reportId: $reportId);
		if ($info === null) {
			throw new Exception('Analytics report not found', 404);
		}

		$link = $this->buildLink(reportId: $reportId, info: $info);
		$target->stamp(link: $link, linkedBy: $uid);

		return $this->analyticsLinkMapper->insert($link);
	}//end linkReport()

	/**
	 * Refuse a link when Analytics is off or the report is already linked to the object.
	 *
	 * @param LinkTarget $target   The object to link to.
	 * @param int        $reportId NC Analytics report id.
	 *
	 * @return void
	 *
	 * @throws Exception Analytics unavailable (503) or duplicate (409).
	 */
	private function assertReportLinkable(LinkTarget $target, int $reportId): void {
		if ($this->isAnalyticsAvailable() === false) {
			throw new Exception('NC Analytics is not available', 503);
		}

		if ($this->analyticsLinkMapper->findByObjectAndReport($target->objectUuid, $reportId) !== null) {
			throw new Exception('Report already linked to this object', 409);
		}
	}//end assertReportLinkable()

	/**
	 * Create a new NC Analytics report and link it to an OR object.
	 *
	 * The new report is a blank group/folder report by default; callers
	 * may pass a different NC Analytics datasource type integer via
	 * `$type`.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string     $name   New report name.
	 * @param int|null   $type   NC Analytics datasource type (default group).
	 *
	 * @return AnalyticsLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Analytics unavailable (503), create failure (500).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkReport(LinkTarget $target, string $name, ?int $type = null): AnalyticsLink {
		$uid = $this->requireUid();

		$name = trim($name);
		if ($name === '') {
			throw new Exception('Report name is required', 400);
		}

		$reportType = ($type ?? self::REPORT_TYPE_GROUP);
		$reportId   = $this->createReport(name: $name, reportType: $reportType);

		$info = ($this->fetchReportInfo(reportId: $reportId) ?? $this->draftReportInfo(name: $name, reportType: $reportType));
		$link = $this->buildLink(reportId: $reportId, info: $info);
		$target->stamp(link: $link, linkedBy: $uid);

		return $this->analyticsLinkMapper->insert($link);
	}//end createAndLinkReport()

	/**
	 * Create a report in NC Analytics.
	 *
	 * @param string $name       The report name.
	 * @param int    $reportType NC Analytics datasource type.
	 *
	 * @return int The new report id.
	 *
	 * @throws Exception Analytics unavailable (503) or create failure (500).
	 */
	private function createReport(string $name, int $reportType): int {
		$service = $this->resolveReportService();
		if ($service === null) {
			throw new Exception('NC Analytics is not available', 503);
		}

		$reportId = $this->invokeCreate(service: $service, args: [$name, '', 0, $reportType, 0, '', '', '', '', '', '']);
		if ($reportId === 0) {
			throw new Exception('Failed to create Analytics report', 500);
		}

		return $reportId;
	}//end createReport()

	/**
	 * Call ReportService::create(), turning any failure into a 500.
	 *
	 * @param object $service The NC Analytics ReportService.
	 * @param array<int,mixed> $args The positional create() arguments.
	 *
	 * @return int The new report id (0 when none was returned).
	 *
	 * @throws Exception Create failure (500).
	 */
	private function invokeCreate(object $service, array $args): int {
		try {
			return (int)$service->create(...$args);
		} catch (Throwable $e) {
			$this->logger->warning('AnalyticsLinkService::createAndLinkReport failed: ' . $e->getMessage());
			throw new Exception('Failed to create Analytics report', 500);
		}
	}//end invokeCreate()

	/**
	 * The cached metadata for a new report whose read came back empty.
	 *
	 * @param string $name       The report name.
	 * @param int    $reportType NC Analytics datasource type.
	 *
	 * @return array<string,mixed> The report info.
	 */
	private function draftReportInfo(string $name, int $reportType): array {
		return [
			'title' => $name,
			'type' => (string)$reportType,
			'subheader' => null,
			'createdAt' => new DateTime(),
			'modifiedAt' => new DateTime(),
		];
	}//end draftReportInfo()

	/**
	 * Unlink a report from an object.
	 *
	 * Does NOT delete the report itself — it stays in NC Analytics for
	 * the user and for any other linked objects.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $reportId NC Analytics report id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkReport(string $objectUuid, int $reportId): void {
		$this->requireUid();

		$deleted = $this->analyticsLinkMapper->deleteByObjectAndReport($objectUuid, $reportId);
		if ($deleted === 0) {
			throw new Exception('Analytics link not found', 404);
		}
	}//end unlinkReport()

	/**
	 * Return the linked reports for an object, refreshing the cached
	 * title/type/subheader/modified columns when a row is older than 24h.
	 *
	 * When Analytics is unavailable the cached link-row values are
	 * returned unchanged.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedReports(string $objectUuid): array {
		$links = $this->analyticsLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isAnalyticsAvailable();
		$now = time();

		$results = [];
		foreach ($links as $link) {
			if ($available === true && $this->isStale(link: $link, now: $now) === true) {
				$link = $this->refreshLink(link: $link);
			}

			$row = $link->jsonSerialize();
			$row['url'] = $this->reportDeepLink(reportId: (int)($row['reportId'] ?? 0));
			$results[] = $row;
		}

		return $results;
	}//end getLinkedReports()

	/**
	 * Return the current user's NC Analytics reports (picker source).
	 *
	 * Optional substring search against the report name. Returns an empty
	 * array when Analytics is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableReports(?string $search = null): array {
		$reports = $this->indexReports();
		$needle = $this->needleOf(search: $search);

		$out = [];
		foreach ($reports as $report) {
			$row = $this->pickerRowFromReport(report: (array)$report, needle: $needle);
			if ($row !== null) {
				$out[] = $row;
			}
		}

		return $out;
	}//end getAvailableReports()

	/**
	 * The current user's NC Analytics reports; empty when Analytics is
	 * unavailable or the listing fails.
	 *
	 * @return mixed The ReportService::index() result, or an empty array.
	 */
	private function indexReports(): mixed {
		$service = $this->resolveReportService();
		if ($service === null) {
			return [];
		}

		try {
			return $service->index();
		} catch (Throwable $e) {
			$this->logger->warning('AnalyticsLinkService::getAvailableReports failed: ' . $e->getMessage());
			return [];
		}
	}//end indexReports()

	/**
	 * The lower-cased name filter, or null when no search was given.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return string|null
	 */
	private function needleOf(?string $search): ?string {
		if ($search === null || $search === '') {
			return null;
		}

		return mb_strtolower($search);
	}//end needleOf()

	/**
	 * Build an AnalyticsLink entity from cached report info.
	 *
	 * @param int $reportId NC Analytics report id.
	 * @param array<string,mixed> $info Normalised report info.
	 *
	 * @return AnalyticsLink
	 */
	private function buildLink(
		int $reportId,
		array $info,
	): AnalyticsLink {
		$link = new AnalyticsLink();
		$link->setReportId($reportId);
		$link->setReportTitle((string)($info['title'] ?? ''));
		$link->setReportType($info['type']);
		$link->setSubheader($info['subheader']);
		$link->setCreatedAt($info['createdAt']);
		$link->setModifiedAt($info['modifiedAt']);

		return $link;
	}//end buildLink()

	/**
	 * Map a single NC Analytics report row into a picker row, applying
	 * the optional name filter.
	 *
	 * @param array<string,mixed> $report A normalised report row.
	 * @param string|null $needle Lower-cased name-substring filter, or null.
	 *
	 * @return array<string,mixed>|null The picker row, or null when the
	 *                                  report is unreadable or filtered out.
	 */
	private function pickerRowFromReport(array $report, ?string $needle): ?array {
		$reportId = (int)($report['id'] ?? 0);
		if ($reportId === 0) {
			return null;
		}

		$row = $this->pickerRow(report: $report, reportId: $reportId);
		if ($needle !== null && str_contains(mb_strtolower($row['name']), $needle) === false) {
			return null;
		}

		return $row;
	}//end pickerRowFromReport()

	/**
	 * The picker row of a readable report.
	 *
	 * @param array<string,mixed> $report A normalised report row.
	 * @param int $reportId The report's (non-zero) id.
	 *
	 * @return array{id:int,name:string,type:?string,subheader:?string,url:string}
	 */
	private function pickerRow(array $report, int $reportId): array {
		return [
			'id' => $reportId,
			'name' => (string)($report['name'] ?? ''),
			'type' => $this->optionalString(report: $report, key: 'type'),
			'subheader' => $this->optionalString(report: $report, key: 'subheader'),
			'url' => $this->reportDeepLink(reportId: $reportId),
		];
	}//end pickerRow()

	/**
	 * A report field as a string, or null when it is not set.
	 *
	 * @param array<string,mixed> $report A report row.
	 * @param string $key The field.
	 *
	 * @return string|null
	 */
	private function optionalString(array $report, string $key): ?string {
		if (isset($report[$key]) === false) {
			return null;
		}

		return (string)$report[$key];
	}//end optionalString()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param AnalyticsLink $link The link row.
	 * @param int $now Current epoch seconds.
	 *
	 * @return bool
	 */
	private function isStale(AnalyticsLink $link, int $now): bool {
		$linkedAt = $link->getLinkedAt();
		if ($linkedAt === null) {
			return true;
		}

		return ($now - $linkedAt->getTimestamp()) > self::CACHE_TTL;
	}//end isStale()

	/**
	 * Refresh a link row's cached report metadata in place.
	 *
	 * Best-effort: when the report can't be resolved the link is left
	 * untouched (the report may have been deleted in NC Analytics).
	 *
	 * @param AnalyticsLink $link The link row.
	 *
	 * @return AnalyticsLink The (possibly updated) link row.
	 */
	private function refreshLink(AnalyticsLink $link): AnalyticsLink {
		$info = $this->fetchReportInfo(reportId: (int)$link->getReportId());
		if ($info === null) {
			return $link;
		}

		$link->setReportTitle((string)($info['title'] ?? ''));
		$link->setReportType($info['type']);
		$link->setSubheader($info['subheader']);
		$link->setModifiedAt($info['modifiedAt']);
		if ($info['createdAt'] !== null) {
			$link->setCreatedAt($info['createdAt']);
		}

		$link->setLinkedAt(new DateTime());
		return $this->saveRefreshed(link: $link);
	}//end refreshLink()

	/**
	 * Persist a refreshed link row, best-effort.
	 *
	 * @param AnalyticsLink $link The refreshed link row.
	 *
	 * @return AnalyticsLink The stored row, or the in-memory row when the update fails.
	 */
	private function saveRefreshed(AnalyticsLink $link): AnalyticsLink {
		try {
			return $this->analyticsLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->logger->debug('AnalyticsLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end saveRefreshed()

	/**
	 * Fetch normalised report metadata from NC Analytics.
	 *
	 * @param int $reportId The report id.
	 *
	 * @return array{title:string,type:?string,subheader:?string,createdAt:?DateTime,modifiedAt:?DateTime}|null
	 */
	private function fetchReportInfo(int $reportId): ?array {
		$report = $this->readReport(reportId: $reportId);
		if (is_array($report) === false || $report === []) {
			return null;
		}

		return [
			'title' => (string)($report['name'] ?? ''),
			'type' => $this->optionalString(report: $report, key: 'type'),
			'subheader' => $this->optionalString(report: $report, key: 'subheader'),
			'createdAt' => $this->parseTimestamp(value: ($report['created_at'] ?? null)),
			'modifiedAt' => $this->parseTimestamp(value: ($report['modified_at'] ?? ($report['updated_at'] ?? null))),
		];
	}//end fetchReportInfo()

	/**
	 * Read a report from NC Analytics; null when Analytics is unavailable or the read fails.
	 *
	 * @param int $reportId The report id.
	 *
	 * @return mixed The ReportService::read() result, or null.
	 */
	private function readReport(int $reportId): mixed {
		$service = $this->resolveReportService();
		if ($service === null) {
			return null;
		}

		try {
			return $service->read($reportId);
		} catch (Throwable $e) {
			$this->logger->debug('AnalyticsLinkService::fetchReportInfo read failed: ' . $e->getMessage());
			return null;
		}
	}//end readReport()

	/**
	 * Parse a NC Analytics timestamp (epoch int or datetime string) into
	 * a DateTime, best-effort.
	 *
	 * @param mixed $value Raw timestamp value.
	 *
	 * @return DateTime|null
	 */
	private function parseTimestamp(mixed $value): ?DateTime {
		if ($value === null || $value === '' || $value === 0) {
			return null;
		}

		try {
			return $this->toDateTime(value: $value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parseTimestamp()

	/**
	 * An epoch number or a datetime string as a DateTime.
	 *
	 * @param mixed $value Raw, non-empty timestamp value.
	 *
	 * @return DateTime
	 *
	 * @throws Throwable When the string is not a parseable datetime.
	 */
	private function toDateTime(mixed $value): DateTime {
		if (is_numeric($value) === true) {
			return (new DateTime())->setTimestamp((int)$value);
		}

		return new DateTime((string)$value);
	}//end toDateTime()

	/**
	 * Build the NC Analytics deep link for a report.
	 *
	 * @param int $reportId The report id.
	 *
	 * @return string
	 */
	private function reportDeepLink(int $reportId): string {
		return '/index.php/apps/analytics/#/r/' . $reportId;
	}//end reportDeepLink()
}//end class
