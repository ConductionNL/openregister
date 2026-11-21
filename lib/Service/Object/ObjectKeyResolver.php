<?php

/**
 * OpenRegister ObjectKeyResolver
 *
 * Finds the object a schema's declared identity points at, so outside software
 * can address a record by its own key (a municipality code plus a case number)
 * instead of by Open Register's uuid.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluatorInterface;

/**
 * Resolves an identity's values to the uuids of the objects that carry them.
 *
 * The lookup goes through the ordinary object read path with RBAC and
 * multitenancy on, so an object the caller may not read is simply not found.
 * It is capped at two rows: enough to tell one match from more than one.
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */
class ObjectKeyResolver {

	/**
	 * The most rows one lookup returns: "none", "one" and "more than one" need no more.
	 *
	 * @var int
	 */
	public const CANDIDATE_CAP = 2;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface          $objectService The object read path.
	 * @param UniqueConstraintEvaluatorInterface $evaluator     Reads the schema's identity.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly UniqueConstraintEvaluatorInterface $evaluator,
	) {
	}//end __construct()

	/**
	 * The schema's identity constraint, or null when it declares none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{name:string,properties:array<int,string>,action:string}|null The identity.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function identityOf(Schema $schema): ?array {
		return $this->evaluator->identity(configuration: $schema->getConfiguration());
	}//end identityOf()

	/**
	 * The identity properties a request gave no usable value for, in declared order.
	 *
	 * A value that is absent, empty or not a scalar counts as missing: a blank
	 * key would otherwise match every object whose property is also blank.
	 *
	 * @param array{name:string,properties:array<int,string>,action:string} $identity The identity.
	 * @param array<string,mixed> $values The request's values.
	 *
	 * @return array<int,string> The missing property names.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function missing(array $identity, array $values): array {
		$missing = [];
		foreach ($identity['properties'] as $property) {
			$value = ($values[$property] ?? null);
			if (is_scalar($value) === false || (string)$value === '') {
				$missing[] = $property;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * The uuids of the readable objects whose identity carries these values, at most two.
	 *
	 * Nothing is looked up, and nothing is returned, when the schema declares no
	 * identity or a value is missing.
	 *
	 * @param Register            $register The register.
	 * @param Schema              $schema   The schema.
	 * @param array<string,mixed> $values   The identity values, by property.
	 *
	 * @return array<int,string> The uuids found, in the order found.
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function resolve(Register $register, Schema $schema, array $values): array {
		$identity = $this->identityOf(schema: $schema);
		if ($identity === null || $this->missing(identity: $identity, values: $values) !== []) {
			return [];
		}

		$filters = [
			'register' => $register->getId(),
			'schema' => $schema->getId(),
		];
		foreach ($identity['properties'] as $property) {
			$filters[$property] = (string)$values[$property];
		}

		$found = $this->objectService->findAll(
			config: [
				'filters' => $filters,
				'limit' => self::CANDIDATE_CAP,
			]
		);

		return $this->uuidsOf(found: $found);
	}//end resolve()

	/**
	 * The distinct uuids of the entries a lookup returned.
	 *
	 * @param array<int|string,mixed> $found The lookup's entries.
	 *
	 * @return array<int,string> The uuids.
	 */
	private function uuidsOf(array $found): array {
		$uuids = [];
		foreach ($found as $entry) {
			$uuid = $this->uuidOf(entry: $entry);
			if ($uuid !== null && $uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return array_values(array_unique($uuids));
	}//end uuidsOf()

	/**
	 * The uuid of one entry: a rendered array or an entity.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string|null The uuid, or null when the entry carries none.
	 */
	private function uuidOf(mixed $entry): ?string {
		if (is_object($entry) === true && method_exists($entry, 'getUuid') === true) {
			return (string)$entry->getUuid();
		}

		if (is_array($entry) === false) {
			return null;
		}

		$uuid = ($entry['@self']['id'] ?? $entry['uuid'] ?? $entry['id'] ?? null);
		if (is_scalar($uuid) === false) {
			return null;
		}

		return (string)$uuid;
	}//end uuidOf()
}//end class
