<?php

/**
 * C12 cases: the configuration helpers the register and federated-config endpoints reach.
 *
 * FederatedConfigController and its services bundle type against
 * FederatedConfigAccessInterface. RegistersController reaches the bundle
 * binding check through the Core port RegisterBundlesInterface (answered by
 * RegisterBundles) and the import job recorder through the Core port
 * AppImportJobOwnersInterface, so it names neither class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

use OCA\OpenRegister\Service\Config;
use OCA\OpenRegister\Service\Configuration;

return [
	'classes' => [
		Config\FederatedConfigAccess::class => Config\FederatedConfigAccessInterface::class,
		Configuration\RegisterBundleBinding::class => Configuration\RegisterBundleBindingInterface::class,
	],
	'kept' => [],
];
