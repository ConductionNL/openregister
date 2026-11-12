<?php

/**
 * Registers a consuming app as its own identity-only Doriath application.
 *
 * GenericInitializeSettings (lib/AppHost) types against this interface;
 * ContractBindings aliases it to DoriathApplicationRegistrar.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Registers a consuming app as its own identity-only Doriath application.
 */
interface DoriathApplicationRegistrarInterface {

	/**
	 * Register the given consuming app as its own identity-only Doriath application.
	 *
	 * Never throws; idempotent.
	 *
	 * @param string      $appId       The consuming app id (becomes the Doriath application name).
	 * @param string|null $description The manifest description (falls back to the appId).
	 *
	 * @return void
	 */
	public function registerApplication(string $appId, ?string $description = null): void;
}//end interface
