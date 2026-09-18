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
use OCA\OpenRegister\Db\MagicMapper;
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
class DestructionService {

	/**
	 * Destruction list status constants.
	 */
	public const STATUS_IN_REVIEW = 'in_review';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_AWAITING_SECOND = 'awaiting_second_approval';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_COMPLETED = 'completed';

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
	 * @var MagicMapper
	 */
	private MagicMapper $objectMapper;

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
	 * @param MagicMapper $objectMapper Object entity data mapper.
	 * @param IAppConfig $appConfig App configuration.
	 * @param IJobList $jobList Background job list.
	 * @param IUserSession $userSession User session service.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		MagicMapper $objectMapper,
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
				message: '[DestructionService] No eligible objects, skipping destruction list creation',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		$now = new DateTime();

		$destructionList = [
			'status' => self::STATUS_IN_REVIEW,
			'createdAt' => $now->format('c'),
			'objectCount' => count($eligibleObjects),
			'objects' => $eligibleObjects,
			'approvals' => [],
			'rejections' => [],
		];

		$this->logger->info(
			message: '[DestructionService] Created destruction list',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'objectCount' => count($eligibleObjects),
				'status' => self::STATUS_IN_REVIEW,
			]
		);

		return $destructionList;
	}//end createDestructionList()

	/**
	 * Approve a destruction list (full or partial).
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
		array $destructionList,
		string $action = 'approve_all',
		array $excludedIds = [],
		array $exclusionReasons = [],
		bool $requiresDual = false,
		?string $listUuid = null,
	): array {
		$userId = $this->getCurrentUserId();
		$now = new DateTime();

		// Record the approval. The `userId` key is CANONICAL: it is the shape
		// RetentionController writes, and the shape both readers of the approvals
		// list consume — RetentionService::generateDestructionCertificate() and
		// DestructionExecutionJob both do array_column($approvals, 'userId'). This
		// method previously wrote `approvedBy`, so the destruction certificate's
		// approver list — the legal record of WHO authorised the destruction —
		// came out empty (openregister#393).
		$destructionList['approvals'][] = [
			'userId' => $userId,
			'approvedAt' => $now->format('c'),
			'action' => $action,
		];

		// Handle partial approval: exclude specific objects.
		if ($action === 'approve_partial' && empty($excludedIds) === false) {
			$destructionList = $this->handlePartialApproval(
				destructionList: $destructionList,
				excludedIds: $excludedIds,
				exclusionReasons: $exclusionReasons
			);
		}

		// A recorded retain/transfer is binding; see the method's own docblock for
		// why it is derived here rather than asked of the approver. Runs AFTER
		// handlePartialApproval(), which REPLACES `excludedObjects`.
		$destructionList = $this->withholdDecidedEntries(destructionList: $destructionList);

		// Check if dual approval is required and this is the first approval.
		if ($requiresDual === true && count($destructionList['approvals']) < 2) {
			$destructionList['status'] = self::STATUS_AWAITING_SECOND;

			$this->logger->info(
				message: '[DestructionService] First approval recorded, awaiting second approval',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'approvedBy' => $userId,
				]
			);

			return $destructionList;
		}

		// Check dual approval: second approver must be different from first.
		if ($requiresDual === true && $this->sameArchivistTwice(destructionList: $destructionList) === true) {
			// The second approval is not a second pair of eyes; drop it.
			array_pop($destructionList['approvals']);

			return $destructionList;
		}

		// Mark as approved and queue execution.
		$destructionList['status'] = self::STATUS_APPROVED;

		// Queue the destruction execution job.
		//
		// The job argument key MUST be `destructionListUuid`: DestructionExecutionJob
		// reads $argument['destructionListUuid'] and returns early when it is absent.
		// This call previously passed the whole list under the key `destructionList`,
		// so the job bailed out on every run — objects were never destroyed and no
		// verklaring van vernietiging was ever produced through the /api/archival
		// approve route (openregister#393). The job reloads the list from storage by
		// uuid, which is why the caller must have persisted it first.
		$this->jobList->add(
			\OCA\OpenRegister\BackgroundJob\DestructionExecutionJob::class,
			['destructionListUuid' => ($listUuid ?? $destructionList['uuid'] ?? null)]
		);

		$this->logger->info(
			message: '[DestructionService] Destruction list approved, execution job queued',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'approvedBy' => $userId,
				'action' => $action,
			]
		);

		return $destructionList;
	}//end approveList()

	/**
	 * Handle partial approval by excluding specific objects and extending their dates.
	 *
	 * @param array<string, mixed> $destructionList The destruction list.
	 * @param array<int, string> $excludedIds UUIDs to exclude.
	 * @param array<string, string> $exclusionReasons Reasons per UUID.
	 *
	 * @return array<string, mixed> The updated destruction list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function handlePartialApproval(
		array $destructionList,
		array $excludedIds,
		array $exclusionReasons,
	): array {
		$extensionPeriod = $this->appConfig->getValueString(
			app: 'openregister',
			key: 'destruction_extension_period',
			default: self::DEFAULT_EXTENSION_PERIOD
		);

		$excluded = [];
		$approved = [];

		foreach ($destructionList['objects'] as $objectEntry) {
			$uuid = $objectEntry['uuid'];
			if (in_array($uuid, $excludedIds, true) === true) {
				$objectEntry['status'] = 'uitgezonderd';
				$objectEntry['exclusionReason'] = $exclusionReasons[$uuid] ?? 'Geen reden opgegeven';
				$excluded[] = $objectEntry;

				// Extend the object's archiefactiedatum.
				$this->extendArchiveActionDate(
					uuid: $uuid,
					extensionPeriod: $extensionPeriod,
					reason: $objectEntry['exclusionReason']
				);
				continue;
			}

			$objectEntry['status'] = 'approved';
			$approved[] = $objectEntry;
		}

		$destructionList['objects'] = $approved;
		$destructionList['excludedObjects'] = $excluded;
		$destructionList['objectCount'] = count($approved);

		return $destructionList;
	}//end handlePartialApproval()

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

		$kept = [];
		$withheld = [];
		foreach ($entries as $objectEntry) {
			if (is_array($objectEntry) === false) {
				// Not an entry this method can read. Left exactly where it was,
				// because silently dropping it would be a destruction decision
				// made by a type check.
				$kept[] = $objectEntry;
				continue;
			}

			$decision = ($objectEntry['decision'] ?? null);
			if (in_array($decision, self::WITHHOLDING_DECISIONS, true) === false) {
				$kept[] = $objectEntry;
				continue;
			}

			$objectEntry['status'] = 'uitgezonderd';
			$objectEntry['exclusionReason'] = $this->withholdingReason(
				destructionList: $destructionList,
				objectEntry: $objectEntry,
				decision: (string)$decision
			);
			$withheld[] = $objectEntry;
		}//end foreach

		if (empty($withheld) === true) {
			return $destructionList;
		}

		$existing = ($destructionList['excludedObjects'] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		$destructionList['objects'] = $kept;
		$destructionList['excludedObjects'] = array_merge($existing, $withheld);
		$destructionList['objectCount'] = count($kept);

		$this->logger->info(
			message: '[DestructionService] Entries withheld from destruction by a recorded review decision',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'withheldCount' => count($withheld),
				'remainingCount' => count($kept),
			]
		);

		return $destructionList;
	}//end withholdDecidedEntries()

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
		$second = ($approvals[1]['userId'] ?? null);
		if ($first !== $second) {
			return false;
		}

		$this->logger->warning(
			message: '[DestructionService] Same archivist cannot provide both approvals',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'approver' => $first,
			]
		);

		return true;
	}//end sameArchivistTwice()

	/**
	 * Why one entry was withheld, in the reviewer's own recorded words.
	 *
	 * The entry itself carries only the answer and who gave it; the reason lives
	 * in the list's `decisions` history. The LAST matching decision is used, so a
	 * corrected answer reads as the reason rather than the first draft.
	 *
	 * @param array<string, mixed> $destructionList The destruction list data.
	 * @param array<string, mixed> $objectEntry     The entry being withheld.
	 * @param string               $decision        The recorded answer.
	 *
	 * @return string The exclusion reason.
	 */
	private function withholdingReason(array $destructionList, array $objectEntry, string $decision): string {
		$uuid = (string)($objectEntry['uuid'] ?? '');
		$reviewer = (string)($objectEntry['decidedBy'] ?? '');

		$reason = '';
		$history = ($destructionList['decisions'] ?? []);
		if (is_array($history) === true) {
			foreach ($history as $recorded) {
				if (is_array($recorded) === false
					|| (string)($recorded['entry'] ?? '') !== $uuid
					|| (string)($recorded['answer'] ?? '') !== $decision
				) {
					continue;
				}

				$reason = (string)($recorded['reason'] ?? '');
			}
		}

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
		$now = new DateTime();

		$extensionPeriod = $this->appConfig->getValueString(
			app: 'openregister',
			key: 'destruction_extension_period',
			default: self::DEFAULT_EXTENSION_PERIOD
		);

		$destructionList['status'] = self::STATUS_REJECTED;
		$destructionList['rejections'][] = [
			'rejectedBy' => $userId,
			'rejectedAt' => $now->format('c'),
			'reason' => $reason,
		];

		// Extend archiefactiedatum for all objects on the list.
		foreach ($destructionList['objects'] as $objectEntry) {
			$this->extendArchiveActionDate(
				uuid: $objectEntry['uuid'],
				extensionPeriod: $extensionPeriod,
				reason: 'Destruction list rejected: ' . $reason
			);
		}

		$this->logger->info(
			message: '[DestructionService] Destruction list rejected',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'rejectedBy' => $userId,
				'reason' => $reason,
			]
		);

		return $destructionList;
	}//end rejectList()

	/**
	 * Extend the archiefactiedatum for an object by the configured period.
	 *
	 * @param string $uuid The object UUID.
	 * @param string $extensionPeriod ISO 8601 duration to add.
	 * @param string $reason The reason for extension.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function extendArchiveActionDate(string $uuid, string $extensionPeriod, string $reason): void {
		try {
			$object = $this->objectMapper->find($uuid);
			$retention = $object->getRetention() ?? [];

			$currentDate = $retention['archiefactiedatum'] ?? null;
			if ($currentDate !== null) {
				$date = new DateTime($currentDate);
				$date->add(new DateInterval($extensionPeriod));
				$retention['archiefactiedatum'] = $date->format('Y-m-d');
			}

			// Record in exclusion history.
			$exclusionHistory = $retention['exclusionHistory'] ?? [];
			$exclusionHistory[] = [
				'date' => (new DateTime())->format('c'),
				'reason' => $reason,
				'newArchiefactiedatum' => $retention['archiefactiedatum'] ?? null,
			];
			$retention['exclusionHistory'] = $exclusionHistory;

			$object->setRetention($retention);
			$this->objectMapper->update($object);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[DestructionService] Failed to extend archiefactiedatum',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $uuid,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end extendArchiveActionDate()

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
