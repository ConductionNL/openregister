<?php

/**
 * Golden test for what the OpenRegister notifier renders for each subject.
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
 * Records every call each renderer makes on the notification and its actions,
 * for two parameter sets per subject, against a golden recorded before the renderers were split.
 *
 * @covers \OCA\OpenRegister\Notification\Notifier
 */
class NotifierRenderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/notifier-render.json';

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
	 * Parameter sets: every optional value present, none, and empty or alternative values.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PARAMETERS = [
		'full' => [
			'configurationId' => 42, 'configurationTitle' => 'Conf', 'currentVersion' => '1.0', 'newVersion' => '2.0',
			'objectUuid' => 'u-1', 'objectTitle' => 'Obj', 'skippedCount' => 2, 'schemaSlug' => 's', 'listUuid' => 'l-1',
			'author' => 'ann', 'newOwner' => 'bob', 'previousOwner' => 'cy', 'label' => 'Lbl', 'actor' => 'dee',
			'oldValue' => 'o', 'newValue' => 'n', 'secret' => false, 'mode' => 'both', 'reportName' => 'Rep',
			'folder' => '/Reports/x/', 'filename' => 'r.csv', 'emailFailureReason' => 'smtp', 'error' => 'E',
			'principal' => 'eve', 'grantUuid' => 'g-1', 'credentialId' => 'c-1', 'viewName' => 'V', 'threshold' => 3,
			'count' => 4, 'formTitle' => 'F', 'formUuid' => 'f-1', 'exportUuid' => 'x-1', 'reason' => 'R',
		],
		'bare' => [],
		'blank' => ['emailFailureReason' => '', 'mode' => 'email', 'secret' => true, 'newOwner' => '', 'author' => '', 'actor' => ''],
	];

	/**
	 * Every call made while rendering one subject with one parameter set.
	 *
	 * @param string $subject The notification subject.
	 * @param array<string, mixed> $parameters The subject parameters.
	 *
	 * @return array<int, string>
	 */
	private function render(string $subject, array $parameters): array {
		$calls = [];
		$record = static function (string $who, string $method, array $args) use (&$calls): void {
			$calls[] = $who . '.' . $method . ' ' . json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		};
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('openregister');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('createAction')->willReturnCallback(function () use ($record, &$calls) {
			$action = $this->createMock(IAction::class);
			$n = count($calls);
			foreach (['setLabel', 'setParsedLabel', 'setPrimary', 'setLink'] as $method) {
				$action->method($method)->willReturnCallback(function (...$args) use ($record, $method, $action, $n) {
					$record('action' . $n, $method, $args);
					return $action;
				});
			}

			$record('notification', 'createAction', []);
			return $action;
		});
		foreach (['setParsedSubject', 'setParsedMessage', 'setRichSubject', 'setRichMessage', 'setIcon', 'setLink', 'addAction', 'addParsedAction'] as $method) {
			$notification->method($method)->willReturnCallback(function (...$args) use ($record, $method, $notification) {
				$record('notification', $method, $method === 'addAction' || $method === 'addParsedAction' ? [] : $args);
				return $notification;
			});
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $args = []): string => 't(' . $text . '|' . json_encode($args) . ')');
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $args = []): string => 'n(' . $many . '|' . $count . '|' . json_encode($args) . ')');
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "img:$app/$file");
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $args = []): string => 'abs:' . $route . json_encode($args));
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args = []): string => 'rel:' . $route . json_encode($args));
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => "abs:$url");

		try {
			(new Notifier($factory, $urls))->prepare($notification, 'en');
		} catch (\Throwable $e) {
			$calls[] = 'threw ' . get_class($e) . ': ' . $e->getMessage();
		}

		return $calls;
	}

	/**
	 * Every subject renders as recorded, with and without its optional parameters.
	 *
	 * @return void
	 */
	public function testEverySubjectRendersAsRecorded(): void {
		$actual = [];
		foreach (self::SUBJECTS as $subject) {
			foreach (self::PARAMETERS as $set => $parameters) {
				$actual[$subject][$set] = $this->render(subject: $subject, parameters: $parameters);
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
