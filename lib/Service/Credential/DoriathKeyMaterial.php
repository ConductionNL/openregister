<?php

/**
 * DoriathKeyMaterial — where the Doriath leaf reads OpenRegister's key material.
 *
 * A plain constructor bundle wired by DI autowiring: the system-scoped
 * `ICredentialsManager` that holds OpenRegister's private key PEM, and the
 * `IAppConfig` that holds the Doriath application UUID and OpenRegister's
 * public key PEM. {@see DoriathCredentialStore} receives them as one
 * collaborator; every read of a key stays in that store, and nothing here
 * reads, logs or exposes a secret.
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

use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;

/**
 * Bundle of the private-key vault and the application config of the Doriath leaf.
 *
 * @spec openspec/specs/credential-broker/spec.md#doriath-backed-secret-custody
 */
final class DoriathKeyMaterial {
	/**
	 * Bundle the key-material sources.
	 *
	 * @param ICredentialsManager $credentialsManager NC vault holding OR's private key (system-scoped).
	 * @param IAppConfig          $appConfig          Holds the Doriath application UUID + public PEM.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function __construct(
		public readonly ICredentialsManager $credentialsManager,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
