<?php

/**
 * Writes an instance set as an encrypted stream.
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
use RuntimeException;

/**
 * Writes an instance set as an encrypted stream (design D-1).
 *
 * The magic and cleartext header, the secretstream header, then the
 * plaintext in 64 KiB chunks, each sealed with XChaCha20-Poly1305. The
 * header's bytes are the additional data of the first chunk, and the last
 * chunk carries the final tag, so a truncated file reads as truncated rather
 * than as a shorter valid set. It streams: memory does not grow with the set.
 *
 * It takes streams, not paths, so it can sit between the serialisation and
 * wherever the set goes (design D-7). It takes a key, never a passphrase:
 * stretching one is the caller's work, and the parameters it used go in the
 * header it passes.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */
class EncryptedSetWriter {

	/**
	 * Encrypt everything readable from one stream into another.
	 *
	 * @param resource           $plain     The set in the clear, read to its end.
	 * @param resource           $encrypted Where the encrypted set is written.
	 * @param string             $key       The 32-byte key.
	 * @param EncryptedSetHeader $header    The header naming how the key was made.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the key is not 32 bytes or is not the key the header names.
	 * @throws RuntimeException When the output cannot be written.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) EncryptedSetHeader's named constructor and key checks are pure functions of their arguments.
	 */
	public function write($plain, $encrypted, string $key, EncryptedSetHeader $header): void {
		EncryptedSetHeader::assertKey(key: $key);
		if ($header->usesKey() === true && $header->fields['keyFingerprint'] !== EncryptedSetHeader::fingerprint(key: $key)) {
			throw new InvalidArgumentException('The header names another export key.');
		}

		[$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
		$this->put(stream: $encrypted, bytes: $header->bytes . $streamHeader);

		$additional = $header->bytes;
		$chunk = self::readChunk(stream: $plain);
		do {
			$next = self::readChunk(stream: $plain);
			$tag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
			if ($next === '') {
				$tag = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
			}

			$this->put(stream: $encrypted, bytes: sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $additional, $tag));
			$additional = '';
			$chunk = $next;
		} while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
	}//end write()

	/**
	 * Up to one chunk of plaintext, reading on through short reads; '' at the end.
	 *
	 * @param resource $stream The plaintext.
	 *
	 * @return string The chunk.
	 */
	private static function readChunk($stream): string {
		$chunk = '';
		while (strlen($chunk) < EncryptedSetHeader::CHUNK_SIZE && feof($stream) === false) {
			$read = fread($stream, (EncryptedSetHeader::CHUNK_SIZE - strlen($chunk)));
			if ($read === false || $read === '') {
				break;
			}

			$chunk .= $read;
		}

		return $chunk;
	}//end readChunk()

	/**
	 * Write all of these bytes.
	 *
	 * @param resource $stream The output.
	 * @param string   $bytes  The bytes.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the output takes less than all of them.
	 */
	private function put($stream, string $bytes): void {
		if (fwrite($stream, $bytes) !== strlen($bytes)) {
			throw new RuntimeException('The encrypted set could not be written.');
		}
	}//end put()
}//end class
