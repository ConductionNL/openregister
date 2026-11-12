<?php

/**
 * OpenRegister FederatedConfigTransport
 *
 * The credential, signing and HTTP collaborators federated config sharing talks through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
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

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClientService;

/**
 * The credential, signing and HTTP collaborators federated config sharing talks through.
 *
 * Groups collaborators of {@see FederatedConfigService} so its constructor stays small;
 * the container autowires it.
 */
final class FederatedConfigTransport {

	/**
	 * Bundle the collaborators.
	 *
	 * @param CredentialBrokerService $broker        Custodies the GitHub token (Doriath's vault where installed, the NC vault otherwise).
	 * @param BundleSigner            $signer        Signs published bundles and verifies installed ones.
	 * @param IClientService          $clientService NC HTTP client factory (anonymous discovery).
	 */
	public function __construct(
		public readonly CredentialBrokerService $broker,
		public readonly BundleSigner $signer,
		public readonly IClientService $clientService,
	) {
	}//end __construct()
}//end class
