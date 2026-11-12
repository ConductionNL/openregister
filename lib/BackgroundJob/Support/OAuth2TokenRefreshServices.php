<?php

/**
 * OpenRegister OAuth2 token refresh services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\OAuth2CredentialSweepInterface;
use OCA\OpenRegister\Service\Credential\ProviderCatalogueInterface;

/**
 * The token sets, the provider catalogue and the sweep that refreshes them.
 *
 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
 */
final readonly class OAuth2TokenRefreshServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface      $objectService Reads the token-set objects.
	 * @param ProviderCatalogueInterface     $catalogue     Provider catalogue.
	 * @param OAuth2CredentialSweepInterface $refresh       Refreshes one token set.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public ProviderCatalogueInterface $catalogue,
		public OAuth2CredentialSweepInterface $refresh,
	) {
	}//end __construct()
}//end class
