<?php

/**
 * RegisterSchemaFilter: an optional register and schema to narrow statistics to.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * Which register-schema pairs a statistics read covers; a null id covers every one.
 *
 * @spec exclude shared statistics plumbing, not a capability of its own
 */
final class RegisterSchemaFilter {

	/**
	 * Constructor.
	 *
	 * @param int|null $registerId Only this register, when given.
	 * @param int|null $schemaId   Only this schema, when given.
	 */
	public function __construct(
		private readonly ?int $registerId,
		private readonly ?int $schemaId,
	) {
	}//end __construct()

	/**
	 * Whether a pair passes the filter.
	 *
	 * @param array{0: int, 1: int} $ids The register id and the schema id.
	 *
	 * @return bool True when the pair is covered.
	 *
	 * @spec exclude shared statistics plumbing, not a capability of its own
	 */
	public function matches(array $ids): bool {
		if ($this->registerId !== null && $ids[0] !== $this->registerId) {
			return false;
		}

		return $this->schemaId === null || $ids[1] === $this->schemaId;
	}//end matches()
}//end class
