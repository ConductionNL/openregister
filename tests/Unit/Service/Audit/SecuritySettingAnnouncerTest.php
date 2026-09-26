<?php

/**
 * Unit tests for announcing a security-relevant setting change.
 *
 * Covers REQ-ATS-004: a marked setting that changes notifies the
 * administrators with the setting, the actor and both values, and a secret is
 * announced as changed without either value, not even in the stored
 * notification parameters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCA\OpenRegister\Service\Audit\SecuritySettingRegistry;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecuritySettingAnnouncerTest extends TestCase {
	/** @var array<int, array{user: string, subject: string, parameters: array}> */
	private array $sent = [];

	/** @var array<string, string> */
	private array $stored = [];

	private function appConfig(): IAppConfig {
		// ONE store behind the reads, so a snapshot taken after a write sees it.
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (
				array_key_exists($key, $this->stored) === true ? $this->stored[$key] === '1' : $default
			)
		);

		return $config;
	}//end appConfig()

	private function notificationManager(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$record = ['user' => '', 'subject' => '', 'parameters' => []];
			$notification = $this->createMock(INotification::class);
			$notification->method('setApp')->willReturnSelf();
			$notification->method('setDateTime')->willReturnSelf();
			$notification->method('setObject')->willReturnSelf();
			$notification->method('setUser')->willReturnCallback(
				function (string $uid) use (&$record, $notification) {
					$record['user'] = $uid;

					return $notification;
				}
			);
			$notification->method('setSubject')->willReturnCallback(
				function (string $subject, array $parameters) use (&$record, $notification) {
					$record['subject'] = $subject;
					$record['parameters'] = $parameters;
					$this->sent[] = &$record;

					return $notification;
				}
			);

			return $notification;
		});

		return $manager;
	}//end notificationManager()

	private function user(string $uid, string $name): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($name);

		return $user;
	}//end user()

	private function announcer(?IGroupManager $groups = null): SecuritySettingAnnouncer {
		if ($groups === null) {
			$admins = $this->createMock(IGroup::class);
			$admins->method('getUsers')->willReturn([$this->user('beheer1', 'Beheer 1'), $this->user('beheer2', 'Beheer 2')]);
			$groups = $this->createMock(IGroupManager::class);
			$groups->method('get')->with('admin')->willReturn($admins);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user('jan', 'Jan Jansen'));

		return new SecuritySettingAnnouncer(
			new SecuritySettingRegistry($this->appConfig()),
			$this->notificationManager(),
			$groups,
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end announcer()

	public function testTheAdministratorsHearWhenAccessControlIsSwitchedOff(): void {
		$announcer = $this->announcer();
		$before = $announcer->snapshot();

		$this->stored['rbac'] = (string)json_encode(['enabled' => false]);
		$sent = $announcer->announce($before, $announcer->snapshot());

		self::assertSame(2, $sent, 'every administrator is told');
		self::assertSame(['beheer1', 'beheer2'], array_column($this->sent, 'user'));

		$parameters = $this->sent[0]['parameters'];
		self::assertSame(SecuritySettingAnnouncer::SUBJECT, $this->sent[0]['subject']);
		self::assertSame('rbac.enabled', $parameters['setting']);
		self::assertSame('Access control', $parameters['label']);
		self::assertSame('Jan Jansen', $parameters['actor']);
		self::assertSame('on', $parameters['oldValue']);
		self::assertSame('off', $parameters['newValue']);
		self::assertFalse($parameters['secret']);
	}//end testTheAdministratorsHearWhenAccessControlIsSwitchedOff()

	public function testASecretIsAnnouncedWithoutEitherValue(): void {
		$this->stored['solr'] = (string)json_encode(['password' => 'oud-geheim']);
		$announcer = $this->announcer();
		$before = $announcer->snapshot();

		$this->stored['solr'] = (string)json_encode(['password' => 'nieuw-geheim']);
		$announcer->announce($before, $announcer->snapshot());

		self::assertNotSame([], $this->sent, 'a changed secret is still announced');
		$parameters = $this->sent[0]['parameters'];
		self::assertSame('solr.password', $parameters['setting']);
		self::assertTrue($parameters['secret']);
		self::assertArrayNotHasKey('oldValue', $parameters);
		self::assertArrayNotHasKey('newValue', $parameters);

		// Not in the parameters at all, because Nextcloud stores those.
		$everything = (string)json_encode($this->sent);
		self::assertStringNotContainsString('oud-geheim', $everything);
		self::assertStringNotContainsString('nieuw-geheim', $everything);
	}//end testASecretIsAnnouncedWithoutEitherValue()

	public function testACredentialMissingItsFlagIsStillNotQuoted(): void {
		// The fallback: a path naming a token is a secret whatever the list says.
		$registry = new SecuritySettingRegistry($this->appConfig());

		self::assertTrue($registry->isSecret('integration.apiToken'));
		self::assertTrue($registry->isSecret('solr.zookeeperPassword'));
		self::assertFalse($registry->isSecret('rbac.enabled'));
	}//end testACredentialMissingItsFlagIsStillNotQuoted()

	public function testSavingTheDefaultOverAnUnsetValueIsNotAChange(): void {
		// First visit to the settings page, click save: nobody changed anything.
		$announcer = $this->announcer();
		$before = $announcer->snapshot();

		$this->stored['rbac'] = (string)json_encode(['enabled' => true, 'adminOverride' => true]);
		$this->stored['flow_kill_switch'] = '0';

		self::assertSame(0, $announcer->announce($before, $announcer->snapshot()));
		self::assertSame([], $this->sent);
	}//end testSavingTheDefaultOverAnUnsetValueIsNotAChange()

	public function testAnUnmarkedSettingIsNotAnnounced(): void {
		$announcer = $this->announcer();

		$changes = $announcer->changes(
			['retention.readLogRetention' => 1, 'rbac.enabled' => true],
			['retention.readLogRetention' => 2, 'rbac.enabled' => true]
		);

		self::assertSame([], $changes);
	}//end testAnUnmarkedSettingIsNotAnnounced()

	public function testTheEmergencyStopIsAnnounced(): void {
		$announcer = $this->announcer();
		$before = $announcer->snapshot();

		$this->stored['flow_kill_switch'] = '1';
		$announcer->announce($before, $announcer->snapshot());

		self::assertSame('@flow_kill_switch', $this->sent[0]['parameters']['setting']);
		self::assertSame('off', $this->sent[0]['parameters']['oldValue']);
		self::assertSame('on', $this->sent[0]['parameters']['newValue']);
	}//end testTheEmergencyStopIsAnnounced()

	public function testAnInstanceWithoutAnAdminGroupSavesQuietly(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn(null);
		$announcer = $this->announcer($groups);
		$before = $announcer->snapshot();

		$this->stored['rbac'] = (string)json_encode(['enabled' => false]);

		self::assertSame(0, $announcer->announce($before, $announcer->snapshot()));
	}//end testAnInstanceWithoutAnAdminGroupSavesQuietly()

	public function testAFailedDeliveryDoesNotFailTheSave(): void {
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn([$this->user('beheer1', 'Beheer 1')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($admins);

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willThrowException(new \RuntimeException('notifications app disabled'));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$announcer = new SecuritySettingAnnouncer(
			new SecuritySettingRegistry($this->appConfig()),
			$manager,
			$groups,
			$session,
			$this->createMock(LoggerInterface::class)
		);

		self::assertSame(
			0,
			$announcer->announce(['rbac.enabled' => true], ['rbac.enabled' => false])
		);
	}//end testAFailedDeliveryDoesNotFailTheSave()

	public function testAChangeWithNoSessionIsAttributedToTheSystem(): void {
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn([$this->user('beheer1', 'Beheer 1')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($admins);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$announcer = new SecuritySettingAnnouncer(
			new SecuritySettingRegistry($this->appConfig()),
			$this->notificationManager(),
			$groups,
			$session,
			$this->createMock(LoggerInterface::class)
		);

		$announcer->announce(['rbac.enabled' => true], ['rbac.enabled' => false]);

		self::assertSame('system', $this->sent[0]['parameters']['actor']);
	}//end testAChangeWithNoSessionIsAttributedToTheSystem()
}//end class
