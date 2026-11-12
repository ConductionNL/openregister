<?php

/**
 * OpenRegister authorization edit
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
 * An authorization block being saved, and the block as stored when it is saved over one.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class AuthorizationEdit {
	/**
	 * Constructor.
	 *
	 * @param array|null $authorization The block.
	 * @param array|null $stored        The block as stored, when saving over one.
	 */
	public function __construct(
		public readonly ?array $authorization,
		public readonly ?array $stored = null,
	) {
	}//end __construct()
}//end class
