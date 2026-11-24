<?php

/**
 * OpenRegister AppHost — credential onboarding collaborators
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\AppHost\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Repair;

use OCA\OpenRegister\Service\Credential\CredentialAppTokenServiceInterface;
use OCA\OpenRegister\Service\Credential\DoriathApplicationRegistrarInterface;
use OCP\App\IAppManager;

/**
 * The optional collaborators {@see GenericInitializeSettings} uses for the
 * credential-broker onboarding hook. Each one left null is resolved lazily
 * through `OCP\Server::get` at the moment it is needed.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialOnboarding {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppManager|null                          $appManager           Locates the leaf's bundled manifest.
	 * @param CredentialAppTokenServiceInterface|null   $tokenService         Credential-broker app registry.
	 * @param DoriathApplicationRegistrarInterface|null $applicationRegistrar Per-app Doriath application registrar.
	 */
	public function __construct(
		public readonly ?IAppManager $appManager = null,
		public readonly ?CredentialAppTokenServiceInterface $tokenService = null,
		public readonly ?DoriathApplicationRegistrarInterface $applicationRegistrar = null,
	) {
	}//end __construct()
}//end class
