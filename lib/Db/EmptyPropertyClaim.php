<?php

/**
 * The claims in progress: a write that may only fill a property that is still empty.
 *
 * A claim is held by uuid for the duration of one save. The mapper asks for it
 * when it writes the row and, when there is one, writes the row only while the
 * property's column is empty (`... AND (col IS NULL OR col = '')`), the same
 * compare-and-swap DraftStatusPolicy uses for leaving draft. Of two callers who
 * both read the property as empty, the database lets one change the row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Request-scoped claims, by object uuid.
 *
 * @spec openspec/specs/declared-actions/spec.md
 */
final class EmptyPropertyClaim {

	/**
	 * The property each claimed object may only fill while empty, by uuid.
	 *
	 * @var array<string, string>
	 */
	private static array $claims = [];

	/**
	 * Hold a claim for the next write of this object.
	 *
	 * @param string $uuid     The object.
	 * @param string $property The property the write may only fill while empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public static function hold(string $uuid, string $property): void {
		self::$claims[$uuid] = $property;
	}//end hold()

	/**
	 * The property this write may only fill while empty, and forget the claim.
	 *
	 * Only the first write of the save is conditional; a later write of the same
	 * object in the same request finds the property set by this caller.
	 *
	 * @param string $uuid The object.
	 *
	 * @return string|null The property, or null for an ordinary write.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public static function consume(string $uuid): ?string {
		$property = (self::$claims[$uuid] ?? null);
		unset(self::$claims[$uuid]);

		return $property;
	}//end consume()
}//end class
