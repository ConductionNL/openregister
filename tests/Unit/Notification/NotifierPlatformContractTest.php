<?php

/**
 * What Nextcloud does with a prepared notification: the double keeps the platform's own rules.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notification;

use InvalidArgumentException;
use OCA\OpenRegister\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The notifications app shows getParsedActions() only, and setIcon() takes an absolute URL only.
 *
 * @covers \OCA\OpenRegister\Notification\Notifier
 * @covers \OCA\OpenRegister\Notification\SubjectRenderer
 * @covers \OCA\OpenRegister\Notification\SubjectRenderers
 * @covers \OCA\OpenRegister\Notification\ArchivalSubjectRenderer
 * @covers \OCA\OpenRegister\Notification\ReportSubjectRenderer
 * @covers \OCA\OpenRegister\Notification\WorkSubjectRenderer
 * @covers \OCA\OpenRegister\Notification\AccessSubjectRenderer
 * @covers \OCA\OpenRegister\Notification\DelegationConsentRenderer
 * @covers \OCA\OpenRegister\Notification\ConsentPromptDraft
 * @covers \OCA\OpenRegister\Notification\DisplayNames
 */
class NotifierPlatformContractTest extends TestCase {
	private const BASE = 'https://cloud.example/index.php/apps/openregister/api/delegations/g-1/answer';

	private string $icon = '';

	private array $parsed = [];

	private function action(): IAction {
		$state = new \ArrayObject(['label' => '', 'link' => '', 'type' => '', 'primary' => false]);
		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setParsedLabel')->willReturnCallback(
			static function (string $label) use ($state, $action): IAction {
				$state['label'] = $label;
				return $action;
			}
		);
		$action->method('setLink')->willReturnCallback(
			static function (string $link, string $requestType) use ($state, $action): IAction {
				$state['link'] = $link;
				$state['type'] = $requestType;
				return $action;
			}
		);
		$action->method('setPrimary')->willReturnCallback(
			static function (bool $primary) use ($state, $action): IAction {
				$state['primary'] = $primary;
				return $action;
			}
		);
		$action->method('getParsedLabel')->willReturnCallback(static fn (): string => $state['label']);
		$action->method('isValidParsed')->willReturnCallback(static fn (): bool => $state['label'] !== '' && $state['link'] !== '');
		$action->method('isPrimary')->willReturnCallback(static fn (): bool => $state['primary']);
		$action->method('getLink')->willReturnCallback(static fn (): string => $state['link']);
		$action->method('getRequestType')->willReturnCallback(static fn (): string => $state['type']);

		return $action;
	}//end action()

	private function notification(string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('createAction')->willReturnCallback(fn (): IAction => $this->action());
		$notification->method('setIcon')->willReturnCallback(
			function (string $icon) use ($notification): INotification {
				if (str_starts_with($icon, 'https://') === false) {
					throw new InvalidArgumentException('icon');
				}

				$this->icon = $icon;
				return $notification;
			}
		);
		$notification->method('addParsedAction')->willReturnCallback(
			function (IAction $action) use ($notification): INotification {
				$this->assertTrue($action->isValidParsed());
				$this->parsed[] = [$action->getParsedLabel(), $action->getLink(), $action->getRequestType(), $action->isPrimary()];
				return $notification;
			}
		);

		return $notification;
	}//end notification()

	private function notifier(): Notifier {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnArgument(1);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/openregister/img/app.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => [
				'files.view.index'               => 'https://cloud.example/index.php/apps/files/',
				'openregister.dashboard.page'    => 'https://cloud.example/index.php/apps/openregister/',
				'openregister.delegation.answer' => self::BASE,
			][$route]
		);

		return new Notifier($factory, $urls);
	}//end notifier()

	public static function subjects(): array {
		return [
			'configuration update' => ['configuration_update_available', ['configurationId' => 42], [['View', 'https://cloud.example/index.php/apps/openregister/#/configurations/42', 'GET', true]]],
			'report delivered'     => ['scheduled_report_delivered', ['mode' => 'files', 'folder' => 'Reports/'], [['Open Files', 'https://cloud.example/index.php/apps/files/?dir=%2FReports', 'GET', true]]],
			'audit export ready'   => ['audit_export_ready', ['folder' => 'Audit/'], [['Open Files', 'https://cloud.example/index.php/apps/files/?dir=%2FAudit', 'GET', true]]],
			'consent requested'    => [
				'delegation_consent_requested',
				['principal' => 'eve', 'grantUuid' => 'g-1'],
				[['Allow', self::BASE . '?allow=1', 'POST', true], ['Deny', self::BASE . '?allow=0', 'POST', false]],
			],
			'no action'            => ['form_unpublished', [], []],
			'archival'             => ['retention_holds_skipped', [], []],
			'access'               => ['external_grant_lapsing', [], []],
		];
	}//end subjects()

	#[DataProvider('subjects')]
	public function testTheNotificationCarriesAnAbsoluteIconAndParsedActions(string $subject, array $parameters, array $actions): void {
		$this->notifier()->prepare($this->notification($subject, $parameters), 'en');

		$this->assertSame('https://cloud.example/apps/openregister/img/app.svg', $this->icon);
		$this->assertSame($actions, $this->parsed);
	}//end testTheNotificationCarriesAnAbsoluteIconAndParsedActions()
}//end class
