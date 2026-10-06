<?php

/**
 * OcmResourceTypeListener — advertise OCM resource types in OCM discovery.
 *
 * Nextcloud builds its Open Cloud Mesh discovery document (served at
 * `/.well-known/ocm` and the legacy `/ocm-provider/`) by firing a
 * {@see ResourceTypeRegisterEvent}; apps that accept federated shares of their
 * own resource type register it here so remote instances know which shares
 * this instance accepts.
 *
 * Two sources:
 *  - the built-in `openregister` type (routed to OpenRegisterCloudFederationProvider);
 *  - every `discovery.ocmResourceTypes[]` entry an enabled app declares in its
 *    `src/manifest.json` (see {@see DiscoveryCatalog}).
 *
 * A declared type is only registered when a cloud federation provider for that
 * name exists. Announcing a type nobody handles would invite shares that are
 * then refused, which is worse than not announcing it.
 *
 * WHY ONLY THE PRE-33 EVENT. Nextcloud 33 deprecates `ResourceTypeRegisterEvent`
 * in favour of `LocalOCMDiscoveryEvent`, but 33 and 34 still dispatch BOTH, one
 * after the other, on the same provider object. Listening to both would
 * register every type twice. While the minimum supported version is 32 this
 * listener stays on the old event; move it when the floor reaches 33.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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
 * @template-implements IEventListener<ResourceTypeRegisterEvent>
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\AppHost\Discovery\DiscoveryCatalog;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\OCM\Events\ResourceTypeRegisterEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers the `openregister` type and every manifest-declared OCM type.
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */
class OcmResourceTypeListener implements IEventListener {
	/**
	 * The built-in resource type, handled by OpenRegisterCloudFederationProvider.
	 *
	 * @var string
	 */
	public const OPENREGISTER_TYPE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param DiscoveryCatalog $catalog Enabled apps' discovery declarations.
	 * @param ICloudFederationProviderManager $providerManager Tells whether a type has a handler.
	 * @param LoggerInterface $logger Reports skipped declarations.
	 */
	public function __construct(
		private readonly DiscoveryCatalog $catalog,
		private readonly ICloudFederationProviderManager $providerManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the resource-type registration event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ResourceTypeRegisterEvent) === false) {
			return;
		}

		$event->registerResourceType(
			self::OPENREGISTER_TYPE,
			['user', 'group'],
			['openregister' => '/apps/openregister/api/federation']
		);

		try {
			$declared = $this->catalog->ocmResourceTypes();
		} catch (Throwable $e) {
			// OCM discovery is served to every federated peer; a broken manifest
			// must cost the declared types, never the whole discovery document.
			$this->logger->error(
				message: '[OcmResourceTypeListener] manifest-declared OCM types skipped: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);
			return;
		}

		foreach ($declared as $type) {
			if ($type['name'] === self::OPENREGISTER_TYPE) {
				continue;
			}

			if ($this->hasProvider(name: $type['name']) === false) {
				$this->logger->info(
					message: sprintf(
						'[OcmResourceTypeListener] OCM type "%s" declared by "%s" not announced: no cloud federation provider handles it',
						$type['name'],
						$type['appId']
					),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			$event->registerResourceType($type['name'], $type['shareTypes'], $type['protocols']);
		}
	}//end handle()

	/**
	 * Whether a cloud federation provider is registered for a resource type.
	 *
	 * @param string $name The OCM resource type.
	 *
	 * @return bool
	 */
	private function hasProvider(string $name): bool {
		try {
			$this->providerManager->getCloudFederationProvider($name);
			return true;
		} catch (Throwable $e) {
			return false;
		}
	}//end hasProvider()
}//end class
