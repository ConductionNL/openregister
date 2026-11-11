<?php

/**
 * The caller's own values for a department matrix, read from their person object.
 *
 * A matrix's user source is either a group prefix or a person schema:
 * `{schema: 'person', property: 'department', match: 'userId'}` reads the
 * person object whose `userId` is the current user and takes its
 * `department` (a single value or a list) as the caller's own values.
 *
 * 🔴 THE READ RUNS WITHOUT RBAC, ON PURPOSE. This class is called while the
 * permission handler is deciding a question; a read that asked the permission
 * handler again would re-enter the decision it is part of. The person object
 * is read only to learn the caller's OWN values and nothing of it is returned
 * to anyone, so reading it unfiltered discloses nothing.
 *
 * 🔴 ANY FAILURE IS NO VALUES. No values means a `$self` row compiles to
 * nothing (the compiler drops a row with an empty `$in` whole), which is the
 * closed direction: the matrix only ever adds ways in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use Psr\Log\LoggerInterface;

/**
 * Reads a matrix user's own values from a person schema.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
 */
class DepartmentMatrixPersonValues {

	/**
	 * The field on the person object matched against the user id when the
	 * source names none.
	 *
	 * @var string
	 */
	public const DEFAULT_MATCH = 'userId';

	/**
	 * At most this many person objects are read for one user. Two person
	 * objects for one account is already a data error; a thousand would be a
	 * misdeclared `match` field, and reading them all would be a slow request
	 * on every authorization question.
	 *
	 * @var int
	 */
	private const PERSON_LIMIT = 10;

	/**
	 * Values per user and source, for the rest of the request.
	 *
	 * @var array<string, string[]>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface    $schemas   Loads the person schema.
	 * @param RegisterMapperInterface  $registers Finds the register that holds it.
	 * @param MagicMapperInterface     $objects   Reads the person object.
	 * @param LoggerInterface $logger    Diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly RegisterMapperInterface $registers,
		private readonly MagicMapperInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The person field a source matches the user on, or the default when it names none.
	 *
	 * @param array<string, mixed>|null $source The matrix's `userSource`.
	 *
	 * @return string The field.
	 */
	private static function matchFieldOf(?array $source): string {
		$match = trim((string)($source['match'] ?? self::DEFAULT_MATCH));
		if ($match === '') {
			return self::DEFAULT_MATCH;
		}

		return $match;
	}//end matchFieldOf()

	/**
	 * The user's values read from the person source now, bypassing the cache.
	 *
	 * @param array<string, mixed>|null $source The matrix's `userSource`, with a schema and a property.
	 * @param PersonMatch               $who    The person field and the user.
	 *
	 * @return string[] The values, or none when the persons cannot be read.
	 */
	private function freshValues(?array $source, PersonMatch $who): array {
		$persons = $this->persons(
			source: new RegisterSchemaRef(register: trim((string)($source['register'] ?? '')), schema: trim((string)($source['schema'] ?? ''))),
			who: $who
		);
		if ($persons === null) {
			return [];
		}

		return self::valuesOf(rows: self::matching(persons: $persons, who: $who), property: trim((string)($source['property'] ?? '')));
	}//end freshValues()

	/**
	 * The caller's own values from the declared person source.
	 *
	 * @param array<string, mixed>|null $source The matrix's `userSource`.
	 * @param string|null               $userId The current user.
	 *
	 * @return string[] The values, or none when the source is not a person source.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
	 */
	public function valuesFor(?array $source, ?string $userId): array {
		$schemaRef = trim((string)($source['schema'] ?? ''));
		$property  = trim((string)($source['property'] ?? ''));
		if ($schemaRef === '' || $property === '' || $userId === null || $userId === '') {
			return [];
		}

		$match = self::matchFieldOf(source: $source);
		$key = $userId . '|' . $schemaRef . '|' . (string)($source['register'] ?? '') . '|' . $property . '|' . $match;
		if (array_key_exists($key, $this->cache) === true) {
			return $this->cache[$key];
		}

		$this->cache[$key] = $this->freshValues(source: $source, who: new PersonMatch(field: $match, userId: $userId));

		return $this->cache[$key];
	}//end valuesFor()

	/**
	 * Read the person objects the search returns for the user.
	 *
	 * @param RegisterSchemaRef $source The person schema's slug or id, and the register's ('' to find it).
	 * @param PersonMatch       $who    The property matched against the user id, and the current user.
	 *
	 * @return array<int, mixed>|null The objects, or null when the source cannot be read.
	 */
	private function persons(RegisterSchemaRef $source, PersonMatch $who): ?array {
		try {
			$persons = $this->searchPersons(source: $source, who: $who);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DepartmentMatrixPersonValues] Could not read the person source; the user gets no own values',
				context: ['schema' => (string)$source->schema, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($persons) === false) {
			return null;
		}

		return $persons;
	}//end persons()

	/**
	 * Search the person schema for the user's person objects, unscoped and capped.
	 *
	 * @param RegisterSchemaRef $source The person schema's slug or id, and the register's ('' to find it).
	 * @param PersonMatch       $who    The property matched against the user id, and the current user.
	 *
	 * @return mixed The search answer, or null when no register holds the schema.
	 *
	 * @throws \Throwable When the schema, the register or the search cannot be read.
	 */
	private function searchPersons(RegisterSchemaRef $source, PersonMatch $who): mixed {
		$schema = $this->schemas->find((string)$source->schema, _multitenancy: false, _rbac: false);

		$register = $this->registerHolding(source: $source, schemaId: (int)$schema->getId());
		if ($register === null) {
			return null;
		}

		return $this->objects->searchObjects(
			query: self::personQuery(
				who: $who,
				table: new RegisterSchemaRef(register: (string)$register->getId(), schema: (string)$schema->getId())
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end searchPersons()

	/**
	 * The search for the user's person objects in one register and schema.
	 *
	 * @param PersonMatch       $who   The property matched against the user id, and the current user.
	 * @param RegisterSchemaRef $table The register and the person schema.
	 *
	 * @return array<string, mixed> The query.
	 */
	private static function personQuery(PersonMatch $who, RegisterSchemaRef $table): array {
		return [
			'@self' => ['register' => $table->register, 'schema' => $table->schema],
			$who->field => $who->userId,
			'_limit' => self::PERSON_LIMIT,
		];
	}//end personQuery()

	/**
	 * The register the source names, else the first one holding the schema; null when there is none.
	 *
	 * @param RegisterSchemaRef $source   The register's slug or id ('' to find it).
	 * @param int               $schemaId The person schema's id.
	 *
	 * @return Register|null The register.
	 *
	 * @throws \Throwable When the register cannot be read.
	 */
	private function registerHolding(RegisterSchemaRef $source, int $schemaId): ?Register {
		$registerId = (string)$source->register;
		if ($registerId === '') {
			$registerId = (string)($this->registers->getFirstRegisterWithSchema($schemaId) ?? '');
		}

		if ($registerId === '') {
			return null;
		}

		return $this->registers->find(id: $registerId, _rbac: false, _multitenancy: false);
	}//end registerHolding()

	/**
	 * The property's values over the persons that really match the user.
	 *
	 * The match is checked again here rather than trusted from the query: a
	 * filter the search layer did not understand would come back as no filter
	 * at all, and every person's department would become this user's.
	 *
	 * @param array<int, mixed> $persons  The objects the search returned.
	 * @param string            $property The property holding the values.
	 * @param string            $match    The property matched against the user id.
	 * @param string            $userId   The current user.
	 *
	 * @return string[] The values, unique and non-empty.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md#requirement-a-schema-declares-a-department-by-role-matrix-keyed-on-an-object-field
	 */
	public static function collect(array $persons, string $property, string $match, string $userId): array {
		return self::valuesOf(rows: self::matching(persons: $persons, who: new PersonMatch(field: $match, userId: $userId)), property: $property);
	}//end collect()

	/**
	 * The bodies of the persons whose match field really holds the user.
	 *
	 * @param array<int, mixed> $persons The objects the search returned.
	 * @param PersonMatch       $who     The property matched against the user id, and the current user.
	 *
	 * @return array<int, array> The matching bodies.
	 */
	private static function matching(array $persons, PersonMatch $who): array {
		$rows = [];
		foreach ($persons as $person) {
			$data = $person;
			if ($person instanceof ObjectEntity === true) {
				$data = ($person->getObject() ?? []);
			}

			if (is_array($data) === true && (string)($data[$who->field] ?? '') === $who->userId) {
				$rows[] = $data;
			}
		}

		return $rows;
	}//end matching()

	/**
	 * The property's scalar, non-empty values over the rows, unique.
	 *
	 * @param array<int, array> $rows     The matching bodies.
	 * @param string            $property The property holding the values.
	 *
	 * @return string[] The values.
	 */
	private static function valuesOf(array $rows, string $property): array {
		$values = [];
		foreach ($rows as $data) {
			foreach ((array)($data[$property] ?? []) as $value) {
				if (is_scalar($value) === true && (string)$value !== '') {
					$values[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($values));
	}//end valuesOf()
}//end class
