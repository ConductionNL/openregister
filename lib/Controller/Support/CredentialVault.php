<?php

/**
 * OpenRegister credential domain
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

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The domain services of the credential API.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class CredentialVault {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface           $objectService   OR object CRUD for credential metadata.
	 * @param CredentialStore         $credentialStore Secret store leaf (vault).
	 * @param ProviderCatalogueInterface $catalogue       Read-only provider catalogue (validation).
	 * @param CredentialBrokerService $broker          The guarded outbound broker.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly CredentialStore $credentialStore,
		public readonly ProviderCatalogueInterface $catalogue,
		public readonly CredentialBrokerService $broker,
	) {
	}//end __construct()
}//end class
