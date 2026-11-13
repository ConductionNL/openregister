<?php

/**
 * Unit tests for the one-use job key: the job argument holds only a
 * credential id, the key is deleted after the job succeeds and after it
 * throws, a refused or foreign credential is left alone, and stale keys are
 * swept after 24 hours.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Exchange;

use DateTimeImmutable;
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
use OCA\OpenRegister\Service\Exchange\ExportKeyException;
use OCA\OpenRegister\Service\Exchange\OneUseJobKey;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Exchange\OneUseJobKey
 * @covers \OCA\OpenRegister\Service\Exchange\BrokeredKeys
 * @uses   \OCA\OpenRegister\Service\Exchange\BrokeredKeyRecord
 * @uses   \OCA\OpenRegister\Service\Exchange\ExportKeyException
 * @uses   \OCA\OpenRegister\Service\Exchange\EncryptedSetHeader
 * @uses   \OCA\OpenRegister\Service\Credential\OAuth2CredentialObjects
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialMint
 * @uses   \OCA\OpenRegister\Service\Credential\CredentialMintOptions
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 * @uses   \OCA\OpenRegister\Service\WriteCause
 */
class OneUseJobKeyTest extends TestCase {

	private const ORG = 'org-a';

	/** @var CredentialBrokerService&MockObject */
	private CredentialBrokerService $broker;

	/** @var ObjectOperationsInterface&MockObject */
	private ObjectOperationsInterface $objects;

	/** @var CredentialStore&MockObject */
	private CredentialStore $store;

	/** @var array<string, array{string, string, string}> uuid => [provider, secret, organisation] */
	private array $credentials = [];

	/** @var array<int, string> */
	private array $deletedObjects = [];

	/** @var array<int, string> */
	private array $deletedSecrets = [];

	private OneUseJobKey $jobKey;

	protected function setUp(): void {
		$this->broker  = $this->createMock(CredentialBrokerService::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->store   = $this->createMock(CredentialStore::class);

		$this->broker->method('mintCredential')->willReturnCallback(
			function (CredentialMint $mint, CredentialMintOptions $options): ObjectEntity {
				$uuid = '4b1c0f3e-0000-4000-8000-00000000000' . (count($this->credentials) + 1);
				$this->credentials[$uuid] = [$mint->provider, (string) $options->secret(), (string) $options->organisation];
				return self::entity(uuid: $uuid, provider: $mint->provider, createdAt: '2026-11-13T10:00:00+00:00');
			}
		);
		$this->broker->method('resolveInjectable')->willReturnCallback(
			function (string $credentialId, string $appId, ?string $actingUserId = null, ?string $actingOrganisationId = null): ?string {
				[, $secret, $organisation] = $this->credentials[$credentialId];
				if ($actingOrganisationId !== $organisation) {
					throw new CredentialAccessDeniedException('Request not permitted');
				}

				return $secret;
			}
		);
		$this->objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => isset($this->credentials[$id])
				? self::entity(uuid: (string) $id, provider: $this->credentials[$id][0], createdAt: null)
				: null
		);
		$this->objects->method('deleteObject')->willReturnCallback(function (ObjectDeletion $deletion): bool {
			$this->deletedObjects[] = $deletion->uuid;
			return true;
		});
		$this->store->method('delete')->willReturnCallback(function (string $uuid, string $scope): void {
			$this->deletedSecrets[] = $uuid . '@' . $scope;
		});
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();

		$keys         = new BrokeredKeys($this->broker, new OAuth2CredentialObjects($this->objects), $this->store, new NullLogger());
		$this->jobKey = new OneUseJobKey($keys, $this->objects);
	}//end setUp()

	private static function entity(string $uuid, string $provider, ?string $createdAt): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$data = ['provider' => $provider];
		if ($createdAt !== null) {
			$data['createdAt'] = $createdAt;
		}

		$entity->setObject($data);
		return $entity;
	}//end entity()

	public function testTheJobArgumentHoldsOnlyTheCredentialId(): void {
		$key = random_bytes(32);
		$this->broker->expects($this->once())->method('mintCredential')->with(
			$this->callback(fn (CredentialMint $m): bool => $m->provider === 'openregister-job-key'
				&& $m->name === 'Job key export-42' && $m->owner === 'admin'),
			$this->callback(fn (CredentialMintOptions $o): bool => $o->scope === 'organisation'
				&& $o->organisation === self::ORG && $o->allowedApps === ['openregister'])
		);

		$id       = $this->jobKey->store(jobName: 'export-42', key: $key, organisation: self::ORG, owner: 'admin');
		$argument = json_encode(['keyCredential' => $id]);

		$this->assertSame('4b1c0f3e-0000-4000-8000-000000000001', $id);
		$this->assertSame(BrokeredKeys::encode(key: $key), $this->credentials[$id][1]);
		$this->assertStringNotContainsString(BrokeredKeys::encode(key: $key), $argument);
		$this->assertStringNotContainsString(bin2hex($key), $argument);
	}//end testTheJobArgumentHoldsOnlyTheCredentialId()

	public function testAKeyThatIsNot32BytesIsNotStored(): void {
		$this->broker->expects($this->never())->method('mintCredential');

		$this->expectException(\InvalidArgumentException::class);
		$this->jobKey->store(jobName: 'export-42', key: 'short', organisation: self::ORG, owner: 'admin');
	}//end testAKeyThatIsNot32BytesIsNotStored()

	public function testTheCredentialIsGoneAfterASuccessfulJob(): void {
		$key = random_bytes(32);
		$id  = $this->jobKey->store(jobName: 'export-42', key: $key, organisation: self::ORG, owner: 'admin');

		$result = $this->jobKey->withKey(credentialId: $id, organisation: self::ORG, work: static fn (string $k): string => bin2hex($k));

		$this->assertSame(bin2hex($key), $result);
		$this->assertSame([$id], $this->deletedObjects);
		$this->assertSame([$id . '@organisation'], $this->deletedSecrets);
	}//end testTheCredentialIsGoneAfterASuccessfulJob()

	public function testTheCredentialIsGoneAfterAThrownJob(): void {
		$id = $this->jobKey->store(jobName: 'load-7', key: random_bytes(32), organisation: self::ORG, owner: 'admin');

		try {
			$this->jobKey->withKey(credentialId: $id, organisation: self::ORG, work: static function (): never {
				throw new RuntimeException('the load failed');
			});
			$this->fail('the job error was swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame('the load failed', $e->getMessage());
		}

		$this->assertSame([$id], $this->deletedObjects);
		$this->assertSame([$id . '@organisation'], $this->deletedSecrets);
	}//end testTheCredentialIsGoneAfterAThrownJob()

	public function testAnExportKeyNamedAsAJobKeyIsRefusedAndKept(): void {
		$this->credentials['export-key'] = ['openregister-export-key', BrokeredKeys::encode(key: random_bytes(32)), self::ORG];
		$ran = false;

		try {
			$this->jobKey->withKey(credentialId: 'export-key', organisation: self::ORG, work: static function () use (&$ran): void {
				$ran = true;
			});
			$this->fail('an export key was used as a job key');
		} catch (ExportKeyException $e) {
			$this->assertSame(ExportKeyException::MESSAGE, $e->getMessage());
		}

		$this->assertFalse($ran);
		$this->assertSame([], $this->deletedObjects);
		$this->assertSame([], $this->deletedSecrets);
	}//end testAnExportKeyNamedAsAJobKeyIsRefusedAndKept()

	public function testAnotherOrganisationsJobKeyIsRefusedAndKept(): void {
		$id = $this->jobKey->store(jobName: 'export-42', key: random_bytes(32), organisation: self::ORG, owner: 'admin');

		$this->expectException(CredentialAccessDeniedException::class);
		try {
			$this->jobKey->withKey(credentialId: $id, organisation: 'org-b', work: static fn (): null => null);
		} finally {
			$this->assertSame([], $this->deletedObjects);
		}
	}//end testAnotherOrganisationsJobKeyIsRefusedAndKept()

	public function testTheSweepDeletesOnlyJobKeysOlderThan24Hours(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['provider' => 'openregister-job-key']], false, false)
			->willReturn([
				self::entity(uuid: 'stale', provider: 'openregister-job-key', createdAt: '2026-11-12T09:59:59+00:00'),
				self::entity(uuid: 'exactly-24h', provider: 'openregister-job-key', createdAt: '2026-11-12T10:00:00+00:00'),
				self::entity(uuid: 'fresh', provider: 'openregister-job-key', createdAt: '2026-11-13T09:00:00+00:00'),
				self::entity(uuid: 'undated', provider: 'openregister-job-key', createdAt: null),
				self::entity(uuid: 'garbled', provider: 'openregister-job-key', createdAt: 'not a date'),
				self::entity(uuid: 'old-export-key', provider: 'openregister-export-key', createdAt: '2026-01-01T00:00:00+00:00'),
			]);

		$swept = $this->jobKey->sweep(now: new DateTimeImmutable('2026-11-13T10:00:00+00:00'));

		$this->assertSame(2, $swept);
		$this->assertSame(['stale', 'exactly-24h'], $this->deletedObjects);
		$this->assertSame(['stale@organisation', 'exactly-24h@organisation'], $this->deletedSecrets);
	}//end testTheSweepDeletesOnlyJobKeysOlderThan24Hours()

	public function testASweepThatCannotDeleteAKeyDoesNotCountIt(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->method('delete')->willThrowException(new RuntimeException('vault down'));
		$this->objects->method('findAll')->willReturn([
			self::entity(uuid: 'stale', provider: 'openregister-job-key', createdAt: '2026-11-01T00:00:00+00:00'),
		]);
		$jobKey = new OneUseJobKey(
			new BrokeredKeys($this->broker, new OAuth2CredentialObjects($this->objects), $store, new NullLogger()),
			$this->objects
		);

		$this->assertSame(0, $jobKey->sweep(now: new DateTimeImmutable('2026-11-13T10:00:00+00:00')));
		$this->assertSame(['stale'], $this->deletedObjects);
	}//end testASweepThatCannotDeleteAKeyDoesNotCountIt()
}//end class
