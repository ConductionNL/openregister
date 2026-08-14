<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the web-push extensions of the annotation validator: the `web-push`
 * channel, the `actions[]` hard cap of 2 plus action-shape errors, and the
 * `originApp` shape error.
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
 */
class NotificationAnnotationValidatorWebPushTest extends TestCase {
	private NotificationAnnotationValidator $v;

	protected function setUp(): void {
		$this->v = new NotificationAnnotationValidator();
	}

	/**
	 * Build a minimal-but-valid notification spec, overlaying $overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to merge onto the base spec.
	 *
	 * @return array<string, mixed> A full schema with one notification.
	 */
	private function schema(array $overrides): array {
		$spec = array_merge(
			[
				'trigger' => ['type' => 'created'],
				'recipients' => [['kind' => 'users', 'users' => ['admin']]],
				'channels' => ['web-push'],
				'subject' => 'hi',
			],
			$overrides
		);

		return [
			'x-openregister-notifications' => ['x' => $spec],
			'properties' => [],
		];
	}

	public function testWebPushChannelIsAccepted(): void {
		$errors = $this->v->validate($this->schema(['channels' => ['web-push']]));
		$codes = array_column($errors, 'code');
		$this->assertNotContains('notification-bad-channel', $codes);
		$this->assertSame([], $errors);
	}

	public function testThirdActionIsRejected(): void {
		$action = [
			'label' => ['en' => 'Open'],
			'target' => ['kind' => 'object-detail'],
		];
		$errors = $this->v->validate($this->schema(['actions' => [$action, $action, $action]]));
		$codes = array_column($errors, 'code');
		$this->assertContains('notification-too-many-actions', $codes);
	}

	public function testBadActionLabelIsRejected(): void {
		$errors = $this->v->validate($this->schema([
			'actions' => [
				[
					'label' => [],
					'target' => ['kind' => 'url', 'url' => 'https://example.com'],
				],
			],
		]));
		$codes = array_column($errors, 'code');
		$this->assertContains('notification-action-bad-label', $codes);
	}

	public function testBadActionTargetKindIsRejected(): void {
		$errors = $this->v->validate($this->schema([
			'actions' => [
				[
					'label' => ['en' => 'Open'],
					'target' => ['kind' => 'mailto'],
				],
			],
		]));
		$codes = array_column($errors, 'code');
		$this->assertContains('notification-action-bad-target', $codes);
	}

	public function testEmptyOriginAppIsRejected(): void {
		$errors = $this->v->validate($this->schema(['originApp' => '']));
		$codes = array_column($errors, 'code');
		$this->assertContains('notification-bad-origin-app', $codes);
	}

	public function testValidOriginAppAndActionsAreAccepted(): void {
		$errors = $this->v->validate($this->schema([
			'originApp' => 'pipelinq',
			'actions' => [
				[
					'label' => ['en' => 'Open client', 'nl' => 'Open klant'],
					'primary' => true,
					'target' => ['kind' => 'object-detail'],
				],
			],
		]));
		$this->assertSame([], $errors);
	}
}
