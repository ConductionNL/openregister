<?php

/**
 * Discovery Capability
 *
 * Publishes, to ANONYMOUS callers of `/ocs/v2.php/cloud/capabilities`, which
 * interoperability standards the apps on this instance provide or consume and
 * where their public entry points are. This is how another instance (or a tool
 * such as Nextcloud Peek) learns whether it can connect, without logging in.
 *
 * Output shape:
 *
 *   discovery:
 *     contractVersion: 1
 *     provider: openregister
 *     apps: [decidiq, opencatalogi, …]
 *   <appId>:
 *     discovery:
 *       contractVersion: 1
 *       standards: [{id, name, role, access, version?, endpoint?, specUrl?}]
 *       links?: {directory: /apps/opencatalogi/api/directory, …}
 *       ocm?: [dossiq-case, …]
 *
 * Each app gets its own top-level key because that key is what capability
 * readers present as "the app": Peek draws one card per key. The keys merge
 * recursively with an app's own capabilities, so an app that already publishes
 * `thematiq.*` keeps its block next to `thematiq.discovery`.
 *
 * Deliberately NOT published: app versions (fingerprinting), endpoints of
 * login-only entries, register or schema names, object counts. App versions
 * are already public through `status.php` for the server as a whole; per-app
 * versions would turn the capability into a vulnerability index.
 *
 * Excluded from page-load initial state: the payload is only useful to remote
 * callers, so the web UI should not pay for it on every page.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
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

namespace OCA\OpenRegister\Capabilities;

use OCA\OpenRegister\AppHost\Discovery\DiscoveryCatalog;
use OCA\OpenRegister\AppHost\Discovery\DiscoveryManifest;
use OCP\Capabilities\IInitialStateExcludedCapability;
use OCP\Capabilities\IPublicCapability;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public, manifest-driven discovery capability for every enabled app.
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */
class DiscoveryCapability implements IPublicCapability, IInitialStateExcludedCapability {
	/**
	 * Constructor.
	 *
	 * @param DiscoveryCatalog $catalog Enabled apps' discovery declarations.
	 * @param LoggerInterface $logger Logs a failure instead of breaking the endpoint.
	 */
	public function __construct(
		private readonly DiscoveryCatalog $catalog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the public discovery capability.
	 *
	 * Never throws: the capabilities endpoint is shared by every app and every
	 * client, so a broken manifest must cost this block, not the whole answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function getCapabilities(): array {
		try {
			$documents = $this->catalog->publicDocuments();
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[DiscoveryCapability] discovery capability skipped: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
			return [];
		}

		if ($documents === []) {
			return [];
		}

		$capabilities = [
			'discovery' => [
				'contractVersion' => DiscoveryManifest::CONTRACT_VERSION,
				'provider' => 'openregister',
				'apps' => array_keys($documents),
			],
		];
		foreach ($documents as $appId => $payload) {
			$capabilities[$appId] = ['discovery' => $payload];
		}

		return $capabilities;
	}//end getCapabilities()
}//end class
