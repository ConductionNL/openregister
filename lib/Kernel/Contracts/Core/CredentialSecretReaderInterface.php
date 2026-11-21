<?php
/**
 * CredentialSecretReaderInterface: read a stored credential secret, as Core asks it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The read side of the credential store, for Core code that opens a connection.
 *
 * Core (the database connection factory) only needs to read the secret a source
 * references; Integrations keeps the store. The Integrations CredentialStore
 * extends this interface, so every store already answers it.
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
interface CredentialSecretReaderInterface {

	/**
	 * Retrieve the secret for a credential, or null when none is stored.
	 *
	 * @param string $uuid  The owning `credential` object's UUID (the vault key).
	 * @param string $scope The credential scope (`personal`|`organisation`); selects the vault owner.
	 *
	 * @return string|null The raw secret, or null when absent. Never logged.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function get(string $uuid, string $scope = 'personal'): ?string;
}//end interface
