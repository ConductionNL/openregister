<?php

/**
 * MagicDdlFailure: what to log when a tolerated DDL statement fails.
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
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The warning a tolerated DDL statement leaves when it fails.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicDdlFailure {
	/**
	 * Constructor.
	 *
	 * @param string $message The warning.
	 * @param array  $context The log context; the error is added to it.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly string $message,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
