<?php

/**
 * OpenRegister Gdpr IdentityVerifyRegistry
 *
 * Discovers and resolves {@see IdentityVerifyProvider} implementations
 * registered by leaf apps at bootstrap. Mirrors ObjectSourceRegistry /
 * IntegrationRegistry / EvidenceSourceRegistry: each app registers its provider
 * from its own boot hook, the registry is a single per-request shared service,
 * and duplicate provider ids follow a first-wins policy with a logged warning
 * (ADR-019).
 *
 * The read path resolves the provider named by the active
 * `dsarPolicyPack.identityVerifyProvider` selector. Resolution is FAIL-CLOSED:
 * an unset or unregistered selector resolves to the OR-shipped fail-closed
 * default provider (never null, never a success shortcut) so a missing provider
 * can never be mistaken for "verified" (ADR-005 / CWE-863).
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

use OCA\OpenRegister\Service\Gdpr\Seam\ProviderSet;
use OCA\OpenRegister\Service\Gdpr\Seam\ProviderFallback;
use Psr\Log\LoggerInterface;

/**
 * Registry of all IdentityVerifyProvider implementations on this instance.
 */
class IdentityVerifyRegistry implements IdentityVerifyRegistryInterface {

	/**
	 * Registered providers, keyed by provider id.
	 *
	 * @var ProviderSet<IdentityVerifyProvider>
	 */
	private ProviderSet $providers;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for collision + fail-closed warnings.
	 * @param NullIdentityVerifyProvider $default Fail-closed default returned when resolution finds no bound provider.
	 *
	 * @return void
	 */
	public function __construct(
		LoggerInterface $logger,
		private readonly NullIdentityVerifyProvider $default,
	) {
		$this->providers = new ProviderSet(
			logger: $logger,
			duplicateWarning: '[IdentityVerifyRegistry] duplicate provider id "%s" — keeping first registration'
		);
		// The fail-closed default is itself registered so a pack that names it
		// explicitly (as the default pack does) resolves through the same path.
		$this->providers->add(id: $this->default->getProviderId(), provider: $this->default);
	}//end __construct()

	/**
	 * Register a provider with the registry.
	 *
	 * Duplicate id: first registration wins, the second logs a warning.
	 *
	 * @param IdentityVerifyProvider $provider The provider to register.
	 *
	 * @return bool True when accepted, false when rejected (duplicate id).
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function addProvider(IdentityVerifyProvider $provider): bool {
		return $this->providers->add(id: $provider->getProviderId(), provider: $provider);
	}//end addProvider()

	/**
	 * Look up a provider by id (no fail-closed fallback).
	 *
	 * Prefer {@see resolve()} on the case path; `get()` is the raw lookup used
	 * by tests and diagnostics.
	 *
	 * @param string $id The provider id.
	 *
	 * @return IdentityVerifyProvider|null Provider, or null when unregistered.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function get(string $id): ?IdentityVerifyProvider {
		return $this->providers->get(id: $id);
	}//end get()

	/**
	 * List every registered provider, irrespective of enablement.
	 *
	 * @return array<int, IdentityVerifyProvider>
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function list(): array {
		return $this->providers->all();
	}//end list()

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
	public function resolve(?string $selectorId): IdentityVerifyProvider {
		return $this->providers->resolve(
			selectorId: $selectorId,
			fallback: new ProviderFallback(
				default: $this->default,
				defaultId: $this->default->getProviderId(),
				warning: '[IdentityVerifyRegistry] identityVerifyProvider "%s" is unset or unregistered — '
					. 'resolving fail-closed default "%s" (identity NOT verified)'
			)
		);
	}//end resolve()

	/**
	 * Return the ids of every registered provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function listIds(): array {
		return $this->providers->ids();
	}//end listIds()
}//end class
