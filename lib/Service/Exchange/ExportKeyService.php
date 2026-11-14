<?php

/**
 * Passphrase stretching and the export keys an encrypted instance set is written with.
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
use OCA\OpenRegister\Db\AuditTrail\ExportOutcome;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The two kinds of key an encrypted set is opened with (design D-2).
 *
 * A passphrase is stretched with Argon2id into a 32-byte key and never stored.
 * An export key is 32 random bytes kept as an organisation credential in the
 * credential broker under the `openregister-export-key` provider; its creator
 * sees it once, as a recovery string. Creating, rotating, deleting and using
 * one each write an audit row naming its fingerprint, never the key.
 *
 * Authorisation of the administrator is the caller's: this service takes an
 * already-gated organisation and owner, and every read of a key goes through
 * the broker's own organisation guard.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 *
 * @SuppressWarnings(PHPMD.StaticAccess) EncryptedSetHeader's and BrokeredKeys' key helpers are pure functions of their arguments.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It ties the key helpers, the broker-side store and the audit trail together, which is its job.
 */
class ExportKeyService {

	/**
	 * The shortest passphrase accepted, in characters.
	 *
	 * @var integer
	 */
	public const MIN_PASSPHRASE_LENGTH = 12;

	/**
	 * Constructor.
	 *
	 * @param BrokeredKeys              $keys             Stores, reads and removes the keys in the broker.
	 * @param AuditTrailMapperInterface $auditTrailMapper Writes the audit rows.
	 * @param LoggerInterface           $logger           Records an audit row that could not be written.
	 */
	public function __construct(
		private readonly BrokeredKeys $keys,
		private readonly AuditTrailMapperInterface $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A header for a new passphrase-encrypted set: a fresh salt and the moderate Argon2id limits.
	 *
	 * @return EncryptedSetHeader The header.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public function passphraseHeader(): EncryptedSetHeader {
		return EncryptedSetHeader::forPassphrase(
			salt: random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES),
			opslimit: SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
			memlimit: SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE
		);
	}//end passphraseHeader()

	/**
	 * Stretch a passphrase into the 32-byte key with the parameters a header names.
	 *
	 * @param string             $passphrase The passphrase, at least 12 characters.
	 * @param EncryptedSetHeader $header     A passphrase header, new or read from a set.
	 *
	 * @return string The 32-byte key.
	 *
	 * @throws InvalidArgumentException When the passphrase is shorter than 12 characters.
	 * @throws EncryptedSetException    When the header is not a passphrase header.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public function passphraseKey(#[\SensitiveParameter] string $passphrase, EncryptedSetHeader $header): string {
		if (mb_strlen($passphrase, 'UTF-8') < self::MIN_PASSPHRASE_LENGTH) {
			throw new InvalidArgumentException('A passphrase has at least 12 characters.');
		}

		$fields = $header->fields;
		if ($header->usesKey() === true || ($fields['kdf'] ?? null) !== EncryptedSetHeader::KDF) {
			throw new EncryptedSetException();
		}

		try {
			return sodium_crypto_pwhash(
				SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
				$passphrase,
				sodium_base642bin((string) $fields['salt'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
				(int) $fields['opslimit'],
				(int) $fields['memlimit'],
				SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
			);
		} catch (Throwable $e) {
			throw new EncryptedSetException();
		}
	}//end passphraseKey()

	/**
	 * Create an export key for an organisation, returning the recovery string once.
	 *
	 * @param string $organisation The organisation UUID, already gated by the caller.
	 * @param string $owner        The administrator creating it.
	 *
	 * @return ExportKeyCreated The credential id, the fingerprint and the recovery string.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function create(string $organisation, string $owner): ExportKeyCreated {
		$created = $this->store(organisation: $organisation, owner: $owner);
		$this->audit(
			outcome: 'key-created',
			summary: ['keyId' => $created->keyId, 'fingerprint' => $created->fingerprint, 'organisation' => $organisation],
			actorId: $owner
		);

		return $created;
	}//end create()

	/**
	 * Rotate an export key: a new key for new sets, the old one kept for loading old sets.
	 *
	 * @param string $previousKeyId The key being rotated out.
	 * @param string $organisation  The organisation UUID, already gated by the caller.
	 * @param string $owner         The administrator rotating it.
	 *
	 * @return ExportKeyCreated The new key, with its recovery string shown once.
	 *
	 * @throws ExportKeyException When the previous key is not an export key.
	 * @throws \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException When the broker refuses the previous key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function rotate(string $previousKeyId, string $organisation, string $owner): ExportKeyCreated {
		$previous = $this->keys->get(keyId: $previousKeyId, provider: BrokeredKeys::EXPORT_PROVIDER, organisation: $organisation);
		$created  = $this->store(organisation: $organisation, owner: $owner);
		$this->audit(
			outcome: 'key-rotated',
			summary: [
				'keyId' => $created->keyId,
				'fingerprint' => $created->fingerprint,
				'previousKeyId' => $previousKeyId,
				'previousFingerprint' => EncryptedSetHeader::fingerprint(key: $previous),
				'organisation' => $organisation,
			],
			actorId: $owner
		);

		return $created;
	}//end rotate()

	/**
	 * Delete an export key; a set encrypted with it can no longer be opened here.
	 *
	 * @param string $keyId        The key's credential UUID.
	 * @param string $organisation The organisation UUID, already gated by the caller.
	 * @param string $owner        The administrator deleting it.
	 *
	 * @return bool True when the key and its secret are gone.
	 *
	 * @throws ExportKeyException When the credential is not an export key.
	 * @throws \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException When the broker refuses the key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function delete(string $keyId, string $organisation, string $owner): bool {
		$key     = $this->keys->get(keyId: $keyId, provider: BrokeredKeys::EXPORT_PROVIDER, organisation: $organisation);
		$removed = $this->keys->remove(keyId: $keyId);
		$this->audit(
			outcome: 'key-deleted',
			summary: [
				'keyId' => $keyId,
				'fingerprint' => EncryptedSetHeader::fingerprint(key: $key),
				'organisation' => $organisation,
				'removed' => $removed,
			],
			actorId: $owner
		);

		return $removed;
	}//end delete()

	/**
	 * The export key to encrypt or open a set with, recorded as a use.
	 *
	 * @param string $keyId        The key's credential UUID.
	 * @param string $organisation The organisation a sessionless caller asserts.
	 *
	 * @return string The 32-byte key.
	 *
	 * @throws ExportKeyException When the credential is not an export key.
	 * @throws \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException When the broker refuses the key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function use(string $keyId, string $organisation): string {
		$key = $this->keys->get(keyId: $keyId, provider: BrokeredKeys::EXPORT_PROVIDER, organisation: $organisation);
		$this->audit(
			outcome: 'key-used',
			summary: ['keyId' => $keyId, 'fingerprint' => EncryptedSetHeader::fingerprint(key: $key), 'organisation' => $organisation],
			actorId: null
		);

		return $key;
	}//end use()

	/**
	 * Generate and store a new export key.
	 *
	 * @param string $organisation The organisation UUID.
	 * @param string $owner        The administrator creating it.
	 *
	 * @return ExportKeyCreated The new key.
	 */
	private function store(string $organisation, string $owner): ExportKeyCreated {
		$key         = sodium_crypto_secretstream_xchacha20poly1305_keygen();
		$fingerprint = EncryptedSetHeader::fingerprint(key: $key);
		$keyId       = $this->keys->put(
			record: new BrokeredKeyRecord(
				provider: BrokeredKeys::EXPORT_PROVIDER,
				name: 'Export key ' . $fingerprint,
				organisation: $organisation,
				owner: $owner
			),
			key: $key
		);

		return new ExportKeyCreated(keyId: $keyId, fingerprint: $fingerprint, recovery: BrokeredKeys::encode(key: $key));
	}//end store()

	/**
	 * Write one audit row; a failure is logged and never undoes what happened.
	 *
	 * @param string               $outcome The event, as `key-created`, `key-rotated`, `key-deleted` or `key-used`.
	 * @param array<string, mixed> $summary The key id, fingerprint and organisation; never the key.
	 * @param string|null          $actorId The administrator, or null for the session actor.
	 *
	 * @return void
	 */
	private function audit(string $outcome, array $summary, ?string $actorId): void {
		try {
			$this->auditTrailMapper->recordExport(
				export: new ExportOutcome(outcome: $outcome, summary: $summary, actorId: $actorId)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[ExportKeyService] the audit row could not be written',
				['event' => 'export.' . $outcome, 'fingerprint' => ($summary['fingerprint'] ?? null)]
			);
		}
	}//end audit()
}//end class
