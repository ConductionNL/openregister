<?php

/**
 * OutsideVaultConnection — how the broker reaches an outside vault (credential-outside-vault-reference D-2).
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
 * An outside-vault connection: the vault's address, how to log in, and the KV v2 mount.
 *
 * The login secret (a token, or an AppRole secret id) is the connection
 * credential's own secret, held in the broker's custody leaf; this object
 * carries it only for the duration of one read.
 *
 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
 */
final class OutsideVaultConnection {

	/**
	 * Log in with a token: the secret is the token.
	 */
	public const AUTH_TOKEN = 'token';

	/**
	 * Log in with AppRole: the secret is the secret id, with the role id.
	 */
	public const AUTH_APPROLE = 'approle';

	/**
	 * Constructor.
	 *
	 * @param string      $baseUrl    The vault's address, such as https://vault.example.
	 * @param string      $authMethod One of the AUTH_* methods.
	 * @param string      $secret     The token or the AppRole secret id.
	 * @param string|null $roleId     The AppRole role id; null for a token.
	 * @param string      $mount      The KV v2 mount the references read from.
	 *
	 * @throws InvalidArgumentException When the address is not https or http, the method is unknown, or AppRole lacks a role id.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function __construct(
		public readonly string $baseUrl,
		public readonly string $authMethod,
		public readonly string $secret,
		public readonly ?string $roleId=null,
		public readonly string $mount='secret',
	) {
		$scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
		if (in_array($scheme, ['https', 'http'], true) === false || (string) parse_url($baseUrl, PHP_URL_HOST) === '') {
			throw new InvalidArgumentException('An outside vault needs an http or https address');
		}

		if (in_array($authMethod, [self::AUTH_TOKEN, self::AUTH_APPROLE], true) === false) {
			throw new InvalidArgumentException('An outside vault logs in with a token or AppRole');
		}

		if ($authMethod === self::AUTH_APPROLE && trim((string) $roleId) === '') {
			throw new InvalidArgumentException('AppRole login needs a role id');
		}

		if (preg_match('/^[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)*$/', $mount) !== 1) {
			throw new InvalidArgumentException('The vault mount must be a plain path');
		}
	}//end __construct()

	/**
	 * The vault address without a trailing slash.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function address(): string {
		return rtrim($this->baseUrl, '/');
	}//end address()

	/**
	 * What a dump shows: everything but the secret.
	 *
	 * @return array<string, mixed> The safe view.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function __debugInfo(): array {
		return ['baseUrl' => $this->baseUrl, 'authMethod' => $this->authMethod, 'roleId' => $this->roleId, 'mount' => $this->mount];
	}//end __debugInfo()
}//end class
