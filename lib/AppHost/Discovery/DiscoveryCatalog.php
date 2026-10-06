<?php

/**
 * AppHost Discovery Catalog
 *
 * Collects the `discovery` manifest blocks of every enabled app on this
 * instance, applies the admin's switches, and serves the result to the public
 * capability and the Open Cloud Mesh resource-type listener.
 *
 * WHY CACHED. `/ocs/v2.php/cloud/capabilities` is called by every desktop and
 * mobile client on every sync, and Nextcloud logs any capability that takes
 * longer than 0.1 s. Reading a manifest from disk for each enabled app on each
 * of those calls would be the slowest capability on the instance. The result
 * is cached in the local memory cache under a key derived from the enabled app
 * set and their versions, so enabling, disabling or upgrading an app
 * invalidates it by construction and no explicit purge is needed. The admin's
 * switches are applied after the cache, so flipping one takes effect at once.
 *
 * Admin switches (app config of `openregister`, set with `occ config:app:set`):
 *  - `discovery_public` (`yes`|`no`, default `yes`) — publish anything at all.
 *  - `discovery_hidden_apps` (comma separated app ids) — never list these.
 * The OCM resource types are NOT gated by these switches: they are only
 * published when the admin has Open Cloud Mesh federation enabled, which is
 * Nextcloud's own switch, and they announce what a trusted peer can share.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Discovery
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Discovery;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Instance-wide view of every enabled app's discovery declaration.
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */
class DiscoveryCatalog {
	/**
	 * App config key: publish the discovery capability at all.
	 *
	 * @var string
	 */
	public const CONFIG_PUBLIC = 'discovery_public';

	/**
	 * App config key: comma-separated app ids that are never listed.
	 *
	 * @var string
	 */
	public const CONFIG_HIDDEN_APPS = 'discovery_hidden_apps';

	/**
	 * Cache lifetime in seconds. The key already changes with the app set, so
	 * this only bounds how long a stale entry can linger after a manifest is
	 * edited in place on a development checkout.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 3600;

	/**
	 * Memoised manifests for this request, keyed by the cache signature.
	 *
	 * @var array<string, array<string, DiscoveryManifest>>
	 */
	private array $memo = [];

	/**
	 * Lazily created local cache.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ManifestLoader $manifestLoader Reads each app's bundled manifest.
	 * @param IAppManager $appManager Enumerates enabled apps and versions.
	 * @param IAppConfig $appConfig Holds the admin switches.
	 * @param ICacheFactory $cacheFactory Local memory cache.
	 * @param LoggerInterface $logger Reports dropped manifest entries.
	 */
	public function __construct(
		private readonly ManifestLoader $manifestLoader,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the admin allows publishing the discovery capability.
	 *
	 * @return bool
	 */
	public function isPublicationEnabled(): bool {
		return $this->appConfig->getValueString('openregister', self::CONFIG_PUBLIC, 'yes') !== 'no';
	}//end isPublicationEnabled()

	/**
	 * The public payload per app id, for apps that opted in and are not hidden.
	 *
	 * @return array<string, array<string, mixed>> Map of app id to `discovery` payload.
	 */
	public function publicDocuments(): array {
		if ($this->isPublicationEnabled() === false) {
			return [];
		}

		$hidden = $this->hiddenApps();
		$documents = [];
		foreach ($this->manifests() as $appId => $manifest) {
			if ($manifest->public === false || in_array($appId, $hidden, true) === true) {
				continue;
			}

			$documents[$appId] = $manifest->toPublicPayload();
		}

		ksort($documents);
		return $documents;
	}//end publicDocuments()

	/**
	 * Every OCM resource type declared by an enabled app.
	 *
	 * @return array<int, array{appId: string, name: string, shareTypes: array<int, string>, protocols: array<string, string>}>
	 */
	public function ocmResourceTypes(): array {
		$types = [];
		$seen = [];
		foreach ($this->manifests() as $appId => $manifest) {
			foreach ($manifest->ocmResourceTypes as $type) {
				// First declaration wins; a second app claiming the same name is a
				// conflict to report, not a type to register twice.
				if (isset($seen[$type['name']]) === true) {
					$this->logger->warning(
						message: sprintf(
							'[AppHost\\DiscoveryCatalog] OCM resource type "%s" of "%s" ignored: already declared by "%s"',
							$type['name'],
							$appId,
							$seen[$type['name']]
						),
						context: ['file' => __FILE__, 'line' => __LINE__]
					);
					continue;
				}

				$seen[$type['name']] = $appId;
				$types[] = ['appId' => $appId] + $type;
			}
		}

		return $types;
	}//end ocmResourceTypes()

	/**
	 * Parsed discovery manifests of every enabled app that declares a block.
	 *
	 * @return array<string, DiscoveryManifest>
	 */
	public function manifests(): array {
		$signature = $this->signature();
		if (isset($this->memo[$signature]) === true) {
			return $this->memo[$signature];
		}

		$cached = $this->cache()?->get($signature);
		if (is_array($cached) === true) {
			$manifests = [];
			foreach ($cached as $appId => $data) {
				$manifests[$appId] = DiscoveryManifest::fromManifest(appId: (string)$appId, manifest: ['discovery' => $data]);
			}

			return $this->memo[$signature] = $manifests;
		}

		$manifests = [];
		$raw = [];
		foreach ($this->enabledApps() as $appId) {
			$manifest = $this->manifestLoader->loadDiscovery(appId: $appId);
			foreach ($manifest->getProblems() as $problem) {
				$this->logger->warning(
					message: sprintf('[AppHost\\DiscoveryCatalog] %s manifest discovery.%s (entry ignored)', $appId, $problem),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}

			if ($manifest->isEmpty() === true) {
				continue;
			}

			$manifests[$appId] = $manifest;
			$raw[$appId] = [
				'public' => $manifest->public,
				'standards' => $manifest->standards,
				'links' => $manifest->links,
				'ocmResourceTypes' => $manifest->ocmResourceTypes,
			];
		}

		$this->cache()?->set($signature, $raw, self::CACHE_TTL);
		return $this->memo[$signature] = $manifests;
	}//end manifests()

	/**
	 * Ids of the enabled apps, sorted for a stable signature.
	 *
	 * @return array<int, string>
	 */
	private function enabledApps(): array {
		try {
			$apps = array_values(array_filter($this->appManager->getEnabledApps(), 'is_string'));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AppHost\\DiscoveryCatalog] could not list enabled apps: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		sort($apps);
		return $apps;
	}//end enabledApps()

	/**
	 * Cache key covering every input that changes the answer.
	 *
	 * @return string
	 */
	private function signature(): string {
		$parts = [];
		foreach ($this->enabledApps() as $appId) {
			$parts[] = $appId . '@' . $this->manifestLoader->appVersion(appId: $appId);
		}

		return 'discovery-' . DiscoveryManifest::CONTRACT_VERSION . '-' . md5(implode(',', $parts));
	}//end signature()

	/**
	 * App ids the admin chose never to list.
	 *
	 * @return array<int, string>
	 */
	private function hiddenApps(): array {
		$raw = $this->appConfig->getValueString('openregister', self::CONFIG_HIDDEN_APPS, '');
		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $id): bool => $id !== ''));
	}//end hiddenApps()

	/**
	 * The local cache, or null when the instance has no memory cache configured.
	 *
	 * @return ICache|null
	 */
	private function cache(): ?ICache {
		if ($this->cache === null && $this->cacheFactory->isLocalCacheAvailable() === true) {
			$this->cache = $this->cacheFactory->createLocal('openregister-discovery');
		}

		return $this->cache;
	}//end cache()
}//end class
