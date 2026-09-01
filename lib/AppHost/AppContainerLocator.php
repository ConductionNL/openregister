<?php

/**
 * Reaches another app's own DI container.
 *
 * Nextcloud app containers are ISOLATED: OpenRegister's container never sees a
 * leaf app's registrations, so an alias a leaf registered — `IMetricsProvider::
 * <appId>`, its health checks, its MCP tool provider — resolves only from that
 * app's own container. Looking it up in OpenRegister's container silently
 * returns nothing, which reads as "the app contributes none" rather than as a
 * failed lookup (#390).
 *
 * WHY THIS IS A COLLABORATOR AND NOT A PRIVATE METHOD
 * ---------------------------------------------------
 * It was a private method — three times over, in `ProviderMetricSource`,
 * `HealthCheckExecutor` and `Application`, each with its own copy of the same
 * body and the same phpcs ignore. Three copies of one rule is three places for
 * it to drift, and none of them could be tested: a private call to
 * `\OC::$server` has no seam, so the tests around them had to mock a container
 * the code did not consult and passed only where the real app was absent.
 *
 * Injecting the lookup makes the topology substitutable, which is the whole
 * point — a test can say "this app has a container, and here is what is in it"
 * without depending on which apps the developer happens to have installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds a leaf app's registered DI container.
 *
 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
 */
class AppContainerLocator {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The app's own container, or null when it has none.
	 *
	 * Fail-soft by design, and null rather than a throw: an app that was never
	 * bootstrapped is the ordinary case during a scrape across every installed
	 * app, not an error. Callers decide what absence means — a metrics source
	 * falls back to its own container so one misbehaving app cannot fatal the
	 * whole scrape, while the MCP catalog skips the app entirely.
	 *
	 * @param string $appId The app id whose container is wanted.
	 *
	 * @return ContainerInterface|null The app's container, or null.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
	 */
	public function find(string $appId): ?ContainerInterface {
		try {
			// Reaching ANOTHER app's DI container has no OCP equivalent — \OCP\Server::get()
			// resolves the server container only. The sniff says this is removed in NC 34,
			// but it is not: core itself calls it in lib/public/AppFramework/App.php. Scoped
			// ignore rather than a blanket one, so any OTHER legacy accessor still fails.
			// phpcs:ignore CustomSniffs.Nextcloud.NoLegacyServerAccessors.LegacyNamedAccessor
			$appContainer = \OC::$server->getRegisteredAppContainer($appId);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: sprintf('[AppHost] no registered app container for "%s": %s', $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => $appId]
			);

			return null;
		}

		if (($appContainer instanceof ContainerInterface) === true) {
			return $appContainer;
		}

		return null;
	}//end find()

	/**
	 * The app's own container, or the given one when it has none.
	 *
	 * The shape two of the three call sites want: they must always have SOME
	 * container to ask, because returning nothing would turn "this app was not
	 * bootstrapped" into "this app reported no metrics", which is the exact
	 * confusion #390 was about.
	 *
	 * @param string $appId The app id whose container is wanted.
	 * @param ContainerInterface $fallback The container to use when the app has none.
	 *
	 * @return ContainerInterface The app's container, or the fallback.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md#requirement-a-leaf-apps-provider-must-be-resolved-from-that-apps-own-container
	 */
	public function findOr(string $appId, ContainerInterface $fallback): ContainerInterface {
		return ($this->find(appId: $appId) ?? $fallback);
	}//end findOr()
}//end class
