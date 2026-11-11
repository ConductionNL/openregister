<?php

/**
 * PrincipalReachListerInterface: every way one principal reaches data.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The reach listing the principal-reach endpoint serves.
 *
 * Bound to PrincipalReachService in ContractBindings.
 */
interface PrincipalReachListerInterface {

	/**
	 * Everything this principal can reach, and where each grant comes from.
	 *
	 * Reads only. Nothing here removes anything, because D-5 says the list is
	 * shown before anything is taken away.
	 *
	 * @param string $principal The account to report on.
	 *
	 * @return array<string, mixed> The reach listing.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function listFor(string $principal): array;
}//end interface
