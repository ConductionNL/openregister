<?php

/**
 * The cleartext header of an encrypted instance set.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use InvalidArgumentException;

/**
 * The cleartext header of an encrypted instance set (design D-1).
 *
 * It carries only what a reader needs to start: the format version, the
 * cipher, the chunk size, and either the passphrase parameters or the export
 * key's fingerprint. Nothing about the contents: whoever holds the file can
 * read it. Its exact bytes are the additional data of the first chunk, so a
 * changed field makes that chunk fail.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */
final class EncryptedSetHeader {

	/**
	 * The first line of every encrypted set: magic and format version.
	 *
	 * @var string
	 */
	public const MAGIC = "ORSETENC1\n";

	/**
	 * The format version the header names.
	 *
	 * @var int
	 */
	public const VERSION = 1;

	/**
	 * The cipher the header names.
	 *
	 * @var string
	 */
	public const CIPHER = 'xchacha20poly1305-secretstream';

	/**
	 * Plaintext bytes per chunk.
	 *
	 * @var int
	 */
	public const CHUNK_SIZE = 65536;

	/**
	 * The key derivation a passphrase header names.
	 *
	 * @var string
	 */
	public const KDF = 'argon2id13';

	/**
	 * Constructor.
	 *
	 * @param array<string, int|string> $fields The header fields.
	 * @param string                    $bytes  The header line exactly as written, magic included.
	 */
	private function __construct(
		public readonly array $fields,
		public readonly string $bytes,
	) {
	}//end __construct()

	/**
	 * A header for a set encrypted with a key stretched from a passphrase.
	 *
	 * @param string $salt     The 16-byte Argon2id salt.
	 * @param int    $opslimit The Argon2id operations limit.
	 * @param int    $memlimit The Argon2id memory limit.
	 *
	 * @return self The header.
	 *
	 * @throws InvalidArgumentException When the salt is not 16 bytes or a limit is not positive.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public static function forPassphrase(string $salt, int $opslimit, int $memlimit): self {
		if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || $opslimit < 1 || $memlimit < 1) {
			throw new InvalidArgumentException('A passphrase header needs a 16-byte salt and positive limits.');
		}

		return self::fromFields(
			fields: self::baseFields() + [
				'kdf' => self::KDF,
				'opslimit' => $opslimit,
				'memlimit' => $memlimit,
				'salt' => sodium_bin2base64($salt, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
			]
		);
	}//end forPassphrase()

	/**
	 * A header for a set encrypted with an export key, naming its fingerprint.
	 *
	 * @param string $key The 32-byte export key.
	 *
	 * @return self The header.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public static function forKey(string $key): self {
		return self::fromFields(fields: self::baseFields() + ['keyFingerprint' => self::fingerprint(key: $key)]);
	}//end forKey()

	/**
	 * The fingerprint of an export key: the first 16 hex characters of its BLAKE2b hash.
	 *
	 * @param string $key The 32-byte key.
	 *
	 * @return string The fingerprint.
	 *
	 * @throws InvalidArgumentException When the key is not 32 bytes.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public static function fingerprint(string $key): string {
		self::assertKey(key: $key);

		return substr(sodium_bin2hex(sodium_crypto_generichash($key)), 0, 16);
	}//end fingerprint()

	/**
	 * Read the header from the start of a stream, leaving the stream after it.
	 *
	 * @param resource $stream The set.
	 *
	 * @return self The header.
	 *
	 * @throws EncryptedSetException When the stream does not start with a well-formed header.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
	 */
	public static function read($stream): self {
		$magic = fread($stream, strlen(self::MAGIC));
		$line  = fgets($stream, 4096);
		if ($magic !== self::MAGIC || is_string($line) === false || str_ends_with($line, "\n") === false) {
			throw new EncryptedSetException();
		}

		$fields = json_decode(substr($line, 0, -1), true);
		if (is_array($fields) === false || self::isKnown(fields: $fields) === false) {
			throw new EncryptedSetException();
		}

		return new self(fields: $fields, bytes: $magic . $line);
	}//end read()

	/**
	 * Whether the header names an export key rather than a passphrase.
	 *
	 * @return bool True for an export key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public function usesKey(): bool {
		return isset($this->fields['keyFingerprint']) === true;
	}//end usesKey()

	/**
	 * Refuse anything but a 32-byte key.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the key is not 32 bytes.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public static function assertKey(string $key): void {
		if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
			throw new InvalidArgumentException('An encryption key is 32 bytes.');
		}
	}//end assertKey()

	/**
	 * The fields every header starts with.
	 *
	 * @return array<string, int|string>
	 */
	private static function baseFields(): array {
		return ['version' => self::VERSION, 'cipher' => self::CIPHER, 'chunkSize' => self::CHUNK_SIZE];
	}//end baseFields()

	/**
	 * A header written from these fields.
	 *
	 * @param array<string, int|string> $fields The fields.
	 *
	 * @return self The header.
	 */
	private static function fromFields(array $fields): self {
		return new self(fields: $fields, bytes: self::MAGIC . json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
	}//end fromFields()

	/**
	 * Whether the fields name this format, this cipher, this chunk size and one kind of key.
	 *
	 * @param array<mixed> $fields The decoded fields.
	 *
	 * @return bool True when a reader can start on them.
	 */
	private static function isKnown(array $fields): bool {
		if (($fields['version'] ?? null) !== self::VERSION
			|| ($fields['cipher'] ?? null) !== self::CIPHER
			|| ($fields['chunkSize'] ?? null) !== self::CHUNK_SIZE
		) {
			return false;
		}

		if (isset($fields['keyFingerprint']) === true) {
			return is_string($fields['keyFingerprint']) === true && isset($fields['kdf']) === false;
		}

		return ($fields['kdf'] ?? null) === self::KDF
			&& is_int($fields['opslimit'] ?? null) === true
			&& is_int($fields['memlimit'] ?? null) === true
			&& is_string($fields['salt'] ?? null) === true;
	}//end isKnown()
}//end class
