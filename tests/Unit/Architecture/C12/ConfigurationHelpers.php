<?php

/**
 * C12 cases: the configuration helpers the register and federated-config endpoints reach.
 *
 * FederatedConfigController and its services bundle type against
 * FederatedConfigAccessInterface; RegistersController builds the bundle
 * binding check and looks the import job recorder up (under its class id)
 * through ConfigurationCollaborators. AppImportJobRecorder has no interface:
 * the controller never needs its type.
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
		Configuration\AppImportJobRecorder::class => null,
		Configuration\RegisterBundleBinding::class => Configuration\RegisterBundleBindingInterface::class,
	],
	'kept' => [],
];
