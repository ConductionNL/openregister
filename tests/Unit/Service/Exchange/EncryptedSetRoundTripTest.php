<?php

/**
 * Unit tests for the encrypted instance set container: what goes in comes
 * out, and every way of tampering with the file, or of opening it with the
 * wrong key, is refused with the same sentence.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-a-load-checks-the-whole-set-before-writing-anything
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Exchange;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Exchange\EncryptedSetException;
use OCA\OpenRegister\Service\Exchange\EncryptedSetHeader;
use OCA\OpenRegister\Service\Exchange\EncryptedSetReader;
use OCA\OpenRegister\Service\Exchange\EncryptedSetWriter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Exchange\EncryptedSetWriter
 * @covers \OCA\OpenRegister\Service\Exchange\EncryptedSetReader
 * @covers \OCA\OpenRegister\Service\Exchange\EncryptedSetHeader
 * @covers \OCA\OpenRegister\Service\Exchange\EncryptedSetException
 */
class EncryptedSetRoundTripTest extends TestCase {

	/**
	 * A stream holding these bytes, at its start.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return resource
	 */
	private static function stream(string $bytes = '') {
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $bytes);
		rewind($stream);
		return $stream;
	}//end stream()

	/**
	 * Encrypt these bytes with this key and header.
	 *
	 * @param string                  $plain  The plaintext.
	 * @param string                  $key    The key.
	 * @param EncryptedSetHeader|null $header The header; an export-key header for the key when null.
	 *
	 * @return string The encrypted set.
	 */
	private static function seal(string $plain, string $key, ?EncryptedSetHeader $header = null): string {
		$out = self::stream();
		(new EncryptedSetWriter())->write(self::stream($plain), $out, $key, $header ?? EncryptedSetHeader::forKey($key));
		rewind($out);
		return stream_get_contents($out);
	}//end seal()

	/**
	 * Decrypt a set, as a load would after verifying it.
	 *
	 * @param string $sealed The encrypted set.
	 * @param string $key    The key.
	 *
	 * @return string The plaintext.
	 */
	private static function open(string $sealed, string $key): string {
		$reader = new EncryptedSetReader();
		$in = self::stream($sealed);
		$header = $reader->header($in);
		$reader->verify($in, $key, $header);

		$in = self::stream($sealed);
		$header = $reader->header($in);
		$out = self::stream();
		$reader->decrypt($in, $out, $key, $header);
		rewind($out);
		return stream_get_contents($out);
	}//end open()

	/**
	 * Assert that opening the set is refused with the one sentence.
	 *
	 * @param string $sealed The encrypted set.
	 * @param string $key    The key.
	 *
	 * @return void
	 */
	private function assertRefused(string $sealed, string $key): void {
		try {
			self::open($sealed, $key);
			$this->fail('the set must be refused');
		} catch (EncryptedSetException $refusal) {
			$this->assertSame('This file was changed or the key is wrong.', $refusal->getMessage());
		}
	}//end assertRefused()

	public function testTenMebibytesRoundTrip(): void {
		$key = random_bytes(32);
		$plain = random_bytes(10 * 1024 * 1024);

		$sealed = self::seal($plain, $key);

		$this->assertSame($plain, self::open($sealed, $key));
		$this->assertStringStartsWith("ORSETENC1\n", $sealed);
		$this->assertStringNotContainsString(substr($plain, 1000, 64), $sealed);
	}

	public function testEmptyShortAndExactlyChunkSizedSetsRoundTrip(): void {
		$key = random_bytes(32);
		foreach (['', 'one record', str_repeat('a', 65536), str_repeat('b', 131072), str_repeat('c', 65537)] as $plain) {
			$this->assertSame($plain, self::open(self::seal($plain, $key), $key));
		}
	}

	public function testTheHeaderSaysNothingAboutTheContents(): void {
		$key = random_bytes(32);
		$sealed = self::seal('{"title":"Jeugdwet zaak Jansen"}', $key);
		$header = (new EncryptedSetReader())->header(self::stream($sealed));

		$this->assertSame(
			['version' => 1, 'cipher' => 'xchacha20poly1305-secretstream', 'chunkSize' => 65536, 'keyFingerprint' => EncryptedSetHeader::fingerprint($key)],
			$header->fields
		);
		$this->assertTrue($header->usesKey());
		$this->assertStringNotContainsString('Jansen', $sealed);
		$this->assertSame(16, strlen(EncryptedSetHeader::fingerprint($key)));
	}

	public function testAPassphraseHeaderCarriesItsParameters(): void {
		$salt = random_bytes(16);
		$key = sodium_crypto_pwhash(32, 'correct horse battery', $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$header = EncryptedSetHeader::forPassphrase($salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
		$sealed = self::seal('records', $key, $header);

		$read = (new EncryptedSetReader())->header(self::stream($sealed));

		$this->assertFalse($read->usesKey());
		$this->assertSame('argon2id13', $read->fields['kdf']);
		$this->assertSame($salt, sodium_base642bin($read->fields['salt'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
		$this->assertSame('records', self::open($sealed, $key));
	}

	public function testAFlippedByteInAChunkIsRefused(): void {
		$key = random_bytes(32);
		$sealed = self::seal(random_bytes(200000), $key);
		$sealed[strlen($sealed) - 70000] = chr(ord($sealed[strlen($sealed) - 70000]) ^ 1);

		$this->assertRefused($sealed, $key);
	}

	public function testAChangedHeaderFieldIsRefused(): void {
		$salt = random_bytes(16);
		$key = random_bytes(32);
		$sealed = self::seal('records', $key, EncryptedSetHeader::forPassphrase($salt, 3, 67108864));

		$this->assertRefused(str_replace('"opslimit":3', '"opslimit":4', $sealed), $key);
		$this->assertRefused(str_replace('"version":1', '"version":2', $sealed), $key);
	}

	public function testAMissingFinalChunkIsRefused(): void {
		$key = random_bytes(32);
		$sealed = self::seal(random_bytes(150000), $key);

		// Cut after the second full chunk: everything read so far is authentic, but the set has no end.
		$headerLength = strpos($sealed, "\n", 10) + 1 + 24;
		$this->assertRefused(substr($sealed, 0, $headerLength + 2 * (65536 + 17)), $key);
		$this->assertRefused(substr($sealed, 0, -1), $key);
	}

	public function testBytesAfterTheFinalChunkAreRefused(): void {
		$key = random_bytes(32);

		$this->assertRefused(self::seal('records', $key) . 'x', $key);
		// A final chunk of exactly one chunk's size is read whole, so the bytes after it are met on their own.
		$this->assertRefused(self::seal(str_repeat('a', 65536), $key) . 'x', $key);
	}

	public function testAWrongKeyIsRefusedAlsoWhenTheHeaderCannotTellIt(): void {
		$key = random_bytes(32);
		$this->assertRefused(self::seal('records', $key), random_bytes(32));

		$salt = random_bytes(16);
		$sealed = self::seal('records', $key, EncryptedSetHeader::forPassphrase($salt, 3, 67108864));
		$this->assertRefused($sealed, random_bytes(32));
	}

	public function testSomethingThatIsNotAnEncryptedSetIsRefused(): void {
		$this->assertRefused('{"registers": []}', random_bytes(32));
		$this->assertRefused("ORSETENC1\nnot json\n", random_bytes(32));
		$key = random_bytes(32);
		$otherCipher = str_replace('xchacha20poly1305-secretstream', 'aes-256-gcm', self::seal('records', $key));
		$this->assertRefused($otherCipher, $key);

		// The header itself is refused before any key is tried: a reader does not start on a cipher it does not know.
		$this->expectException(EncryptedSetException::class);
		(new EncryptedSetReader())->header(self::stream($otherCipher));
	}

	public function testVerifyingWritesNothingAndRefusesBeforeAnyWrite(): void {
		$key = random_bytes(32);
		$sealed = self::seal(random_bytes(100000), $key);
		$sealed[strlen($sealed) - 10] = chr(ord($sealed[strlen($sealed) - 10]) ^ 1);
		$reader = new EncryptedSetReader();
		$in = self::stream($sealed);

		$this->expectException(EncryptedSetException::class);
		$reader->verify($in, $key, $reader->header($in));
	}

	public function testAKeyMustBeThirtyTwoBytesAndMatchItsHeader(): void {
		$key = random_bytes(32);

		try {
			(new EncryptedSetWriter())->write(self::stream('x'), self::stream(), random_bytes(32), EncryptedSetHeader::forKey($key));
			$this->fail('another key than the header names must be refused');
		} catch (InvalidArgumentException $refusal) {
			$this->assertSame('The header names another export key.', $refusal->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		EncryptedSetHeader::forKey('short');
	}

	public function testAnOutputThatTakesNothingIsReportedNotSkipped(): void {
		$key = random_bytes(32);
		$readOnly = fopen('php://memory', 'rb');

		try {
			(new EncryptedSetWriter())->write(self::stream('records'), $readOnly, $key, EncryptedSetHeader::forKey($key));
			$this->fail('a set that cannot be written must not pass as written');
		} catch (\RuntimeException $failure) {
			$this->assertSame('The encrypted set could not be written.', $failure->getMessage());
		}

		$reader = new EncryptedSetReader();
		$in = self::stream(self::seal('records', $key));
		$this->expectExceptionMessage('The decrypted set could not be written.');
		$reader->decrypt($in, fopen('php://memory', 'rb'), $key, $reader->header($in));
	}

	public function testAPassphraseHeaderNeedsASixteenByteSalt(): void {
		$this->expectException(InvalidArgumentException::class);
		EncryptedSetHeader::forPassphrase('short', 3, 67108864);
	}
}
