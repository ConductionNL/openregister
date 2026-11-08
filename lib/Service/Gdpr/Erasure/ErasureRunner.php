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

/**
 * Runs an approved erasure, destroying through the recorded destruction.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureRunner {
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
		$subject = (string)($record->getSubject() ?? '');
		$type = $record->getSubjectType();
		$mode = (string)($record->getEraseMode() ?? DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

		$fresh = $this->previewService->preview(subjectId: $subject, type: $type, eraseMode: $mode);
		$this->refuseIfStale(record: $record, freshDigest: $fresh['digest']);

		$hits = $this->hitsByUuid(subject: $subject, type: $type);
		$outcome = $this->emptyOutcome(record: $record, subject: $subject, mode: $mode);
		foreach ($fresh['items'] as $item) {
			$this->runOne(item: $item, hits: $hits, record: $record, outcome: $outcome);
		}

		$outcome = $this->conclude(outcome: $outcome);
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
			statusCode: 409,
			context: [
				'approvedDigest' => $record->getDigest(),
				'currentDigest' => $freshDigest,
			]
		);
	}//end refuseIfStale()

	/**
	 * The subject's discovery hits, indexed by object uuid.
	 *
	 * @param string      $subject The subject value.
	 * @param string|null $type    Optional GdprEntity type filter.
	 *
	 * @return array<string, array> The hits.
	 */
	private function hitsByUuid(string $subject, ?string $type): array {
		$hits = [];
		foreach ($this->subjects->findSubjectObjects($subject, $type, 'exact') as $hit) {
			$hits[(string)($hit['object']->getUuid() ?? '')] = $hit;
		}

		return $hits;
	}//end hitsByUuid()

	/**
	 * The run report before any item was acted on.
	 *
	 * @param ErasurePreview $record  The preview being run.
	 * @param string         $subject The subject value.
	 * @param string         $mode    The erase mode.
	 *
	 * @return array<string, mixed> The empty outcome.
	 */
	private function emptyOutcome(ErasurePreview $record, string $subject, string $mode): array {
		$outcome = [
			'preview' => $record->getUuid(),
			'request' => $record->getRequestId(),
			'subject' => $subject,
			'eraseMode' => $mode,
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
	 * @param array<string, mixed> $item    The preview item.
	 * @param array<string, array> $hits    Discovery hits indexed by uuid.
	 * @param ErasurePreview       $record  The preview being run.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference).
	 *
	 * @return void
	 */
	private function runOne(array $item, array $hits, ErasurePreview $record, array &$outcome): void {
		if ((string)($item['bucket'] ?? '') === ErasureBucket::PROTECTED) {
			$outcome['withheld'][] = $item;
			return;
		}

		$uuid = (string)($item['uuid'] ?? '');
		$hit = ($hits[$uuid] ?? null);
		if ($hit === null) {
			$outcome['failed'][] = ['uuid' => $uuid, 'error' => 'The object could not be loaded at run time.'];
			return;
		}

		$this->actOn(item: $item, hit: $hit, record: $record, outcome: $outcome);
	}//end runOne()

	/**
	 * Scrub or destroy one loaded, unprotected object, as its bucket says.
	 *
	 * Anything not marked pseudonymised goes through the destruction path, whose
	 * own per-object refusals decide whether it is actually destroyed.
	 *
	 * @param array<string, mixed> $item    The preview item.
	 * @param array<string, mixed> $hit     The discovery hit for its object.
	 * @param ErasurePreview       $record  The preview being run.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference).
	 *
	 * @return void
	 */
	private function actOn(array $item, array $hit, ErasurePreview $record, array &$outcome): void {
		if ((string)($item['bucket'] ?? '') === ErasureBucket::PSEUDONYMISED) {
			$this->pseudonymise(item: $item, hit: $hit, record: $record, outcome: $outcome);
			return;
		}

		$this->destroyer->destroy(object: $hit['object'], record: $record, outcome: $outcome);
	}//end actOn()

	/**
	 * Scrub the subject's values from one object, through the audited write.
	 *
	 * @param array<string, mixed> $item    The preview item.
	 * @param array<string, mixed> $hit     The discovery hit for its object.
	 * @param ErasurePreview       $record  The preview being run.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference).
	 *
	 * @return void
	 */
	private function pseudonymise(array $item, array $hit, ErasurePreview $record, array &$outcome): void {
		$uuid = (string)($item['uuid'] ?? '');
		$saved = $this->subjects->pseudonymiseObject(
			object: $hit['object'],
			subjectId: (string)($record->getSubject() ?? ''),
			matched: $hit['gdprEntities']
		);

		if ($saved === null) {
			$outcome['failed'][] = ['uuid' => $uuid, 'error' => 'The scrub could not be persisted.'];
			return;
		}

		$outcome['pseudonymised'][] = ['uuid' => $uuid, 'ground' => ($item['ground'] ?? null)];
	}//end pseudonymise()
}//end class
