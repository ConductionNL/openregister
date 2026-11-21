<?php

/**
 * OpenRegister MovedObjectLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Follows a moved object from its old address, for one year.
 *
 * The `moved` audit entry MoveObject writes IS the pointer: it names both
 * addresses and when, and it lives on the object's unbroken chain. So the old
 * address is answered from the trail, on a miss only, and the read path every
 * object goes through is not touched. A year after the move the entry no
 * longer counts and the old address is a plain miss again.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */
final class MovedObjectLookup implements MovedObjectLookupInterface {

	/**
	 * How long the old address keeps answering.
	 */
	public const WINDOW = 'P1Y';

	/**
	 * How many `moved` entries are read; an object moved more often than this
	 * in a year answers at its most recent old addresses only.
	 */
	private const MOVES_READ = 20;

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface      $objects The object tables, searched across every source.
	 * @param AuditTrailMapperInterface $trail   The object's `moved` entries.
	 * @param ITimeFactory              $time    Now, for the one-year window.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objects,
		private readonly AuditTrailMapperInterface $trail,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The address an object moved to from the given address, or null.
	 *
	 * @param string     $identifier The id the caller asked for.
	 * @param int|string $register   The register id of the address asked for.
	 * @param int|string $schema     The schema id of the address asked for.
	 * @param bool       $scoped     Whether the caller's RBAC and organisation apply.
	 *
	 * @return array{register: string, schema: string, uuid: string, movedAt: string}|null The new address.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
	 */
	public function movedFrom(string $identifier, int|string $register, int|string $schema, bool $scoped): ?array {
		$object = $this->readable(identifier: $identifier, scoped: $scoped);
		if ($object === null) {
			return null;
		}

		$current = ['register' => (string)$object->getRegister(), 'schema' => (string)$object->getSchema()];
		if ($current['register'] === (string)$register && $current['schema'] === (string)$schema) {
			// It lives here; the miss was something else, and is not ours to answer.
			return null;
		}

		$movedAt = $this->movedAwayAt(uuid: (string)$object->getUuid(), register: (string)$register, schema: (string)$schema);
		if ($movedAt === null) {
			return null;
		}

		return ['register' => $current['register'], 'schema' => $current['schema'], 'uuid' => (string)$object->getUuid(), 'movedAt' => $movedAt];
	}//end movedFrom()

	/**
	 * The object, wherever it lives, if the caller may read it.
	 *
	 * @param string $identifier The id the caller asked for.
	 * @param bool   $scoped     Whether the caller's RBAC and organisation apply.
	 *
	 * @return ObjectEntity|null The object, or null when it is not there for this caller.
	 */
	private function readable(string $identifier, bool $scoped): ?ObjectEntity {
		try {
			$found = $this->objects->findAcrossAllSources(
				identifier: $identifier,
				includeDeleted: false,
				_rbac: $scoped,
				_multitenancy: $scoped,
				registerIdScope: null
			);
		} catch (Throwable $e) {
			return null;
		}

		$object = ($found['object'] ?? null);
		if (($object instanceof ObjectEntity) === false || $object->isSoftDeleted() === true) {
			return null;
		}

		return $object;
	}//end readable()

	/**
	 * When the object moved away from the address, within the window, or null.
	 *
	 * @param string $uuid     The object.
	 * @param string $register The register id of the old address.
	 * @param string $schema   The schema id of the old address.
	 *
	 * @return string|null The move's time (ATOM), or null when it did not move from here this year.
	 */
	private function movedAwayAt(string $uuid, string $register, string $schema): ?string {
		$since = DateTimeImmutable::createFromInterface($this->time->now())->sub(new \DateInterval(self::WINDOW));
		$entries = $this->trail->findObjectActions(
			objectUuid: $uuid,
			options: new ActionLookupOptions(actions: [MoveObject::ACTION], limit: self::MOVES_READ)
		);
		foreach ($entries as $entry) {
			$created = $entry->getCreated();
			if (($created instanceof \DateTimeInterface) === false || $created < $since) {
				continue;
			}

			$from = ($entry->getChanged()['from'] ?? null);
			if (is_array($from) === true && (string)($from['register'] ?? '') === $register && (string)($from['schema'] ?? '') === $schema) {
				return $created->format(DATE_ATOM);
			}
		}

		return null;
	}//end movedAwayAt()
}//end class
