<?php

/**
 * Golden test: what FieldEncryptionHandler encrypts and decrypts across a whole object.
 *
 * Pins, on fixed inputs, which flagged properties encryptProperties() turns
 * into an envelope (and which it leaves: absent, null, empty, non-string,
 * already an envelope, unflagged), which ones decryptProperties() reveals
 * (and which it leaves: plaintext, non-string, unflagged, absent), the error
 * marker a failed decryption leaves, and the fail-loud disposition that
 * rethrows instead, with the log lines both write.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FieldDecryptionException;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes of the whole-object field encryption.
 */
class FieldEncryptionHandlerPropertiesGoldenTest extends TestCase {

	private const P = FieldEncryptionHandler::ENVELOPE_PREFIX;

	/**
	 * Log lines written, as [level, message, context-without-file-and-line].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $logs = [];

	private function handler(): FieldEncryptionHandler {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'C(' . $plain . ')');
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher): string {
				if (preg_match('/^C\((.*)\)$/', $cipher, $match) === 1) {
					return $match[1];
				}

				throw new RuntimeException('bad ciphertext');
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new FieldEncryptionHandler(crypto: $crypto, logger: $logger);
	}//end handler()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(
			[
				'bsn'    => ['type' => 'string', 'x-openregister-encrypted' => true],
				'iban'   => ['type' => 'string', 'x-openregister-encrypted' => true],
				'empty'  => ['type' => 'string', 'x-openregister-encrypted' => true],
				'nil'    => ['type' => 'string', 'x-openregister-encrypted' => true],
				'list'   => ['type' => 'array', 'x-openregister-encrypted' => true],
				'num'    => ['type' => 'integer', 'x-openregister-encrypted' => true],
				'absent' => ['type' => 'string', 'x-openregister-encrypted' => true],
				'name'   => ['type' => 'string'],
			]
		);
		return $schema;
	}//end schema()

	public function testEncryptPropertiesEncryptsOnlyPresentNonEmptyPlainStrings(): void {
		$data = ['bsn' => '123', 'iban' => self::P . 'C(NL01)', 'empty' => '', 'nil' => null, 'list' => ['a'], 'num' => 5, 'name' => 'Ann'];

		$out = $this->handler()->encryptProperties($data, $this->schema());

		self::assertSame(
			['bsn' => self::P . 'C(123)', 'iban' => self::P . 'C(NL01)', 'empty' => '', 'nil' => null, 'list' => ['a'], 'num' => 5, 'name' => 'Ann'],
			$out
		);
		self::assertSame(
			[
				['warning', '[FieldEncryptionHandler] Skipped non-string value for encrypted property', ['property' => 'list', 'type' => 'array']],
				['warning', '[FieldEncryptionHandler] Skipped non-string value for encrypted property', ['property' => 'num', 'type' => 'integer']],
			],
			$this->logs
		);
	}//end testEncryptPropertiesEncryptsOnlyPresentNonEmptyPlainStrings()

	public function testEncryptPropertiesIsANoOpWithoutFlaggedProperties(): void {
		$schema = new Schema();
		$schema->setProperties(['bsn' => ['type' => 'string']]);

		self::assertSame(['bsn' => '123'], $this->handler()->encryptProperties(['bsn' => '123'], $schema));
	}//end testEncryptPropertiesIsANoOpWithoutFlaggedProperties()

	public function testDecryptPropertiesRevealsEnvelopesAndMarksFailures(): void {
		$data = [
			'bsn' => self::P . 'C(123)',
			'iban' => self::P . 'garbage',
			'empty' => 'plain',
			'list' => [self::P . 'C(x)'],
			'num' => 5,
			'name' => self::P . 'C(Ann)',
		];

		$out = $this->handler()->decryptProperties($data, $this->schema());

		self::assertSame(
			[
				'bsn' => '123',
				'iban' => ['@openregister_decryption_error' => true, 'message' => 'This field could not be decrypted (key unavailable or rotated).'],
				'empty' => 'plain',
				'list' => [self::P . 'C(x)'],
				'num' => 5,
				'name' => self::P . 'C(Ann)',
			],
			$out
		);
		self::assertSame(
			[
				[
					'error',
					'[FieldEncryptionHandler] Decryption failed for property',
					['property' => 'iban', 'schema' => 7, 'error' => 'Failed to decrypt field value: bad ciphertext'],
				],
			],
			$this->logs
		);
	}//end testDecryptPropertiesRevealsEnvelopesAndMarksFailures()

	public function testDecryptPropertiesFailsLoudWhenAsked(): void {
		$data = ['bsn' => self::P . 'C(123)', 'iban' => self::P . 'garbage', 'empty' => self::P . 'C(never)'];

		try {
			$this->handler()->decryptProperties($data, $this->schema(), true);
			self::fail('Expected a FieldDecryptionException.');
		} catch (FieldDecryptionException $e) {
			self::assertSame('Failed to decrypt field value: bad ciphertext', $e->getMessage());
			self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
		}

		self::assertCount(1, $this->logs);
		self::assertSame('iban', $this->logs[0][2]['property']);
	}//end testDecryptPropertiesFailsLoudWhenAsked()

	public function testDecryptPropertiesIsANoOpWithoutFlaggedProperties(): void {
		$schema = new Schema();
		$schema->setProperties(['bsn' => ['type' => 'string']]);
		$data = ['bsn' => self::P . 'C(123)'];

		self::assertSame($data, $this->handler()->decryptProperties($data, $schema));
	}//end testDecryptPropertiesIsANoOpWithoutFlaggedProperties()
}//end class
