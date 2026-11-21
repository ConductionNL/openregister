<?php

/**
 * Keeps 32-byte encryption keys as organisation credentials in the credential broker.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2CredentialObjects;
use Psr\Log\LoggerInterface;
use SodiumException;
use Throwable;

/**
 * The broker side of export keys and one-use job keys.
 *
 * A key is stored as the base64url form of its 32 bytes, as an inject-only,
 * organisation-scoped credential usable by Open Register alone. Reading it
 * back always goes through {@see CredentialBrokerService::resolveInjectable()},
 * so the broker's owner and organisation guard decides who may use it; the
 * provider is checked first, so another inject-only secret of the same
 * organisation is never taken for a key. No key ever reaches a log line.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 *
 * @SuppressWarnings(PHPMD.StaticAccess) EncryptedSetHeader's key check is a pure function of its argument.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It is the one adapter between the key formats and the credential broker's value objects.
 */
class BrokeredKeys {

	/**
	 * The provider of an export key in the credential catalogue.
	 *
	 * @var string
	 */
	public const EXPORT_PROVIDER = 'openregister-export-key';

	/**
	 * The provider of a one-use job key in the credential catalogue.
	 *
	 * @var string
	 */
	public const JOB_PROVIDER = 'openregister-job-key';

	/**
	 * The only app that may use either kind of key.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The scope every key is stored under.
	 *
	 * @var string
	 */
	private const SCOPE = 'organisation';

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $broker      Mints and resolves the credentials, guards every use.
	 * @param OAuth2CredentialObjects $credentials Loads and deletes the credential objects.
	 * @param CredentialStore         $store       Deletes a key's stored secret.
	 * @param LoggerInterface         $logger      Records a key that could not be removed, by id only.
	 */
	public function __construct(
		private readonly CredentialBrokerService $broker,
		private readonly OAuth2CredentialObjects $credentials,
		private readonly CredentialStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store a key as an organisation credential and return the credential id.
	 *
	 * @param BrokeredKeyRecord $record Which provider, what name, which organisation and owner.
	 * @param string            $key    The 32-byte key.
	 *
	 * @return string The credential UUID.
	 *
	 * @throws InvalidArgumentException When the key is not 32 bytes or the organisation is empty.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function put(BrokeredKeyRecord $record, #[\SensitiveParameter] string $key): string {
		EncryptedSetHeader::assertKey(key: $key);

		$saved = $this->broker->mintCredential(
			mint: new CredentialMint(name: $record->name, provider: $record->provider, owner: $record->owner),
			options: new CredentialMintOptions(
				allowedApps: [self::APP_ID],
				secret: self::encode(key: $key),
				scope: self::SCOPE,
				organisation: $record->organisation
			)
		);

		return (string) $saved->getUuid();
	}//end put()

	/**
	 * The key behind a credential, after the broker admitted the caller.
	 *
	 * @param string $keyId        The credential UUID.
	 * @param string $provider     The provider the credential must have.
	 * @param string $organisation The organisation a sessionless caller asserts.
	 *
	 * @return string The 32-byte key.
	 *
	 * @throws ExportKeyException When the credential is missing, of another provider, or holds no key.
	 * @throws \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException When the broker guard refuses the caller.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function get(string $keyId, string $provider, string $organisation): string {
		$this->assertProvider(keyId: $keyId, provider: $provider);

		$secret = $this->broker->resolveInjectable(
			credentialId: $keyId,
			appId: self::APP_ID,
			actingOrganisationId: $organisation
		);

		return self::decode(secret: $secret);
	}//end get()

	/**
	 * Refuse a credential that is missing or belongs to another provider.
	 *
	 * @param string $keyId    The credential UUID.
	 * @param string $provider The provider the credential must have.
	 *
	 * @return void
	 *
	 * @throws ExportKeyException When the credential is missing or of another provider.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function assertProvider(string $keyId, string $provider): void {
		try {
			$entity = $this->credentials->find(credentialId: $keyId);
		} catch (Throwable $e) {
			$entity = null;
		}

		if ($entity === null || ($entity->getObject()['provider'] ?? null) !== $provider) {
			throw new ExportKeyException();
		}
	}//end assertProvider()

	/**
	 * Remove a key's credential object and its stored secret.
	 *
	 * @param string $keyId The credential UUID.
	 *
	 * @return bool True when both are gone, false when either could not be removed (logged by id).
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function remove(string $keyId): bool {
		$removed = true;
		try {
			$this->credentials->delete(credentialId: $keyId);
		} catch (Throwable $e) {
			$removed = false;
			$this->logger->error('[BrokeredKeys] could not delete the key credential', ['credential' => $keyId]);
		}

		try {
			$this->store->delete($keyId, self::SCOPE);
		} catch (Throwable $e) {
			$removed = false;
			$this->logger->error('[BrokeredKeys] could not delete the stored key', ['credential' => $keyId]);
		}

		return $removed;
	}//end remove()

	/**
	 * The recovery string of a key: its bytes in base64url without padding.
	 *
	 * @param string $key The 32-byte key.
	 *
	 * @return string The recovery string.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public static function encode(#[\SensitiveParameter] string $key): string {
		return sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
	}//end encode()

	/**
	 * The key a stored secret or a recovery string holds.
	 *
	 * @param string|null $secret The stored secret, or null when the broker kept it.
	 *
	 * @return string The 32-byte key.
	 *
	 * @throws ExportKeyException When the secret is absent or not a 32-byte key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public static function decode(#[\SensitiveParameter] ?string $secret): string {
		if ($secret === null || $secret === '') {
			throw new ExportKeyException();
		}

		try {
			$key = sodium_base642bin($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
		} catch (SodiumException $e) {
			throw new ExportKeyException();
		}

		if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
			throw new ExportKeyException();
		}

		return $key;
	}//end decode()
}//end class
