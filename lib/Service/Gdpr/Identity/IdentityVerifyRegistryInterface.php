<?php

/**
 * IdentityVerifyRegistryInterface: what callers outside the Service component use of IdentityVerifyRegistry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Identity
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

namespace OCA\OpenRegister\Service\Gdpr\Identity;

/**
 * The registered identity-verification providers, resolved by id.
 */
interface IdentityVerifyRegistryInterface {

	/**
	 * Resolve the identity provider for a pack selector, FAIL-CLOSED.
	 *
	 * Returns the provider registered under $selectorId when present; otherwise
	 * (selector unset, empty, or naming an unregistered provider) returns the
	 * OR-shipped fail-closed default and logs a warning. NEVER returns null and
	 * NEVER treats "provider unavailable" as "verified" (ADR-005 / CWE-863).
	 *
	 * @param string|null $selectorId The active pack's identityVerifyProvider selector.
	 *
	 * @return IdentityVerifyProvider The bound provider, or the fail-closed default.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function resolve(?string $selectorId): IdentityVerifyProvider;

	/**
	 * Return the ids of every registered provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function listIds(): array;
}//end interface
