<?php

/**
 * IntegrationRegistry — discovers and dispatches to IntegrationProvider
 * implementations registered at app bootstrap.
 *
 * Acts as stage 1 of the three-stage filter (AD-5):
 *   1. registry — what providers exist on this instance
 *   2. schema   — which of those are relevant for a given schema (Schema::linkedTypes)
 *   3. component — which of those should show on this surface (CnObjectSidebar etc.)
 *
 * Registration model: each app that ships an IntegrationProvider calls
 * `IntegrationRegistry::addProvider()` from its own `Application::register()`
 * hook. The registry is a single per-request service (registered via NC's
 * IRegistrationContext::registerService with `$shared=true`) so all apps
 * see the same instance.
 *
 * The spec (proposal.md AD-1) refers to this as "DI-tag-based" — that's
 * the intent, but modern Nextcloud doesn't expose a public
 * `IAppContainer::queryAll(<tag>)` method, so we use explicit
 * registration at bootstrap. The semantics are identical: providers
 * declare themselves, the registry collects them, the rest of the
 * codebase reads.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use Psr\Log\LoggerInterface;

/**
 * Registry of all IntegrationProvider implementations on this NC instance.
 *
 * Providers register via `addProvider()` from their owning app's
 * bootstrap. Duplicate ids: first registration wins, second logs a
 * warning (AD-13). External providers without a declared OpenConnector
 * source are rejected at registration time so misconfigurations fail
 * at bootstrap, not on first call.
 */
class IntegrationRegistry {

	/**
	 * Registered providers, keyed by id.
	 *
	 * @var array<string, IntegrationProvider>
	 */
	private array $providers = [];

	/**
	 * Registered page-level widget contracts, keyed by widget id.
	 *
	 * Distinct from per-object providers: a page widget is a declarative,
	 * RBAC-scoped render surface (e.g. a dashboard chart) that a leaf app
	 * registers so the nc-vue render layer can show it on a page rather
	 * than in an object's sidebar. The value is the declarative descriptor
	 * (id / type / title / providerId / config). The series DATA itself is
	 * supplied/fetched separately (see AnalyticsSeriesService) so the
	 * registry stays a thin declaration surface.
	 *
	 * @var array<string, array<string,mixed>>
	 */
	private array $pageWidgets = [];

	/**
	 * Optional lazy loader that collects sibling-app leaf providers.
	 *
	 * Set by `Application::boot()` to a closure that reads the `LeafRegistry`
	 * catalogue. Invoked once, on the first registry READ, so a data-provider
	 * leaf contributed through `RegisterLeafProvidersEvent` lands in
	 * `$providers` before `ObjectIntegrationsController` resolves it — without
	 * eagerly dispatching the collect-event when nothing reads the registry.
	 *
	 * @var (callable():void)|null
	 */
	private $leafLoader = null;

	/**
	 * Whether the lazy leaf loader has already run this request.
	 *
	 * @var boolean
	 */
	private bool $leavesLoaded = false;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger for collision and config warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Install the lazy leaf loader.
	 *
	 * The loader is a closure that collects sibling-app leaf providers into this
	 * registry (via `addProvider()`); it runs at most once, on the first read.
	 * Kept as a settable hook rather than a constructor dependency to avoid a
	 * `LeafRegistry` <-> `IntegrationRegistry` circular construction.
	 *
	 * @param callable $loader Zero-arg loader closure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-leaf-provider-registration/specs/leaf-provider-registration/spec.md
	 */
	public function setLeafLoader(callable $loader): void {
		$this->leafLoader = $loader;

	}//end setLeafLoader()

	/**
	 * Run the lazy leaf loader once, on the first registry read.
	 *
	 * The `$leavesLoaded` flag is set BEFORE invoking the loader so the loader's
	 * own `addProvider()` writes cannot re-enter this method into a loop. A
	 * throwing loader is swallowed — sibling-app discovery must never break the
	 * built-in registry.
	 *
	 * @return void
	 */
	private function ensureLeavesLoaded(): void {
		if ($this->leavesLoaded === true || $this->leafLoader === null) {
			return;
		}

		$this->leavesLoaded = true;

		try {
			($this->leafLoader)();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[IntegrationRegistry] leaf loader failed: {message}',
				['message' => $e->getMessage(), 'exception' => $e]
			);
		}

	}//end ensureLeavesLoaded()

	/**
	 * Register a provider with the registry.
	 *
	 * Apps call this from their own `Application::register()` hook,
	 * typically wrapping the call in a factory so the provider is only
	 * instantiated when its dependencies are ready:
	 *
	 * ```php
	 * $context->registerService(MyProvider::class, function ($c) { ... });
	 * \OC::$server->get(IntegrationRegistry::class)->addProvider(
	 *     \OC::$server->get(MyProvider::class)
	 * );
	 * ```
	 *
	 * Validation rules:
	 *   - duplicate id: first registration wins, second logs a warning;
	 *   - external storage without OpenConnector source: rejected with
	 *     a warning.
	 *
	 * @param IntegrationProvider $provider The provider to register.
	 *
	 * @return bool True when the provider was accepted, false when
	 *              rejected (duplicate or misconfigured).
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
	 */
	public function addProvider(IntegrationProvider $provider): bool {
		$id = $provider->getId();
		if (isset($this->providers[$id]) === true) {
			$this->logger->warning(
				sprintf(
					'[IntegrationRegistry] duplicate provider id "%s" — keeping first registration',
					$id
				)
			);
			return false;
		}

		// External providers MUST declare an OpenConnector source —
		// the registry rejects mis-configured externals so the
		// failure surfaces at boot, not on first call.
		if ($provider->getStorageStrategy() === 'external'
			&& $provider->getOpenConnectorSource() === null
		) {
			$this->logger->warning(
				sprintf(
					'[IntegrationRegistry] provider "%s" declares storage=external but no OpenConnector source — skipping',
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
	 * Useful for unit tests that don't want to spin up app bootstraps.
	 * Production code SHOULD use `addProvider()` instead.
	 *
	 * @param array<int, IntegrationProvider> $providers Provider instances.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
	 */
	public function withProviders(array $providers): void {
		$this->providers = [];
		foreach ($providers as $provider) {
			$this->addProvider(provider: $provider);
		}
	}//end withProviders()

	/**
	 * List every registered provider, irrespective of isEnabled().
	 *
	 * @return array<int, IntegrationProvider>
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
	 */
	public function list(): array {
		$this->ensureLeavesLoaded();
		return array_values($this->providers);
	}//end list()

	/**
	 * Return the ids of every registered provider.
	 *
	 * Used by `Schema::validateLinkedTypesValue()` (task 7) as the
	 * authoritative existence check; replaced the hardcoded public
	 * Schema linked-types constant via `cleanup-linked-entity-type-map`.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
	 */
	public function listIds(): array {
		$this->ensureLeavesLoaded();
		return array_keys($this->providers);
	}//end listIds()

	/**
	 * Look up a provider by id.
	 *
	 * @param string $id Stable integration id (e.g. 'files', 'xwiki').
	 *
	 * @return IntegrationProvider|null Provider, or null when unknown.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
	 */
	public function get(string $id): ?IntegrationProvider {
		$this->ensureLeavesLoaded();
		return $this->providers[$id] ?? null;
	}//end get()

	/**
	 * Whether the given id is a registered integration.
	 *
	 * Used by Schema::validateLinkedTypesValue() (task 7) as part of
	 * the registry-driven validation path. Distinct from `get()` to
	 * keep the schema validator's read intent obvious.
	 *
	 * @param string $id Stable integration id to check.
	 *
	 * @return bool True when the id is registered.
	 */
	public function isValidIntegrationId(string $id): bool {
		$this->ensureLeavesLoaded();
		return isset($this->providers[$id]);
	}//end isValidIntegrationId()

	/**
	 * Register a page-level widget contract.
	 *
	 * ADDITIVE: this surface is independent of the per-object provider
	 * registry above. A leaf app declares a renderable page widget — most
	 * commonly a chart fed by a pre-computed analytics series — so the
	 * nc-vue render layer can place it on a dashboard page. The descriptor
	 * is declarative + RBAC-scoped; it carries no per-request data.
	 *
	 * Expected descriptor shape (all but `id` optional, defaulted):
	 *   - `id`         string  — stable widget id (required).
	 *   - `type`       string  — render kind (default 'chart').
	 *   - `title`      ?string — display title.
	 *   - `providerId` ?string — the data provider/series key the render
	 *                            layer fetches data from.
	 *   - `config`     array   — declarative render config (chart type,
	 *                            axes, visibility scope, …).
	 *
	 * Duplicate id: first registration wins, second logs a warning —
	 * mirroring addProvider() so behaviour is predictable.
	 *
	 * @param array<string,mixed> $descriptor The page-widget descriptor.
	 *
	 * @return bool True when accepted, false when rejected (missing id or
	 *              duplicate).
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function registerPageWidget(array $descriptor): bool {
		$id = (string)($descriptor['id'] ?? '');
		if ($id === '') {
			$this->logger->warning('[IntegrationRegistry] page widget rejected — missing id');
			return false;
		}

		if (isset($this->pageWidgets[$id]) === true) {
			$this->logger->warning(
				sprintf(
					'[IntegrationRegistry] duplicate page widget id "%s" — keeping first registration',
					$id
				)
			);
			return false;
		}

		// Normalise the descriptor with declared defaults so consumers
		// always see a complete shape.
		$this->pageWidgets[$id] = [
			'id' => $id,
			'type' => (string)($descriptor['type'] ?? 'chart'),
			'title' => ($descriptor['title'] ?? null),
			'providerId' => ($descriptor['providerId'] ?? null),
			'config' => (array)($descriptor['config'] ?? []),
		];
		return true;
	}//end registerPageWidget()

	/**
	 * List every registered page-level widget descriptor.
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function listPageWidgets(): array {
		return array_values($this->pageWidgets);
	}//end listPageWidgets()

	/**
	 * Look up a page-level widget descriptor by id.
	 *
	 * @param string $id The widget id.
	 *
	 * @return array<string,mixed>|null The descriptor, or null when unknown.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	public function getPageWidget(string $id): ?array {
		return $this->pageWidgets[$id] ?? null;
	}//end getPageWidget()

	/**
	 * Return only the providers that are currently usable.
	 *
	 * A provider is "enabled" when `isEnabled()` returns true —
	 * typically meaning its required NC app is installed and external
	 * providers have their OpenConnector source configured.
	 *
	 * @return array<int, IntegrationProvider>
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
	 */
	public function getEnabled(): array {
		$this->ensureLeavesLoaded();
		$enabled = [];
		foreach ($this->providers as $provider) {
			if ($provider->isEnabled() === true) {
				$enabled[] = $provider;
			}
		}

		return $enabled;
	}//end getEnabled()
}//end class
