<?php

/**
 * OpenRegister CredentialSources
 *
 * Where the broker reads a credential from: its metadata object, its secret and its provider.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * Where the broker reads a credential from: its metadata object, its secret and its provider.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final readonly class CredentialSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService   OR object CRUD (loads credential metadata).
	 * @param CredentialStore           $credentialStore Secret store leaf (reads the injected secret).
	 * @param ProviderCatalogue         $catalogue       Read-only provider catalogue (host-lock + rules).
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public CredentialStore $credentialStore,
		public ProviderCatalogue $catalogue,
	) {
	}//end __construct()
}//end class
