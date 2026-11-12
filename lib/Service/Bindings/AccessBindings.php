<?php

/**
 * The contract bindings of access control.
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

use OCA\OpenRegister\Service\Credential\OAuth2InstanceHost;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceHostInterface;
use OCA\OpenRegister\Service\Party\PartySearchInterface;
use OCA\OpenRegister\Service\Party\PartySearchService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;

/**
 * Contract => service map for access control.
 *
 * ContractBindings::BINDINGS spreads this map, so an entry here is registered
 * as an alias exactly like an entry written in ContractBindings itself. It
 * exists so that lanes adding bindings for different areas edit different
 * files.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AccessBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		DenyEnforcementModeInterface::class => DenyEnforcementMode::class,
		PartySearchInterface::class => PartySearchService::class,
		OAuth2InstanceHostInterface::class => OAuth2InstanceHost::class,
	];
}//end class
