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
 *   - linkReport(uuid, registerId, schemaId, reportId)
 *       — link an existing report
 *   - createAndLinkReport(uuid, registerId, schemaId, name, type)
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
use OCA\OpenRegister\Db\AnalyticsLinkMapper;
use OCP\App\IAppManager;
use OCP\IUserSession;
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
class AnalyticsLinkService {
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
	 * @param AnalyticsLinkMapper $analyticsLinkMapper Persistence for link rows.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly AnalyticsLinkMapper $analyticsLinkMapper,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
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
			return \OCP\Server::get(self::REPORT_SERVICE);
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
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param int $reportId NC Analytics report id.
	 *
	 * @return AnalyticsLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing report (404),
	 *                   duplicate (409), Analytics unavailable (503).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkReport(string $objectUuid, int $registerId, int $schemaId, int $reportId): AnalyticsLink {
		$uid = $this->requireUid();

		if ($this->isAnalyticsAvailable() === false) {
			throw new Exception('NC Analytics is not available', 503);
		}

		$existing = $this->analyticsLinkMapper->findByObjectAndReport($objectUuid, $reportId);
		if ($existing !== null) {
			throw new Exception('Report already linked to this object', 409);
		}

		$info = $this->fetchReportInfo(reportId: $reportId);
		if ($info === null) {
			throw new Exception('Analytics report not found', 404);
		}

		$link = $this->buildLink(
			objectUuid: $objectUuid,
			registerId: $registerId,
			schemaId: $schemaId,
			reportId: $reportId,
			info: $info,
			uid: $uid,
		);

		return $this->analyticsLinkMapper->insert($link);
	}//end linkReport()

	/**
	 * Create a new NC Analytics report and link it to an OR object.
	 *
	 * The new report is a blank group/folder report by default; callers
	 * may pass a different NC Analytics datasource type integer via
	 * `$type`.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param string $name New report name.
	 * @param int|null $type NC Analytics datasource type (default group).
	 *
	 * @return AnalyticsLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Analytics unavailable (503), create failure (500).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkReport(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		string $name,
		?int $type = null,
	): AnalyticsLink {
		$uid = $this->requireUid();

		$name = trim($name);
		if ($name === '') {
			throw new Exception('Report name is required', 400);
		}

		$service = $this->resolveReportService();
		if ($service === null) {
			throw new Exception('NC Analytics is not available', 503);
		}

		$reportType = ($type ?? self::REPORT_TYPE_GROUP);

		try {
			$reportId = (int)$service->create(
				$name,
				'',
				0,
				$reportType,
				0,
				'',
				'',
				'',
				'',
				'',
				''
			);
		} catch (Throwable $e) {
			$this->logger->warning('AnalyticsLinkService::createAndLinkReport failed: ' . $e->getMessage());
			throw new Exception('Failed to create Analytics report', 500);
		}

		if ($reportId === 0) {
			throw new Exception('Failed to create Analytics report', 500);
		}

		$info = $this->fetchReportInfo(reportId: $reportId);

		$link = $this->buildLink(
			objectUuid: $objectUuid,
			registerId: $registerId,
			schemaId: $schemaId,
			reportId: $reportId,
			info: ($info ?? [
				'title' => $name,
				'type' => (string)$reportType,
				'subheader' => null,
				'createdAt' => new DateTime(),
				'modifiedAt' => new DateTime(),
			]),
			uid: $uid,
		);

		return $this->analyticsLinkMapper->insert($link);
	}//end createAndLinkReport()

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
		$service = $this->resolveReportService();
		if ($service === null) {
			return [];
		}

		try {
			$reports = $service->index();
		} catch (Throwable $e) {
			$this->logger->warning('AnalyticsLinkService::getAvailableReports failed: ' . $e->getMessage());
			return [];
		}

		$needle = null;
		if ($search !== null && $search !== '') {
			$needle = mb_strtolower($search);
		}

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
	 * Build an AnalyticsLink entity from cached report info.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $registerId OR register id.
	 * @param int $schemaId OR schema id.
	 * @param int $reportId NC Analytics report id.
	 * @param array<string,mixed> $info Normalised report info.
	 * @param string $uid Owning user id.
	 *
	 * @return AnalyticsLink
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	private function buildLink(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		int $reportId,
		array $info,
		string $uid,
	): AnalyticsLink {
		$link = new AnalyticsLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		$link->setReportId($reportId);
		$link->setReportTitle((string)($info['title'] ?? ''));
		$link->setReportType($info['type']);
		$link->setSubheader($info['subheader']);
		$link->setCreatedAt($info['createdAt']);
		$link->setModifiedAt($info['modifiedAt']);
		$link->setLinkedBy($uid);
		$link->setLinkedAt(new DateTime());

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

		$name = (string)($report['name'] ?? '');
		if ($needle !== null && str_contains(mb_strtolower($name), $needle) === false) {
			return null;
		}

		$type = null;
		$subheader = null;
		if (isset($report['type']) === true) {
			$type = (string)$report['type'];
		}

		if (isset($report['subheader']) === true) {
			$subheader = (string)$report['subheader'];
		}

		return [
			'id' => $reportId,
			'name' => $name,
			'type' => $type,
			'subheader' => $subheader,
			'url' => $this->reportDeepLink(reportId: $reportId),
		];
	}//end pickerRowFromReport()

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

		try {
			return $this->analyticsLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->logger->debug('AnalyticsLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end refreshLink()

	/**
	 * Fetch normalised report metadata from NC Analytics.
	 *
	 * @param int $reportId The report id.
	 *
	 * @return array{title:string,type:?string,subheader:?string,createdAt:?DateTime,modifiedAt:?DateTime}|null
	 */
	private function fetchReportInfo(int $reportId): ?array {
		$service = $this->resolveReportService();
		if ($service === null) {
			return null;
		}

		try {
			$report = $service->read($reportId);
		} catch (Throwable $e) {
			$this->logger->debug('AnalyticsLinkService::fetchReportInfo read failed: ' . $e->getMessage());
			return null;
		}

		if (is_array($report) === false || $report === []) {
			return null;
		}

		$type2 = null;
		$subheader2 = null;
		if (isset($report['type']) === true) {
			$type2 = (string)$report['type'];
		}

		if (isset($report['subheader']) === true) {
			$subheader2 = (string)$report['subheader'];
		}

		return [
			'title' => (string)($report['name'] ?? ''),
			'type' => $type2,
			'subheader' => $subheader2,
			'createdAt' => $this->parseTimestamp(value: ($report['created_at'] ?? null)),
			'modifiedAt' => $this->parseTimestamp(value: ($report['modified_at'] ?? ($report['updated_at'] ?? null))),
		];
	}//end fetchReportInfo()

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
			if (is_numeric($value) === true) {
				return (new DateTime())->setTimestamp((int)$value);
			}

			return new DateTime((string)$value);
		} catch (Throwable $e) {
			return null;
		}
	}//end parseTimestamp()

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
