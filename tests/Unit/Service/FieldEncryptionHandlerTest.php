<?php

/**
 * Unit tests for FieldEncryptionHandler.
 *
 * Covers:
 *  - Envelope round trip: encryptValue()/decryptValue() and isEnvelope().
 *  - encryptProperties(): only flagged, present, non-empty string values are
 *    encrypted; idempotent on an already-encrypted value; non-string values
 *    and absent/null/empty values are left untouched.
 *  - decryptProperties(): only decrypts values that are present AND already an
 *    envelope (mixed plaintext/ciphertext rollout state passes through
 *    unchanged); a value absent from the data (already redacted upstream) is
 *    never touched — the RBAC/writeOnly composition guarantee.
 *  - Decryption failure never fails silently: default behaviour substitutes a
 *    structured error marker and logs at ERROR; `throwOnFailure: true`
 *    rethrows instead.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FieldDecryptionException;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FieldEncryptionHandlerTest extends TestCase {
	private FieldEncryptionHandler $handler;

	/** @var ICrypto&MockObject */
	private ICrypto $crypto;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->crypto = $this->createMock(ICrypto::class);
		$this->crypto->method('encrypt')->willReturnCallback(
			fn (string $plain): string => 'CIPHER(' . $plain . ')'
		);
		$this->crypto->method('decrypt')->willReturnCallback(
			function (string $cipher): string {
				if (preg_match('/^CIPHER\((.*)\)$/', $cipher, $m) === 1) {
					return $m[1];
				}

				throw new \RuntimeException('bad ciphertext');
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new FieldEncryptionHandler($this->crypto, $this->logger);
	}

	private function schemaWithEncrypted(array $encryptedProperties, array $extraProperties = []): Schema {
		$properties = $extraProperties;
		foreach ($encryptedProperties as $name) {
			$properties[$name] = ['type' => 'string', 'x-openregister-encrypted' => true];
		}

		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}

	// ── envelope primitives ──

	public function testEncryptValueProducesEnvelopePrefixedCiphertext(): void {
		$envelope = $this->handler->encryptValue('123456789');
		$this->assertStringStartsWith(FieldEncryptionHandler::ENVELOPE_PREFIX, $envelope);
		$this->assertStringContainsString('CIPHER(123456789)', $envelope);
	}

	public function testDecryptValueRoundTrips(): void {
		$envelope = $this->handler->encryptValue('secret-bsn');
		$this->assertSame('secret-bsn', $this->handler->decryptValue($envelope));
	}

	public function testIsEnvelopeDetectsPrefix(): void {
		$this->assertTrue($this->handler->isEnvelope($this->handler->encryptValue('x')));
		$this->assertFalse($this->handler->isEnvelope('plain text'));
		$this->assertFalse($this->handler->isEnvelope(123));
		$this->assertFalse($this->handler->isEnvelope(null));
	}

	public function testDecryptValueThrowsOnNonEnvelope(): void {
		$this->expectException(FieldDecryptionException::class);
		$this->handler->decryptValue('not-an-envelope');
	}

	public function testDecryptValueThrowsWhenCryptoFails(): void {
		$envelope = FieldEncryptionHandler::ENVELOPE_PREFIX . 'garbage-ciphertext';
		$this->expectException(FieldDecryptionException::class);
		$this->handler->decryptValue($envelope);
	}

	// ── encryptProperties() ──

	public function testEncryptPropertiesEncryptsOnlyFlaggedFields(): void {
		$schema = $this->schemaWithEncrypted(['bsn'], ['name' => ['type' => 'string']]);
		$data = ['name' => 'Jan Jansen', 'bsn' => '123456789'];

		$result = $this->handler->encryptProperties($data, $schema);

		$this->assertSame('Jan Jansen', $result['name'], 'Unflagged field must stay plaintext');
		$this->assertTrue($this->handler->isEnvelope($result['bsn']), 'Flagged field must be enveloped');
		$this->assertSame('123456789', $this->handler->decryptValue($result['bsn']));
	}

	public function testEncryptPropertiesIsIdempotent(): void {
		$schema = $this->schemaWithEncrypted(['bsn']);
		$data = ['bsn' => '123456789'];

		$once = $this->handler->encryptProperties($data, $schema);
		$twice = $this->handler->encryptProperties($once, $schema);

		$this->assertSame($once['bsn'], $twice['bsn'], 'Re-encrypting an envelope must be a no-op');
	}

	public function testEncryptPropertiesSkipsNullAndEmptyValues(): void {
		$schema = $this->schemaWithEncrypted(['bsn', 'medical']);
		$data = ['bsn' => null, 'medical' => ''];

		$result = $this->handler->encryptProperties($data, $schema);

		$this->assertNull($result['bsn']);
		$this->assertSame('', $result['medical']);
	}

	public function testEncryptPropertiesSkipsNonStringValues(): void {
		$schema = $this->schemaWithEncrypted(['scores']);
		$data = ['scores' => [1, 2, 3]];

		$result = $this->handler->encryptProperties($data, $schema);

		$this->assertSame([1, 2, 3], $result['scores'], 'Non-string values are left untouched (v1 limitation)');
	}

	public function testEncryptPropertiesLeavesAbsentPropertiesUntouched(): void {
		$schema = $this->schemaWithEncrypted(['bsn']);
		$data = ['name' => 'no bsn field here'];

		$result = $this->handler->encryptProperties($data, $schema);

		$this->assertArrayNotHasKey('bsn', $result);
		$this->assertSame('no bsn field here', $result['name']);
	}

	public function testEncryptPropertiesNoOpWhenSchemaHasNoEncryptedProperties(): void {
		$schema = new Schema();
		$schema->setProperties(['name' => ['type' => 'string']]);
		$data = ['name' => 'unchanged'];

		$this->assertSame($data, $this->handler->encryptProperties($data, $schema));
	}

	// ── decryptProperties() ──

	public function testDecryptPropertiesDecryptsEnvelopedValues(): void {
		$schema = $this->schemaWithEncrypted(['bsn']);
		$encoded = $this->handler->encryptProperties(['bsn' => '123456789'], $schema);

		$result = $this->handler->decryptProperties($encoded, $schema);

		$this->assertSame('123456789', $result['bsn']);
	}

	public function testDecryptPropertiesLeavesPlaintextRolloutStateUnchanged(): void {
		// A field just flagged encrypted but not yet migrated still holds plaintext.
		$schema = $this->schemaWithEncrypted(['bsn']);
		$data = ['bsn' => '123456789'];

		$result = $this->handler->decryptProperties($data, $schema);

		$this->assertSame('123456789', $result['bsn']);
	}

	public function testDecryptPropertiesNeverDecryptsAFieldAbsentFromTheData(): void {
		// This is the composition guarantee with RBAC/writeOnly redaction: a
		// property that upstream redaction already stripped is not a key in
		// $data any more, so decryptProperties() must never resurrect it.
		$schema = $this->schemaWithEncrypted(['bsn']);
		$data = ['name' => 'Jan Jansen'];

		$result = $this->handler->decryptProperties($data, $schema);

		$this->assertArrayNotHasKey('bsn', $result);
		$this->assertSame(['name' => 'Jan Jansen'], $result);
	}

	public function testDecryptPropertiesFailureProducesErrorMarkerByDefault(): void {
		$schema = $this->schemaWithEncrypted(['bsn']);
		$data = ['bsn' => FieldEncryptionHandler::ENVELOPE_PREFIX . 'corrupted'];

		$this->logger->expects($this->once())->method('error');

		$result = $this->handler->decryptProperties($data, $schema);

		$this->assertIsArray($result['bsn']);
		$this->assertTrue($result['bsn']['@openregister_decryption_error']);
	}

	public function testDecryptPropertiesFailureThrowsWhenRequested(): void {
		$schema = $this->schemaWithEncrypted(['bsn']);
		$data = ['bsn' => FieldEncryptionHandler::ENVELOPE_PREFIX . 'corrupted'];

		$this->expectException(FieldDecryptionException::class);
		$this->handler->decryptProperties($data, $schema, throwOnFailure: true);
	}

	public function testDecryptPropertiesNoOpWhenSchemaHasNoEncryptedProperties(): void {
		$schema = new Schema();
		$schema->setProperties(['name' => ['type' => 'string']]);
		$data = ['name' => 'unchanged'];

		$this->assertSame($data, $this->handler->decryptProperties($data, $schema));
	}
}
