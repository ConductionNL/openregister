<?php

/**
 * PurposeBoundTables: doelbinding on a search that finds its own tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Audit\PurposeRefusedException;

/**
 * Applies the purpose rule of each table to a search that names no table itself.
 *
 * A search by ids answers named rows, so it is refused as the one-table read
 * would be. A text search answers whatever matches, so it leaves the tables it
 * may not read out.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
final class PurposeBoundTables {

	/**
	 * Constructor.
	 *
	 * @param PurposeGuard $guard The doelbinding guard.
	 */
	public function __construct(private readonly PurposeGuard $guard) {
	}//end __construct()

	/**
	 * Refuse when any of the tables refuses a read without a usable purpose.
	 *
	 * @param array<int, array{register: mixed, schema: mixed}> $pairs The tables.
	 *
	 * @return void
	 *
	 * @throws PurposeRefusedException When a table needs a purpose and none is usable.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function refuseUnbound(array $pairs): void {
		foreach ($pairs as $pair) {
			$this->guard->enforce(register: $pair['register'], schema: $pair['schema']);
		}
	}//end refuseUnbound()

	/**
	 * The tables a read may run on.
	 *
	 * @param array<int, array{register: mixed, schema: mixed}> $pairs The tables.
	 *
	 * @return array<int, array{register: mixed, schema: mixed}> The readable ones, in order.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function readable(array $pairs): array {
		return array_values(
			array_filter(
				$pairs,
				fn (array $pair): bool => $this->guard->allows(register: $pair['register'], schema: $pair['schema'])
			)
		);
	}//end readable()
}//end class
