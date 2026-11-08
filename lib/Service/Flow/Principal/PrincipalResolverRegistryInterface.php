<?php

/**
 * PrincipalResolverRegistryInterface: what callers outside the component use of PrincipalResolverRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

/**
 * The principal types the flow principal endpoint lists.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface PrincipalResolverRegistryInterface {

	/**
	 * Every type this instance understands, for an editor to offer.
	 *
	 * @return array<int, string> The types, sorted so the list is stable.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function types(): array;
}//end interface
