<?php

/**
 * How many existing objects break an identity a schema update declares.
 *
 * A schema that gains an identity over data that already breaks it answers 409
 * on the affected keys. The save is not refused for it (the data is the
 * maintainer's to fix), but it warns with the count, so the maintainer learns
 * of the duplicates when declaring the identity rather than from a failing
 * lookup later.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Counts the existing objects that break a newly declared identity.
 *
 * @spec openspec/changes/modelling-composite-identity/tasks.md#task-1.2
 */
class IdentityBreachCounter {

	/**
	 * The most objects read to count breaches; a larger schema reports "at least".
	 *
	 * @var int
	 */
	public const SCAN_CAP = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Reads the schema's existing objects.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The warning for an identity the update declares and existing objects break, or null.
	 *
	 * Only a newly declared identity, or one over other properties than
	 * before, is counted: an unchanged identity was already enforced on every
	 * write. Every object of the schema counts, whoever may read it, because a
	 * duplicate the maintainer cannot see still breaks the key.
	 *
	 * @param Schema     $schema                The schema as saved.
	 * @param array|null $previousConfiguration The schema's configuration before the update.
	 *
	 * @return array{identity: string, breaches: int, capped: bool, message: string}|null The warning, or null.
	 *
	 * @spec openspec/changes/modelling-composite-identity/tasks.md#task-1.2
	 */
	public function warningFor(Schema $schema, ?array $previousConfiguration): ?array {
		$evaluator = new UniqueConstraintEvaluator();
		$identity  = $evaluator->identity(configuration: $schema->getConfiguration());
		if ($identity === null) {
			return null;
		}

		$before = $evaluator->identity(configuration: $previousConfiguration);
		if ($before !== null && $before['properties'] === $identity['properties']) {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['schema' => $schema->getId()], 'limit' => self::SCAN_CAP],
			_rbac: false,
			_multitenancy: false
		);

		$breaches = self::breaches(evaluator: $evaluator, identity: $identity, objects: $objects);
		if ($breaches === 0) {
			return null;
		}

		$capped = count($objects) >= self::SCAN_CAP;
		$prefix = '';
		if ($capped === true) {
			$prefix = 'At least ';
		}

		return [
			'identity' => $identity['name'],
			'breaches' => $breaches,
			'capped' => $capped,
			'message' => sprintf(
				'%s%d existing object(s) share a key under the identity "%s". A lookup by such a key answers 409 until the duplicates are made unique.',
				$prefix,
				$breaches,
				$identity['name']
			),
		];
	}//end warningFor()

	/**
	 * The objects beyond the first per key: two objects with one key are one breach.
	 *
	 * An object without every identity value has no key and breaks nothing.
	 *
	 * @param UniqueConstraintEvaluator                                      $evaluator The evaluator.
	 * @param array{name:string,properties:array<int,string>,action:string} $identity  The identity.
	 * @param array<int|string, mixed>                                        $objects   The schema's objects, rendered or as entities.
	 *
	 * @return int The number of breaches.
	 */
	private static function breaches(UniqueConstraintEvaluator $evaluator, array $identity, array $objects): int {
		$seen     = [];
		$breaches = 0;
		foreach ($objects as $object) {
			$key = $evaluator->keyOf(identity: $identity, object: self::dataOf(object: $object));
			if ($key === null) {
				continue;
			}

			if (isset($seen[$key]) === true) {
				$breaches++;
				continue;
			}

			$seen[$key] = true;
		}

		return $breaches;
	}//end breaches()

	/**
	 * An object's data, from a rendered array or an entity.
	 *
	 * @param mixed $object The object.
	 *
	 * @return array<string, mixed>
	 */
	private static function dataOf(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			return (array)$object->getObject();
		}

		if (is_array($object) === true) {
			return $object;
		}

		return [];
	}//end dataOf()
}//end class
