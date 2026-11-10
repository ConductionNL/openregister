<?php

/**
 * OpenRegister grant lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Which caller asks about which object, when a per-object grant is looked up.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GrantLookup {
	/**
	 * Constructor.
	 *
	 * @param string|null $userId     The caller.
	 * @param string|null $objectUuid The object's UUID.
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly ?string $objectUuid,
	) {
	}//end __construct()
}//end class
