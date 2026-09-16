<?php

/**
 * Unit tests for the settings facade's draft gate.
 *
 * Four facts this class has to get right:
 *
 * - With drafting off it answers null and touches nothing, because that is the
 *   only answer that leaves an existing instance behaving as it did.
 * - With drafting on it writes a draft and never a live value.
 * - A payload naming three fields of nine stages the other six as they are,
 *   rather than staging a blob that silently drops them.
 * - The value it stages reads back the same as the value a straight-through
 *   write would have left. That last one is the control: the gate deliberately
 *   does not copy the handlers' defaults, so something has to prove the two
 *   paths still converge, and the real handler is the only honest witness.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/settings-management/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\SettingsDomainMap;
use OCA\OpenRegister\Service\ConfigurationDeployment\SettingsDraftGate;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SettingsDraftGateTest extends TestCase {

	/**
	 * The app config values this test's instance holds, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigValues = [];

	private function appConfig(): IAppConfig&MockObject {
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('hasKey')->willReturnCallback(
			fn (string $app, string $key): bool => isset($this->appConfigValues[$key])
		);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appConfigValues[$key] ?? $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => match (($this->appConfigValues[$key] ?? null)) {
				null => $default,
				'0', '' => false,
				default => true,
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfigValues[$key] = $value;
				return true;
			}
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->appConfigValues[$key] = match ($value) {
					true => '1',
					false => '0',
				};
				return true;
			}
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->appConfigValues[$key] = (string)$value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	private function openSet(string $uuid = 'set-1', string $state = ConfigurationDraftSet::STATE_OPEN): ConfigurationDraftSet {
		$set = new ConfigurationDraftSet();
		$set->setUuid($uuid);
		$set->setName('settings drafted on 2026-09-16');
		$set->setState($state);

		return $set;
	}//end openSet()

	private function store(array $live = []): ConfigurationValueStore&MockObject {
		$store = $this->createMock(ConfigurationValueStore::class);
		$store->method('read')->willReturnCallback(
			static function (string $layer, ?string $ref, string $key) use ($live): ConfigurationSnapshot {
				if (array_key_exists($key, $live) === false) {
					return ConfigurationSnapshot::absent(layer: $layer, layerRef: $ref, configKey: $key);
				}

				return new ConfigurationSnapshot(
					layer: $layer,
					layerRef: $ref,
					configKey: $key,
					value: $live[$key],
					present: true,
					deploymentUuid: null,
					updatedBy: null
				);
			}
		);

		return $store;
	}//end store()

	public function testDraftingOffAnswersNullAndStagesNothing(): void {
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->expects($this->never())->method('openSet');
		$drafts->expects($this->never())->method('draftValue');

		$gate = new SettingsDraftGate($drafts, $this->store(), new SettingsDomainMap(), $this->appConfig(), 'openregister');

		$this->assertNull($gate->draftIfEnabled(SettingsDomainMap::DOMAIN_RBAC, ['enabled' => false]));
		$this->assertFalse($gate->isDrafting());
	}//end testDraftingOffAnswersNullAndStagesNothing()

	public function testWithDraftingOnTheWriteIsStagedAtTheInstanceLayer(): void {
		$this->appConfigValues[SettingsDraftGate::DRAFTING_KEY] = '1';

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($this->openSet());
		$drafts->expects($this->once())
			->method('draftValue')
			->with('set-1', ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => false])
			->willReturn(new ConfigurationDraft());

		// The live half of the store is never written: a draft that touched a
		// live value would be the exact failure REQ-CAD-001 forbids.
		$store = $this->store();
		$store->expects($this->never())->method('write');

		$gate = new SettingsDraftGate($drafts, $store, new SettingsDomainMap(), $this->appConfig(), 'openregister');
		$pending = $gate->draftIfEnabled(SettingsDomainMap::DOMAIN_RBAC, ['enabled' => false]);

		$this->assertIsArray($pending);
		$this->assertTrue($pending['pending']);
		$this->assertSame('rbac', $pending['drafts'][0]['key']);
		$this->assertSame('set-1', $this->appConfigValues[SettingsDraftGate::TARGET_SET_KEY]);
	}//end testWithDraftingOnTheWriteIsStagedAtTheInstanceLayer()

	public function testAPartialPayloadIsStagedOverTheLiveValue(): void {
		$this->appConfigValues[SettingsDraftGate::DRAFTING_KEY] = '1';

		$staged = null;
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($this->openSet());
		$drafts->expects($this->once())
			->method('draftValue')
			->willReturnCallback(
				static function (string $setUuid, string $layer, ?string $ref, string $key, mixed $value) use (&$staged): ConfigurationDraft {
					$staged = $value;
					return new ConfigurationDraft();
				}
			);

		$live = ['rbac' => ['enabled' => true, 'anonymousGroup' => 'guests', 'adminOverride' => true]];

		$gate = new SettingsDraftGate(
			$drafts,
			$this->store($live),
			new SettingsDomainMap(),
			$this->appConfig(),
			'openregister'
		);
		$gate->draftIfEnabled(SettingsDomainMap::DOMAIN_RBAC, ['enabled' => false]);

		// The two fields the payload never named are staged as they are live.
		// Without the merge the draft would stage {enabled:false} alone, and
		// deploying it would drop a group nobody asked to change.
		$this->assertSame(
			['enabled' => false, 'anonymousGroup' => 'guests', 'adminOverride' => true],
			$staged
		);
	}//end testAPartialPayloadIsStagedOverTheLiveValue()

	public function testTheMultiKeyDomainStagesEveryKeyItNames(): void {
		$this->appConfigValues[SettingsDraftGate::DRAFTING_KEY] = '1';

		$staged = [];
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($this->openSet());
		$drafts->method('draftValue')->willReturnCallback(
			static function (string $setUuid, string $layer, ?string $ref, string $key, mixed $value) use (&$staged): ConfigurationDraft {
				$staged[$key] = $value;
				return new ConfigurationDraft();
			}
		);

		$gate = new SettingsDraftGate($drafts, $this->store(), new SettingsDomainMap(), $this->appConfig(), 'openregister');
		$gate->draftIfEnabled(
			SettingsDomainMap::DOMAIN_SETTINGS,
			[
				'rbac' => ['enabled' => false],
				'flow' => ['retentionDays' => 14, 'killSwitch' => true],
				'party' => ['queryCap' => 250],
			]
		);

		// The loose keys are staged as the type the registry declares them as,
		// not as whatever the form posted: a string cap would be refused at
		// deploy time, which is a refusal nobody could act on from here.
		$this->assertSame(['enabled' => false], $staged['rbac']);
		$this->assertSame('14', $staged['flow_run_retention_days']);
		$this->assertTrue($staged['flow_kill_switch']);
		$this->assertSame(250, $staged['party_query_cap']);
		// A section the payload never named is not staged.
		$this->assertArrayNotHasKey('solr', $staged);
		$this->assertArrayNotHasKey('flow_audit_enabled', $staged);
	}//end testTheMultiKeyDomainStagesEveryKeyItNames()

	public function testADeployedSetIsNotReusedForTheNextWrite(): void {
		$this->appConfigValues[SettingsDraftGate::DRAFTING_KEY] = '1';
		$this->appConfigValues[SettingsDraftGate::TARGET_SET_KEY] = 'set-old';

		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('loadSet')->willReturn($this->openSet('set-old', ConfigurationDraftSet::STATE_DEPLOYED));
		$drafts->expects($this->once())->method('openSet')->willReturn($this->openSet('set-new'));
		$drafts->method('draftValue')->willReturn(new ConfigurationDraft());

		$gate = new SettingsDraftGate($drafts, $this->store(), new SettingsDomainMap(), $this->appConfig(), 'openregister');
		$gate->draftIfEnabled(SettingsDomainMap::DOMAIN_RBAC, ['enabled' => false]);

		$this->assertSame('set-new', $this->appConfigValues[SettingsDraftGate::TARGET_SET_KEY]);
	}//end testADeployedSetIsNotReusedForTheNextWrite()

	public function testADraftedDomainReadsBackTheSameAsAStraightWrite(): void {
		$payload = ['enabled' => false, 'defaultObjectOwner' => 'admin'];

		// The straight-through path: the real handler, writing live.
		$handler = $this->handler();
		$handler->updateRbacSettingsOnly($payload);
		$straightThrough = $handler->getRbacSettingsOnly()['rbac'];

		// The drafted path: the gate's staged value, deployed by hand into the
		// same app config the getter reads.
		$this->appConfigValues = [];
		$this->appConfigValues[SettingsDraftGate::DRAFTING_KEY] = '1';

		$staged = [];
		$drafts = $this->createMock(ConfigurationDraftService::class);
		$drafts->method('openSet')->willReturn($this->openSet());
		$drafts->method('draftValue')->willReturnCallback(
			static function (string $setUuid, string $layer, ?string $ref, string $key, mixed $value) use (&$staged): ConfigurationDraft {
				$staged[$key] = $value;
				return new ConfigurationDraft();
			}
		);

		$gate = new SettingsDraftGate($drafts, $this->store(), new SettingsDomainMap(), $this->appConfig(), 'openregister');
		$gate->draftIfEnabled(SettingsDomainMap::DOMAIN_RBAC, $payload);

		$this->appConfigValues['rbac'] = (string)json_encode($staged['rbac']);
		$afterDeployment = $this->handler()->getRbacSettingsOnly()['rbac'];

		$this->assertSame($straightThrough, $afterDeployment);
	}//end testADraftedDomainReadsBackTheSameAsAStraightWrite()

	private function handler(): ConfigurationSettingsHandler {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('search')->willReturn([]);

		return new ConfigurationSettingsHandler(
			$this->appConfig(),
			$groupManager,
			$userManager,
			$this->createMock(OrganisationMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppManager::class),
			'openregister'
		);
	}//end handler()
}//end class
