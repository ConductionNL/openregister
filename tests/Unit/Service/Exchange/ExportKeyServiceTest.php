<?php

/**
 * Unit tests for export keys: stored in the broker as organisation
 * credentials, shown once, audited by fingerprint only, never logged, and
 * refused to another organisation by the broker's own guard.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Exchange;

use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ExportOutcome;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\OAuth2CredentialObjects;
use OCA\OpenRegister\Service\Exchange\BrokeredKeys;
use OCA\OpenRegister\Service\Exchange\EncryptedSetException;
use OCA\OpenRegister\Service\Exchange\EncryptedSetHeader;
use OCA\OpenRegister\Service\Exchange\ExportKeyException;
use OCA\OpenRegister\Service\Exchange\ExportKeyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Exchange\ExportKeyService
 * @covers \OCA\OpenRegister\Service\Exchange\BrokeredKeys
 * @covers \OCA\OpenRegister\Service\Exchange\ExportKeyCreated
 * @covers \OCA\OpenRegister\Service\Exchange\ExportKeyException
 * @uses   \OCA\OpenRegister\Service\Exchange\BrokeredKeyRecord
 * @uses   \OCA\OpenRegister\Service\Exchange\EncryptedSetHeader
 * @uses   \OCA\OpenRegister\Service\Exchange\EncryptedSetException
 * @uses   \OCA\OpenRegister\Service\Credential\OAuth2CredentialObjects
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialMint
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialMintOptions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\AuditTrail\ExportOutcome
 * @uses   \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class ExportKeyServiceTest extends TestCase {

	private const ORG = 'org-a';

	/** @var CredentialBrokerService&MockObject */
	private CredentialBrokerService $broker;

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var CredentialStore&MockObject */
	private CredentialStore $store;

	/** @var AuditTrailMapperInterface&MockObject */
	private AuditTrailMapperInterface $audit;

	/** @var array<int, ExportOutcome> */
	private array $auditRows = [];

	/** @var array<int, array{string, array<mixed>}> */
	private array $logLines = [];

	/** @var array<string, array{string, string, string}> uuid => [provider, secret, organisation] */
	private array $credentials = [];

	private ExportKeyService $service;

	protected function setUp(): void {
		$this->broker  = $this->createMock(CredentialBrokerService::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->store   = $this->createMock(CredentialStore::class);
		$this->audit   = $this->createMock(AuditTrailMapperInterface::class);

		$this->audit->method('recordExport')->willReturnCallback(function (ExportOutcome $export): AuditTrail {
			$this->auditRows[] = $export;
			return new AuditTrail();
		});

		$this->broker->method('mintCredential')->willReturnCallback(
			function (CredentialMint $mint, CredentialMintOptions $options): ObjectEntity {
				$uuid = 'cred-' . (count($this->credentials) + 1);
				$this->credentials[$uuid] = [$mint->provider, (string) $options->secret(), (string) $options->organisation];
				return self::entity(uuid: $uuid, provider: $mint->provider);
			}
		);

		// The broker's guard: an organisation credential resolves only for its own organisation.
		$this->broker->method('resolveInjectable')->willReturnCallback(
			function (string $credentialId, string $appId, ?string $actingUserId = null, ?string $actingOrganisationId = null): ?string {
				[, $secret, $organisation] = $this->credentials[$credentialId];
				if ($appId !== BrokeredKeys::APP_ID || $actingOrganisationId !== $organisation) {
					throw new CredentialAccessDeniedException('Request not permitted');
				}

				return $secret;
			}
		);

		$this->objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($this->credentials[$id])
				? self::entity(uuid: (string) $id, provider: $this->credentials[$id][0])
				: null
		);

		$logger = new class($this->logLines) extends AbstractLogger {
			/** @param array<int, array{string, array<mixed>}> $lines */
			public function __construct(private array &$lines) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->lines[] = [(string) $message, $context];
			}
		};

		$keys          = new BrokeredKeys($this->broker, new OAuth2CredentialObjects($this->objects), $this->store, $logger);
		$this->service = new ExportKeyService($keys, $this->audit, $logger);
	}//end setUp()

	private static function entity(string $uuid, string $provider): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['provider' => $provider, 'createdAt' => '2026-11-13T10:00:00+00:00']);
		return $entity;
	}//end entity()

	public function testCreateStoresAnOrganisationCredentialAndShowsTheKeyOnce(): void {
		$this->broker->expects($this->once())->method('mintCredential')->with(
			$this->callback(fn (CredentialMint $mint): bool => $mint->provider === 'openregister-export-key'
				&& $mint->owner === 'admin'
				&& str_starts_with($mint->name, 'Export key ')),
			$this->callback(fn (CredentialMintOptions $o): bool => $o->scope === 'organisation'
				&& $o->organisation === self::ORG
				&& $o->allowedApps === ['openregister'])
		);

		$created = $this->service->create(organisation: self::ORG, owner: 'admin');

		$key = BrokeredKeys::decode(secret: $created->recovery);
		$this->assertSame(32, strlen($key));
		$this->assertSame('cred-1', $created->keyId);
		$this->assertSame(EncryptedSetHeader::fingerprint(key: $key), $created->fingerprint);
		$this->assertSame($created->recovery, $this->credentials['cred-1'][1]);
		$this->assertStringNotContainsString($created->recovery, print_r($created, true));

		$this->assertCount(1, $this->auditRows);
		$this->assertSame('key-created', $this->auditRows[0]->outcome);
		$this->assertSame('admin', $this->auditRows[0]->actorId);
		$this->assertSame(
			['keyId' => 'cred-1', 'fingerprint' => $created->fingerprint, 'organisation' => self::ORG],
			$this->auditRows[0]->summary
		);
	}//end testCreateStoresAnOrganisationCredentialAndShowsTheKeyOnce()

	public function testTwoKeysAreDifferent(): void {
		$first  = $this->service->create(organisation: self::ORG, owner: 'admin');
		$second = $this->service->create(organisation: self::ORG, owner: 'admin');

		$this->assertNotSame($first->recovery, $second->recovery);
		$this->assertNotSame($first->fingerprint, $second->fingerprint);
	}//end testTwoKeysAreDifferent()

	public function testTheKeyNeverAppearsInALogContextOrAnAuditRow(): void {
		$created = $this->service->create(organisation: self::ORG, owner: 'admin');
		$key     = $this->service->use(keyId: $created->keyId, organisation: self::ORG);
		$rotated = $this->service->rotate(previousKeyId: $created->keyId, organisation: self::ORG, owner: 'admin');
		$this->service->delete(keyId: $rotated->keyId, organisation: self::ORG, owner: 'admin');

		$this->assertSame(
			['key-created', 'key-used', 'key-rotated', 'key-deleted'],
			array_map(static fn (ExportOutcome $row): string => $row->outcome, $this->auditRows)
		);

		$haystack = serialize($this->collectAuditSummaries()) . serialize($this->logLines);
		foreach ([$key, $created->recovery, $rotated->recovery, bin2hex($key)] as $secret) {
			$this->assertStringNotContainsString($secret, $haystack);
		}
	}//end testTheKeyNeverAppearsInALogContextOrAnAuditRow()

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function collectAuditSummaries(): array {
		return array_map(static fn (ExportOutcome $row): array => [$row->outcome => $row->summary], $this->auditRows);
	}//end collectAuditSummaries()

	public function testUseReturnsTheKeyAndRecordsTheUseByFingerprint(): void {
		$created = $this->service->create(organisation: self::ORG, owner: 'admin');

		$key = $this->service->use(keyId: $created->keyId, organisation: self::ORG);

		$this->assertSame(BrokeredKeys::decode(secret: $created->recovery), $key);
		$this->assertSame('key-used', $this->auditRows[1]->outcome);
		$this->assertNull($this->auditRows[1]->actorId);
		$this->assertSame($created->fingerprint, $this->auditRows[1]->summary['fingerprint']);
	}//end testUseReturnsTheKeyAndRecordsTheUseByFingerprint()

	public function testAnotherOrganisationsKeyIsRefusedByTheBrokerGuard(): void {
		$created = $this->service->create(organisation: self::ORG, owner: 'admin');

		try {
			$this->service->use(keyId: $created->keyId, organisation: 'org-b');
			$this->fail('another organisation used the key');
		} catch (CredentialAccessDeniedException $e) {
			$this->assertSame('Request not permitted', $e->getMessage());
		}

		$this->assertCount(1, $this->auditRows, 'a refused use writes no use row');
	}//end testAnotherOrganisationsKeyIsRefusedByTheBrokerGuard()

	public function testAnotherOrganisationCannotDeleteTheKey(): void {
		$created = $this->service->create(organisation: self::ORG, owner: 'admin');
		$this->objects->expects($this->never())->method('deleteObject');
		$this->store->expects($this->never())->method('delete');

		$this->expectException(CredentialAccessDeniedException::class);
		$this->service->delete(keyId: $created->keyId, organisation: 'org-b', owner: 'intruder');
	}//end testAnotherOrganisationCannotDeleteTheKey()

	public function testAnInjectOnlyCredentialOfAnotherProviderIsNotAKey(): void {
		$this->credentials['cred-x'] = ['generic-apikey', BrokeredKeys::encode(key: str_repeat('k', 32)), self::ORG];
		$this->broker->expects($this->never())->method('resolveInjectable');

		$this->expectException(ExportKeyException::class);
		$this->expectExceptionMessage('This key cannot be used.');
		$this->service->use(keyId: 'cred-x', organisation: self::ORG);
	}//end testAnInjectOnlyCredentialOfAnotherProviderIsNotAKey()

	public function testAMissingCredentialIsNotAKey(): void {
		$this->expectException(ExportKeyException::class);
		$this->service->use(keyId: 'cred-missing', organisation: self::ORG);
	}//end testAMissingCredentialIsNotAKey()

	public function testRotateKeepsTheOldKeyAndAuditsBothFingerprints(): void {
		$old = $this->service->create(organisation: self::ORG, owner: 'admin');
		$this->objects->expects($this->never())->method('deleteObject');

		$new = $this->service->rotate(previousKeyId: $old->keyId, organisation: self::ORG, owner: 'admin');

		$this->assertNotSame($old->keyId, $new->keyId);
		$this->assertSame(BrokeredKeys::decode(secret: $old->recovery), $this->service->use(keyId: $old->keyId, organisation: self::ORG));
		$this->assertSame('key-rotated', $this->auditRows[1]->outcome);
		$this->assertSame(
			[
				'keyId' => $new->keyId,
				'fingerprint' => $new->fingerprint,
				'previousKeyId' => $old->keyId,
				'previousFingerprint' => $old->fingerprint,
				'organisation' => self::ORG,
			],
			$this->auditRows[1]->summary
		);
	}//end testRotateKeepsTheOldKeyAndAuditsBothFingerprints()

	public function testDeleteRemovesTheObjectAndTheSecret(): void {
		$created = $this->service->create(organisation: self::ORG, owner: 'admin');
		$this->objects->expects($this->once())->method('deleteObject')
			->with($this->callback(fn (ObjectDeletion $d): bool => $d->uuid === $created->keyId), false, false)
			->willReturn(true);
		$this->store->expects($this->once())->method('delete')->with($created->keyId, 'organisation');

		$this->assertTrue($this->service->delete(keyId: $created->keyId, organisation: self::ORG, owner: 'admin'));
		$this->assertSame('key-deleted', $this->auditRows[1]->outcome);
		$this->assertSame($created->fingerprint, $this->auditRows[1]->summary['fingerprint']);
		$this->assertTrue($this->auditRows[1]->summary['removed']);
	}//end testDeleteRemovesTheObjectAndTheSecret()

	public function testADeleteThatCannotRemoveTheSecretSaysSoAndLogsOnlyTheId(): void {
		$created = $this->service->create(organisation: self::ORG, owner: 'admin');
		$this->store->method('delete')->willThrowException(new RuntimeException('vault down'));

		$this->assertFalse($this->service->delete(keyId: $created->keyId, organisation: self::ORG, owner: 'admin'));
		$this->assertFalse($this->auditRows[1]->summary['removed']);
		$this->assertSame(['credential' => $created->keyId], end($this->logLines)[1]);
	}//end testADeleteThatCannotRemoveTheSecretSaysSoAndLogsOnlyTheId()

	public function testAFailedAuditWriteIsLoggedWithoutTheKeyAndDoesNotUndoTheCreate(): void {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordExport')->willThrowException(new RuntimeException('chain broken'));
		$logger  = new class($this->logLines) extends AbstractLogger {
			/** @param array<int, array{string, array<mixed>}> $lines */
			public function __construct(private array &$lines) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->lines[] = [(string) $message, $context];
			}
		};
		$service = new ExportKeyService(
			new BrokeredKeys($this->broker, new OAuth2CredentialObjects($this->objects), $this->store, $logger),
			$audit,
			$logger
		);

		$created = $service->create(organisation: self::ORG, owner: 'admin');

		$this->assertSame('cred-1', $created->keyId);
		$this->assertSame(
			['[ExportKeyService] the audit row could not be written', ['event' => 'export.key-created', 'fingerprint' => $created->fingerprint]],
			end($this->logLines)
		);
		$this->assertStringNotContainsString($created->recovery, serialize($this->logLines));
	}//end testAFailedAuditWriteIsLoggedWithoutTheKeyAndDoesNotUndoTheCreate()

	public function testAPassphraseIsStretchedWithTheHeadersParameters(): void {
		$header = EncryptedSetHeader::forPassphrase(
			salt: str_repeat("\x01", SODIUM_CRYPTO_PWHASH_SALTBYTES),
			opslimit: SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			memlimit: SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
		);

		$key = $this->service->passphraseKey(passphrase: 'correct horse battery', header: $header);

		$this->assertSame(32, strlen($key));
		$this->assertSame($key, $this->service->passphraseKey(passphrase: 'correct horse battery', header: $header));
		$this->assertNotSame($key, $this->service->passphraseKey(passphrase: 'correct horse batterz', header: $header));
		$this->assertSame(
			sodium_crypto_pwhash(
				32,
				'correct horse battery',
				str_repeat("\x01", SODIUM_CRYPTO_PWHASH_SALTBYTES),
				SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
				SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
				SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
			),
			$key
		);
	}//end testAPassphraseIsStretchedWithTheHeadersParameters()

	public function testAPassphraseShorterThanTwelveCharactersIsRefused(): void {
		$header = EncryptedSetHeader::forPassphrase(salt: str_repeat("\x01", 16), opslimit: 2, memlimit: 8192);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A passphrase has at least 12 characters.');
		$this->service->passphraseKey(passphrase: 'elevenchars', header: $header);
	}//end testAPassphraseShorterThanTwelveCharactersIsRefused()

	public function testTwelveMultibyteCharactersAreEnough(): void {
		$header = EncryptedSetHeader::forPassphrase(
			salt: str_repeat("\x02", 16),
			opslimit: SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			memlimit: SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
		);

		$this->assertSame(32, strlen($this->service->passphraseKey(passphrase: str_repeat('é', 12), header: $header)));
	}//end testTwelveMultibyteCharactersAreEnough()

	public function testAKeyHeaderCannotBeOpenedWithAPassphrase(): void {
		$this->expectException(EncryptedSetException::class);
		$this->service->passphraseKey(passphrase: 'correct horse battery', header: EncryptedSetHeader::forKey(key: str_repeat('k', 32)));
	}//end testAKeyHeaderCannotBeOpenedWithAPassphrase()

	public function testANewPassphraseHeaderUsesAFreshSaltAndTheModerateLimits(): void {
		$first  = $this->service->passphraseHeader();
		$second = $this->service->passphraseHeader();

		$this->assertSame('argon2id13', $first->fields['kdf']);
		$this->assertSame(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, $first->fields['opslimit']);
		$this->assertSame(SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, $first->fields['memlimit']);
		$this->assertSame(16, strlen(sodium_base642bin($first->fields['salt'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
		$this->assertNotSame($first->fields['salt'], $second->fields['salt']);
	}//end testANewPassphraseHeaderUsesAFreshSaltAndTheModerateLimits()

	public function testARecoveryStringThatIsNotAKeyIsRefused(): void {
		foreach ([null, '', '***', BrokeredKeys::encode(key: str_repeat('k', 31))] as $secret) {
			try {
				BrokeredKeys::decode(secret: $secret);
				$this->fail('decoded a non-key');
			} catch (ExportKeyException $e) {
				$this->assertSame(ExportKeyException::MESSAGE, $e->getMessage());
			}
		}
	}//end testARecoveryStringThatIsNotAKeyIsRefused()
}//end class
