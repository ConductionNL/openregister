<?php

/**
 * OpenRegister Destruction Service
 *
 * Orchestrates the archival destruction workflow: finding eligible objects,
 * creating destruction lists, handling approvals/rejections, executing
 * destruction, and generating destruction certificates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for orchestrating archival destruction workflows.
 *
 * Manages the lifecycle of destruction lists from creation through approval
 * to execution and certificate generation, conforming to Archiefbesluit 1995.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Destruction orchestration requires many service dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex workflow state machine with multiple paths
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Large service covering full destruction lifecycle
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Public API surface for destruction workflow management
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class DestructionService implements DestructionServiceInterface {

	/**
	 * Default extension period when objects are excluded or rejected (1 year).
	 */
	private const DEFAULT_EXTENSION_PERIOD = 'P1Y';

	/**
	 * The review answers that take an entry off a destruction list.
	 *
	 * Taken from DestructionReviewService's own constants, not copied as
	 * literals: the writer of the value and the reader of it must not be able to
	 * drift apart, because the cost of drifting is destroying a record somebody
	 * said to keep. The third answer, `destroy`, is the one that leaves the
	 * entry where it is. Same namespace, so no import is needed.
	 *
	 * @var string[]
	 */
	private const WITHHOLDING_DECISIONS = [
		DestructionReviewService::ANSWER_RETAIN,
		DestructionReviewService::ANSWER_TRANSFER,
	];

	/**
	 * Object entity mapper.
	 *
	 * @var MagicMapperInterface
	 */
	private MagicMapperInterface $objectMapper;

	/**
	 * App configuration.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Background job list.
	 *
	 * @var IJobList
	 */
	private IJobList $jobList;

	/**
	 * User session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $objectMapper Object entity data mapper.
	 * @param IAppConfig $appConfig App configuration.
	 * @param IJobList $jobList Background job list.
	 * @param IUserSession $userSession User session service.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		MagicMapperInterface $objectMapper,
		IAppConfig $appConfig,
		IJobList $jobList,
		IUserSession $userSession,
		LoggerInterface $logger,
	) {
		$this->objectMapper = $objectMapper;
		$this->appConfig = $appConfig;
		$this->jobList = $jobList;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Create a destruction list from eligible objects.
	 *
	 * The destruction list is stored as a register object with status 'in_review'.
	 *
	 * @param array<int, array<string, mixed>> $eligibleObjects Array of eligible object data.
	 *
	 * @return array<string, mixed> The created destruction list data.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function createDestructionList(array $eligibleObjects): array {
		if (empty($eligibleObjects) === true) {
			$this->logger->info(
				message: '[DestructionService] No eligible objects, skipping destruction list creation', context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		$destructionList = $this->newList(eligibleObjects: $eligibleObjects);

		$this->logger->info(
			message: '[DestructionService] Created destruction list',
			context: ['file' => __FILE__, 'line' => __LINE__, 'objectCount' => count($eligibleObjects), 'status' => self::STATUS_IN_REVIEW]
		);

		return $destructionList;
	}//end createDestructionList()

	/**
	 * A fresh destruction list, in review, over these objects.
	 *
	 * @param array<int, array<string, mixed>> $eligibleObjects Array of eligible object data.
	 *
	 * @return array<string, mixed> The destruction list data.
	 */
	private function newList(array $eligibleObjects): array {
		return [
			'status' => self::STATUS_IN_REVIEW,
			'createdAt' => (new DateTime())->format('c'),
			'objectCount' => count($eligibleObjects),
			'objects' => $eligibleObjects,
			'approvals' => [],
			'rejections' => [],
		];
	}//end newList()

	/**
	 * Approve a destruction list (full or partial).
	 *
	 * The approval is recorded under the CANONICAL `userId` key: it is the shape
	 * RetentionController writes, and the shape both readers of the approvals
	 * list consume — RetentionService::generateDestructionCertificate() and
	 * DestructionExecutionJob both do array_column($approvals, 'userId'). This
	 * method previously wrote `approvedBy`, so the destruction certificate's
	 * approver list — the legal record of WHO authorised the destruction —
	 * came out empty (openregister#393).
	 *
	 * A recorded retain/transfer is binding; see withholdDecidedEntries() for
	 * why it is derived here rather than asked of the approver. It runs AFTER
	 * handlePartialApproval(), which REPLACES `objects`.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param string $action The approval action: 'approve_all' or 'approve_partial'.
	 * @param array<int, string> $excludedIds UUIDs of objects to exclude (for partial approval).
	 * @param array<string, string> $exclusionReasons Reasons per excluded object UUID.
	 * @param bool $requiresDual Whether two-step approval is required.
	 * @param string|null $listUuid UUID of the persisted destruction list; passed to the
	 *                              execution job so it can reload the list from storage.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Configuration-driven dual approval toggle
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function approveList(
		array $destructionList, string $action='approve_all', array $excludedIds=[],
		array $exclusionReasons=[], bool $requiresDual=false, ?string $listUuid=null
	): array {
		$userId = $this->getCurrentUserId();
		$destructionList['approvals'][] = ['userId' => $userId, 'approvedAt' => (new DateTime())->format('c'), 'action' => $action];

		if ($action === 'approve_partial' && empty($excludedIds) === false) {
			$exclusion = new PartialExclusion(excludedIds: $excludedIds, reasons: $exclusionReasons, extensionPeriod: $this->extensionPeriod());
			$destructionList = $this->handlePartialApproval(destructionList: $destructionList, exclusion: $exclusion);
		}

		$destructionList = $this->withholdDecidedEntries(destructionList: $destructionList);

		$unfinished = null;
		if ($requiresDual === true) {
			$unfinished = $this->unfinishedDualApproval(destructionList: $destructionList, userId: $userId);
		}

		$approval = new DestructionApproval(userId: $userId, action: $action, listUuid: $listUuid);
		return ($unfinished ?? $this->markApproved(destructionList: $destructionList, approval: $approval));
	}//end approveList()

	/**
	 * A dual sign-off that is not complete yet, or null when it is.
	 *
	 * With one approval the list waits for a second. When the second came from
	 * the same archivist it is not a second pair of eyes, so it is dropped and
	 * the list keeps waiting.
	 *
	 * Only asked when two-step approval is required at all.
	 *
	 * @param array<string, mixed> $destructionList The destruction list, with this approval recorded.
	 * @param string               $userId          The approver.
	 *
	 * @return array<string, mixed>|null The list still awaiting approval, or null when it may be approved.
	 */
	private function unfinishedDualApproval(array $destructionList, string $userId): ?array {
		if (count($destructionList['approvals']) < 2) {
			return $this->awaitingSecondApproval(destructionList: $destructionList, userId: $userId);
		}

		if ($this->sameArchivistTwice(destructionList: $destructionList) === true) {
			array_pop($destructionList['approvals']);
			return $destructionList;
		}

		return null;
	}//end unfinishedDualApproval()

	/**
	 * A list with its first approval recorded, waiting for the second.
	 *
	 * @param array<string, mixed> $destructionList The destruction list.
	 * @param string               $userId          The first approver.
	 *
	 * @return array<string, mixed> The list, awaiting its second approval.
	 */
	private function awaitingSecondApproval(array $destructionList, string $userId): array {
		$destructionList['status'] = self::STATUS_AWAITING_SECOND;

		$this->logger->info(
			message: '[DestructionService] First approval recorded, awaiting second approval',
			context: ['file' => __FILE__, 'line' => __LINE__, 'approvedBy' => $userId]
		);

		return $destructionList;
	}//end awaitingSecondApproval()

	/**
	 * Mark a list approved and queue its execution.
	 *
	 * The job argument key MUST be `destructionListUuid`: DestructionExecutionJob
	 * reads $argument['destructionListUuid'] and returns early when it is absent.
	 * This call previously passed the whole list under the key `destructionList`,
	 * so the job bailed out on every run — objects were never destroyed and no
	 * verklaring van vernietiging was ever produced through the /api/archival
	 * approve route (openregister#393). The job reloads the list from storage by
	 * uuid, which is why the caller must have persisted it first.
	 *
	 * @param array<string, mixed> $destructionList The destruction list.
	 * @param DestructionApproval  $approval        The approver, the action and the persisted list's UUID.
	 *
	 * @return array<string, mixed> The approved list.
	 */
	private function markApproved(array $destructionList, DestructionApproval $approval): array {
		$destructionList['status'] = self::STATUS_APPROVED;

		$this->jobList->add(
			\OCA\OpenRegister\BackgroundJob\DestructionExecutionJob::class,
			['destructionListUuid' => ($approval->listUuid ?? $destructionList['uuid'] ?? null)]
		);

		$this->logger->info(
			message: '[DestructionService] Destruction list approved, execution job queued',
			context: ['file' => __FILE__, 'line' => __LINE__, 'approvedBy' => $approval->userId, 'action' => $approval->action]
		);

		return $destructionList;
	}//end markApproved()

	/**
	 * Handle partial approval by excluding specific objects and extending their dates.
	 *
	 * @param array<string, mixed> $destructionList The destruction list.
	 * @param PartialExclusion     $exclusion       The UUIDs to exclude, their reasons and the extension period.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function handlePartialApproval(array $destructionList, PartialExclusion $exclusion): array {
		$split = ['kept' => [], 'withheld' => []];
		foreach ($destructionList['objects'] as $objectEntry) {
			if (in_array($objectEntry['uuid'], $exclusion->excludedIds, true) === true) {
				$split['withheld'][] = $this->excludeEntry(objectEntry: $objectEntry, exclusion: $exclusion);
				continue;
			}

			$objectEntry['status'] = 'approved';
			$split['kept'][]       = $objectEntry;
		}

		return $this->withExcluded(destructionList: $destructionList, split: $split);
	}//end handlePartialApproval()

	/**
	 * Mark one entry excluded by the approver, and extend its archiefactiedatum.
	 *
	 * @param array<string, mixed> $objectEntry The entry.
	 * @param PartialExclusion     $exclusion   The reasons per UUID and the extension period.
	 *
	 * @return array<string, mixed> The excluded entry.
	 */
	private function excludeEntry(array $objectEntry, PartialExclusion $exclusion): array {
		$uuid = $objectEntry['uuid'];
		$objectEntry['status'] = 'uitgezonderd';
		$objectEntry['exclusionReason'] = $exclusion->reasons[$uuid] ?? 'Geen reden opgegeven';

		$this->extendArchiveActionDate(
			uuid: $uuid,
			extension: new RetentionExtension(period: $exclusion->extensionPeriod, reason: $objectEntry['exclusionReason'])
		);

		return $objectEntry;
	}//end excludeEntry()

	/**
	 * Replace the list's entries with the kept ones, and add the excluded ones to its record.
	 *
	 * MERGED, not assigned. On the second pass of a dual sign-off assigning used
	 * to overwrite, dropping the record of what the FIRST pass withheld - the
	 * retained and transferred entries then vanished from `excludedObjects`
	 * entirely. The records stay safe either way, because they are already out
	 * of `objects`; what was lost was the exclusion RECORD, on a path whose
	 * whole output is a destruction certificate.
	 *
	 * @param array<string, mixed>                                                    $destructionList The destruction list.
	 * @param array{kept: array<int, mixed>, withheld: array<int, array<string, mixed>>} $split           The kept and taken-off entries.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 */
	private function withExcluded(array $destructionList, array $split): array {
		$kept     = $split['kept'];
		$excluded = $split['withheld'];
		$existing = ($destructionList['excludedObjects'] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		$destructionList['objects'] = $kept;
		$destructionList['excludedObjects'] = array_merge($existing, $excluded);
		$destructionList['objectCount'] = count($kept);

		return $destructionList;
	}//end withExcluded()

	/**
	 * Take every entry a reviewer answered `retain` or `transfer` off the list.
	 *
	 * The reviewer's answer is the authority here, not the approver's action: an
	 * entry carrying such a decision must not reach DestructionExecutionJob under
	 * ANY approval action, `approve_all` included.
	 *
	 * A RECORDED "KEEP THIS" IS BINDING, AND IT IS BINDING HERE. A named
	 * reviewer answering `retain` or `transfer` through
	 * {@see DestructionReviewService::recordAnswer()} only ever stamped the
	 * answer onto the entry. Nothing removed the entry and nothing downstream
	 * read the stamp, so `approve_all` — the default — handed the entry to
	 * {@see DestructionExecutionJob} and the record was hard-deleted anyway.
	 * That is irreversible and on a statutory path.
	 *
	 * Derived from the decisions rather than asked of the approver: the approver
	 * is not the reviewer, and an exclusion the approver has to remember to type
	 * is an exclusion that gets forgotten.
	 *
	 * NO DATE IS EXTENDED HERE, deliberately, and this is the one thing that
	 * separates it from {@see self::handlePartialApproval()}. The outcome of a
	 * retention or a transfer was already applied to the RECORD when the answer
	 * was given — {@see ReviewOutcomeService::apply()} writes the reviewer's own
	 * new archiefactiedatum, or puts the record on a transfer list. Adding the
	 * configured extension period on top would overwrite the date the reviewer
	 * chose with a generic one, which is a different defect in the same file.
	 *
	 * Idempotent: an entry already moved to `excludedObjects` is no longer in
	 * `objects`, so a second approval (dual sign-off) finds nothing left to move.
	 * That holds for `objects`. It holds for `excludedObjects` only because
	 * handlePartialApproval() MERGES that key rather than assigning it (see
	 * withExcluded()).
	 *
	 * Public because `RetentionController::approveDestructionList()` is a fully
	 * independent approval implementation that never calls `approveList()`. The
	 * records are safe there either way - DestructionExecutionJob refuses them -
	 * but the route computes its audit trail and its 200 response BEFORE the job
	 * runs, and never corrects them. Without this the approval record and the
	 * destruction certificate state a count that never happened, on a statutory
	 * records-management path. Routing that controller through `approveList()`
	 * is the clean fix and remains the right one.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 *
	 * @return array<string, mixed> The list, with decided-against entries withheld.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function withholdDecidedEntries(array $destructionList): array {
		$entries = ($destructionList['objects'] ?? []);
		if (is_array($entries) === false) {
			return $destructionList;
		}

		$split = $this->splitByDecision(destructionList: $destructionList, entries: $entries);
		if (empty($split['withheld']) === true) {
			return $destructionList;
		}

		$this->logger->info(
			message: '[DestructionService] Entries withheld from destruction by a recorded review decision',
			context: ['file' => __FILE__, 'line' => __LINE__, 'withheldCount' => count($split['withheld']), 'remainingCount' => count($split['kept'])]
		);

		return $this->withExcluded(destructionList: $destructionList, split: $split);
	}//end withholdDecidedEntries()

	/**
	 * Split a list's entries into those that stay and those a recorded decision withholds.
	 *
	 * An entry this method cannot read is left exactly where it was, because
	 * silently dropping it would be a destruction decision made by a type check.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param array<mixed>         $entries         Its entries.
	 *
	 * @return array{kept: array<int, mixed>, withheld: array<int, array<string, mixed>>} The two groups, in list order.
	 */
	private function splitByDecision(array $destructionList, array $entries): array {
		$split = ['kept' => [], 'withheld' => []];
		foreach ($entries as $objectEntry) {
			$decision = $this->decisionOf(objectEntry: $objectEntry);
			if (in_array($decision, self::WITHHOLDING_DECISIONS, true) === false) {
				$split['kept'][] = $objectEntry;
				continue;
			}

			$split['withheld'][] = $this->withheldEntry(destructionList: $destructionList, objectEntry: $objectEntry);
		}

		return $split;
	}//end splitByDecision()

	/**
	 * Mark one entry withheld by a recorded review decision.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param array<string, mixed> $objectEntry     The entry being withheld, carrying its recorded answer.
	 *
	 * @return array<string, mixed> The withheld entry.
	 */
	private function withheldEntry(array $destructionList, array $objectEntry): array {
		$objectEntry['status'] = 'uitgezonderd';
		$objectEntry['exclusionReason'] = $this->withholdingReason(destructionList: $destructionList, objectEntry: $objectEntry);

		return $objectEntry;
	}//end withheldEntry()

	/**
	 * The review answer recorded on an entry, or null for an entry that is not an array.
	 *
	 * @param mixed $objectEntry The entry.
	 *
	 * @return mixed The recorded answer.
	 */
	private function decisionOf(mixed $objectEntry): mixed {
		if (is_array($objectEntry) === false) {
			return null;
		}

		return ($objectEntry['decision'] ?? null);
	}//end decisionOf()

	/**
	 * Whether both approvals on a list came from the same archivist.
	 *
	 * Dual sign-off exists to put a second pair of eyes on an irreversible
	 * action, which one person approving twice does not provide.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 *
	 * @return bool True when the first two approvals share an approver.
	 */
	private function sameArchivistTwice(array $destructionList): bool {
		$approvals = ($destructionList['approvals'] ?? []);
		if (count($approvals) < 2) {
			return false;
		}

		$first = ($approvals[0]['userId'] ?? null);
		if ($first !== ($approvals[1]['userId'] ?? null)) {
			return false;
		}

		$this->logger->warning(
			message: '[DestructionService] Same archivist cannot provide both approvals',
			context: ['file' => __FILE__, 'line' => __LINE__, 'approver' => $first]
		);

		return true;
	}//end sameArchivistTwice()

	/**
	 * Why one entry was withheld, in the reviewer's own recorded words.
	 *
	 * The entry itself carries only the answer and who gave it; the reason lives
	 * in the list's `decisions` history.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param array<string, mixed> $objectEntry     The entry being withheld, carrying its recorded answer.
	 *
	 * @return string The exclusion reason.
	 */
	private function withholdingReason(array $destructionList, array $objectEntry): string {
		$decision = (string)($objectEntry['decision'] ?? '');
		$reviewer = (string)($objectEntry['decidedBy'] ?? '');
		$reason = $this->recordedReason(history: ($destructionList['decisions'] ?? []), objectEntry: $objectEntry);

		$sentence = sprintf('Review decision "%s"', $decision);
		if ($reviewer !== '') {
			$sentence .= sprintf(' by %s', $reviewer);
		}

		if ($reason !== '') {
			$sentence .= sprintf(': %s', $reason);
		}

		return $sentence;
	}//end withholdingReason()

	/**
	 * The reason recorded with the LAST matching decision, so a corrected answer
	 * reads as the reason rather than the first draft.
	 *
	 * @param mixed                $history     The list's `decisions` history.
	 * @param array<string, mixed> $objectEntry The entry, carrying its uuid and recorded answer.
	 *
	 * @return string The reason, empty when none was recorded.
	 */
	private function recordedReason(mixed $history, array $objectEntry): string {
		if (is_array($history) === false) {
			return '';
		}

		$reason = '';
		foreach ($history as $recorded) {
			if ($this->isDecisionFor(recorded: $recorded, objectEntry: $objectEntry) === true) {
				$reason = (string)($recorded['reason'] ?? '');
			}
		}

		return $reason;
	}//end recordedReason()

	/**
	 * Whether one history row records this answer for this entry.
	 *
	 * @param mixed                $recorded    The history row.
	 * @param array<string, mixed> $objectEntry The entry, carrying its uuid and recorded answer.
	 *
	 * @return bool True when it does.
	 */
	private function isDecisionFor(mixed $recorded, array $objectEntry): bool {
		return is_array($recorded) === true
			&& (string)($recorded['entry'] ?? '') === (string)($objectEntry['uuid'] ?? '')
			&& (string)($recorded['answer'] ?? '') === (string)($objectEntry['decision'] ?? '');
	}//end isDecisionFor()

	/**
	 * Reject an entire destruction list.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param string $reason The reason for rejection.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function rejectList(array $destructionList, string $reason): array {
		$userId = $this->getCurrentUserId();
		$extensionPeriod = $this->extensionPeriod();

		$destructionList['status'] = self::STATUS_REJECTED;
		$destructionList['rejections'][] = ['rejectedBy' => $userId, 'rejectedAt' => (new DateTime())->format('c'), 'reason' => $reason];

		// Extend archiefactiedatum for all objects on the list.
		$extension = new RetentionExtension(period: $extensionPeriod, reason: 'Destruction list rejected: ' . $reason);
		foreach ($destructionList['objects'] as $objectEntry) {
			$this->extendArchiveActionDate(uuid: $objectEntry['uuid'], extension: $extension);
		}

		$this->logger->info(
			message: '[DestructionService] Destruction list rejected',
			context: ['file' => __FILE__, 'line' => __LINE__, 'rejectedBy' => $userId, 'reason' => $reason]
		);

		return $destructionList;
	}//end rejectList()

	/**
	 * The configured period an excluded or rejected object's date moves by.
	 *
	 * @return string ISO 8601 duration.
	 */
	private function extensionPeriod(): string {
		return $this->appConfig->getValueString(
			app: 'openregister',
			key: 'destruction_extension_period',
			default: self::DEFAULT_EXTENSION_PERIOD
		);
	}//end extensionPeriod()

	/**
	 * Extend the archiefactiedatum for an object by the configured period.
	 *
	 * @param string             $uuid      The object UUID.
	 * @param RetentionExtension $extension The ISO 8601 duration to add and the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function extendArchiveActionDate(string $uuid, RetentionExtension $extension): void {
		try {
			$object = $this->objectMapper->find($uuid);
			$object->setRetention($this->extendedRetention(retention: ($object->getRetention() ?? []), extension: $extension));
			$this->objectMapper->update($object);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[DestructionService] Failed to extend archiefactiedatum',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
		}
	}//end extendArchiveActionDate()

	/**
	 * A retention block with its archiefactiedatum moved and the move recorded.
	 *
	 * @param array<string, mixed> $retention The object's retention block.
	 * @param RetentionExtension   $extension The ISO 8601 duration to add and the reason.
	 *
	 * @return array<string, mixed> The extended retention block.
	 *
	 * @throws \Exception When the stored date or the period cannot be read.
	 */
	private function extendedRetention(array $retention, RetentionExtension $extension): array {
		$currentDate = $retention['archiefactiedatum'] ?? null;
		if ($currentDate !== null) {
			$date = new DateTime($currentDate);
			$date->add(new DateInterval($extension->period));
			$retention['archiefactiedatum'] = $date->format('Y-m-d');
		}

		// Record in exclusion history.
		$exclusionHistory = $retention['exclusionHistory'] ?? [];
		$exclusionHistory[] = [
			'date' => (new DateTime())->format('c'), 'reason' => $extension->reason, 'newArchiefactiedatum' => ($retention['archiefactiedatum'] ?? null),
		];
		$retention['exclusionHistory'] = $exclusionHistory;

		return $retention;
	}//end extendedRetention()

	/**
	 * Get the current authenticated user ID.
	 *
	 * @return string The user ID or 'system' if no user is authenticated.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function getCurrentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end getCurrentUserId()
}//end class
