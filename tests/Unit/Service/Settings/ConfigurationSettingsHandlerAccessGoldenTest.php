<?php

/**
 * Golden outcomes of the RBAC / multitenancy settings handler's access decisions and audit hand-off.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\SettingsDirectory;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsSupport;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins whether multitenancy reads as on (allowed) or off (refused), which
 * tenant and organisation are the defaults, and that a save is recorded on
 * the audit trail only once it is stored: a save that fails records nothing.
 */
class ConfigurationSettingsHandlerAccessGoldenTest extends TestCase {

	/**
	 * The stored app config values, key to string.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Whether a write is refused by the store.
	 *
	 * @var boolean
	 */
	private bool $refuseWrites = false;

	/**
	 * Build the handler under test.
	 *
	 * @param SecuritySettingAnnouncer|null  $announcer      The announcer, if any.
	 * @param OwnSettingsChangeRecorder|null $changeRecorder The recorder, if any.
	 *
	 * @return ConfigurationSettingsHandler The handler.
	 */
	private function handler(
		?SecuritySettingAnnouncer $announcer = null,
		?OwnSettingsChangeRecorder $changeRecorder = null
	): ConfigurationSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($this->refuseWrites === true) {
					throw new RuntimeException('store refused');
				}

				$this->store[$key] = $value;
				return true;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('search')->willReturn([]);
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn([]);
		$organisations = $this->createMock(OrganisationMapper::class);
		$organisations->method('findAllWithUserCount')->willReturn([]);

		return new ConfigurationSettingsHandler(
			$appConfig,
			new SettingsDirectory($groups, $users, $organisations),
			new ConfigurationSettingsSupport($this->createMock(LoggerInterface::class), $this->createMock(IAppManager::class), $announcer, $changeRecorder),
			'openregister'
		);
	}//end handler()

	/**
	 * Multitenancy reads as on only when stored as enabled.
	 *
	 * @return void
	 */
	public function testMultitenancyIsOnOnlyWhenStoredEnabled(): void {
		$this->assertFalse($this->handler()->isMultiTenancyEnabled());

		$this->store['multitenancy'] = (string) json_encode(['enabled' => false]);
		$this->assertFalse($this->handler()->isMultiTenancyEnabled());

		$this->store['multitenancy'] = (string) json_encode(['defaultUserTenant' => 't1']);
		$this->assertFalse($this->handler()->isMultiTenancyEnabled());

		$this->store['multitenancy'] = (string) json_encode(['enabled' => true]);
		$this->assertTrue($this->handler()->isMultiTenancyEnabled());
	}//end testMultitenancyIsOnOnlyWhenStoredEnabled()

	/**
	 * The default tenant and organisation come from the stored sections.
	 *
	 * @return void
	 */
	public function testDefaultTenantAndOrganisation(): void {
		$this->assertSame('', $this->handler()->getTenantId());
		$this->assertNull($this->handler()->getOrganisationId());

		$this->store['multitenancy'] = (string) json_encode(['defaultUserTenant' => 'tenant-a']);
		$this->store['organisation'] = (string) json_encode(['default_organisation' => 'org-a']);
		$this->assertSame('tenant-a', $this->handler()->getTenantId());
		$this->assertSame('org-a', $this->handler()->getDefaultOrganisationUuid());
		$this->assertSame('org-a', $this->handler()->getOrganisationId());
	}//end testDefaultTenantAndOrganisation()

	/**
	 * A stored RBAC save is recorded with the rbac key.
	 *
	 * @return void
	 */
	public function testAStoredRbacSaveIsRecorded(): void {
		$snapshot = ['settings' => ['rbac.enabled' => true], 'security' => []];
		$recorder = $this->createMock(OwnSettingsChangeRecorder::class);
		$recorder->expects($this->once())->method('snapshot')->with(['rbac'])->willReturn($snapshot);
		$recorder->expects($this->once())->method('record')->with($snapshot, ['rbac'])->willReturn(1);

		$result = $this->handler(null, $recorder)->updateRbacSettingsOnly(['enabled' => false]);

		$this->assertFalse($result['rbac']['enabled']);
		$this->assertFalse(json_decode($this->store['rbac'], true)['enabled']);
	}//end testAStoredRbacSaveIsRecorded()

	/**
	 * A stored multitenancy save is recorded with the multitenancy key.
	 *
	 * @return void
	 */
	public function testAStoredMultitenancySaveIsRecorded(): void {
		$snapshot = ['settings' => [], 'security' => []];
		$recorder = $this->createMock(OwnSettingsChangeRecorder::class);
		$recorder->expects($this->once())->method('snapshot')->with(['multitenancy'])->willReturn($snapshot);
		$recorder->expects($this->once())->method('record')->with($snapshot, ['multitenancy'])->willReturn(1);

		$this->handler(null, $recorder)->updateMultitenancySettingsOnly(['enabled' => true]);

		$this->assertTrue(json_decode($this->store['multitenancy'], true)['enabled']);
	}//end testAStoredMultitenancySaveIsRecorded()

	/**
	 * A full save goes through the recorder, not straight to the announcer.
	 *
	 * @return void
	 */
	public function testAFullSaveIsRecordedThroughTheRecorder(): void {
		$snapshot = ['settings' => [], 'security' => []];
		$recorder = $this->createMock(OwnSettingsChangeRecorder::class);
		$recorder->expects($this->once())->method('snapshot')->with(OwnSettingsChangeRecorder::FULL_SAVE_KEYS)->willReturn($snapshot);
		$recorder->expects($this->once())->method('record')->with($snapshot, OwnSettingsChangeRecorder::FULL_SAVE_KEYS)->willReturn(1);
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturn([]);
		$announcer->expects($this->never())->method('announce');

		$this->handler($announcer, $recorder)->updateSettings(['rbac' => ['enabled' => false]]);
	}//end testAFullSaveIsRecordedThroughTheRecorder()

	/**
	 * Without a recorder a full save is announced on its own.
	 *
	 * @return void
	 */
	public function testWithoutARecorderAFullSaveIsAnnounced(): void {
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturnOnConsecutiveCalls(['rbac.enabled' => true], ['rbac.enabled' => false]);
		$announcer->expects($this->once())->method('announce')->with(['rbac.enabled' => true], ['rbac.enabled' => false]);

		$this->handler($announcer)->updateSettings(['rbac' => ['enabled' => false]]);
	}//end testWithoutARecorderAFullSaveIsAnnounced()

	/**
	 * A refused save throws and records and announces nothing.
	 *
	 * @return void
	 */
	public function testARefusedSaveRecordsNothing(): void {
		$this->refuseWrites = true;
		$recorder = $this->createMock(OwnSettingsChangeRecorder::class);
		$recorder->method('snapshot')->willReturn(['settings' => [], 'security' => []]);
		$recorder->expects($this->never())->method('record');
		$announcer = $this->createMock(SecuritySettingAnnouncer::class);
		$announcer->method('snapshot')->willReturn([]);
		$announcer->expects($this->never())->method('announce');

		try {
			$this->handler($announcer, $recorder)->updateSettings(['rbac' => ['enabled' => false]]);
			$this->fail('A refused save must throw.');
		} catch (RuntimeException $e) {
			$this->assertSame('Failed to update settings: store refused', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to update RBAC settings: store refused');
		$this->handler(null, $recorder)->updateRbacSettingsOnly(['enabled' => false]);
	}//end testARefusedSaveRecordsNothing()
}//end class
