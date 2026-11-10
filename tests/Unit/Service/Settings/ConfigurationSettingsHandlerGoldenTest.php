<?php

/**
 * Golden test for the RBAC, multitenancy, organisation and retention sections of ConfigurationSettingsHandler.
 *
 * Records the result and every app config write of getSettings, updateSettings and the
 * per-section get and update methods over a set of stored states, per scenario, in
 * fixtures/configuration-settings-handler-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\SettingsDirectory;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsSupport;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Service\Settings\SettingsSectionDefaults
 */
class ConfigurationSettingsHandlerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/configuration-settings-handler-golden.json';

	/**
	 * Stored app config states.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function stores(): array {
		return [
			'empty' => [],
			'partial' => [
				'rbac' => '{"enabled":false,"anonymousGroup":"anon"}',
				'multitenancy' => '{"defaultUserTenant":"t1","adminOverride":false}',
				'retention' => '{"readLogRetention":5,"auditTrailsEnabled":false,"objectArchiveRetention":null}',
				'organisation' => '{"default_organisation":"org-1"}',
				'blob_migration_processed' => '12',
				'blob_migration_complete' => 'true',
				'flow_run_retention_days' => '7',
			],
			'invalid' => [
				'rbac' => '{not json',
				'multitenancy' => '"text"',
				'retention' => '5',
				'organisation' => '[1,2]',
				'blob_migration_remaining' => 'x',
			],
			'zero' => [
				'rbac' => '0',
				'multitenancy' => '0',
				'retention' => '0',
				'organisation' => '0',
			],
		];
	}

	/**
	 * Scenarios: [store, call, argument].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$out = [];
		foreach (array_keys(self::stores()) as $store) {
			foreach (['getSettings', 'getRbacSettingsOnly', 'getMultitenancySettingsOnly', 'getOrganisationSettingsOnly'] as $call) {
				$out[$store . ' ' . $call] = [$store, $call, null];
			}
		}

		$updates = [
			'nothing' => [],
			'rbac partial' => ['rbac' => ['enabled' => false, 'defaultObjectOwner' => 'admin']],
			'rbac string' => ['rbac' => 'yes'],
			'rbac null' => ['rbac' => null, 'multitenancy' => null, 'retention' => null, 'flow' => null, 'party' => null],
			'all sections' => [
				'rbac' => ['enabled' => true, 'anonymousGroup' => 'g', 'defaultNewUserGroup' => 'u', 'defaultObjectOwner' => 'o', 'adminOverride' => false, 'extra' => 1],
				'multitenancy' => ['enabled' => false, 'publishedObjectsBypassMultiTenancy' => true],
				'retention' => ['objectDeleteRetention' => 1, 'searchTrailsEnabled' => false, 'deleteLogRetention' => null],
				'flow' => ['retentionDays' => 3, 'auditEnabled' => 1, 'killSwitch' => true],
				'party' => ['queryCap' => 50],
			],
			'flow refused' => ['flow' => ['retentionDays' => 0], 'party' => ['queryCap' => 0]],
			'party scalar' => ['party' => 7, 'flow' => []],
		];
		foreach ($updates as $name => $data) {
			$out['update ' . $name] = ['partial', 'updateSettings', $data];
		}

		foreach (['Rbac', 'Multitenancy', 'Organisation'] as $section) {
			$out['update ' . $section . ' empty'] = ['empty', 'update' . $section . 'SettingsOnly', []];
			$out['update ' . $section . ' values'] = ['empty', 'update' . $section . 'SettingsOnly', ['enabled' => false, 'defaultUserTenant' => 'x', 'default_organisation' => 'o', 'auto_create_default_organisation' => false, 'anonymousGroup' => 'a', 'adminOverride' => null]];
		}

		return $out;
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testSettingsGolden(string $store, string $call, ?array $argument): void {
		$log = [];
		$values = self::stores()[$store];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$values) {
				return $values[$key] ?? $default;
			}
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			function (string $app, string $key, bool $default = false) {
				return $key === 'flow_kill_switch' ? true : $default;
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0) {
				return $default + 1;
			}
		);
		foreach (['setValueString', 'setValueBool', 'setValueInt'] as $setter) {
			$appConfig->method($setter)->willReturnCallback(
				function (string $app, string $key, $value) use (&$log, &$values, $setter) {
					$log[] = [$setter, $app, $key, $value];
					if ($setter === 'setValueString') {
						$values[$key] = $value;
					}

					return true;
				}
			);
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('admin');
		$group->method('getDisplayName')->willReturn('Admins');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('search')->willReturn([$group]);

		$named = $this->createMock(IUser::class);
		$named->method('getUID')->willReturn('jan');
		$named->method('getDisplayName')->willReturn('Jan');
		$blank = $this->createMock(IUser::class);
		$blank->method('getUID')->willReturn('anon');
		$blank->method('getDisplayName')->willReturn('');
		$users = $this->createMock(IUserManager::class);
		$users->method('search')->willReturn([$named, $blank]);

		$organisation = new Organisation();
		$organisation->setUuid('org-uuid');
		$organisation->setName('Org');
		$organisations = $this->createMock(OrganisationMapper::class);
		if ($store === 'invalid') {
			$organisations->method('findAllWithUserCount')->willThrowException(new RuntimeException('db'));
		} else {
			$organisations->method('findAllWithUserCount')->willReturn([$organisation]);
		}

		$handler = new ConfigurationSettingsHandler(
			$appConfig,
			new SettingsDirectory($groups, $users, $organisations),
			new ConfigurationSettingsSupport($this->createMock(LoggerInterface::class), $this->createMock(IAppManager::class))
		);

		if ($argument === null) {
			$result = $handler->$call();
		} else {
			$result = $handler->$call($argument);
		}

		$actual = ['result' => $result, 'writes' => $log];
		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recorded for ' . $name);
		$this->assertSame($golden[$name], json_decode(json_encode($actual), true));
	}
}
