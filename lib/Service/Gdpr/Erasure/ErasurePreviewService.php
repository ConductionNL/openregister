<?php

/**
 * ErasurePreviewService — what an erasure would touch, before it touches it.
 *
 * OpenRegister could already erase a data subject across every register: the
 * discovery, the mode parameter, the legal hold and the archival refusal all
 * live in {@see \OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService}. What
 * it could not do is ANSWER FIRST. `erase(dryRun: true)` reports one list of
 * things it would have erased, which is the half a gemeente does not need: the
 * lawful answer to a data subject under the Archiefwet is "deels niet, en dit
 * is waarom", and that sentence needs the protected count and its ground.
 *
 * So this service classifies every hit into one of three buckets and counts
 * four kinds per bucket, and it writes nothing at all while it does so.
 *
 * WHAT IT REUSES RATHER THAN REBUILDS:
 *   - the cross-register discovery, through DataSubjectRequestService::findSubjectObjects();
 *   - the retention refusal, through ArchivalRetentionGuard::erasureRefusal(),
 *     which already names the legal hold, the Archiefwet obligation and the
 *     unresolvable schema with a sentence to pass back to the requester;
 *   - the immutable archival status, through RetentionService::validateNotImmutable();
 *   - the per-object destruction scope, through DestructionScopeService::preview(),
 *     which is exactly "what goes with the object" and therefore exactly what
 *     an erasable object's files and timeline rows cost.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCA\OpenRegister\Service\RetentionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts what an erasure would touch, split three ways, writing nothing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The preview's whole job is to
 * ask every existing authority its own question rather than re-derive any of
 * them, so it holds one collaborator per authority.
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class ErasurePreviewService {
	/**
	 * Wire the authorities the preview asks.
	 *
	 * @param DataSubjectRequestService $subjects       Cross-register discovery.
	 * @param ArchivalRetentionGuard    $archivalGuard  Retention and legal-hold refusal, with its wording.
	 * @param RetentionService          $retention      Immutable archival status.
	 * @param DestructionScopeService   $scopeService   What goes with an object.
	 * @param SchemaMapper              $schemaMapper   Schema resolution for the scope.
	 * @param SubjectPartyCounter       $parties        Party records naming the subject.
	 * @param IDBConnection             $db             The PII index, for other subjects on a record.
	 * @param LoggerInterface           $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DataSubjectRequestService $subjects,
		private readonly ArchivalRetentionGuard $archivalGuard,
		private readonly RetentionService $retention,
		private readonly DestructionScopeService $scopeService,
		private readonly SchemaMapper $schemaMapper,
		private readonly SubjectPartyCounter $parties,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Preview an erasure: the counts, the named protected records, a digest.
	 *
	 * Writes nothing. Every collaborator it calls is a read, and the two that
	 * could refuse in a way the caller cannot see — the archival guard and the
	 * retention service — are called inside a catch that counts an unanswerable
	 * question as PROTECTED (D-2).
	 *
	 * @param string      $subjectId Subject identifier value (email, bsn, …).
	 * @param string|null $type      Optional GdprEntity type filter.
	 * @param string      $eraseMode `pseudonymise` or `whole-object`.
	 *
	 * @return array<string, mixed> The preview: counts, items, protected, digest.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ErasureBucket is a closed vocabulary,
	 * the same shape as DestructionScope. Its members are compile-time constants
	 * and emptyCounts() derives the zeroed block from them, so injecting it would
	 * add a collaborator that can never vary.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function preview(
		string $subjectId,
		?string $type = null,
		string $eraseMode = DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE,
	): array {
		$subjectId = trim($subjectId);
		$eraseMode = $this->normaliseMode(eraseMode: $eraseMode);

		$counts = ErasureBucket::emptyCounts();
		$items = [];
		$protected = [];

		foreach ($this->subjects->findSubjectObjects($subjectId, $type, 'exact') as $hit) {
			$object = $hit['object'];
			$item = $this->classify(
				object: $object,
				subjectId: $subjectId,
				matched: $hit['gdprEntities'],
				eraseMode: $eraseMode
			);

			foreach ($item['counts'] as $kind => $value) {
				$counts[$item['bucket']][$kind] += $value;
			}

			$items[] = $item;
			if ($item['bucket'] === ErasureBucket::PROTECTED) {
				$protected[] = $item;
			}
		}

		$preview = [
			'subject' => $subjectId,
			'type' => $type,
			'eraseMode' => $eraseMode,
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'matchedCount' => count($items),
			'counts' => $counts,
			'items' => $items,
			// D-1: the protected records are listed again, by name, because the
			// count alone does not let a handler write the sentence the data
			// subject is owed.
			'protected' => $protected,
		];

		$preview['digest'] = $this->digest(preview: $preview);

		return $preview;
	}//end preview()

	/**
	 * The digest of a preview: what was approved, so a stale approval is seen.
	 *
	 * Covers the request (subject, type, mode) and the classified outcome of
	 * every object. It deliberately does NOT cover `generatedAt`, so previewing
	 * the same unchanged world twice agrees.
	 *
	 * @param array<string, mixed> $preview The preview to digest.
	 *
	 * @return string A hex sha256.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function digest(array $preview): string {
		$material = [
			'subject' => ($preview['subject'] ?? ''),
			'type' => ($preview['type'] ?? null),
			'eraseMode' => ($preview['eraseMode'] ?? ''),
			'items' => [],
		];

		foreach (($preview['items'] ?? []) as $item) {
			$material['items'][] = [
				'uuid' => ($item['uuid'] ?? ''),
				'bucket' => ($item['bucket'] ?? ''),
				'ground' => ($item['ground'] ?? null),
				'counts' => ($item['counts'] ?? []),
			];
		}

		usort(
			$material['items'],
			static function (array $left, array $right): int {
				return strcmp((string)$left['uuid'], (string)$right['uuid']);
			}
		);

		return hash('sha256', (string)json_encode($material));
	}//end digest()

	/**
	 * Classify one object and count what an erasure would cost on it.
	 *
	 * @param ObjectEntity      $object    The object.
	 * @param string            $subjectId The subject value.
	 * @param array<int, array> $matched   The PII hits that pulled it in.
	 * @param string            $eraseMode The requested mode.
	 *
	 * @return array<string, mixed> One preview item.
	 */
	private function classify(ObjectEntity $object, string $subjectId, array $matched, string $eraseMode): array {
		$item = [
			'uuid' => (string)($object->getUuid() ?? ''),
			'register' => $object->getRegister(),
			'schema' => $object->getSchema(),
			'matchedOn' => array_values(array_unique(array_filter(array_column($matched, 'type')))),
		];

		$ground = $this->protectionFor(object: $object);
		if ($ground !== null) {
			return array_merge(
				$item,
				$ground,
				[
					'bucket' => ErasureBucket::PROTECTED,
					// A protected record is not destroyed and not scrubbed, so
					// the only thing it costs is itself. Counting its files here
					// would read as "these files go", which is the opposite of
					// what protected means.
					'counts' => [ErasureBucket::OBJECTS => 1],
				]
			);
		}

		$parties = $this->countPartyRecords(object: $object, subjectId: $subjectId, matched: $matched);

		if ($eraseMode === DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT) {
			$shared = $this->otherSubjectsOn(object: $object, subjectId: $subjectId, matched: $matched);
			if ($shared === []) {
				$scope = $this->scopeCounts(object: $object);

				return array_merge(
					$item,
					[
						'bucket' => ErasureBucket::ERASABLE,
						'counts' => [
							ErasureBucket::OBJECTS => 1,
							ErasureBucket::FILES => $scope[ErasureBucket::FILES],
							ErasureBucket::TIMELINE => $scope[ErasureBucket::TIMELINE],
							ErasureBucket::PARTIES => $parties,
						],
					]
				);
			}

			// DESTROYING A SHARED RECORD ERASES SOMEBODY WHO DID NOT ASK.
			// A record carrying another person's identifier of the same kind is
			// not this subject's record to destroy, so the whole-object request
			// is downgraded to a scrub and the downgrade is named.
			return array_merge(
				$item,
				[
					'bucket' => ErasureBucket::PSEUDONYMISED,
					'ground' => ErasureBucket::GROUND_SHARED_RECORD,
					'message' => 'This record also holds another person\'s data, so it is scrubbed rather than destroyed.',
					'sharedWith' => $shared,
					'counts' => [
						ErasureBucket::OBJECTS => 1,
						ErasureBucket::PARTIES => $parties,
					],
				]
			);
		}//end if

		// Pseudonymisation leaves the object, its folder and its timeline where
		// they are: only the subject's values inside the payload move.
		return array_merge(
			$item,
			[
				'bucket' => ErasureBucket::PSEUDONYMISED,
				'counts' => [
					ErasureBucket::OBJECTS => 1,
					ErasureBucket::PARTIES => $parties,
				],
			]
		);
	}//end classify()

	/**
	 * The ground this object is protected under, or null when it is not.
	 *
	 * D-2 IS THE WHOLE POINT OF THE CATCH. The archival guard already answers
	 * `SCHEMA_UNRESOLVED` for a question it can ask and not answer. What it
	 * cannot report is a failure to ask at all — a database that will not
	 * answer, a schema row that throws on read. That case used to surface as an
	 * exception out of the preview, or worse as a record that looked erasable.
	 * It counts as protected and it is named.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed>|null The ground block, or null when erasable.
	 */
	private function protectionFor(ObjectEntity $object): ?array {
		try {
			$refusal = $this->archivalGuard->erasureRefusal(object: $object);
			if ($refusal !== null) {
				return [
					'ground' => (string)($refusal['ground'] ?? ArchivalRetentionGuard::GROUND_UNRESOLVED),
					'message' => (string)($refusal['message'] ?? ''),
					'basis' => (string)($refusal['basis'] ?? ''),
					'action' => (string)($refusal['action'] ?? ''),
				];
			}
		} catch (Throwable $e) {
			return $this->unresolvable(object: $object, error: $e);
		}

		try {
			$immutable = $this->retention->validateNotImmutable(object: $object);
			if ($immutable !== null) {
				return [
					'ground' => ErasureBucket::GROUND_IMMUTABLE,
					'message' => 'This record is in an archival status that cannot be changed, so we did not erase it.',
					'basis' => 'Archiefwet: status ' . $immutable . '.',
					'action' => 'Name this record in your answer to the requester.',
				];
			}
		} catch (Throwable $e) {
			return $this->unresolvable(object: $object, error: $e);
		}

		return null;
	}//end protectionFor()

	/**
	 * The ground block for a hold nobody could resolve.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Throwable    $error  What went wrong resolving it.
	 *
	 * @return array<string, mixed> The ground block.
	 */
	private function unresolvable(ObjectEntity $object, Throwable $error): array {
		$this->logger->warning(
			message: '[ErasurePreview] Could not resolve the hold on an object, counting it as protected',
			context: ['uuid' => $object->getUuid(), 'error' => $error->getMessage()]
		);

		return [
			'ground' => ErasureBucket::GROUND_UNRESOLVABLE,
			'message' => 'We could not establish whether this record is held, so we left it alone.',
			'basis' => 'Precaution: erasing on an unknown is the one failure that cannot be undone.',
			'action' => 'Ask an administrator to repair this record, then run the preview again.',
		];
	}//end unresolvable()

	/**
	 * The destruction scope counts for an object, as files and timeline rows.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, int> Files and timeline counts.
	 */
	private function scopeCounts(ObjectEntity $object): array {
		$empty = [
			ErasureBucket::FILES => 0,
			ErasureBucket::TIMELINE => 0,
		];

		try {
			$scope = $this->scopeService->preview(object: $object, schema: $this->schemaOf(object: $object));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ErasurePreview] Could not read the destruction scope',
				context: ['uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);
			return $empty;
		}

		return [
			ErasureBucket::FILES => (int)($scope['counts']['files'] ?? 0),
			ErasureBucket::TIMELINE => (int)($scope['counts']['timeline'] ?? 0),
		];
	}//end scopeCounts()

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

	/**
	 * Count the party records inside an object that name this subject.
	 *
	 * @param ObjectEntity      $object    The object.
	 * @param string            $subjectId The subject value.
	 * @param array<int, array> $matched   The PII hits.
	 *
	 * @return int The number of party records naming the subject.
	 */
	private function countPartyRecords(ObjectEntity $object, string $subjectId, array $matched): int {
		return $this->parties->count(
			payload: ($object->getObject() ?? []),
			needles: $this->parties->needles(subjectId: $subjectId, matched: $matched)
		);
	}//end countPartyRecords()

	/**
	 * Other people's identifiers of the SAME KIND on this object.
	 *
	 * Same kind matters. One person's record routinely carries an email, a
	 * phone number and a bsn, and counting those as three subjects would
	 * downgrade every record to a scrub. A SECOND email on the record is the
	 * signal that a second person is in it.
	 *
	 * @param ObjectEntity      $object    The object.
	 * @param string            $subjectId The subject value.
	 * @param array<int, array> $matched   The PII hits for this subject.
	 *
	 * @return array<int, string> The other subjects' PII types, deduplicated.
	 */
	private function otherSubjectsOn(ObjectEntity $object, string $subjectId, array $matched): array {
		$types = array_values(array_unique(array_filter(array_column($matched, 'type'))));
		if ($types === []) {
			return [];
		}

		$needles = $this->parties->needles(subjectId: $subjectId, matched: $matched);
		$uuid = (string)($object->getUuid() ?? '');
		if ($uuid === '') {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct(['e.type', 'e.value'])
				->from('openregister_entities', 'e')
				->innerJoin('e', 'openregister_entity_relations', 'r', $qb->expr()->eq('r.entity_id', 'e.id'))
				->where($qb->expr()->eq('r.object_uuid', $qb->createNamedParameter($uuid)))
				->andWhere($qb->expr()->in('e.type', $qb->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY)));

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (Throwable $e) {
			// FAILING CLOSED HERE MEANS "ASSUME SHARED". An index we cannot read
			// is not evidence that the record is this subject's alone, and the
			// cheaper mistake is a scrub where a destruction would have done.
			$this->logger->warning(
				message: '[ErasurePreview] Could not read the PII index for co-subjects, assuming the record is shared',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return $types;
		}//end try

		$others = [];
		foreach ($rows as $row) {
			$value = strtolower(trim((string)($row['value'] ?? '')));
			if ($value === '' || in_array($value, $needles, true) === true) {
				continue;
			}

			$others[] = (string)($row['type'] ?? '');
		}

		return array_values(array_unique(array_filter($others)));
	}//end otherSubjectsOn()

	/**
	 * Fold an unknown mode onto the safe one.
	 *
	 * @param string $eraseMode The requested mode.
	 *
	 * @return string A known mode.
	 */
	private function normaliseMode(string $eraseMode): string {
		if ($eraseMode === DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT) {
			return DataSubjectRequestService::ERASE_MODE_WHOLE_OBJECT;
		}

		return DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE;
	}//end normaliseMode()
}//end class
