<?php

/**
 * Unit tests for the encryption of the copy before a destruction.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Exchange;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\CopySealerInterface;
use OCA\OpenRegister\Kernel\Contracts\ExportKeySealersInterface;
use OCA\OpenRegister\Service\Archival\DestructionCopies;
use OCA\OpenRegister\Service\Archival\DestructionCopyWriter;
use OCA\OpenRegister\Service\Exchange\EncryptedSetHeader;
use OCA\OpenRegister\Service\Exchange\EncryptedSetReader;
use OCA\OpenRegister\Service\Exchange\EncryptedSetWriter;
use OCA\OpenRegister\Service\Exchange\ExportKeySealer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `destructionCopyEncryption` off, when-key and required, with and without a usable export key.
 *
 * @covers \OCA\OpenRegister\Service\Archival\DestructionCopies
 * @covers \OCA\OpenRegister\Service\Archival\DestructionCopy
 */
class DestructionCopyEncryptionTest extends TestCase {

	private const ORG = 'org-zuiddrecht';

	private string $location = '';

	private string $key = '';

	/**
	 * How often the sealers were asked, by organisation.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->location = sys_get_temp_dir() . '/or-copy-encryption-' . bin2hex(random_bytes(4));
		mkdir($this->location);
		$this->key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
	}//end setUp()

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((array) glob($this->location . '/*/*') as $file) {
			unlink((string) $file);
		}

		foreach ((array) glob($this->location . '/*') as $directory) {
			rmdir((string) $directory);
		}

		rmdir($this->location);
	}//end tearDown()

	/**
	 * @return ObjectEntity
	 */
	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('7');
		$object->setSchema('8');
		$object->setObject(['titel' => 'Besluit over de Kerkstraat']);

		return $object;
	}//end object()

	/**
	 * The copies of one run.
	 *
	 * @param string|null $mode         The setting, null to leave it out.
	 * @param bool        $hasKey       Whether the organisation has a usable export key.
	 * @param string|null $location     The copy location; the temp directory by default.
	 * @param string|null $organisation The list's organisation.
	 *
	 * @return DestructionCopies
	 */
	private function copies(?string $mode, bool $hasKey, ?string $location=null, ?string $organisation=self::ORG): DestructionCopies {
		$settings = ['destructionCopyLocation' => ($location ?? $this->location)];
		if ($mode !== null) {
			$settings[DestructionCopies::SETTING] = $mode;
		}

		$sealer  = new ExportKeySealer(key: $this->key, writer: new EncryptedSetWriter());
		$sealers = $this->createMock(ExportKeySealersInterface::class);
		$sealers->method('sealerFor')->willReturnCallback(
			function (string $organisation) use ($hasKey, $sealer): ?CopySealerInterface {
				$this->asked[] = $organisation;
				return $hasKey === true ? $sealer : null;
			}
		);

		return new DestructionCopies(
			settings: $settings,
			writer: new DestructionCopyWriter(),
			sealers: static fn (): ExportKeySealersInterface => $sealers,
			organisation: $organisation
		);
	}//end copies()

	public function testOffIsTheDefaultAndWritesInTheClearWithoutAskingForAKey(): void {
		$copy = $this->copies(mode: null, hasKey: true)->write(listUuid: 'list-1', object: self::object());

		$this->assertSame($this->location . '/list-1/obj-1.json', $copy->path);
		$this->assertNull($copy->keyFingerprint);
		$this->assertStringContainsString('Kerkstraat', (string) file_get_contents($copy->path));
		$this->assertSame(['copy' => $copy->path], $copy->auditContext());
		$this->assertSame([], $this->asked);
	}

	public function testWhenKeyWithAKeyWritesAnEncryptedSetThatTheKeyOpens(): void {
		$copy = $this->copies(mode: 'when-key', hasKey: true)->write(listUuid: 'list-1', object: self::object());

		$this->assertSame($this->location . '/list-1/obj-1' . DestructionCopyWriter::SEALED_SUFFIX, $copy->path);
		$this->assertFileDoesNotExist($this->location . '/list-1/obj-1.json');
		$bytes = (string) file_get_contents($copy->path);
		$this->assertStringNotContainsString('Kerkstraat', $bytes);
		$this->assertStringStartsWith(EncryptedSetHeader::MAGIC, $bytes);

		$fingerprint = EncryptedSetHeader::fingerprint(key: $this->key);
		$this->assertSame($fingerprint, $copy->keyFingerprint);
		$this->assertSame(['copy' => $copy->path, 'copyKeyFingerprint' => $fingerprint], $copy->auditContext());

		$stream = fopen($copy->path, 'rb');
		$reader = new EncryptedSetReader();
		$header = $reader->header(encrypted: $stream);
		$this->assertSame($fingerprint, $header->fields['keyFingerprint']);
		$reader->verify(encrypted: $stream, key: $this->key, header: $header);
		fclose($stream);
	}

	public function testWhenKeyWithoutAKeyWritesInTheClear(): void {
		$copies = $this->copies(mode: 'when-key', hasKey: false);
		$copy   = $copies->write(listUuid: 'list-1', object: self::object());

		$this->assertSame($this->location . '/list-1/obj-1.json', $copy->path);
		$this->assertNull($copy->keyFingerprint);
		$this->assertNull($copies->refusal());
	}

	public function testRequiredWithoutAKeyWritesNothingAndNamesTheMissingKey(): void {
		$copies = $this->copies(mode: 'required', hasKey: false);

		try {
			$copies->write(listUuid: 'list-1', object: self::object());
			$this->fail('A required encryption without a key must refuse the copy.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('obj-1', $e->getMessage());
			$this->assertStringContainsString('no usable export key', $e->getMessage());
		}

		$this->assertSame([], (array) glob($this->location . '/list-1/*'));
		$this->assertStringContainsString('"' . self::ORG . '" has no usable export key', (string) $copies->refusal());
	}

	public function testRequiredWithAKeyEncrypts(): void {
		$copy = $this->copies(mode: 'required', hasKey: true)->write(listUuid: 'list-1', object: self::object());

		$this->assertSame(EncryptedSetHeader::fingerprint(key: $this->key), $copy->keyFingerprint);
	}

	public function testAListWithoutAnOrganisationHasNoKeyToUse(): void {
		$copies = $this->copies(mode: 'required', hasKey: true, organisation: null);

		$this->expectException(RuntimeException::class);
		try {
			$copies->write(listUuid: 'list-1', object: self::object());
		} finally {
			$this->assertSame([], $this->asked);
		}
	}

	public function testAnUnknownValueReadsAsRequired(): void {
		$this->assertSame(DestructionCopies::MODE_REQUIRED, DestructionCopies::mode(value: 'requird'));
		$this->assertSame(DestructionCopies::MODE_OFF, DestructionCopies::mode(value: ''));
		$this->assertSame(DestructionCopies::MODE_OFF, DestructionCopies::mode(value: null));
		$this->assertSame(DestructionCopies::MODE_WHEN_KEY, DestructionCopies::mode(value: 'when-key'));

		$this->expectException(RuntimeException::class);
		$this->copies(mode: 'requird', hasKey: false)->write(listUuid: 'list-1', object: self::object());
	}

	public function testWithoutALocationNoCopyIsWrittenInAnyModeAndNoKeyIsAsked(): void {
		foreach (['off', 'when-key', 'required'] as $mode) {
			$this->assertNull($this->copies(mode: $mode, hasKey: false, location: '')->write(listUuid: 'list-1', object: self::object()));
		}

		$this->assertSame([], $this->asked);
	}

	public function testTheKeyIsLookedUpOncePerRun(): void {
		$copies = $this->copies(mode: 'when-key', hasKey: true);
		$first  = self::object();
		$second = self::object();
		$second->setUuid('obj-2');

		$copies->write(listUuid: 'list-1', object: $first);
		$copies->write(listUuid: 'list-1', object: $second);

		$this->assertSame([self::ORG], $this->asked);
	}

	public function testALookupThatThrowsIsNotRememberedAsNoKey(): void {
		$sealer  = new ExportKeySealer(key: $this->key, writer: new EncryptedSetWriter());
		$sealers = $this->createMock(ExportKeySealersInterface::class);
		$sealers->expects($this->exactly(2))->method('sealerFor')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('store down')),
			$sealer
		);
		$copies = new DestructionCopies(
			settings: ['destructionCopyLocation' => $this->location, DestructionCopies::SETTING => 'when-key'],
			writer: new DestructionCopyWriter(),
			sealers: static fn (): ExportKeySealersInterface => $sealers,
			organisation: self::ORG
		);

		try {
			$copies->write(listUuid: 'list-1', object: self::object());
			$this->fail('A failed lookup must not fall back to a copy in the clear.');
		} catch (RuntimeException $e) {
			$this->assertSame('store down', $e->getMessage());
		}

		$this->assertFileDoesNotExist($this->location . '/list-1/obj-1.json');
		$this->assertNotNull($copies->write(listUuid: 'list-1', object: self::object())->keyFingerprint);
	}
}//end class
