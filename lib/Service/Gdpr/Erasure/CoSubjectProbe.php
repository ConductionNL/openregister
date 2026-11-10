<?php

/**
 * CoSubjectProbe — other people's identifiers of the same kind on a record.
 *
 * Same kind matters. One person's record routinely carries an email, a phone
 * number and a bsn, and counting those as three subjects would downgrade every
 * record to a scrub. A SECOND email on the record is the signal that a second
 * person is in it, and destroying that record would erase somebody who did not
 * ask. The probe reads the PII index and writes nothing.
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

use OCA\OpenRegister\Service\Gdpr\SubjectMatch;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds the PII types other subjects hold on the same record.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class CoSubjectProbe {
	/**
	 * Wire the probe.
	 *
	 * @param IDBConnection       $db      The PII index.
	 * @param SubjectPartyCounter $parties The one definition of "this subject's values".
	 * @param LoggerInterface     $logger  PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SubjectPartyCounter $parties,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Other people's identifiers of the SAME KIND on this object.
	 *
	 * @param SubjectMatch $match     The object and the PII hits for this subject.
	 * @param string       $subjectId The subject value.
	 *
	 * @return array<int, string> The other subjects' PII types, deduplicated.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function otherSubjectsOn(SubjectMatch $match, string $subjectId): array {
		$types = array_values(array_unique(array_filter(array_column($match->matched, 'type'))));
		$uuid = (string)($match->object->getUuid() ?? '');
		if ($types === [] || $uuid === '') {
			return [];
		}

		$rows = $this->readIndex(uuid: $uuid, types: $types);
		if ($rows === null) {
			return $types;
		}

		return $this->othersAmong(
			rows: $rows,
			needles: $this->parties->needles(subjectId: $subjectId, matched: $match->matched)
		);
	}//end otherSubjectsOn()

	/**
	 * The index rows, or null when the index cannot be read (logged).
	 *
	 * FAILING CLOSED HERE MEANS "ASSUME SHARED". An index we cannot read is not
	 * evidence that the record is this subject's alone, and the cheaper mistake
	 * is a scrub where a destruction would have done: on null the caller counts
	 * every type as shared.
	 *
	 * @param string             $uuid  The object uuid.
	 * @param array<int, string> $types The subject's PII types.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when unreadable.
	 */
	private function readIndex(string $uuid, array $types): ?array {
		try {
			return $this->indexRows(uuid: $uuid, types: $types);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ErasurePreview] Could not read the PII index for co-subjects, assuming the record is shared',
				context: ['uuid' => $uuid, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end readIndex()

	/**
	 * The PII index rows of the given types on one object.
	 *
	 * @param string             $uuid  The object uuid.
	 * @param array<int, string> $types The PII types to read.
	 *
	 * @return array<int, array<string, mixed>> The distinct type/value rows.
	 */
	private function indexRows(string $uuid, array $types): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['e.type', 'e.value'])
			->from('openregister_entities', 'e')
			->innerJoin('e', 'openregister_entity_relations', 'r', $qb->expr()->eq('r.entity_id', 'e.id'))
			->where($qb->expr()->eq('r.object_uuid', $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->in('e.type', $qb->createNamedParameter($types, IQueryBuilder::PARAM_STR_ARRAY)));

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end indexRows()

	/**
	 * The types of the rows whose value is not one of the subject's own.
	 *
	 * @param array<int, array<string, mixed>> $rows    The index rows.
	 * @param array<int, string>               $needles The subject's lower-cased values.
	 *
	 * @return array<int, string> The other subjects' types, deduplicated.
	 */
	private function othersAmong(array $rows, array $needles): array {
		$others = [];
		foreach ($rows as $row) {
			$value = strtolower(trim((string)($row['value'] ?? '')));
			if ($value !== '' && in_array($value, $needles, true) === false) {
				$others[] = (string)($row['type'] ?? '');
			}
		}

		return array_values(array_unique(array_filter($others)));
	}//end othersAmong()
}//end class
