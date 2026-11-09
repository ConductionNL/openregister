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

use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Records every call each renderer makes on the notification and its actions,
 * for two parameter sets per subject, against a golden recorded before the renderers were split.
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
		'external_grant_lapsing',
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
		'other' => [
			'configurationId' => 0, 'setting' => 'Set', 'secret' => true, 'actor' => 'dee', 'provider' => 'Prov', 'pendingCount' => 3,
			'handoffId' => 'h-1', 'targetKind' => 'kind', 'status' => 'failed-permission', 'rows' => 12, 'view' => 'Vw', 'operator' => 'gte',
			'threshold' => 3, 'count' => 9, 'schema' => 'Sch', 'daysLeft' => 5, 'action' => 'read', 'endsAt' => '2031-01-02',
			'folder' => 'Audit exports/x/', 'filename' => 'a.csv', 'mode' => 'email', 'emailFailureReason' => 'smtp', 'reportName' => 'Rep',
		],
		'text flag' => ['secret' => 'yes', 'oldValue' => 'a', 'newValue' => 'b'],
		'plain' => [
			'setting' => 'Set', 'status' => 'failed', 'operator' => 'lte', 'view' => 'Vw', 'count' => 1, 'threshold' => 3, 'pendingCount' => 1,
			'skippedCount' => 1, 'mode' => 'files', 'folder' => 'R', 'filename' => 'r.csv', 'reason' => 'Why', 'objectTitle' => 'Obj',
			'author' => 'ann', 'newOwner' => 'nobody',
		],
	];

	/**
	 * Subjects an administrator edited, with what the template registry answers.
	 *
	 * @var array<string, array{known: bool, edited: bool, rendered: array<string, string>|null}>
	 */
	private const TEMPLATES = [
		'credential_relink_needed' => ['known' => true, 'edited' => true, 'rendered' => ['subject' => 'Edited subject', 'body' => 'Edited body']],
		'view_alert_crossed' => ['known' => true, 'edited' => true, 'rendered' => null],
		'form_unpublished' => ['known' => true, 'edited' => true, 'rendered' => ['subject' => '', 'body' => 'Empty subject']],
		'timeline_mention' => ['known' => true, 'edited' => false, 'rendered' => ['subject' => 'Not edited', 'body' => 'Not edited']],
		'audit_export_ready' => ['known' => false, 'edited' => true, 'rendered' => ['subject' => 'Not known', 'body' => 'Not known']],
	];

	/**
	 * Every call made while rendering one subject with one parameter set.
	 *
	 * A message overwritten by the next call is left out: only the last one is ever read.
	 *
	 * @param string $subject The notification subject.
	 * @param array<string, mixed> $parameters The subject parameters.
	 * @param bool $withCollaborators Whether the optional user manager and template registry are there.
	 *
	 * @return array<int, string>
	 */
	private function render(string $subject, array $parameters, bool $withCollaborators = false): array {
		$calls = [];
		$record = static function (string $who, string $method, array $args) use (&$calls): void {
			$line = $who . '.' . $method . ' ' . json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($method === 'setParsedMessage' && str_starts_with((string)end($calls), 'notification.setParsedMessage') === true) {
				array_pop($calls);
			}

			$calls[] = $line;
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
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $args = []): string => 'n(' . $one . '|' . $many . '|' . $count . '|' . json_encode($args) . ')');
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => "img:$app/$file");
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $args = []): string => 'abs:' . $route . json_encode($args));
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args = []): string => 'rel:' . $route . json_encode($args));
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => "abs:$url");

		try {
			$notifier = new Notifier($factory, $urls);
			if ($withCollaborators === true) {
				$notifier = new Notifier($factory, $urls, $this->users(), $this->templates(record: $record));
			}

			$notifier->prepare($notification, 'en');
		} catch (\Throwable $e) {
			$calls[] = 'threw ' . get_class($e) . ': ' . $e->getMessage();
		}

		return $calls;
	}

	/**
	 * A user manager that knows bob by name, eve by a blank name, and answers for an empty uid too.
	 *
	 * @return IUserManager
	 */
	private function users(): IUserManager {
		$bob = $this->createMock(IUser::class);
		$bob->method('getDisplayName')->willReturn(' Bob Jansen ');
		$eve = $this->createMock(IUser::class);
		$eve->method('getDisplayName')->willReturn('  ');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ['bob' => $bob, 'eve' => $eve, '' => $bob][$uid] ?? null
		);

		return $users;
	}

	/**
	 * A template registry answering from TEMPLATES, recording what it is asked to render.
	 *
	 * @param callable $record Records a call.
	 *
	 * @return NotificationTemplatesInterface
	 */
	private function templates(callable $record): NotificationTemplatesInterface {
		$templates = $this->createMock(NotificationTemplatesInterface::class);
		$templates->method('knows')->willReturnCallback(static fn (string $event): bool => self::TEMPLATES[$event]['known'] ?? false);
		$templates->method('hasEdit')->willReturnCallback(static fn (string $event): bool => self::TEMPLATES[$event]['edited'] ?? false);
		$templates->method('render')->willReturnCallback(
			static function (string $event, string $locale, array $variables) use ($record): ?array {
				$record('templates', 'render', [$event, $locale, $variables]);
				return self::TEMPLATES[$event]['rendered'] ?? null;
			}
		);

		return $templates;
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

			$actual[$subject]['collaborators'] = $this->render(subject: $subject, parameters: self::PARAMETERS['full'], withCollaborators: true);
			$actual[$subject]['blank name'] = $this->render(subject: $subject, parameters: ['principal' => 'eve', 'newOwner' => 'eve'], withCollaborators: true);
			$actual[$subject]['known user'] = $this->render(subject: $subject, parameters: ['principal' => 'bob', 'newOwner' => 'bob'], withCollaborators: true);
			$actual[$subject]['no user'] = $this->render(subject: $subject, parameters: ['principal' => '', 'newOwner' => ''], withCollaborators: true);
			$actual[$subject]['unknown user'] = $this->render(subject: $subject, parameters: ['principal' => 'zed', 'newOwner' => 'zed'], withCollaborators: true);
		}

		$actual['unknown_subject']['full'] = $this->render(subject: 'unknown_subject', parameters: self::PARAMETERS['full']);

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertGreaterThan(50000, strlen($json));
		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
