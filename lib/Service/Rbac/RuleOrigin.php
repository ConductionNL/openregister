<?php

/**
 * Where a granted verb is written: the level and the verb.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The level a rule list sits at and the verb it grants.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RuleOrigin {
	/**
	 * Set the fields.
	 *
	 * @param string $level  Where the block is written.
	 * @param string $action The verb the entries grant.
	 */
	public function __construct(
		public readonly string $level,
		public readonly string $action,
	) {
	}//end __construct()
}//end class
