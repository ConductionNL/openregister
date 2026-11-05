<?php

/**
 * Pins the icon every rendered subject of the OpenRegister notifier carries.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notification;

use OCA\OpenRegister\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Every subject the notifier renders gets the app icon, and nothing else.
 *
 * @covers \OCA\OpenRegister\Notification\Notifier
 */
class NotifierIconTest extends TestCase {

	private const SUBJECTS = [
		'configuration_update_available',
		'handoff_drain_failed',
		'scheduled_report_delivered',
		'scheduled_report_failed',
		'audit_export_ready',
		'delegation_consent_requested',
		'credential_relink_needed',
		'retention_holds_skipped',
		'destruction_holds_skipped',
		'destruction_review_pending',
		'timeline_mention',
		'object_ownership_changed',
		'security_setting_changed',
		'view_alert_crossed',
		'form_unpublished',
	];

	/**
	 * The icons set while rendering one subject.
	 *
	 * @param string $subject The notification subject.
	 *
	 * @return array<int, string>
	 */
	private function iconsFor(string $subject): array {
		$icons = [];
		$action = $this->createMock(IAction::class);
		foreach (['setLabel', 'setParsedLabel', 'setPrimary', 'setLink'] as $method) {
			$action->method($method)->willReturnSelf();
		}

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn([
			'configurationId' => 42, 'objectUuid' => 'u-1', 'objectTitle' => 'T', 'skippedCount' => 2,
			'schemaSlug' => 's', 'listUuid' => 'l-1', 'author' => 'a', 'newOwner' => 'b', 'label' => 'L',
			'actor' => 'c', 'oldValue' => '1', 'newValue' => '2', 'secret' => false, 'mode' => 'email',
		]);
		$notification->method('createAction')->willReturn($action);
		foreach (['setParsedSubject', 'setParsedMessage', 'setRichSubject', 'setRichMessage', 'setLink', 'addParsedAction', 'addAction'] as $method) {
			$notification->method($method)->willReturnSelf();
		}

		$notification->method('setIcon')->willReturnCallback(function (string $icon) use (&$icons, $notification) {
			$icons[] = $icon;
			return $notification;
		});

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many): string => $many);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "img:$app/$file");
		$urls->method('linkToRouteAbsolute')->willReturn('https://example.org/r');
		$urls->method('linkToRoute')->willReturn('/r');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => "abs:$url");

		(new Notifier($factory, $urls))->prepare($notification, 'en');
		return $icons;
	}

	/**
	 * Each rendered subject sets the app icon exactly once.
	 *
	 * @return void
	 */
	public function testEverySubjectGetsTheAppIconOnce(): void {
		$actual = [];
		foreach (self::SUBJECTS as $subject) {
			$actual[$subject] = $this->iconsFor(subject: $subject);
		}

		$this->assertSame(array_fill_keys(self::SUBJECTS, ['img:openregister/app.svg']), $actual);
	}
}
