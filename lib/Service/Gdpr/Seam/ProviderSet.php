<?php

/**
 * OpenRegister Gdpr ProviderSet
 *
 * The keyed, first-registration-wins provider store shared by the DSAR seam
 * registries (identity-verify, regulator-escalate, evidence-source). Each
 * registry composes one and keeps its own typed public surface and its own
 * log wording; this class only holds the providers, refuses a duplicate id
 * with a warning, and resolves a selector FAIL-CLOSED to a caller-supplied
 * default (never null, ADR-005 / CWE-863).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Seam
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

namespace OCA\OpenRegister\Service\Gdpr\Seam;

use Psr\Log\LoggerInterface;

/**
 * First-wins provider store with fail-closed selector resolution.
 *
 * @template T of object
 *
 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
 */
final class ProviderSet {

	/**
	 * Registered providers, keyed by provider id, in registration order.
	 *
	 * @var array<string, T>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger           Logger for collision + fail-closed warnings.
	 * @param string          $duplicateWarning sprintf() format of the duplicate warning (one `%s`: the id).
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $duplicateWarning,
	) {

	}//end __construct()

	/**
	 * Register a provider; a duplicate id is refused with a warning.
	 *
	 * @param string $id       The provider id.
	 * @param T      $provider The provider.
	 *
	 * @return bool True when accepted, false when rejected (duplicate id).
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function add(string $id, object $provider): bool {
		if (isset($this->providers[$id]) === true) {
			$this->logger->warning(sprintf($this->duplicateWarning, $id));
			return false;
		}

		$this->providers[$id] = $provider;
		return true;

	}//end add()

	/**
	 * Look up a provider by id (no fallback).
	 *
	 * @param string $id The provider id.
	 *
	 * @return T|null The provider, or null when unregistered.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function get(string $id): ?object {
		return ($this->providers[$id] ?? null);

	}//end get()

	/**
	 * Resolve a selector to its provider, FAIL-CLOSED to the default.
	 *
	 * An unset, empty or unregistered selector logs the fallback warning and
	 * answers the default. Never null.
	 *
	 * @param string|null $selectorId      The pack selector.
	 * @param T           $default         The fail-closed default provider.
	 * @param string      $defaultId       The default's provider id (for the warning).
	 * @param string      $fallbackWarning sprintf() format (two `%s`: the selector, the default id).
	 *
	 * @return T The bound provider, or the default.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function resolve(?string $selectorId, object $default, string $defaultId, string $fallbackWarning): object {
		$fallback = new ProviderFallback(default: $default, defaultId: $defaultId, warning: $fallbackWarning);
		return $this->resolveOr(selectorId: $selectorId, fallback: $fallback);

	}//end resolve()

	/**
	 * Resolve a selector to its provider, FAIL-CLOSED to the fallback's default.
	 *
	 * An unset, empty or unregistered selector logs the fallback warning and
	 * answers the default. Never null.
	 *
	 * @param string|null      $selectorId The pack selector.
	 * @param ProviderFallback $fallback   The fail-closed default, its id and the warning.
	 *
	 * @return T The bound provider, or the default.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function resolveOr(?string $selectorId, ProviderFallback $fallback): object {
		if ($selectorId !== null && $selectorId !== '' && isset($this->providers[$selectorId]) === true) {
			return $this->providers[$selectorId];
		}

		$this->logger->warning(sprintf($fallback->warning, ($selectorId ?? '(null)'), $fallback->defaultId));
		return $fallback->default;

	}//end resolveOr()

	/**
	 * Every registered provider, in registration order.
	 *
	 * @return array<int, T>
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function all(): array {
		return array_values($this->providers);

	}//end all()

	/**
	 * The ids of every registered provider, in registration order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function ids(): array {
		return array_keys($this->providers);

	}//end ids()

	/**
	 * Forget every registered provider.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dsar-evidence-collection/spec.md
	 */
	public function clear(): void {
		$this->providers = [];

	}//end clear()
}//end class
