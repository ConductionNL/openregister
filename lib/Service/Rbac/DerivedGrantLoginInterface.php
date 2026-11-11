<?php

/**
 * DerivedGrantLoginInterface: the claim-derived grants a login applies or forgets.
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
 * The rules, and the grants they derive from one login's identity claims.
 *
 * Bound to DerivedGrantStore in ContractBindings.
 */
interface DerivedGrantLoginInterface {

	/**
	 * The declared rules.
	 *
	 * @return array<int, mixed> The rules, or an empty list when none are declared.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function rules(): array;

	/**
	 * Derive one account's grants from these claims, and keep both.
	 *
	 * The claims are kept so a rule change can be re-run without waiting for
	 * everybody to sign in again, which is the difference between an access
	 * change taking effect today and taking effect over a fortnight.
	 *
	 * @param string               $userId The account.
	 * @param array<string, mixed> $claims The claims the provider asserted.
	 *
	 * @return array<int, array<string, mixed>> The grants that were stored.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function apply(string $userId, array $claims): array;

	/**
	 * Forget one account's derived access.
	 *
	 * Called when a sign-in asserts nothing. Keeping the previous set would keep
	 * somebody in a department they left, which is the failure this whole
	 * mechanism is supposed to end rather than automate.
	 *
	 * @param string $userId The account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function forget(string $userId): void;
}//end interface
