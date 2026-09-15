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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs an approved erasure, destroying through the recorded destruction.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The runner exists to call the
 * existing destruction path rather than grow a second one, so it holds the
 * collaborators of that path plus the two that decide what to run over.
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureRunner {
	/**
	 * The rule a recorded destruction from an AVG erasure runs under.
	 *
	 * @var string
	 */
	public const RULE = 'gdpr-erasure-approved';

	/**
	 * Wire the runner.
	 *
	 * @param ErasurePreviewService     $previewService The preview, recomputed for the digest check.
	 * @param ErasurePreviewStore       $store          The recorded preview and its approval.
	 * @param DataSubjectRequestService $subjects       Discovery and the audited pseudonymise write.
	 * @param MagicMapper               $objectMapper   Object loads and the final delete.
	 * @param SchemaMapper              $schemaMapper   Schema resolution for the destruction scope.
	 * @param DestroyRightService       $destroyRight   Whether this caller may destroy.
	 * @param RetentionClockService     $clockService   Whether the clocks allow it.
	 * @param DestructionScopeService   $scopeService   What goes with the object.
	 * @param DestructionRecorder       $recorder       The record that outlives the object.
	 * @param LoggerInterface           $logger         PSR logger.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A Nextcloud DI constructor.
	 * Every parameter is one authority this runner asks rather than re-derives,
	 * and the destruction path alone accounts for four of them. Bundling them
	 * behind a container would hide which authorities an erasure consults.
	 */
	public function __construct(
		private readonly ErasurePreviewService $previewService,
		private readonly ErasurePreviewStore $store,
		private readonly DataSubjectRequestService $subjects,
		private readonly MagicMapper $objectMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly DestroyRightService $destroyRight,
		private readonly RetentionClockService $clockService,
		private readonly DestructionScopeService $scopeService,
		private readonly DestructionRecorder $recorder,
		private readonly LoggerInterface $logger,
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
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function run(ErasurePreview $record): array {
		$subject = (string)($record->getSubject() ?? '');
		$type = $record->getSubjectType();
		$mode = (string)($record->getEraseMode() ?? DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE);

		$fresh = $this->previewService->preview(subjectId: $subject, type: $type, eraseMode: $mode);
		if ($fresh['digest'] !== $record->getDigest()) {
			throw new ErasureRefusedException(
				rule: 'erasure-preview-stale',
				reason: 'The records this subject appears on have changed since the preview was approved, '
					. 'so nothing was erased. Take a new preview and approve that one.',
				statusCode: 409,
				context: [
					'approvedDigest' => $record->getDigest(),
					'currentDigest' => $fresh['digest'],
				]
			);
		}

		$hits = [];
		foreach ($this->subjects->findSubjectObjects($subject, $type, 'exact') as $hit) {
			$hits[(string)($hit['object']->getUuid() ?? '')] = $hit;
		}

		$outcome = [
			'preview' => $record->getUuid(),
			'request' => $record->getRequestId(),
			'subject' => $subject,
			'eraseMode' => $mode,
			'ranAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'destroyed' => [],
			'pseudonymised' => [],
			'withheld' => [],
			'refused' => [],
			'failed' => [],
		];

		foreach ($fresh['items'] as $item) {
			$this->runOne(item: $item, hits: $hits, record: $record, outcome: $outcome);
		}

		// A RUN THAT KEPT DATA BACK IS NOT A COMPLETE ERASURE, on the same terms
		// DataSubjectRequestService::erase() already answers on: `withheld` is
		// explained to the subject, `refused` waits for a rule to move, `failed`
		// is retried. Only an empty three means the request is closed.
		$outcome['complete'] = ($outcome['withheld'] === []
			&& $outcome['refused'] === []
			&& $outcome['failed'] === []);

		foreach (['destroyed', 'pseudonymised', 'withheld', 'refused', 'failed'] as $bucket) {
			$outcome[$bucket . 'Count'] = count($outcome[$bucket]);
		}

		$this->store->consume(preview: $record, outcome: $outcome);

		return $outcome;
	}//end run()

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
		$uuid = (string)($item['uuid'] ?? '');
		$bucket = (string)($item['bucket'] ?? '');

		if ($bucket === ErasureBucket::PROTECTED) {
			$outcome['withheld'][] = $item;
			return;
		}

		$hit = ($hits[$uuid] ?? null);
		if ($hit === null) {
			$outcome['failed'][] = [
				'uuid' => $uuid,
				'error' => 'The object could not be loaded at run time.',
			];
			return;
		}

		if ($bucket === ErasureBucket::PSEUDONYMISED) {
			$saved = $this->subjects->pseudonymiseObject(
				object: $hit['object'],
				subjectId: (string)($record->getSubject() ?? ''),
				matched: $hit['gdprEntities']
			);

			if ($saved === null) {
				$outcome['failed'][] = ['uuid' => $uuid, 'error' => 'The scrub could not be persisted.'];
				return;
			}

			$outcome['pseudonymised'][] = [
				'uuid' => $uuid,
				'ground' => ($item['ground'] ?? null),
			];
			return;
		}

		$this->destroy(object: $hit['object'], record: $record, outcome: $outcome);
	}//end runOne()

	/**
	 * Destroy one object through the recorded destruction.
	 *
	 * @param ObjectEntity         $object  The object to destroy.
	 * @param ErasurePreview       $record  The preview being run.
	 * @param array<string, mixed> $outcome The outcome (mutated by reference).
	 *
	 * @return void
	 */
	private function destroy(ObjectEntity $object, ErasurePreview $record, array &$outcome): void {
		$uuid = (string)($object->getUuid() ?? '');
		$schema = $this->schemaOf(object: $object);

		// THE RIGHT AND THE CLOCKS ARE ASKED AGAIN, PER OBJECT. An approval is
		// not a permission, and a hold placed after the approval has to win.
		$refusal = $this->destroyRight->refusalFor(object: $object, schema: $schema);
		if ($refusal === null) {
			$refusal = $this->clockService->refusalFor(object: $object);
		}

		if ($refusal !== null) {
			$outcome['refused'][] = [
				'uuid' => $uuid,
				'rule' => $refusal->getRule(),
				'message' => $refusal->getMessage(),
			];
			return;
		}

		try {
			$scopeReport = $this->scopeService->destroy(object: $object, schema: $schema);

			// THE DESTRUCTION RECORD NAMES THE REQUEST (D-3). Without this the
			// destruction log and the data subject request are two histories of
			// the same act that nobody can join.
			$this->recorder->record(
				object: $object,
				scope: $scopeReport,
				rule: self::RULE,
				context: [
					'dataSubjectRequest' => $record->getRequestId(),
					'erasurePreview' => $record->getUuid(),
					'dataSubject' => $record->getSubject(),
					'approvedBy' => $record->getApprovedBy(),
					// The recovery window is waived by construction: an approved
					// erasure is a decision to destroy now, and the record says so
					// rather than leaving a reader to infer it.
					'windowWaived' => true,
				]
			);
		} catch (DestructionRefusedException $e) {
			$outcome['refused'][] = [
				'uuid' => $uuid,
				'rule' => $e->getRule(),
				'message' => $e->getMessage(),
			];
			return;
		} catch (Throwable $e) {
			$outcome['failed'][] = ['uuid' => $uuid, 'error' => $e->getMessage()];
			return;
		}//end try

		try {
			$this->objectMapper->delete($object);
		} catch (Throwable $e) {
			// The record is already written, so this leaves an over-recorded
			// destruction rather than an unrecorded one — the same trade the
			// delete window's own destroy endpoint makes.
			$this->logger->error(
				message: '[ErasureRunner] The destruction was recorded but the object could not be deleted',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			$outcome['failed'][] = ['uuid' => $uuid, 'error' => $e->getMessage()];
			return;
		}

		$outcome['destroyed'][] = [
			'uuid' => $uuid,
			'scope' => ($scopeReport['destroyed'] ?? []),
			'total' => ($scopeReport['total'] ?? 0),
		];
	}//end destroy()

	/**
	 * Resolve an object's schema, or null when it does not resolve.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId);
		} catch (Throwable $e) {
			return null;
		}
	}//end schemaOf()
}//end class
