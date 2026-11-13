<?php

/**
 * Unit tests for the "who sees who read a record" setting in the full
 * settings read and save: the stored group list is read back as a list, a
 * submitted list is stored cleaned, and a save without one leaves it alone.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
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
 * @spec openspec/changes/audit-read-events-visible-to/specs/audit-trail-immutable/spec.md#requirement-read-entries-are-shown-only-to-the-groups-named-for-them
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Audit\ReadEventVisibility;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsSupport;
use OCA\OpenRegister\Service\Settings\OwnSettingsChangeRecorder;
use OCA\OpenRegister\Service\Settings\SettingsDirectory;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler
 * @covers \OCA\OpenRegister\Service\Audit\ReadEventVisibility
 * @uses   \OCA\OpenRegister\Service\Settings\ConfigurationSettingsSupport
 * @uses   \OCA\OpenRegister\Service\Settings\SettingsDirectory
 */
class ConfigurationSettingsHandlerReadEventsTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A handler over an app config that keeps what is written.
	 *
	 * @return ConfigurationSettingsHandler
	 */
	private function handler(): ConfigurationSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => $default);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
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
			new ConfigurationSettingsSupport($this->createMock(LoggerInterface::class), $this->createMock(IAppManager::class))
		);
	}//end handler()

	public function testTheStoredGroupsAreReadBackAsAList(): void {
		$this->stored[ReadEventVisibility::CONFIG_KEY] = '["dossiq-sensitive","",42,"jeugd"]';

		$this->assertSame(['dossiq-sensitive', 'jeugd'], $this->handler()->getSettings()['audit']['readEventsVisibleTo']);
	}

	public function testNoStoredValueOrAnUnreadableOneIsNoGroup(): void {
		$this->assertSame([], $this->handler()->getSettings()['audit']['readEventsVisibleTo']);

		$this->stored[ReadEventVisibility::CONFIG_KEY] = 'not json';
		$this->assertSame([], $this->handler()->getSettings()['audit']['readEventsVisibleTo']);
	}

	public function testASubmittedListIsStoredTrimmedOnceEachAndWithoutBlanks(): void {
		$answer = $this->handler()->updateSettings(['audit' => ['readEventsVisibleTo' => [' jeugd ', 'jeugd', '', 7, null, 'dossiq-sensitive']]]);

		$this->assertSame('["jeugd","dossiq-sensitive"]', $this->stored[ReadEventVisibility::CONFIG_KEY]);
		$this->assertSame(['jeugd', 'dossiq-sensitive'], $answer['audit']['readEventsVisibleTo']);
	}

	public function testAnEmptyListLeavesReadEntriesToAdministratorsOnly(): void {
		$this->stored[ReadEventVisibility::CONFIG_KEY] = '["jeugd"]';

		$this->handler()->updateSettings(['audit' => ['readEventsVisibleTo' => []]]);

		$this->assertSame('[]', $this->stored[ReadEventVisibility::CONFIG_KEY]);
	}

	public function testASaveWithoutAListLeavesTheStoredGroupsAlone(): void {
		$this->stored[ReadEventVisibility::CONFIG_KEY] = '["jeugd"]';
		$handler = $this->handler();

		$handler->updateSettings(['audit' => ['readEventsVisibleTo' => 'jeugd,beheer']]);
		$handler->updateSettings(['audit' => []]);
		$handler->updateSettings(['flow' => ['retentionDays' => 31]]);

		$this->assertSame('["jeugd"]', $this->stored[ReadEventVisibility::CONFIG_KEY]);
	}

	public function testTheFullSaveRecordsAChangeOfTheSetting(): void {
		$this->assertContains('@' . ReadEventVisibility::CONFIG_KEY, OwnSettingsChangeRecorder::FULL_SAVE_KEYS);
	}
}
