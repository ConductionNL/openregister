<?php

/**
 * IdentifierArms: the single-column lookups an id, uuid or slug identifier is resolved through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The arms of an identifier lookup, one column each, in the order they are tried.
 *
 * A schema or register is addressed by id, uuid or slug, and the lookup used to
 * ask all three in one `uuid = ? OR LOWER(slug) = ? OR id = ?`. A disjunction
 * over three columns cannot be served by an index on any of them, so every miss
 * of the request cache paid a sequential scan. This asks one column at a time,
 * starting with the one the identifier's shape says the caller holds, and moves
 * to the next arm only when that one finds nothing:
 *
 * - numeric: id, then slug, then uuid;
 * - shaped like a UUID: uuid, then slug;
 * - anything else: slug, then uuid.
 *
 * Every row the disjunction could match is still reachable. Only when two
 * different rows match different arms does the answer follow the arm order
 * (an exact id beats a numeric slug, a uuid beats a slug that spells a uuid)
 * instead of the row order of one merged result.
 *
 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md#requirement-schema-and-register-resolution-is-read-once-per-request
 */
final class IdentifierArms {

	/**
	 * The primary key arm.
	 */
	public const ID = 'id';

	/**
	 * The uuid arm.
	 */
	public const UUID = 'uuid';

	/**
	 * The case-insensitive slug arm.
	 */
	public const SLUG = 'slug';

	/**
	 * The shape of a UUID, any letter case.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The arms to try for an identifier, most likely first.
	 *
	 * @param int|string $id The identifier.
	 *
	 * @return array<int, string> The arms, each one of the constants above.
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md#requirement-schema-and-register-resolution-is-read-once-per-request
	 */
	public static function of(int|string $id): array {
		if (is_numeric($id) === true) {
			return [self::ID, self::SLUG, self::UUID];
		}

		if (preg_match(self::UUID_PATTERN, (string)$id) === 1) {
			return [self::UUID, self::SLUG];
		}

		return [self::SLUG, self::UUID];
	}//end of()

	/**
	 * The condition one arm adds to a lookup query.
	 *
	 * The id arm compares the identifier as an integer, as the disjunction did:
	 * PostgreSQL types the column strictly. The slug arm lowers both sides.
	 *
	 * @param IQueryBuilder $qb  The query the condition is for.
	 * @param string        $arm One of the arm constants.
	 * @param int|string    $id  The identifier.
	 *
	 * @return string The condition.
	 *
	 * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md#requirement-schema-and-register-resolution-is-read-once-per-request
	 */
	public static function condition(IQueryBuilder $qb, string $arm, int|string $id): string {
		return match ($arm) {
			self::ID => $qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)),
			self::UUID => $qb->expr()->eq('uuid', $qb->createNamedParameter((string)$id, IQueryBuilder::PARAM_STR)),
			default => $qb->expr()->eq(
				$qb->func()->lower('slug'),
				$qb->createNamedParameter(strtolower((string)$id), IQueryBuilder::PARAM_STR)
			),
		};
	}//end condition()
}//end class
