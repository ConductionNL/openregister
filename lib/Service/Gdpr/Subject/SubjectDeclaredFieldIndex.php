<?php

/**
 * SubjectDeclaredFieldIndex — finds a data subject's objects through the
 * fields their schema declares as the subject's id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Subject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Subject;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Gdpr\ProcessingAnnotationReader;
use OCA\OpenRegister\Service\Gdpr\SubjectQuery;
use Psr\Log\LoggerInterface;

/**
 * The second discovery source beside {@see SubjectEntityIndex}.
 *
 * The entity index holds what the detector recognised in text; an internal id
 * such as a learner uuid is never detected. A schema that keys its records on
 * such an id declares it under `x-openregister-processing.subjectIdFields`,
 * and this source searches exactly those fields, exactly: an `ilike` match on
 * an id would find other people's records.
 *
 * @spec openspec/changes/dsar-finds-records-by-declared-subject-id/specs/gdpr-data-subject-rights/spec.md
 */
class SubjectDeclaredFieldIndex {

	/**
	 * How many objects one field search reads.
	 *
	 * @var int
	 */
	private const SEARCH_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface      $schemas   The schemas, read for their declared subject id fields.
	 * @param RegisterMapperInterface    $registers The registers each schema lives in.
	 * @param MagicObjectSearchInterface $objects   The object search, RBAC and tenant scoped.
	 * @param ProcessingAnnotationReader $reader    The reader of the processing dialect.
	 * @param LoggerInterface            $logger    Logger.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly RegisterMapperInterface $registers,
		private readonly MagicObjectSearchInterface $objects,
		private readonly ProcessingAnnotationReader $reader,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The objects whose declared subject id field holds the subject, grouped by object.
	 *
	 * With a type, only the fields declared under that type are searched. A
	 * list-valued field matches when it contains the id. The match mode of the
	 * query is not used: a declared id field is always matched exactly.
	 *
	 * @param SubjectQuery $query        The subject value and optional id type.
	 * @param bool         $rbac         Apply RBAC.
	 * @param bool         $multitenancy Apply tenant scoping.
	 *
	 * @return array<string, array{object_id: int, object_uuid: string, subjectFields: list<array{type: string, field: string}>}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/dsar-finds-records-by-declared-subject-id/specs/gdpr-data-subject-rights/spec.md
	 */
	public function discover(SubjectQuery $query, bool $rbac = true, bool $multitenancy = true): array {
		$subjectId = trim($query->subjectId);
		if ($subjectId === '') {
			return [];
		}

		$grouped = [];
		foreach ($this->declaringSchemas(type: $query->type) as [$schemaId, $fields]) {
			foreach ($this->registersOf(schemaId: $schemaId) as $registerId) {
				foreach ($fields as $type => $field) {
					$table = ['register' => $registerId, 'schema' => $schemaId];
					foreach ($this->search(table: $table, field: $field, subjectId: $subjectId, scope: [$rbac, $multitenancy]) as $object) {
						$key = self::groupKey(object: $object);
						if ($key === null) {
							continue;
						}

						$grouped[$key] ??= [
							'object_id' => (int)($object->getId() ?? 0),
							'object_uuid' => (string)($object->getUuid() ?? ''),
							'subjectFields' => [],
						];
						$reason = ['type' => (string)$type, 'field' => $field];
						if (in_array($reason, $grouped[$key]['subjectFields'], true) === false) {
							$grouped[$key]['subjectFields'][] = $reason;
						}
					}
				}//end foreach
			}//end foreach
		}//end foreach

		return $grouped;

	}//end discover()

	/**
	 * The schemas that declare a subject id field, with those fields.
	 *
	 * @param string|null $type The id type to keep, or null for every type.
	 *
	 * @return list<array{0: int, 1: array<string, string>}> Schema id and its fields per id type.
	 */
	private function declaringSchemas(?string $type): array {
		try {
			$schemas = $this->schemas->findAll(_rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning(message: '[DSR] Schemas with subject id fields could not be read', context: ['error' => $e::class]);
			return [];
		}

		$declaring = [];
		foreach ($schemas as $schema) {
			$fields = $this->reader->subjectIdFields(config: $schema->getConfiguration(), type: $type);
			$schemaId = (int)($schema->getId() ?? 0);
			if ($fields !== [] && $schemaId > 0) {
				$declaring[] = [$schemaId, $fields];
			}
		}

		return $declaring;

	}//end declaringSchemas()

	/**
	 * The registers a schema lives in; none when they cannot be read.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return list<int>
	 */
	private function registersOf(int $schemaId): array {
		try {
			return array_values(array_map('intval', $this->registers->getAllRegisterIdsWithSchema(schemaId: $schemaId)));
		} catch (\Throwable $e) {
			$this->logger->warning(message: '[DSR] Registers of a schema could not be read', context: ['schema' => $schemaId, 'error' => $e::class]);
			return [];
		}

	}//end registersOf()

	/**
	 * The objects in one register and schema whose field holds the subject exactly.
	 *
	 * The search narrows; the exact check decides, so nothing a looser
	 * database comparison lets through reaches the result.
	 *
	 * @param array{register: int, schema: int} $table     The register and schema.
	 * @param string                            $field     The declared field.
	 * @param string                            $subjectId The subject id.
	 * @param array{0: bool, 1: bool}           $scope     RBAC and tenant scoping.
	 *
	 * @return list<ObjectEntity>
	 */
	private function search(array $table, string $field, string $subjectId, array $scope): array {
		try {
			$found = $this->objects->searchObjects(
				query: ['@self' => $table, $field => $subjectId, '_limit' => self::SEARCH_LIMIT],
				_rbac: $scope[0],
				_multitenancy: $scope[1]
			);
		} catch (\Throwable $e) {
			// No subject in the context: the app log is no place for personal data.
			$this->logger->warning(
				message: '[DSR] Declared subject id field search failed',
				context: ['schema' => $table['schema'], 'register' => $table['register'], 'field' => $field, 'error' => $e::class]
			);
			return [];
		}

		if (is_array($found) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$found,
				static fn (mixed $object): bool => $object instanceof ObjectEntity
					&& self::holds(value: (($object->getObject() ?? [])[$field] ?? null), subjectId: $subjectId)
			)
		);

	}//end search()

	/**
	 * Whether a field value is the subject id, or a list containing it.
	 *
	 * @param mixed  $value     The field value.
	 * @param string $subjectId The subject id.
	 *
	 * @return bool
	 */
	private static function holds(mixed $value, string $subjectId): bool {
		if (is_array($value) === true) {
			foreach ($value as $item) {
				if (self::holds(value: $item, subjectId: $subjectId) === true && is_array($item) === false) {
					return true;
				}
			}

			return false;
		}

		return is_scalar($value) === true && (string)$value === $subjectId;

	}//end holds()

	/**
	 * The grouping key of an object, the same as the entity index uses.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null `uuid:<uuid>`, `id:<id>`, or null when it names neither.
	 */
	private static function groupKey(ObjectEntity $object): ?string {
		$uuid = (string)($object->getUuid() ?? '');
		if ($uuid !== '') {
			return 'uuid:' . $uuid;
		}

		$id = (int)($object->getId() ?? 0);
		if ($id > 0) {
			return 'id:' . $id;
		}

		return null;

	}//end groupKey()
}//end class
