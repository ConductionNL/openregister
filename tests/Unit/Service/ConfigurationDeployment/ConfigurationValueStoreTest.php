<?php

/**
 * Unit tests for the live configuration value at one address.
 *
 * The three facts this class has to get right: app config is the authority at
 * the instance layer and the row is only the provenance, a key app config does
 * not hold is absent whatever the row says, and every write hands back enough
 * to put the app config side back when the surrounding transaction rolls the
 * row side back.
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

use OCA\OpenRegister\Db\ConfigurationValue;
use OCA\OpenRegister\Db\ConfigurationValueMapper;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationWrite;

final class ConfigurationValueStoreTest extends TestCase {

	private function row(string $layer, ?string $ref, string $key, mixed $value, ?string $deployment): ConfigurationValue {
		$row = new ConfigurationValue();
		$row->setUuid('value-1');
		$row->setLayer($layer);
		$row->setLayerRef($ref);
		$row->setConfigKey($key);
		$row->setConfigValue(['value' => $value]);
		$row->setDeploymentUuid($deployment);
		$row->setUpdatedBy('admin');

		return $row;
	}//end row()

	public function testTheInstanceValueComesFromAppConfigAndTheProvenanceFromTheRow(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(
			$this->row(ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => false], 'dep-7')
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		// The row holds a STALE value on purpose: if the store read the row
		// instead of app config, this is the value it would report, and the
		// assertion below would fail.
		$appConfig->method('getValueString')->willReturn('{"enabled":true}');

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$snapshot = $store->read(ConfigurationLayer::INSTANCE, null, 'rbac');

		$this->assertSame(['enabled' => true], $snapshot->value);
		$this->assertTrue($snapshot->present);
		$this->assertSame('dep-7', $snapshot->deploymentUuid);
		$this->assertTrue($snapshot->hasProvenance());
	}//end testTheInstanceValueComesFromAppConfigAndTheProvenanceFromTheRow()

	public function testAKeyAppConfigDoesNotHoldIsAbsentEvenWithAProvenanceRow(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(
			$this->row(ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => true], 'dep-7')
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$snapshot = $store->read(ConfigurationLayer::INSTANCE, null, 'rbac');

		$this->assertFalse($snapshot->present);
		$this->assertNull($snapshot->value);
	}//end testAKeyAppConfigDoesNotHoldIsAbsentEvenWithAProvenanceRow()

	public function testBelowTheInstanceTheRowIsTheValue(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(
			$this->row(ConfigurationLayer::REGISTER, 'zaken', 'integration.mail', ['relay' => 'smtp'], null)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('getValueString');

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$snapshot = $store->read(ConfigurationLayer::REGISTER, 'zaken', 'integration.mail');

		$this->assertSame(['relay' => 'smtp'], $snapshot->value);
		$this->assertTrue($snapshot->present);
		$this->assertFalse($snapshot->hasProvenance());
	}//end testBelowTheInstanceTheRowIsTheValue()

	public function testAnAddressWithNoRowBelowTheInstanceIsAbsent(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);

		$store = new ConfigurationValueStore(
			$mapper,
			$this->createMock(IAppConfig::class),
			'openregister'
		);
		$snapshot = $store->read(ConfigurationLayer::SUBJECT, 'zaak', 'permission.matrix');

		$this->assertFalse($snapshot->present);
	}//end testAnAddressWithNoRowBelowTheInstanceIsAbsent()

	public function testReadSnapshotsCarryTheirAddressAndProvenance(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturnCallback(
			fn (string $layer, ?string $layerRef, string $configKey) => match ($layer) {
				ConfigurationLayer::REGISTER => $this->row($layer, $layerRef, $configKey, ['relay' => 'smtp'], 'dep-3'),
				default => null,
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');

		$below = $store->read(ConfigurationLayer::REGISTER, 'zaken', 'integration.mail');
		$this->assertSame(
			[ConfigurationLayer::REGISTER, 'zaken', 'integration.mail', ['relay' => 'smtp'], true, 'dep-3', 'admin'],
			[$below->layer, $below->layerRef, $below->configKey, $below->value, $below->present, $below->deploymentUuid, $below->updatedBy]
		);

		$absent = $store->read(ConfigurationLayer::BUNDLE, 'b-1', 'integration.mail');
		$this->assertSame([ConfigurationLayer::BUNDLE, 'b-1', false], [$absent->layer, $absent->layerRef, $absent->present]);

		// The instance layer never carries a reference, even when one is passed.
		$instance = $store->read(ConfigurationLayer::INSTANCE, 'ignored', 'rbac');
		$this->assertSame([ConfigurationLayer::INSTANCE, null, false], [$instance->layer, $instance->layerRef, $instance->present]);
	}//end testReadSnapshotsCarryTheirAddressAndProvenance()

	public function testAWriteBelowTheInstanceUpdatesAnExistingRowInPlace(): void {
		$row = $this->row(ConfigurationLayer::REGISTER, 'zaken', 'integration.mail', ['relay' => 'old'], 'dep-1');
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn($row);
		$mapper->expects($this->once())->method('save')->with($row);
		$mapper->expects($this->never())->method('createFromArray');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$undo = $store->write(new ConfigurationAddress(ConfigurationLayer::REGISTER, 'zaken', 'integration.mail'), new ConfigurationWrite(['relay' => 'new'], 'dep-2', 'bob'));

		$this->assertSame(['value' => ['relay' => 'new']], $row->getConfigValue());
		$this->assertSame('dep-2', $row->getDeploymentUuid());
		$this->assertSame('bob', $row->getUpdatedBy());
		$this->assertSame(ConfigurationLayer::REGISTER, $undo['layer']);
	}//end testAWriteBelowTheInstanceUpdatesAnExistingRowInPlace()

	public function testAWriteWithNoRowCreatesItKeyForKey(): void {
		$created = [];
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);
		$mapper->expects($this->never())->method('save');
		$mapper->method('createFromArray')->willReturnCallback(
			function (array $data) use (&$created): ConfigurationValue {
				$created[] = $data;

				return $this->row(ConfigurationLayer::SUBJECT, 'zaak', 'permission.matrix', null, null);
			}
		);

		$store = new ConfigurationValueStore($mapper, $this->createMock(IAppConfig::class), 'openregister');
		$store->write(new ConfigurationAddress(ConfigurationLayer::SUBJECT, 'zaak', 'permission.matrix'), new ConfigurationWrite(['read' => ['*']], null, 'bob'));

		$this->assertSame(
			[
				[
					'layer' => ConfigurationLayer::SUBJECT,
					'layerRef' => 'zaak',
					'configKey' => 'permission.matrix',
					'configValue' => ['value' => ['read' => ['*']]],
					'deploymentUuid' => null,
					'updatedBy' => 'bob',
				],
			],
			$created
		);
	}//end testAWriteWithNoRowCreatesItKeyForKey()

	public function testAWriteHandsBackWhatItTakesToUndoTheAppConfigSide(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);
		$mapper->method('createFromArray')->willReturn(
			$this->row(ConfigurationLayer::INSTANCE, null, 'rbac', ['enabled' => false], 'dep-8')
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('{"enabled":true}');
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'rbac', '{"enabled":false}');

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$undo = $store->write(new ConfigurationAddress(ConfigurationLayer::INSTANCE, null, 'rbac'), new ConfigurationWrite(['enabled' => false], 'dep-8', 'admin'));

		$this->assertSame(ConfigurationLayer::INSTANCE, $undo['layer']);
		$this->assertSame('rbac', $undo['key']);
		$this->assertTrue($undo['present']);
		$this->assertSame('{"enabled":true}', $undo['raw']);
	}//end testAWriteHandsBackWhatItTakesToUndoTheAppConfigSide()

	public function testRestorePutsTheEarlierStringBackAndDeletesAKeyThatDidNotExist(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'rbac', '{"enabled":true}');
		$appConfig->expects($this->once())
			->method('deleteKey')
			->with('openregister', 'archival');

		$store = new ConfigurationValueStore(
			$this->createMock(ConfigurationValueMapper::class),
			$appConfig,
			'openregister'
		);

		$restored = $store->restore(
			[
				['layer' => ConfigurationLayer::INSTANCE, 'key' => 'rbac', 'present' => true, 'raw' => '{"enabled":true}'],
				['layer' => ConfigurationLayer::INSTANCE, 'key' => 'archival', 'present' => false, 'raw' => ''],
				// A layer below the instance has no app config side to undo;
				// its row is put back by the transaction rolling back.
				['layer' => ConfigurationLayer::REGISTER, 'key' => 'integration.mail', 'present' => false, 'raw' => ''],
			]
		);

		$this->assertSame(2, $restored);
	}//end testRestorePutsTheEarlierStringBackAndDeletesAKeyThatDidNotExist()

	public function testRemovingAnInstanceKeyDeletesItFromAppConfig(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(
			$this->row(ConfigurationLayer::INSTANCE, null, 'archival', ['enabled' => true], 'dep-1')
		);
		$mapper->expects($this->once())->method('remove');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(true);
		$appConfig->method('getValueString')->willReturn('{"enabled":true}');
		$appConfig->expects($this->once())->method('deleteKey')->with('openregister', 'archival');

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$undo = $store->remove(ConfigurationLayer::INSTANCE, null, 'archival');

		$this->assertTrue($undo['present']);
		$this->assertSame('{"enabled":true}', $undo['raw']);
	}//end testRemovingAnInstanceKeyDeletesItFromAppConfig()

	public function testABooleanIsStoredTheWayTheSettingsReadersReadIt(): void {
		$mapper = $this->createMock(ConfigurationValueMapper::class);
		$mapper->method('findAtAddress')->willReturn(null);
		$mapper->method('createFromArray')->willReturn(
			$this->row(ConfigurationLayer::INSTANCE, null, 'flow_kill_switch', true, 'dep-9')
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'flow_kill_switch', '1');

		$store = new ConfigurationValueStore($mapper, $appConfig, 'openregister');
		$store->write(new ConfigurationAddress(ConfigurationLayer::INSTANCE, null, 'flow_kill_switch'), new ConfigurationWrite(true, 'dep-9', 'admin'));
	}//end testABooleanIsStoredTheWayTheSettingsReadersReadIt()
}//end class
