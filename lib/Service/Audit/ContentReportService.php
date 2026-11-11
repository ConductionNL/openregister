<?php

/**
 * Filing a report takes the copy, and a removal names it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTime;
use OCA\OpenRegister\Db\ContentReport;
use OCA\OpenRegister\Db\ContentReportMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The copy is taken at filing time, read by reviewers only, and outlives the
 * content it describes.
 *
 * Three rules, and each one is the answer to a way this goes wrong:
 *
 * - **Copy at filing, never at removal (D-5).** A copy made when the delete
 *   runs races the delete, and the content that gets removed fastest is
 *   usually the content somebody most wanted the evidence of.
 * - **Reviewers only.** The copy is a frozen piece of content that was
 *   reported, which is to say it is the material somebody complained about. A
 *   list endpoint that served it would republish it to everybody.
 * - **Its own retention.** The copy is kept longer than the content, because
 *   the requirement is precisely that removing the content does not destroy
 *   the evidence. A copy inheriting the object's retention would be deleted
 *   by the same sweep that deletes what it was evidence of.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
class ContentReportService implements ContentReportServiceInterface {
	/**
	 * The app the configuration lives under.
	 *
	 * @var string
	 */
	private const APP = 'openregister';

	/**
	 * The group whose members may read a copy.
	 *
	 * @var string
	 */
	public const CONFIG_REVIEWER_GROUP = 'content_report_reviewer_group';

	/**
	 * How long a copy is kept, in days.
	 *
	 * @var string
	 */
	public const CONFIG_RETENTION_DAYS = 'content_report_retention_days';

	/**
	 * The group an instance that configures none falls back to.
	 *
	 * Deliberately NOT `admin`. A moderation reviewer and an instance
	 * administrator are different jobs, and defaulting to admin would make
	 * every administrator a reviewer of reported content on an instance that
	 * never asked for that.
	 *
	 * @var string
	 */
	public const DEFAULT_REVIEWER_GROUP = 'content-reviewers';

	/**
	 * The default retention for a copy, in days.
	 *
	 * Three years. Long enough to outlive the content and any complaint
	 * procedure about it, short enough that it is a retention rather than a
	 * permanent second archive of material somebody objected to.
	 *
	 * @var integer
	 */
	public const DEFAULT_RETENTION_DAYS = 1095;

	/**
	 * Constructor.
	 *
	 * @param ContentReportMapperInterface $reports      The reports and their copies.
	 * @param IAppConfig          $appConfig    Reviewer group and retention.
	 * @param IGroupManager       $groupManager Resolves reviewer membership.
	 * @param LoggerInterface     $logger       Reports a bookkeeping failure.
	 */
	public function __construct(
		private readonly ContentReportMapperInterface $reports,
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * File a report, taking the copy now.
	 *
	 * @param ObjectEntity $object   The content being reported.
	 * @param string       $reason   Why, in the reporter's words.
	 * @param string|null  $reporter The uid of whoever filed it.
	 *
	 * @return ContentReport The persisted report, with the copy already taken.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ContentReport::hashCopy is the entity's own checksum, kept static
	 *   so the copy and its later integrity check hash exactly the same way.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function file(ObjectEntity $object, string $reason, ?string $reporter): ContentReport {
		return $this->fileReport(filing: new ContentReportFiling(object: $object, reason: $reason, reporter: $reporter));
	}//end file()

	/**
	 * File one report: take the copy, pin the reviewer group and the retention, and persist it.
	 *
	 * @param ContentReportFiling $filing The object, the reason and the reporter.
	 *
	 * @return ContentReport The persisted report, with the copy already taken.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function fileReport(ContentReportFiling $filing): ContentReport {
		$copy = $this->snapshot(object: $filing->object);

		$report = new ContentReport();
		$report->setObjectUuid($filing->object->getUuid());
		$report->setRegister((string)$filing->object->getRegister());
		$report->setSchema((string)$filing->object->getSchema());
		$report->setReason($filing->reason);
		$report->setReportedBy($filing->reporter);
		$report->setStatus(ContentReport::STATUS_OPEN);
		$report->setCopy($copy);
		$report->setCopyHash(ContentReport::hashCopy(copy: $copy));
		$report->setOrganisationId($filing->object->getOrganisation());

		// Pinned onto the row rather than read at review time. An administrator
		// widening the configured group later must not retroactively widen who
		// may read copies already taken.
		$report->setReviewerGroup($this->reviewerGroup());

		$days = $this->retentionDays();
		$report->setRetentionPeriod('content-report:' . $days . 'd');
		$report->setExpires((new DateTime())->modify('+' . $days . ' days'));

		return $this->reports->insert($report);
	}//end fileReport()

	/**
	 * The frozen content, as it read when the report was filed.
	 *
	 * The object's own fields and the handful of identifiers a reviewer needs
	 * to know what they are looking at. Not the whole entity: files, locks and
	 * authorisation are about the record's plumbing rather than about what it
	 * said, and a copy is evidence of what it said.
	 *
	 * @param ObjectEntity $object The content being reported.
	 *
	 * @return array<string, mixed> The snapshot.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function snapshot(ObjectEntity $object): array {
		return [
			'uuid' => $object->getUuid(),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
			'version' => $object->getVersion(),
			'name' => $object->getName(),
			'owner' => $object->getOwner(),
			'object' => ($object->getObject() ?? []),
			'takenAt' => (new DateTime())->format('c'),
		];
	}//end snapshot()

	/**
	 * Whether a user may read the copies on a report.
	 *
	 * @param ContentReport $report The report.
	 * @param IUser|null    $user   The caller.
	 *
	 * @return bool True when the caller is in the report's reviewer group.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function mayReadCopy(ContentReport $report, ?IUser $user): bool {
		if ($user === null) {
			return false;
		}

		$group = $this->reviewerGroupOf(report: $report);

		try {
			$groups = $this->groupManager->getUserGroupIds($user);
		} catch (Throwable $lookupFailed) {
			// FAIL CLOSED. A group lookup that cannot answer is not a licence to
			// read reported content; the whole point of the copy is that it is
			// narrower than the instance.
			return false;
		}

		return in_array(needle: $group, haystack: $groups, strict: true);
	}//end mayReadCopy()

	/**
	 * The group whose members may read a report's copy: the one fixed on the
	 * report, or the configured reviewer group when the report names none.
	 *
	 * @param ContentReport $report The report.
	 *
	 * @return string The group id.
	 */
	private function reviewerGroupOf(ContentReport $report): string {
		$group = $report->getReviewerGroup();
		if ($group === null || $group === '') {
			return $this->reviewerGroup();
		}

		return $group;
	}//end reviewerGroupOf()

	/**
	 * Read the copy, when the caller is a reviewer.
	 *
	 * @param ContentReport $report The report.
	 * @param IUser|null    $user   The caller.
	 *
	 * @return array<string, mixed>|null The copy, or null when the caller may not read it.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function readCopy(ContentReport $report, ?IUser $user): ?array {
		if ($this->mayReadCopy(report: $report, user: $user) === false) {
			return null;
		}

		return ($report->getCopy() ?? []);
	}//end readCopy()

	/**
	 * Record that the reported content has been removed, on every open report.
	 *
	 * The removal and the copy name each other from both ends: the report gains
	 * the removal's audit uuid, and the caller is handed the copies so the
	 * removal record can name them. A removal that leaves the report saying
	 * nothing is the state where a reviewer opens a report, finds the content
	 * gone, and cannot tell whether the copy is still the right one.
	 *
	 * @param string      $objectUuid  The removed object's uuid.
	 * @param string|null $auditUuid   The uuid of the audit entry recording the removal.
	 *
	 * @return string[] The uuids of the copies the removal should name.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function noteRemoval(string $objectUuid, ?string $auditUuid = null): array {
		if ($objectUuid === '') {
			return [];
		}

		$reports = $this->reportsOf(objectUuid: $objectUuid);

		return $this->noteRemovalOn(reports: $reports, removal: new ReportRemoval(at: new DateTime(), auditUuid: $auditUuid));
	}//end noteRemoval()

	/**
	 * The reports on a removed object; none, with a warning, when the lookup fails.
	 *
	 * @param string $objectUuid The removed object's uuid.
	 *
	 * @return ContentReport[] The reports.
	 */
	private function reportsOf(string $objectUuid): array {
		try {
			return $this->reports->findByObjectUuid(objectUuid: $objectUuid);
		} catch (Throwable $lookupFailed) {
			$this->logger->warning(
				message: '[ContentReportService] Could not look up reports for a removed object: '
					. $lookupFailed->getMessage(),
				context: ['app' => 'openregister', 'objectUuid' => $objectUuid]
			);

			return [];
		}
	}//end reportsOf()

	/**
	 * Note a removal on each report, and name the copies it was noted on.
	 *
	 * @param ContentReport[] $reports The reports on the removed object.
	 * @param ReportRemoval   $removal The instant and the audit entry of the removal.
	 *
	 * @return string[] The uuids of the copies the removal should name.
	 */
	private function noteRemovalOn(array $reports, ReportRemoval $removal): array {
		$named = [];
		foreach ($reports as $report) {
			if ($this->isNotedOn(report: $report, removal: $removal) === true) {
				$named[] = (string)$report->getUuid();
			}
		}

		return $named;
	}//end noteRemovalOn()

	/**
	 * Whether a report carries the removal: it already did, or the note was written now.
	 *
	 * @param ContentReport $report  The report.
	 * @param ReportRemoval $removal The instant and the audit entry of the removal.
	 *
	 * @return bool False when writing the note failed.
	 */
	private function isNotedOn(ContentReport $report, ReportRemoval $removal): bool {
		if ($report->isRemoved() === true) {
			// Already recorded. A second delete of the same uuid must not
			// move the instant the first one established.
			return true;
		}

		return $this->recordRemoval(report: $report, removal: $removal);
	}//end isNotedOn()

	/**
	 * Write the removal onto an open report.
	 *
	 * @param ContentReport $report  The open report.
	 * @param ReportRemoval $removal The instant and the audit entry of the removal.
	 *
	 * @return bool True when the note was written.
	 */
	private function recordRemoval(ContentReport $report, ReportRemoval $removal): bool {
		$report->setRemovedAt($removal->at);
		$report->setRemovalAudit($removal->auditUuid);

		try {
			$this->reports->update($report);
		} catch (Throwable $writeFailed) {
			// FAIL-SOFT IN ONE DIRECTION ONLY. The copy itself is already
			// safe; what failed is the note beside it. Stopping the delete
			// here would let a failed bookkeeping write block a removal
			// somebody may be legally required to make.
			$this->logger->warning(
				message: '[ContentReportService] Could not note a removal on a report: '
					. $writeFailed->getMessage(),
				context: ['app' => 'openregister', 'report' => $report->getUuid()]
			);
			return false;
		}

		return true;
	}//end recordRemoval()

	/**
	 * The configured reviewer group.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function reviewerGroup(): string {
		try {
			$group = trim(
				$this->appConfig->getValueString(self::APP, self::CONFIG_REVIEWER_GROUP, '')
			);
		} catch (Throwable $configUnavailable) {
			return self::DEFAULT_REVIEWER_GROUP;
		}

		if ($group === '') {
			return self::DEFAULT_REVIEWER_GROUP;
		}

		return $group;
	}//end reviewerGroup()

	/**
	 * How long a copy is kept, in days.
	 *
	 * A configured zero or a negative is refused rather than honoured: it would
	 * mean "expire every copy on the next sweep", which is the one outcome this
	 * whole requirement exists to prevent, and is never what a mistyped field
	 * is asking for.
	 *
	 * @return int The retention in days.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function retentionDays(): int {
		try {
			$days = $this->appConfig->getValueInt(
				self::APP,
				self::CONFIG_RETENTION_DAYS,
				self::DEFAULT_RETENTION_DAYS
			);
		} catch (Throwable $configUnavailable) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		if ($days <= 0) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}//end retentionDays()
}//end class
