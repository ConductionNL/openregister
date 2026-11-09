<?php

/**
 * The contract bindings of services that belong to no other map.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Bindings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Bindings;

use OCA\OpenRegister\Service\Flow\FlowConsentParking;
use OCA\OpenRegister\Service\Flow\FlowConsentSweepInterface;

/**
 * Contract => service map for services that belong to no other map.
 *
 * ContractBindings::BINDINGS spreads this map, so an entry here is registered
 * as an alias exactly like an entry written in ContractBindings itself. It
 * exists so that lanes adding bindings for different areas edit different
 * files.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class MiscBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		FlowConsentSweepInterface::class => FlowConsentParking::class,
		\OCA\OpenRegister\Kernel\Contracts\CredentialAppTokenServiceInterface::class => \OCA\OpenRegister\Service\Credential\CredentialAppTokenService::class,
		\OCA\OpenRegister\Kernel\Contracts\DoriathApplicationRegistrarInterface::class => \OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrar::class,
		\OCA\OpenRegister\Kernel\Contracts\OAuth2ConnectionRepositoryInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository::class,
		\OCA\OpenRegister\Kernel\Contracts\OAuth2CredentialSweepInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2RefreshService::class,
		\OCA\OpenRegister\Kernel\Contracts\OAuth2EndpointsInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2Endpoints::class,
		\OCA\OpenRegister\Kernel\Contracts\OAuth2InstanceClientInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2InstanceClient::class,
		\OCA\OpenRegister\Kernel\Contracts\OAuth2RelayGuardInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2RelayGuard::class,
		\OCA\OpenRegister\Kernel\Contracts\OAuth2StateServiceInterface::class => \OCA\OpenRegister\Service\Credential\OAuth2StateService::class,
		\OCA\OpenRegister\Kernel\Contracts\ProviderCatalogueInterface::class => \OCA\OpenRegister\Service\Credential\ProviderCatalogue::class,
		\OCA\OpenRegister\Kernel\Contracts\ConnectionReporterInterface::class => \OCA\OpenRegister\Service\Connection\ConnectionReporter::class,
		\OCA\OpenRegister\Kernel\Contracts\ProxySettingsInterface::class => \OCA\OpenRegister\Service\Outbound\ProxySettings::class,
		\OCA\OpenRegister\Kernel\Contracts\SecurityTxtBuilderInterface::class => \OCA\OpenRegister\Service\WellKnown\SecurityTxtBuilder::class,
		\OCA\OpenRegister\Kernel\Contracts\AnalyticsSeriesServiceInterface::class => \OCA\OpenRegister\Service\AnalyticsSeriesService::class,
	];
}//end class
