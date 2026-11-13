<?php

/**
 * VaultReference — where an outside credential's secret lives (credential-outside-vault-reference D-2).
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

use InvalidArgumentException;

/**
 * A credential's `vaultRef`: the outside-vault connection, the secret path and the key.
 *
 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
 */
final class VaultReference {

	/**
	 * Constructor.
	 *
	 * @param string $connection The uuid of the outside-vault connection credential.
	 * @param string $path       The secret path under the connection's mount, such as integriq/zaaksysteem.
	 * @param string $key        The key within that secret, such as password.
	 *
	 * @throws InvalidArgumentException When a part is empty or the path leaves its mount.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function __construct(
		public readonly string $connection,
		public readonly string $path,
		public readonly string $key,
	) {
		if (trim($connection) === '' || trim($key) === '') {
			throw new InvalidArgumentException('A vault reference names its connection and key');
		}

		if (preg_match('/^[A-Za-z0-9_.-]+(\/[A-Za-z0-9_.-]+)*$/', $path) !== 1 || preg_match('/(^|\/)\.\.?(\/|$)/', $path) === 1) {
			throw new InvalidArgumentException('A vault path is a plain relative path');
		}
	}//end __construct()

	/**
	 * A reference from a credential's stored `vaultRef`.
	 *
	 * @param array<string, mixed> $vaultRef The stored reference.
	 *
	 * @return self The reference.
	 *
	 * @throws InvalidArgumentException When a part is missing or malformed.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public static function fromArray(array $vaultRef): self {
		return new self(
			connection: (string) ($vaultRef['connection'] ?? ''),
			path: (string) ($vaultRef['path'] ?? ''),
			key: (string) ($vaultRef['key'] ?? '')
		);
	}//end fromArray()
}//end class
