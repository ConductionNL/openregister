<?php

/**
 * ErasureRunner — runs an approved erasure preview, through the one
 * destruction path the instance already has.
 *
 * D-3 IS THE WHOLE DESIGN. The delete window left OpenRegister with exactly one
 * way to destroy an object: {@see \OCA\OpenRegister\Service\Deletion\DestructionScopeService}
 * takes what the schema declares goes with it, and
 * {@see \OCA\OpenRegister\Service\Deletion\DestructionRecorder} writes the record
 * that outlives it. An erasure that soft-deleted its own way would produce rows
 * nobody can reconcile with the destruction log, so this runner calls that path
 * and the destruction record NAMES the data subject request that caused it.
 *
 * TWO REFUSALS SIT IN FRONT OF EVERY ACT:
 *   - the preview must be approved (the store's rule), and
 *   - the world must still be the world that was approved. The digest is
 *     recomputed here, and a preview approved for twelve objects does not run
 *     against thirteen.
 *
 * And two more sit in front of every OBJECT, because a hold can be placed in
 * the minutes between the approval and the run: the destroy right and the
 * retention clock are asked again, per object, and a refusal moves that object
 * into `refused` without stopping the rest of the request (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ErasurePreview;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\Gdpr\SubjectMatch;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;

/**
 * Runs an approved erasure, destroying through the recorded destruction.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureRunner implements ErasureRunnerInterface {
	/**
	 * The rule a recorded destruction from an AVG erasure runs under.
	 *
	 * @var string
	 */
	public const RULE = 'gdpr-erasure-approved';

	/**
	 * The outcome lists, in the order the run report carries them.
	 *
	 * @var array<int, string>
	 */
	private const OUTCOME_BUCKETS = ['destroyed', 'pseudonymised', 'withheld', 'refused', 'failed'];

	/**
	 * Wire the runner.
	 *
	 * @param ErasurePreviewService     $previewService The preview, recomputed for the digest check.
	 * @param ErasurePreviewStore       $store          The recorded preview and its approval.
	 * @param DataSubjectRequestService $subjects       Discovery and the audited pseudonymise write.
	 * @param ErasureDestroyer          $destroyer      The one recorded destruction path, refusals first.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ErasurePreviewService $previewService,
		private readonly ErasurePreviewStore $store,
		private readonly DataSubjectRequestService $subjects,
		private readonly ErasureDestroyer $destroyer,
	) {
	}//end __construct()

	/**
	 * Run an approved preview.
	 *
	 * @param ErasurePreview $record The approved, unspent preview.
	 *
	 * @return array<string, mixed> What the run did, per bucket.
	 *
	 * @throws ErasureRefusedException When the approved preview no longer describes the world.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function run(ErasurePreview $record): array {
		$run = new ApprovedErasureRun(record: $record);
		$subject = new SubjectQuery(subjectId: $run->subject(), type: $record->getSubjectType());
		$fresh = $this->previewService->preview(subject: $subject, eraseMode: $run->mode());
		$this->refuseIfStale(record: $record, freshDigest: $fresh['digest']);

		$run->hits = $this->hitsByUuid(subject: $subject);
		$run->outcome = $this->emptyOutcome(run: $run);
		foreach ($fresh['items'] as $item) {
			$this->runOne(run: $run, item: $item);
		}

		$outcome = $this->conclude(outcome: $run->outcome);
		$this->store->consume(preview: $record, outcome: $outcome);

		return $outcome;
	}//end run()

	/**
	 * Refuse before any write when the world moved since the approval.
	 *
	 * @param ErasurePreview $record      The approved preview.
	 * @param mixed          $freshDigest The digest of the preview recomputed now.
	 *
	 * @return void
	 *
	 * @throws ErasureRefusedException When the digests differ.
	 */
	private function refuseIfStale(ErasurePreview $record, mixed $freshDigest): void {
		if ($freshDigest === $record->getDigest()) {
			return;
		}

		throw new ErasureRefusedException(
			rule: 'erasure-preview-stale',
			reason: 'The records this subject appears on have changed since the preview was approved, '
				. 'so nothing was erased. Take a new preview and approve that one.',
			context: [
				'approvedDigest' => $record->getDigest(),
				'currentDigest' => $freshDigest,
			]
		);
	}//end refuseIfStale()

	/**
	 * The subject's discovery hits, indexed by object uuid.
	 *
	 * @param SubjectQuery $subject The subject value and optional GdprEntity type filter, exact match.
	 *
	 * @return array<string, array> The hits.
	 */
	private function hitsByUuid(SubjectQuery $subject): array {
		$hits = [];
		foreach ($this->subjects->findSubjectObjects($subject) as $hit) {
			$hits[(string)($hit['object']->getUuid() ?? '')] = $hit;
		}

		return $hits;
	}//end hitsByUuid()

	/**
	 * The run report before any item was acted on.
	 *
	 * @param ApprovedErasureRun $run The run: the preview, its subject and mode.
	 *
	 * @return array<string, mixed> The empty outcome.
	 */
	private function emptyOutcome(ApprovedErasureRun $run): array {
		$outcome = [
			'preview' => $run->record->getUuid(),
			'request' => $run->record->getRequestId(),
			'subject' => $run->subject(),
			'eraseMode' => $run->mode(),
			'ranAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		return array_merge($outcome, array_fill_keys(self::OUTCOME_BUCKETS, []));
	}//end emptyOutcome()

	/**
	 * Stamp completeness and the per-bucket counts on the run report.
	 *
	 * A RUN THAT KEPT DATA BACK IS NOT A COMPLETE ERASURE, on the same terms
	 * DataSubjectRequestService::erase() already answers on: `withheld` is
	 * explained to the subject, `refused` waits for a rule to move, `failed`
	 * is retried. Only an empty three means the request is closed.
	 *
	 * @param array<string, mixed> $outcome The run report.
	 *
	 * @return array<string, mixed> The concluded report.
	 */
	private function conclude(array $outcome): array {
		$outcome['complete'] = ($outcome['withheld'] === []
			&& $outcome['refused'] === []
			&& $outcome['failed'] === []);

		foreach (self::OUTCOME_BUCKETS as $bucket) {
			$outcome[$bucket . 'Count'] = count($outcome[$bucket]);
		}

		return $outcome;
	}//end conclude()

	/**
	 * Act on one preview item.
	 *
	 * @param ApprovedErasureRun   $run  The run: the preview, its hits and the report.
	 * @param array<string, mixed> $item The preview item.
	 *
	 * @return void
	 */
	private function runOne(ApprovedErasureRun $run, array $item): void {
		if ((string)($item['bucket'] ?? '') === ErasureBucket::PROTECTED) {
			$run->outcome['withheld'][] = $item;
			return;
		}

		$uuid = (string)($item['uuid'] ?? '');
		if (($run->hits[$uuid] ?? null) === null) {
			$run->outcome['failed'][] = ['uuid' => $uuid, 'error' => 'The object could not be loaded at run time.'];
			return;
		}

		$this->actOn(run: $run, item: $item);
	}//end runOne()

	/**
	 * Scrub or destroy one loaded, unprotected object, as its bucket says.
	 *
	 * Anything not marked pseudonymised goes through the destruction path, whose
	 * own per-object refusals decide whether it is actually destroyed.
	 *
	 * @param ApprovedErasureRun   $run  The run: the preview, its hits and the report.
	 * @param array<string, mixed> $item The preview item, whose object is loaded.
	 *
	 * @return void
	 */
	private function actOn(ApprovedErasureRun $run, array $item): void {
		if ((string)($item['bucket'] ?? '') === ErasureBucket::PSEUDONYMISED) {
			$this->pseudonymise(run: $run, item: $item);
			return;
		}

		$hit = $run->hits[(string)($item['uuid'] ?? '')];
		[$bucket, $entry] = $this->destroyer->destroy(object: $hit['object'], record: $run->record);
		$run->outcome[$bucket][] = $entry;
	}//end actOn()

	/**
	 * Scrub the subject's values from one object, through the audited write.
	 *
	 * @param ApprovedErasureRun   $run  The run: the preview, its hits and the report.
	 * @param array<string, mixed> $item The preview item, whose object is loaded.
	 *
	 * @return void
	 */
	private function pseudonymise(ApprovedErasureRun $run, array $item): void {
		$uuid = (string)($item['uuid'] ?? '');
		$hit = $run->hits[$uuid];
		$saved = $this->subjects->pseudonymiseObject(
			match: new SubjectMatch(object: $hit['object'], matched: $hit['gdprEntities']),
			subjectId: $run->subject()
		);

		if ($saved === null) {
			$run->outcome['failed'][] = ['uuid' => $uuid, 'error' => 'The scrub could not be persisted.'];
			return;
		}

		$run->outcome['pseudonymised'][] = ['uuid' => $uuid, 'ground' => ($item['ground'] ?? null)];
	}//end pseudonymise()
}//end class
