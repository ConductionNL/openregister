<?php

/**
 * Reads and verifies an encrypted instance set.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads an encrypted instance set (design D-1), and verifies one without
 * writing anything (design D-4).
 *
 * Every refusal is the same {@see EncryptedSetException}: a flipped byte, a
 * changed header field, a missing final chunk and a wrong key all read "this
 * file was changed or the key is wrong", naming no offset that would help
 * anybody work on the file.
 *
 * Decrypting writes as it goes, so a load verifies the whole set first and
 * only then streams it again into the place that applies it.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
 */
class EncryptedSetReader {

	/**
	 * Read the header, which says how to make the key.
	 *
	 * @param resource $encrypted The encrypted set, at its start.
	 *
	 * @return EncryptedSetHeader The header; the stream is left after it.
	 *
	 * @throws EncryptedSetException When the stream is not an encrypted set this reader knows.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) EncryptedSetHeader's named constructor and key checks are pure functions of their arguments.
	 */
	public function header($encrypted): EncryptedSetHeader {
		return EncryptedSetHeader::read(stream: $encrypted);
	}//end header()

	/**
	 * Decrypt every chunk to its final tag, writing nothing.
	 *
	 * @param resource           $encrypted The encrypted set, positioned after its header.
	 * @param string             $key       The 32-byte key.
	 * @param EncryptedSetHeader $header    The header read from it.
	 *
	 * @return void
	 *
	 * @throws EncryptedSetException When the set was changed, is truncated or the key is wrong.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
	 */
	public function verify($encrypted, string $key, EncryptedSetHeader $header): void {
		$this->decrypt(encrypted: $encrypted, plain: null, key: $key, header: $header);
	}//end verify()

	/**
	 * Decrypt the set into a stream, or into nothing when none is given.
	 *
	 * A failure partway leaves what was already written; a caller that must
	 * write nothing on failure calls {@see verify()} first.
	 *
	 * @param resource           $encrypted The encrypted set, positioned after its header.
	 * @param resource|null      $plain     Where the plaintext goes, or null to only check.
	 * @param string             $key       The 32-byte key.
	 * @param EncryptedSetHeader $header    The header read from it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the key is not 32 bytes.
	 * @throws EncryptedSetException When the set was changed, is truncated or the key is wrong.
	 * @throws RuntimeException When the output cannot be written.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
	 */
	public function decrypt($encrypted, $plain, string $key, EncryptedSetHeader $header): void {
		$state = $this->openStream(encrypted: $encrypted, key: $key, header: $header);

		$additional = $header->bytes;
		do {
			[$bytes, $tag] = self::pullChunk(encrypted: $encrypted, state: $state, additional: $additional);
			if ($plain !== null && fwrite($plain, $bytes) !== strlen($bytes)) {
				throw new RuntimeException('The decrypted set could not be written.');
			}

			$additional = '';
		} while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

		// Nothing may follow the final chunk: bytes after it are not part of the set.
		if (self::readExactly(stream: $encrypted, length: 1) !== '') {
			throw new EncryptedSetException();
		}
	}//end decrypt()

	/**
	 * Check the key against the header and start pulling the stream.
	 *
	 * @param resource           $encrypted The encrypted set, positioned after its header.
	 * @param string             $key       The 32-byte key.
	 * @param EncryptedSetHeader $header    The header read from it.
	 *
	 * @return string The secretstream pull state.
	 *
	 * @throws InvalidArgumentException When the key is not 32 bytes.
	 * @throws EncryptedSetException When the key is not the one the header names, or the stream has no start.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) EncryptedSetHeader's named constructor and key checks are pure functions of their arguments.
	 */
	private function openStream($encrypted, string $key, EncryptedSetHeader $header): string {
		EncryptedSetHeader::assertKey(key: $key);
		if ($header->usesKey() === true && hash_equals((string)$header->fields['keyFingerprint'], EncryptedSetHeader::fingerprint(key: $key)) === false) {
			throw new EncryptedSetException();
		}

		$streamHeader = self::readExactly(stream: $encrypted, length: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
		if (strlen($streamHeader) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
			throw new EncryptedSetException();
		}

		return sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $key);
	}//end openStream()

	/**
	 * Read and open the next chunk.
	 *
	 * @param resource $encrypted  The encrypted set.
	 * @param string   $state      The pull state, advanced in place.
	 * @param string   $additional The chunk's additional data.
	 *
	 * @return array{0: string, 1: int} The plaintext and the chunk's tag.
	 *
	 * @throws EncryptedSetException When the chunk was altered, the key is wrong or the stream ended before the final tag.
	 */
	private static function pullChunk($encrypted, string &$state, string $additional): array {
		$chunk = self::readExactly(stream: $encrypted, length: (EncryptedSetHeader::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES));
		if (strlen($chunk) < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
			throw new EncryptedSetException();
		}

		$opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk, $additional);
		if ($opened === false) {
			throw new EncryptedSetException();
		}

		return $opened;
	}//end pullChunk()

	/**
	 * Exactly this many bytes, or fewer only at the end of the stream.
	 *
	 * @param resource $stream The stream.
	 * @param int      $length The number of bytes.
	 *
	 * @return string The bytes.
	 */
	private static function readExactly($stream, int $length): string {
		$bytes = '';
		while (strlen($bytes) < $length && feof($stream) === false) {
			$read = fread($stream, ($length - strlen($bytes)));
			if ($read === false || $read === '') {
				break;
			}

			$bytes .= $read;
		}

		return $bytes;
	}//end readExactly()
}//end class
