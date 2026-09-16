<?php

/**
 * Unit tests for applying a set as one unit, and undoing it as another.
 *
 * The two scenarios REQ-CAD-002 is made of are here: nine values restored in
 * one act with a new record naming what it restored, and a set holding one
 * failing value applying NOTHING and naming the value that refused.
 *
 * The second is asserted the only way that means anything: by proving the
 * value store was never written to at all. A test that only checked the
 * exception would pass just as happily on an implementation that wrote six of
 * nine values and then threw.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationDeployment;
use OCA\OpenRegister\Db\ConfigurationDeploymentMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class DeploymentServiceTest extends TestCase {

	private function set(
		string $state = ConfigurationDraftSet::STATE_APPROVED,
		string $author = 'author',
		?string $approver = 'reviewer'
	): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('september tuning');
		$set->setState($state);
		$set->setCreatedBy($author);
		$set->setApprovedBy($approver);

		return $set;
	}//end set()

	private function session(string $uid = 'deployer'): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	private function change(string $key, mixed $from, mixed $to, bool $fromPresent = true): array {
		return [
			'draft' => 'draft-'.$key,
			'layer' => ConfigurationLayer::INSTANCE,
			'layerRef' => null,
			'key' => $key,
			'removes' => false,
			'from' => $from,
			'fromPresent' => $fromPresent,
			'to' => $to,
			'status' => 'change',
		];
	}//end change()

	/**
	 * The service over staged collaborators.
	 *
	 * @param ConfigurationDraftSet                   $set        The set being deployed.
	 * @param array<string, mixed>                    $preview    What the preview answers.
	 * @param bool                                    $fourEyes   Whether the instance requires four eyes.
	 * @param (MockObject&ConfigurationValueStore)|null $store    A staged store, or null for a fresh one.
	 * @param (MockObject&ConfigurationDeploymentMapper)|null $mapper A staged history, or null for a fresh one.
	 *
	 * @return array{0: DeploymentService, 1: MockObject&ConfigurationValueStore, 2: MockObject&ConfigurationDeploymentMapper}
	 */
	private function service(
		ConfigurationDraftSet $set,
		array $preview,
		bool $fourEyes = false,
		?MockObject $store = null,
		?MockObject $mapper = null
	): array {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('loadSet')->willReturn($set);
		$drafts->method('requiresFourEyes')->willReturn($fourEyes);

		$previews = $this->createMock(DeploymentPreviewService::class);
		$previews->method('preview')->willReturn($preview);

		if ($store === null) {
			$store = $this->createMock(ConfigurationValueStore::class);
			$store->method('write')->willReturn(['layer' => ConfigurationLayer::INSTANCE, 'key' => 'x', 'present' => false, 'raw' => '']);
		}

		if ($mapper === null) {
			$mapper = $this->createMock(ConfigurationDeploymentMapper::class);
			$mapper->method('createFromArray')->willReturnCallback(
				static function (array $data): ConfigurationDeployment {
					$deployment = new ConfigurationDeployment();
					$deployment->hydrate($data);
					$deployment->setChangeCount(count($deployment->readChanges()));

					return $deployment;
				}
			);
		}

		$service = new DeploymentService(
			$drafts,
			$previews,
			$store,
			$mapper,
			$this->createMock(ConfigurationDraftSetMapper::class),
			$this->createMock(IDBConnection::class),
			$this->session(),
			new NullLogger()
		);

		return [$service, $store, $mapper];
	}//end service()

	public function testADeploymentRecordsBothSidesOfEveryValueItMoved(): void {
		$preview = [
			'changes' => [
				$this->change('rbac', ['enabled' => true], ['enabled' => false]),
				$this->change('solr', ['enabled' => false], ['enabled' => true]),
			],
			'refusals' => [],
		];

		[$service, $store] = $this->service($this->set(), $preview);
		$store->expects($this->exactly(2))->method('write');

		$deployment = $service->deploy('set-1', 'september tuning');

		$this->assertSame('september tuning', $deployment->getName());
		$this->assertSame('author', $deployment->getAuthor());
		$this->assertSame('reviewer', $deployment->getApprover());
		$this->assertSame('deployer', $deployment->getDeployedBy());
		$this->assertCount(2, $deployment->readChanges());
		$this->assertSame(['enabled' => true], $deployment->readChanges()[0]['previous']);
		$this->assertSame(['enabled' => false], $deployment->readChanges()[0]['value']);
		$this->assertFalse($deployment->isRollback());
	}//end testADeploymentRecordsBothSidesOfEveryValueItMoved()

	public function testASetHoldingOneRefusingValueWritesNothingAtAll(): void {
		$preview = [
			'changes' => [
				$this->change('rbac', ['enabled' => true], ['enabled' => false]),
				$this->change('solr', ['enabled' => false], ['enabled' => true]),
			],
			'refusals' => [
				['key' => 'llm', 'reason' => 'declared as object and the value is string', 'refusal' => 'invalid-value'],
			],
		];

		$store = $this->createMock(ConfigurationValueStore::class);
		// The assertion that separates "applies none" from "applies some and
		// then throws": the store is never touched.
		$store->expects($this->never())->method('write');
		$store->expects($this->never())->method('remove');

		[$service] = $this->service($this->set(), $preview, false, $store);

		try {
			$service->deploy('set-1');
			$this->fail('a set holding a refusing value must not deploy');
		} catch (DeploymentRefusedException $exception) {
			$this->assertSame(DeploymentRefusedException::REASON_VALUE_REFUSED, $exception->getReason());
			$this->assertStringContainsString('llm', $exception->getMessage());
			$this->assertStringContainsString('nothing was applied', $exception->getMessage());
			$this->assertSame(0, $exception->toResponseBody()['applied']);
		}
	}//end testASetHoldingOneRefusingValueWritesNothingAtAll()

	public function testUnderFourEyesAnAuthorCannotDeployTheirOwnSet(): void {
		[$service, $store] = $this->service(
			$this->set(ConfigurationDraftSet::STATE_APPROVED, 'author', 'author'),
			['changes' => [$this->change('rbac', [], ['enabled' => false])], 'refusals' => []],
			true
		);
		$store->expects($this->never())->method('write');

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('approver other than the author');

		$service->deploy('set-1');
	}//end testUnderFourEyesAnAuthorCannotDeployTheirOwnSet()

	public function testUnderFourEyesAnUnapprovedSetCannotDeploy(): void {
		[$service] = $this->service(
			$this->set(ConfigurationDraftSet::STATE_OPEN, 'author', null),
			['changes' => [$this->change('rbac', [], ['enabled' => false])], 'refusals' => []],
			true
		);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('requires an approval');

		$service->deploy('set-1');
	}//end testUnderFourEyesAnUnapprovedSetCannotDeploy()

	public function testWithoutFourEyesAnOpenSetDeploysStraightThrough(): void {
		[$service] = $this->service(
			$this->set(ConfigurationDraftSet::STATE_OPEN, 'author', null),
			['changes' => [$this->change('rbac', ['enabled' => true], ['enabled' => false])], 'refusals' => []],
			false
		);

		$deployment = $service->deploy('set-1');

		$this->assertCount(1, $deployment->readChanges());
		$this->assertNull($deployment->getApprover());
	}//end testWithoutFourEyesAnOpenSetDeploysStraightThrough()

	public function testASetAlreadyDeployedCannotBeDeployedAgain(): void {
		[$service] = $this->service(
			$this->set(ConfigurationDraftSet::STATE_DEPLOYED),
			['changes' => [$this->change('rbac', [], ['enabled' => false])], 'refusals' => []]
		);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('cannot be deployed');

		$service->deploy('set-1');
	}//end testASetAlreadyDeployedCannotBeDeployedAgain()

	public function testASetThatWouldChangeNothingIsRefusedRatherThanRecorded(): void {
		[$service] = $this->service($this->set(), ['changes' => [], 'refusals' => []]);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('would change nothing');

		$service->deploy('set-1');
	}//end testASetThatWouldChangeNothingIsRefusedRatherThanRecorded()

	public function testABrokenWeekendIsUndoneInOneActAndTheHistoryKeepsBothRows(): void {
		$original = new ConfigurationDeployment();
		$original->setUuid('dep-1');
		$original->setName('friday afternoon');
		$original->setChanges(
			array_map(
				static fn (int $index): array => [
					'layer' => ConfigurationLayer::INSTANCE,
					'layerRef' => null,
					'key' => 'setting_'.$index,
					'previous' => ['n' => $index],
					'previousPresent' => true,
					'value' => ['n' => 999],
					'removes' => false,
				],
				range(1, 9)
			)
		);

		$mapper = $this->createMock(ConfigurationDeploymentMapper::class);
		$mapper->method('findByUuid')->willReturn($original);
		$mapper->method('createFromArray')->willReturnCallback(
			static function (array $data): ConfigurationDeployment {
				$deployment = new ConfigurationDeployment();
				$deployment->hydrate($data);

				return $deployment;
			}
		);
		// Append-only: undoing writes a new row and never touches the old one.
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->never())->method('delete');

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static fn (string $layer, ?string $ref, string $key): ConfigurationSnapshot
				=> new ConfigurationSnapshot($layer, $ref, $key, ['n' => 999], true, 'dep-1', 'author')
		);
		$store->method('write')->willReturn(['layer' => ConfigurationLayer::INSTANCE, 'key' => 'x', 'present' => false, 'raw' => '']);
		$store->expects($this->exactly(9))->method('write');

		[$service] = $this->service($this->set(), ['changes' => [], 'refusals' => []], false, $store, $mapper);

		$rollback = $service->rollback('dep-1');

		$this->assertTrue($rollback->isRollback());
		$this->assertSame('dep-1', $rollback->getRestoresUuid());
		$this->assertSame('rollback of friday afternoon', $rollback->getName());
		$this->assertCount(9, $rollback->readChanges());
		$this->assertSame(['n' => 1], $rollback->readChanges()[0]['value']);
		$this->assertSame(['n' => 999], $rollback->readChanges()[0]['previous']);
	}//end testABrokenWeekendIsUndoneInOneActAndTheHistoryKeepsBothRows()

	public function testRollingBackAValueThatDidNotExistBeforeRemovesItAgain(): void {
		$original = new ConfigurationDeployment();
		$original->setUuid('dep-2');
		$original->setName('first solr');
		$original->setChanges(
			[
				[
					'layer' => ConfigurationLayer::INSTANCE,
					'layerRef' => null,
					'key' => 'solr',
					'previous' => null,
					// The key did not exist before that deployment, so undoing
					// it must delete the key rather than write a null over it.
					'previousPresent' => false,
					'value' => ['enabled' => true],
					'removes' => false,
				],
			]
		);

		$mapper = $this->createMock(ConfigurationDeploymentMapper::class);
		$mapper->method('findByUuid')->willReturn($original);
		$mapper->method('createFromArray')->willReturnCallback(
			static function (array $data): ConfigurationDeployment {
				$deployment = new ConfigurationDeployment();
				$deployment->hydrate($data);

				return $deployment;
			}
		);

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturn(
			new ConfigurationSnapshot(ConfigurationLayer::INSTANCE, null, 'solr', ['enabled' => true], true, 'dep-2', 'a')
		);
		$store->method('remove')->willReturn(['layer' => ConfigurationLayer::INSTANCE, 'key' => 'solr', 'present' => true, 'raw' => '{}']);
		$store->expects($this->once())->method('remove');
		$store->expects($this->never())->method('write');

		[$service] = $this->service($this->set(), ['changes' => [], 'refusals' => []], false, $store, $mapper);

		$rollback = $service->rollback('dep-2');

		$this->assertTrue($rollback->readChanges()[0]['removes']);
	}//end testRollingBackAValueThatDidNotExistBeforeRemovesItAgain()

	public function testRollingBackADeploymentThatMovedNothingIsRefused(): void {
		$original = new ConfigurationDeployment();
		$original->setUuid('dep-3');
		$original->setName('empty');
		$original->setChanges([]);

		$mapper = $this->createMock(ConfigurationDeploymentMapper::class);
		$mapper->method('findByUuid')->willReturn($original);

		[$service] = $this->service($this->set(), ['changes' => [], 'refusals' => []], false, null, $mapper);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('nothing to restore');

		$service->rollback('dep-3');
	}//end testRollingBackADeploymentThatMovedNothingIsRefused()

	public function testAWriteThatThrowsMidApplyPutsTheEarlierWritesBack(): void {
		$store = $this->createMock(ConfigurationValueStore::class);
		$calls = 0;
		$store->method('write')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				if ($calls === 3) {
					throw new RuntimeException('the third value refused');
				}

				return ['layer' => ConfigurationLayer::INSTANCE, 'key' => 'k'.$calls, 'present' => true, 'raw' => 'old'];
			}
		);
		// Two writes landed before the third threw, and both are put back.
		$store->expects($this->once())
			->method('restore')
			->with($this->countOf(2))
			->willReturn(2);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('loadSet')->willReturn($this->set());
		$drafts->method('requiresFourEyes')->willReturn(false);

		$previews = $this->createMock(DeploymentPreviewService::class);
		$previews->method('preview')->willReturn(
			[
				'changes' => [
					$this->change('rbac', [], ['enabled' => false]),
					$this->change('solr', [], ['enabled' => true]),
					$this->change('llm', [], ['provider' => 'ollama']),
				],
				'refusals' => [],
			]
		);

		$service = new DeploymentService(
			$drafts,
			$previews,
			$store,
			$this->createMock(ConfigurationDeploymentMapper::class),
			$this->createMock(ConfigurationDraftSetMapper::class),
			$db,
			$this->session(),
			new NullLogger()
		);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('undone in full');

		$service->deploy('set-1');
	}//end testAWriteThatThrowsMidApplyPutsTheEarlierWritesBack()
}//end class
