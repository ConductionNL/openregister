<?php

/**
 * ConnectorRoutedProviderTrait: the descriptor, enable check and health of a
 * provider whose calls go through the connector app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * Descriptor methods shared by the connector-backed integration providers.
 *
 * The using provider declares the constants `SOURCE_ID` (its connector
 * source) and `REQUIRED_APP` (the connector app), and fills the router, app
 * manager and localisation, from a ConnectorRouting through useRouting() or
 * as constructor-promoted properties of the same name and type.
 */
trait ConnectorRoutedProviderTrait {

	/**
	 * External-call router.
	 *
	 * @var ExternalIntegrationRouterInterface
	 */
	private ExternalIntegrationRouterInterface $router;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Take the router, app manager and localisation from the routing bundle.
	 *
	 * @param ConnectorRouting $routing The router, app manager and localisation.
	 *
	 * @return void
	 */
	private function useRouting(ConnectorRouting $routing): void {
		$this->router     = $routing->router;
		$this->appManager = $routing->appManager;
		$this->l10n       = $routing->l10n;
	}//end useRouting()

	/**
	 * Named group this integration belongs to (AD-16).
	 *
	 * @return string|null
	 *
	 * @spec exclude Provider descriptor shared by the connector-backed providers; each provider spec owns the values.
	 */
	public function getGroup(): ?string {
		return 'external';
	}//end getGroup()

	/**
	 * Nextcloud app that must be installed for this integration to function.
	 *
	 * Returns the id the instance ACTUALLY registered, not the canonical name.
	 * The value is published verbatim in the `integrations` capability and
	 * read back client-side as `isAppInstalled(requiredApp)`, so a spelling the
	 * instance does not answer to renders "not installed" over a connector that
	 * is installed and working.
	 *
	 * @return string|null
	 *
	 * @spec exclude Provider descriptor shared by the connector-backed providers; each provider spec owns the values.
	 */
	public function getRequiredApp(): ?string {
		return (FleetAppId::resolve($this->appManager, self::REQUIRED_APP) ?? self::REQUIRED_APP);
	}//end getRequiredApp()

	/**
	 * Storage strategy (AD-22): `external`, the data lives behind the connector source.
	 *
	 * @return string
	 *
	 * @spec exclude Provider descriptor shared by the connector-backed providers; each provider spec owns the values.
	 */
	public function getStorageStrategy(): string {
		return 'external';
	}//end getStorageStrategy()

	/**
	 * Connector source id this provider routes its calls through (AD-4).
	 *
	 * @return string|null
	 *
	 * @spec exclude Provider descriptor shared by the connector-backed providers; each provider spec owns the values.
	 */
	public function getOpenConnectorSource(): ?string {
		return self::SOURCE_ID;
	}//end getOpenConnectorSource()

	/**
	 * Whether the integration is available: true when the connector app is installed.
	 *
	 * @return bool
	 *
	 * @spec exclude Provider descriptor shared by the connector-backed providers; each provider spec owns the values.
	 */
	public function isEnabled(): bool {
		return FleetAppId::isInstalled($this->appManager, self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * Health descriptor: the router's probe, so admin UI and OCS capabilities
	 * report the same status runtime callers would see.
	 *
	 * @return array{status: string, authStatus: string, message: ?string}
	 *
	 * @spec exclude Thin delegation to ExternalIntegrationRouter::probe
	 *              (annotated to pluggable-integration-registry task-4);
	 *              carries no provider-specific health behaviour.
	 */
	public function health(): array {
		return $this->router->probe(provider: $this);
	}//end health()
}//end trait
