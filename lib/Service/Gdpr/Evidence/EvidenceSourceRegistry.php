<?php

/**
 * OpenRegister Gdpr EvidenceSourceRegistry
 *
 * Discovers and resolves {@see EvidenceSourceProvider} implementations
 * registered by leaf apps at bootstrap. Mirrors ObjectSourceRegistry /
 * IntegrationRegistry: each app registers its provider from its own boot hook,
 * the registry is a single per-request shared service, and duplicate source ids
 * follow a first-wins policy with a logged warning.
 *
 * OpenRegister core enumerates ONLY registered providers — an unregistered
 * source contributes no evidence (ADR-019).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Evidence
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

namespace OCA\OpenRegister\Service\Gdpr\Evidence;

use Psr\Log\LoggerInterface;

/**
 * Registry of all EvidenceSourceProvider implementations on this instance.
 */
class EvidenceSourceRegistry {

	/**
	 * Registered providers, keyed by source id.
	 *
	 * @var array<string, EvidenceSourceProvider>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for collision warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register a provider with the registry.
	 *
	 * Duplicate id: first registration wins, the second logs a warning.
	 *
	 * @param EvidenceSourceProvider $provider The provider to register.
	 *
	 * @return bool True when accepted, false when rejected (duplicate id).
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
	 */
	public function addProvider(EvidenceSourceProvider $provider): bool {
		$id = $provider->getSourceId();
		if (isset($this->providers[$id]) === true) {
			$this->logger->warning(
				sprintf(
					'[EvidenceSourceRegistry] duplicate source id "%s" — keeping first registration',
					$id
				)
			);
			return false;
		}

		$this->providers[$id] = $provider;
		return true;
	}//end addProvider()

	/**
	 * Replace the entire provider set in one call (test seam).
	 *
	 * @param array<int, EvidenceSourceProvider> $providers Provider instances.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
	 */
	public function withProviders(array $providers): void {
		$this->providers = [];
		foreach ($providers as $provider) {
			$this->addProvider(provider: $provider);
		}
	}//end withProviders()

	/**
	 * Look up a provider by source id.
	 *
	 * @param string $id The source id.
	 *
	 * @return EvidenceSourceProvider|null Provider, or null when unregistered.
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
	 */
	public function get(string $id): ?EvidenceSourceProvider {
		return $this->providers[$id] ?? null;
	}//end get()

	/**
	 * List every registered provider, irrespective of isEnabled().
	 *
	 * @return array<int, EvidenceSourceProvider>
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
	 */
	public function list(): array {
		return array_values($this->providers);
	}//end list()

	/**
	 * Return the source ids of every registered provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/dsar-case-engine/specs/dsar-evidence-collection/spec.md
	 */
	public function listIds(): array {
		return array_keys($this->providers);
	}//end listIds()
}//end class
