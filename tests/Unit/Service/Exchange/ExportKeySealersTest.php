<?php

/**
 * Unit tests for finding the export key an unattended copy is encrypted with.
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
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Exchange\BrokeredKeys;
use OCA\OpenRegister\Service\Exchange\EncryptedSetHeader;
use OCA\OpenRegister\Service\Exchange\EncryptedSetReader;
use OCA\OpenRegister\Service\Exchange\EncryptedSetWriter;
use OCA\OpenRegister\Service\Exchange\ExportKeyException;
use OCA\OpenRegister\Service\Exchange\ExportKeySealers;
use OCA\OpenRegister\Service\Exchange\ExportKeyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The newest usable export key of the organisation seals the copy; nothing else does.
 *
 * @covers \OCA\OpenRegister\Service\Exchange\ExportKeySealers
 * @covers \OCA\OpenRegister\Service\Exchange\ExportKeySealer
 */
class ExportKeySealersTest extends TestCase {

	private const ORG = 'org-zuiddrecht';

	/**
	 * Keys by credential id.
	 *
	 * @var array<string, string>
	 */
	private array $keys = [];

	/**
	 * Credential ids the broker refuses.
	 *
	 * @var array<int, string>
	 */
	private array $refused = [];

	/**
	 * Credential ids read back, in order.
	 *
	 * @var array<int, string>
	 */
	private array $used = [];

	/**
	 * Logged warnings: [message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * A credential object.
	 *
	 * @param string $uuid         The credential id.
	 * @param string $provider     The provider.
	 * @param string $organisation The organisation.
	 * @param string $createdAt    When it was minted.
	 *
	 * @return ObjectEntity
	 */
	private function credential(string $uuid, string $provider, string $organisation, string $createdAt): ObjectEntity {
		$this->keys[$uuid] = sodium_crypto_secretstream_xchacha20poly1305_keygen();
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['provider' => $provider, 'organisation' => $organisation, 'createdAt' => $createdAt, 'scope' => 'organisation']);

		return $object;
	}//end credential()

	/**
	 * The sealers over these credentials.
	 *
	 * @param array<int, ObjectEntity> $credentials The listed credentials.
	 *
	 * @return ExportKeySealers
	 */
	private function sealers(array $credentials): ExportKeySealers {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->with('credential-broker')->willReturnSelf();
		$objects->method('setSchema')->with('brokeredcredential')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac, bool $_multitenancy) use ($credentials): array {
				$this->assertSame(['filters' => ['provider' => BrokeredKeys::EXPORT_PROVIDER]], $config);
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				return $credentials;
			}
		);

		$exportKeys = $this->createMock(ExportKeyService::class);
		$exportKeys->method('use')->willReturnCallback(
			function (string $keyId, string $organisation): string {
				$this->assertSame(self::ORG, $organisation);
				$this->used[] = $keyId;
				if (in_array($keyId, $this->refused, true) === true) {
					throw new ExportKeyException('refused');
				}

				return $this->keys[$keyId];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new ExportKeySealers(exportKeys: $exportKeys, objectService: $objects, writer: new EncryptedSetWriter(), logger: $logger);
	}//end sealers()

	public function testTheNewestKeyOfTheOrganisationSealsAndItsKeyOpensTheSet(): void {
		$sealers = $this->sealers(
			[
				$this->credential('old', BrokeredKeys::EXPORT_PROVIDER, self::ORG, '2026-01-01T10:00:00+00:00'),
				$this->credential('new', BrokeredKeys::EXPORT_PROVIDER, self::ORG, '2026-11-01T10:00:00+00:00'),
				$this->credential('foreign', BrokeredKeys::EXPORT_PROVIDER, 'org-elders', '2026-11-20T10:00:00+00:00'),
				$this->credential('job', BrokeredKeys::JOB_PROVIDER, self::ORG, '2026-11-21T10:00:00+00:00'),
			]
		);

		$sealer = $sealers->sealerFor(organisation: self::ORG);

		$this->assertNotNull($sealer);
		$this->assertSame(['new'], $this->used);
		$this->assertSame(EncryptedSetHeader::fingerprint(key: $this->keys['new']), $sealer->fingerprint());

		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $sealer->seal(plain: '{"titel":"Besluit"}'));
		rewind($stream);
		$reader = new EncryptedSetReader();
		$header = $reader->header(encrypted: $stream);
		$reader->verify(encrypted: $stream, key: $this->keys['new'], header: $header);
		$this->assertSame($sealer->fingerprint(), $header->fields['keyFingerprint']);
	}

	public function testARefusedNewestKeyIsPassedOverForTheNextOne(): void {
		$sealers = $this->sealers(
			[
				$this->credential('old', BrokeredKeys::EXPORT_PROVIDER, self::ORG, '2026-01-01T10:00:00+00:00'),
				$this->credential('new', BrokeredKeys::EXPORT_PROVIDER, self::ORG, '2026-11-01T10:00:00+00:00'),
			]
		);
		$this->refused = ['new'];

		$sealer = $sealers->sealerFor(organisation: self::ORG);

		$this->assertSame(['new', 'old'], $this->used);
		$this->assertSame(EncryptedSetHeader::fingerprint(key: $this->keys['old']), $sealer?->fingerprint());
		$this->assertSame('new', $this->warnings[0][1]['keyId']);
		$this->assertStringNotContainsString(bin2hex($this->keys['new']), json_encode($this->warnings));
	}

	public function testAnOrganisationWithoutAUsableKeyHasNoSealer(): void {
		$sealers = $this->sealers(
			[
				$this->credential('only', BrokeredKeys::EXPORT_PROVIDER, self::ORG, '2026-01-01T10:00:00+00:00'),
				$this->credential('foreign', BrokeredKeys::EXPORT_PROVIDER, 'org-elders', '2026-11-20T10:00:00+00:00'),
			]
		);
		$this->refused = ['only'];

		$this->assertNull($sealers->sealerFor(organisation: self::ORG));
		$this->assertSame(['only'], $this->used);
	}

	public function testAnEmptyOrganisationHasNoSealerAndNothingIsRead(): void {
		$this->assertNull($this->sealers([])->sealerFor(organisation: ' '));
		$this->assertSame([], $this->used);
	}
}//end class
