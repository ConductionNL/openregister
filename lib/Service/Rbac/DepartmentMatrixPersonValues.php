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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
	 * @param SchemaMapper    $schemas   Loads the person schema.
	 * @param RegisterMapper  $registers Finds the register that holds it.
	 * @param MagicMapper     $objects   Reads the person object.
	 * @param LoggerInterface $logger    Diagnostics.
	 */
	public function __construct(
		private readonly SchemaMapper $schemas,
		private readonly RegisterMapper $registers,
		private readonly MagicMapper $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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

		$match = trim((string)($source['match'] ?? self::DEFAULT_MATCH));
		if ($match === '') {
			$match = self::DEFAULT_MATCH;
		}

		$key = $userId . '|' . $schemaRef . '|' . (string)($source['register'] ?? '') . '|' . $property . '|' . $match;
		if (array_key_exists($key, $this->cache) === true) {
			return $this->cache[$key];
		}

		$this->cache[$key] = $this->read(
			schemaRef: $schemaRef,
			registerRef: trim((string)($source['register'] ?? '')),
			property: $property,
			match: $match,
			userId: $userId
		);

		return $this->cache[$key];
	}//end valuesFor()

	/**
	 * Read the person objects and collect the property's values.
	 *
	 * @param string $schemaRef   The person schema's slug or id.
	 * @param string $registerRef The register's slug or id, or '' to find it.
	 * @param string $property    The property holding the values.
	 * @param string $match       The property matched against the user id.
	 * @param string $userId      The current user.
	 *
	 * @return string[] The values.
	 */
	private function read(string $schemaRef, string $registerRef, string $property, string $match, string $userId): array {
		try {
			$schema = $this->schemas->find($schemaRef, _multitenancy: false, _rbac: false);

			$registerId = $registerRef;
			if ($registerId === '') {
				$registerId = (string)($this->registers->getFirstRegisterWithSchema((int)$schema->getId()) ?? '');
			}

			if ($registerId === '') {
				return [];
			}

			$register = $this->registers->find(id: $registerId, _rbac: false, _multitenancy: false);

			$persons = $this->objects->searchObjects(
				query: [
					'@self' => ['register' => (string)$register->getId(), 'schema' => (string)$schema->getId()],
					$match => $userId,
					'_limit' => self::PERSON_LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[DepartmentMatrixPersonValues] Could not read the person source; the user gets no own values',
				context: ['schema' => $schemaRef, 'error' => $e->getMessage()]
			);
			return [];
		}//end try

		if (is_array($persons) === false) {
			return [];
		}

		return self::collect(persons: $persons, property: $property, match: $match, userId: $userId);
	}//end read()

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
	 */
	public static function collect(array $persons, string $property, string $match, string $userId): array {
		$values = [];
		foreach ($persons as $person) {
			$data = $person;
			if ($person instanceof ObjectEntity === true) {
				$data = ($person->getObject() ?? []);
			}

			if (is_array($data) === false || (string)($data[$match] ?? '') !== $userId) {
				continue;
			}

			foreach ((array)($data[$property] ?? []) as $value) {
				if (is_scalar($value) === true && (string)$value !== '') {
					$values[] = (string)$value;
				}
			}
		}

		return array_values(array_unique($values));
	}//end collect()
}//end class
