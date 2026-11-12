<?php

/**
 * Where an OAuth2 refresh reads and writes the credential it renews.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * A dependency bundle of {@see OAuth2RefreshService}.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md
 */
final class OAuth2RefreshStores {
	/**
	 * Bundle the services.
	 *
	 * @param CredentialStore           $credentialStore Holds the token set; the only place it is ever written.
	 * @param ObjectOperationsInterface $objectService   Updates the credential's non-secret metadata.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md
	 */
	public function __construct(
		public readonly CredentialStore $credentialStore,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
