<?php

/**
 * OpenRegister CredentialOutbound
 *
 * How the broker makes the outbound call: the HTTP client, the token refresh and the diagnostics log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * How the broker makes the outbound call: the HTTP client, the token refresh and the diagnostics log.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final readonly class CredentialOutbound {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IClientService            $clientService NC HTTP client factory (the outbound call).
	 * @param LoggerInterface           $logger        Logger for secret-free server-side diagnostics.
	 * @param OAuth2RefreshService|null $oauth2Refresh Refreshes and rotates an oauth2-token-set credential; absent, that path denies.
	 */
	public function __construct(
		public IClientService $clientService,
		public LoggerInterface $logger,
		public ?OAuth2RefreshService $oauth2Refresh = null,
	) {
	}//end __construct()
}//end class
