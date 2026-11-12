<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\Notification\NotificationsAnnotationInstaller;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A persistent notification webhook is a webhook, and webhooks are written by administrators only.
 */
class NotificationsAnnotationInstallerAdminTest extends TestCase {

	private WebhookMapper&MockObject $webhookMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->webhookMapper = $this->createMock(WebhookMapper::class);
		$this->webhookMapper->method('findAll')->willReturn([]);
	}

	/**
	 * An installer for a session with the given user, or none.
	 *
	 * @param string|null $uid     The signed-in user, null for none.
	 * @param bool        $isAdmin Whether that user is an administrator.
	 *
	 * @return NotificationsAnnotationInstaller
	 */
	private function installer(?string $uid, bool $isAdmin): NotificationsAnnotationInstaller {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $id === $uid && $isAdmin);

		return new NotificationsAnnotationInstaller($this->webhookMapper, new NullLogger(), $session, $groups);
	}

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('meeting');
		$schema->setConfiguration(
			[
				'x-openregister-notifications' => [
					'n' => [
						'channels' => ['webhook'],
						'webhook' => ['persistent' => true, 'url' => 'https://example.com/hook', 'events' => []],
					],
				],
			]
		);

		return $schema;
	}

	public function testASchemaManagerWhoIsNotAnAdministratorInstallsNoWebhook(): void {
		$this->webhookMapper->expects($this->never())->method('createFromArray');
		$this->webhookMapper->expects($this->never())->method('updateFromArray');

		$this->installer(uid: 'manager', isAdmin: false)->installSchema($this->schema());
	}

	public function testANonAdministratorDoesNotRewriteAnInstalledWebhookEither(): void {
		$existing = new Webhook();
		$existing->setId(42);
		$existing->setName('or-notif-meeting-n');
		$webhooks = $this->createMock(WebhookMapper::class);
		$webhooks->method('findAll')->willReturn([$existing]);
		$webhooks->expects($this->never())->method('updateFromArray');
		$this->webhookMapper = $webhooks;

		$this->installer(uid: 'manager', isAdmin: false)->installSchema($this->schema());
	}

	public function testAnAdministratorInstallsTheWebhook(): void {
		$this->webhookMapper->expects($this->once())->method('createFromArray')->willReturn(new Webhook());

		$this->installer(uid: 'admin', isAdmin: true)->installSchema($this->schema());
	}

	public function testAnInstallWithoutASignedInUserInstallsTheWebhook(): void {
		$this->webhookMapper->expects($this->once())->method('createFromArray')->willReturn(new Webhook());

		$this->installer(uid: null, isAdmin: false)->installSchema($this->schema());
	}
}
