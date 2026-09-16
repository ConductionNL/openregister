<?php

/**
 * Unit tests for the half of the lifecycle before anything is live.
 *
 * REQ-CAD-001's two scenarios: an edit that does not take effect yet, and an
 * instance requiring an approver other than the author refusing a self-approval
 * by name.
 *
 * The first is asserted by proving the value store is never written to, not by
 * reading the setting back through a mock that would have answered the same
 * either way.
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

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftMapper;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Db\ConfigurationDraftSetMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentRefusedException;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConfigurationDraftServiceTest extends TestCase {

	private function set(
		string $state = ConfigurationDraftSet::STATE_OPEN,
		string $author = 'author'
	): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('september tuning');
		$set->setState($state);
		$set->setCreatedBy($author);

		return $set;
	}//end set()

	/**
	 * The service over staged collaborators.
	 *
	 * @param ConfigurationDraftSet|null $set      The set findByUuid answers with.
	 * @param ConfigurationDraft[]       $drafts   The pending values in that set.
	 * @param bool                       $fourEyes Whether the instance requires four eyes.
	 * @param string                     $uid      The acting principal.
	 *
	 * @return array{0: ConfigurationDraftService, 1: MockObject&ConfigurationValueStore, 2: MockObject&ConfigurationDraftMapper}
	 */
	private function service(
		?ConfigurationDraftSet $set,
		array $drafts = [],
		bool $fourEyes = false,
		string $uid = 'author'
	): array {
		$sets = $this->createMock(ConfigurationDraftSetMapper::class);
		if ($set === null) {
			$sets->method('findByUuid')->willThrowException(
				new \OCP\AppFramework\Db\DoesNotExistException('no such set')
			);
		} else {
			$sets->method('findByUuid')->willReturn($set);
		}

		$sets->method('save')->willReturnArgument(0);
		$sets->method('createFromArray')->willReturnCallback(
			static function (array $data): ConfigurationDraftSet {
				$created = new ConfigurationDraftSet();
				$created->hydrate($data);
				$created->setUuid('set-new');

				return $created;
			}
		);

		$draftMapper = $this->createMock(ConfigurationDraftMapper::class);
		$draftMapper->method('findBySet')->willReturn($drafts);
		$draftMapper->method('findAtAddress')->willReturn(null);
		$draftMapper->method('createFromArray')->willReturnCallback(
			static function (array $data): ConfigurationDraft {
				$created = new ConfigurationDraft();
				$created->hydrate($data);
				$created->setUuid('draft-new');

				return $created;
			}
		);
		$draftMapper->method('save')->willReturnArgument(0);

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturn(
			new ConfigurationSnapshot(ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => true], true, 'dep-1')
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn($fourEyes);

		$service = new ConfigurationDraftService(
			$sets,
			$draftMapper,
			$store,
			new ConfigurationKeyRegistry(),
			$session,
			$appConfig,
			'openregister'
		);

		return [$service, $store, $draftMapper];
	}//end service()

	public function testAnEditDoesNotTakeEffectYet(): void {
		[$service, $store] = $this->service($this->set());
		// The whole of REQ-CAD-001's first scenario: drafting writes no live
		// value, so a read afterwards still answers with the old one.
		$store->expects($this->never())->method('write');
		$store->expects($this->never())->method('remove');

		$draft = $service->draftValue('set-1', ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => false]);

		$this->assertSame('rbac', $draft->getConfigKey());
		$this->assertSame(['enabled' => false], $draft->readDraftValue());
		// The live value it was taken against travels with it.
		$this->assertSame(['enabled' => true], $draft->readBaseValue());
		$this->assertTrue($draft->getBasePresent());
	}//end testAnEditDoesNotTakeEffectYet()

	public function testDraftingTheSameAddressTwiceReplacesThePendingValue(): void {
		$existing = new ConfigurationDraft();
		$existing->setUuid('draft-1');
		$existing->setSetUuid('set-1');
		$existing->setLayer(ConfigurationLayer::INSTANCE);
		$existing->setConfigKey('rbac');
		$existing->setDraftValue(['value' => ['enabled' => false]]);

		// A mapper that already holds a draft at this address.
		$draftMapper = $this->createMock(ConfigurationDraftMapper::class);
		$sets = $this->createMock(ConfigurationDraftSetMapper::class);
		$sets->method('findByUuid')->willReturn($this->set());

		$draftMapper->method('findAtAddress')->willReturn($existing);
		$draftMapper->method('save')->willReturnArgument(0);
		$draftMapper->expects($this->never())->method('createFromArray');

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturn(
			new ConfigurationSnapshot(ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => true], true)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('author');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$service = new ConfigurationDraftService(
			$sets,
			$draftMapper,
			$store,
			new ConfigurationKeyRegistry(),
			$session,
			$this->createMock(IAppConfig::class),
			'openregister'
		);

		$draft = $service->draftValue('set-1', ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => true]);

		$this->assertSame('draft-1', $draft->getUuid());
		$this->assertSame(['enabled' => true], $draft->readDraftValue());
	}//end testDraftingTheSameAddressTwiceReplacesThePendingValue()

	public function testDraftingARemovalIsItsOwnActNotAFlag(): void {
		[$service, $store] = $this->service($this->set());
		$store->expects($this->never())->method('remove');

		$draft = $service->draftRemoval('set-1', ConfigurationLayer::INSTANCE, null, 'rbac');

		// Unsetting a key and setting it to null are different acts, and the
		// draft has to record which one it is: a rollback restores the
		// difference.
		$this->assertTrue($draft->getRemoves());
		$this->assertNull($draft->readDraftValue());
		$this->assertTrue($draft->getBasePresent());
		$this->assertSame(['enabled' => true], $draft->readBaseValue());
	}//end testDraftingARemovalIsItsOwnActNotAFlag()

	public function testDraftingAValueIsNotARemoval(): void {
		[$service] = $this->service($this->set());

		$draft = $service->draftValue('set-1', ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => false]);

		$this->assertFalse($draft->getRemoves());
	}//end testDraftingAValueIsNotARemoval()

	public function testFourEyesAreRequired(): void {
		$draft = new ConfigurationDraft();
		$draft->setUuid('draft-1');

		[$service] = $this->service($this->set(), [$draft], true, 'author');

		try {
			$service->approveSet('set-1');
			$this->fail('an author must not approve their own set under four eyes');
		} catch (DeploymentRefusedException $exception) {
			$this->assertSame(DeploymentRefusedException::REASON_FOUR_EYES, $exception->getReason());
			$this->assertStringContainsString('approver other than the author', $exception->getMessage());
		}
	}//end testFourEyesAreRequired()

	public function testAnotherPrincipalMayApproveUnderFourEyes(): void {
		$draft = new ConfigurationDraft();
		$draft->setUuid('draft-1');

		[$service] = $this->service($this->set(), [$draft], true, 'reviewer');

		$approved = $service->approveSet('set-1');

		$this->assertSame(ConfigurationDraftSet::STATE_APPROVED, $approved->getState());
		$this->assertSame('reviewer', $approved->getApprovedBy());
		$this->assertTrue($approved->isApproved());
	}//end testAnotherPrincipalMayApproveUnderFourEyes()

	public function testAnEmptySetCannotBeApproved(): void {
		[$service] = $this->service($this->set(), []);

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('holds no pending value');

		$service->approveSet('set-1');
	}//end testAnEmptySetCannotBeApproved()

	public function testADeployedSetTakesNoMoreValues(): void {
		[$service] = $this->service($this->set(ConfigurationDraftSet::STATE_DEPLOYED));

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('no longer takes values');

		$service->draftValue('set-1', ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => false]);
	}//end testADeployedSetTakesNoMoreValues()

	public function testADeployedSetCannotBeDiscarded(): void {
		[$service] = $this->service($this->set(ConfigurationDraftSet::STATE_DEPLOYED));

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('roll its deployment back instead');

		$service->discardSet('set-1');
	}//end testADeployedSetCannotBeDiscarded()

	public function testALowerLayerNeedsAReference(): void {
		[$service] = $this->service($this->set());

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('needs a reference');

		$service->draftValue('set-1', ConfigurationLayer::REGISTER, null, 'integration.mail', ['relay' => 'x']);
	}//end testALowerLayerNeedsAReference()

	public function testAnUndeclaredKeyIsRefusedAtDraftTime(): void {
		[$service] = $this->service($this->set());

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('not a declared configuration key');

		$service->draftValue('set-1', ConfigurationLayer::INSTANCE, null, 'whatever', 'x');
	}//end testAnUndeclaredKeyIsRefusedAtDraftTime()

	public function testAnInstanceSettingCannotBeSetAtTheRegisterLayer(): void {
		[$service] = $this->service($this->set());

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('is an instance setting');

		$service->draftValue('set-1', ConfigurationLayer::REGISTER, 'zaken', 'rbac', ['enabled' => false]);
	}//end testAnInstanceSettingCannotBeSetAtTheRegisterLayer()

	public function testASetNeedsAName(): void {
		[$service] = $this->service($this->set());

		$this->expectException(DeploymentRefusedException::class);
		$this->expectExceptionMessage('needs a name');

		$service->openSet('   ');
	}//end testASetNeedsAName()

	public function testAnUnknownSetRefusesByName(): void {
		[$service] = $this->service(null);

		try {
			$service->loadSet('nope');
			$this->fail('an unknown set must refuse');
		} catch (DeploymentRefusedException $exception) {
			$this->assertSame(DeploymentRefusedException::REASON_UNKNOWN, $exception->getReason());
			$this->assertSame(404, $exception->getStatusCode());
		}
	}//end testAnUnknownSetRefusesByName()
}//end class
