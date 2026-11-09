<?php

/**
 * OpenRegister Credential — Brokered Credentials
 *
 * The collaborators that read and write brokered credentials and their token sets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Credential
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Reading and writing brokered credentials: the broker that mints and calls
 * through them, the refresh service that holds their token sets, and the
 * object operations their non-secret records live in.
 *
 * Autowired; consumed by OAuth2AccountIdentity, OAuth2ConnectionWriter and
 * OAuth2InstanceClient (ADR-011 Rule 4).
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
 */
final class BrokeredCredentials {
	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService   $broker        Mints credentials and makes brokered calls.
	 * @param OAuth2RefreshService      $refresh       Reads and persists token sets.
	 * @param ObjectOperationsInterface $objectService Reads the credential objects.
	 */
	public function __construct(
		public readonly CredentialBrokerService $broker,
		public readonly OAuth2RefreshService $refresh,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
