<?php

/**
 * Characterisation tests for staging a pending value (ConfigurationDraftService::stage()).
 *
 * Pins, on the code before stage() was split into named steps: the live read
 * and the address lookup it makes and their order, the fields a re-staged draft
 * is given and the order they are marked updated in (that order shapes the
 * UPDATE the mapper writes), and the full row a first draft is created from.
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
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftTarget;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ConfigurationDraftStageTest extends TestCase {

	/**
	 * The collaborator calls, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	private function service(?ConfigurationDraft $existing, ?array &$created): ConfigurationDraftService {
		$set = new ConfigurationDraftSet();
		$set->setUuid('set-1');
		$set->setName('Set');
		$set->setState(ConfigurationDraftSet::STATE_OPEN);
		$set->setCreatedBy('author');

		$sets = $this->createMock(ConfigurationDraftSetMapper::class);
		$sets->method('findByUuid')->willReturnCallback(
			function (string $uuid) use ($set): ConfigurationDraftSet {
				$this->calls[] = 'sets.findByUuid:' . $uuid;
				return $set;
			}
		);

		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			function (string $layer, ?string $layerRef, string $configKey): ConfigurationSnapshot {
				$this->calls[] = 'store.read:' . $layer . '/' . var_export($layerRef, true) . '/' . $configKey;
				return new ConfigurationSnapshot(new ConfigurationAddress($layer, $layerRef, $configKey), ['enabled' => true], true);
			}
		);

		$drafts = $this->createMock(ConfigurationDraftMapper::class);
		$drafts->method('findAtAddress')->willReturnCallback(
			function (string $setUuid, string $layer, ?string $layerRef, string $configKey) use ($existing): ?ConfigurationDraft {
				$this->calls[] = 'drafts.findAtAddress:' . $setUuid . '/' . $layer . '/' . var_export($layerRef, true) . '/' . $configKey;
				return $existing;
			}
		);
		$drafts->method('save')->willReturnCallback(
			function (ConfigurationDraft $draft): ConfigurationDraft {
				$this->calls[] = 'drafts.save:' . implode(',', array_keys($draft->getUpdatedFields()));
				return $draft;
			}
		);
		$drafts->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$created): ConfigurationDraft {
				$this->calls[] = 'drafts.createFromArray';
				$created = $data;
				$draft   = new ConfigurationDraft();
				$draft->hydrate($data);
				return $draft;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('author');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ConfigurationDraftService(
			$sets,
			$drafts,
			$store,
			new ConfigurationKeyRegistry(),
			$session,
			$this->createMock(IAppConfig::class),
			'openregister'
		);
	}

	public function testRestagingAnAddressRebasesTheDraftInFieldOrder(): void {
		$existing = new ConfigurationDraft();
		$existing->setUuid('draft-1');
		$existing->setSetUuid('set-1');
		$existing->setLayer(ConfigurationLayer::INSTANCE);
		$existing->setConfigKey('rbac');
		$existing->setDraftValue(['value' => ['enabled' => false]]);
		$existing->resetUpdatedFields();
		$created = null;

		$draft = $this->service(existing: $existing, created: $created)->draftRemoval(
			new ConfigurationDraftTarget('set-1', new ConfigurationAddress(ConfigurationLayer::INSTANCE, null, 'rbac'))
		);

		$this->assertSame($existing, $draft);
		$this->assertNull($created);
		$this->assertSame(['value' => null], $draft->getDraftValue());
		$this->assertSame(['value' => ['enabled' => true]], $draft->getBaseValue());
		$this->assertTrue($draft->getBasePresent());
		$this->assertTrue($draft->getRemoves());
		$this->assertSame(
			[
				'sets.findByUuid:set-1',
				'store.read:instance/NULL/rbac',
				'drafts.findAtAddress:set-1/instance/NULL/rbac',
				'drafts.save:draftValue,baseValue,basePresent,removes',
			],
			$this->calls
		);
	}

	public function testAFirstDraftIsCreatedFromTheFullRow(): void {
		$created = null;

		$this->service(existing: null, created: $created)->draftValue(
			new ConfigurationDraftTarget('set-1', new ConfigurationAddress(ConfigurationLayer::INSTANCE, null, 'rbac')),
			['enabled' => false]
		);

		$this->assertSame(
			[
				'setUuid' => 'set-1',
				'layer' => ConfigurationLayer::INSTANCE,
				'layerRef' => null,
				'configKey' => 'rbac',
				'baseValue' => ['value' => ['enabled' => true]],
				'basePresent' => true,
				'draftValue' => ['value' => ['enabled' => false]],
				'removes' => false,
				'createdBy' => 'author',
			],
			$created
		);
		$this->assertSame(
			[
				'sets.findByUuid:set-1',
				'store.read:instance/NULL/rbac',
				'drafts.findAtAddress:set-1/instance/NULL/rbac',
				'drafts.createFromArray',
			],
			$this->calls
		);
	}
}//end class
