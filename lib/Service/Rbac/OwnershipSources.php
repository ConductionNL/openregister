<?php

/**
 * OpenRegister ownership sources
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
 * Where an ownership change resolves its principals and its objects.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnershipSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param PrincipalDirectory $directory Resolves a named new owner, their display name, and groups.
	 * @param ObjectLookup       $lookup    Resolves objects, their children, and a child's register and schema.
	 */
	public function __construct(
		public readonly PrincipalDirectory $directory,
		public readonly ObjectLookup $lookup,
	) {
	}//end __construct()
}//end class
