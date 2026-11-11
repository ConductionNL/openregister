<?php

/**
 * GrantedRows: the rows a per-object grant re-opens for the caller, as SQL parts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The `_uuid` column of the rows being read and the granted uuids, already quoted.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GrantedRows {

	/**
	 * Constructor.
	 *
	 * @param string   $uuidColumn  The `_uuid` column, qualified by the caller.
	 * @param string[] $quotedUuids Granted object UUIDs, ALREADY quoted as SQL literals.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly string $uuidColumn,
		public readonly array $quotedUuids,
	) {
	}//end __construct()
}//end class
