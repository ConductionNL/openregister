<?php

/**
 * DerivedGrantStoreInterface: what callers outside the component use of DerivedGrantStore.
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
 * The derived grant rules the derived grant endpoints read and reapply.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface DerivedGrantStoreInterface {

	/**
	 * Re-run the derivation for every account that has claims, and count what moved.
	 *
	 * @return array{users: integer, changed: integer, grantsBefore: integer, grantsAfter: integer, accounts: array<int, string>}
	 *         What the re-run did.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function reapply(): array;

	/**
	 * The declared rules.
	 *
	 * @return array<int, mixed> The rules, or an empty list when none are declared.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rules(): array;
}//end interface
