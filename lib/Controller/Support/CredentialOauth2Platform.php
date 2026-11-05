<?php

/**
 * OpenRegister credential oauth2 platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the credential oauth2 API.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final class CredentialOauth2Platform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession    $userSession The current session.
	 * @param IThrottler      $throttler   Registers failed callback attempts (ADR-082).
	 * @param LoggerInterface $logger      Secret-free diagnostics.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IThrottler $throttler,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
