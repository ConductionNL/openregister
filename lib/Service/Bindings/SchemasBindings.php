<?php

/**
 * The contract bindings of schemas.
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

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftsInterface;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCA\OpenRegister\Service\Schema\SchemaRevalidatorInterface;

/**
 * Contract => service map for schemas.
 *
 * ContractBindings::BINDINGS spreads this map, so an entry here is registered
 * as an alias exactly like an entry written in ContractBindings itself. It
 * exists so that lanes adding bindings for different areas edit different
 * files.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SchemasBindings {

	/**
	 * Contract => the service that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		\OCA\OpenRegister\Service\Schemas\ExtendingFormValidatorInterface::class => \OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration::class,
		ConfigurationDraftsInterface::class => ConfigurationDraftService::class,
		SchemaRevalidatorInterface::class => SchemaRevalidationService::class,
	];
}//end class
