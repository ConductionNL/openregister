<?php

/**
 * The services a connector-routed integration provider reaches its upstream through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCP\App\IAppManager;
use OCP\IL10N;

/**
 * The routing bundle of a connector-routed integration provider: the router
 * that carries its calls through the connector app, the app manager that says
 * whether the connector app is installed, and the localisation of its labels.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class ConnectorRouting {
	/**
	 * Bundle the services.
	 *
	 * @param ExternalIntegrationRouterInterface $router     External-call router.
	 * @param IAppManager                        $appManager NC app manager.
	 * @param IL10N                              $l10n       Localisation.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public function __construct(
		public readonly ExternalIntegrationRouterInterface $router,
		public readonly IAppManager $appManager,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
