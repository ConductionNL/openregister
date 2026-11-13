<?php

/**
 * Unit tests for WebhookMapper::findOwnedBy(), run as SQL against the table
 * the migrations build: the owner column exists, a user's own webhooks are
 * found, an administrator's webhook (no owner) never is, and an empty user id
 * owns nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
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
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\WebhookMapper
 * @covers \OCA\OpenRegister\Db\Webhook
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113120000
 */
class WebhookMapperOwnedByTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private WebhookMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->database = new MigratedSqliteDatabase($this, ['openregister_webhooks']);

		// Multitenancy off, so the rows below are filtered on their owner alone.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'multitenancy') ? '{"enabled":false}' : $default
		);

		$this->mapper = new WebhookMapper(
			$this->database->idbConnection(),
			new TenancyServices($this->createMock(OrganisationMapper::class), $this->createMock(IUserSession::class), $this->createMock(IGroupManager::class)),
			$appConfig
		);

		foreach ([['admin-hook', null], ['sanne-1', 'sanne'], ['mark-1', 'mark'], ['sanne-2', 'sanne']] as [$name, $owner]) {
			$this->database->insert('openregister_webhooks', ['uuid' => $name, 'name' => $name, 'url' => 'https://receiver.example/' . $name, 'owner' => $owner]);
		}
	}//end setUp()

	public function testAUsersOwnWebhooksAreFoundAndNoOneElses(): void {
		$found = $this->mapper->findOwnedBy('sanne');

		$this->assertSame(['sanne-1', 'sanne-2'], array_map(static fn (Webhook $hook): string => $hook->getName(), $found));
		$this->assertSame(['sanne', 'sanne'], array_map(static fn (Webhook $hook): ?string => $hook->getOwner(), $found));
	}

	public function testAnAdministratorsWebhookHasNoOwnerAndIsNeverOwned(): void {
		$this->assertSame([], $this->mapper->findOwnedBy('admin'));
		$this->assertSame(['mark-1'], array_map(static fn (Webhook $hook): string => $hook->getName(), $this->mapper->findOwnedBy('mark')));
	}

	public function testAnEmptyUserIdOwnsNothing(): void {
		$this->assertSame([], $this->mapper->findOwnedBy(''));
		$this->assertSame([], $this->mapper->findOwnedBy('  '));
	}

	public function testTheOwnerIsSerialisedAndIsNotTakenFromARequestBody(): void {
		$hook = $this->mapper->findOwnedBy('mark')[0];
		$this->assertSame('mark', $hook->jsonSerialize()['owner']);

		$fresh = new Webhook();
		$fresh->hydrate(['name' => 'x', 'url' => 'https://receiver.example/x', 'owner' => 'eve']);
		$this->assertNull($fresh->getOwner());
		$this->assertNull($fresh->jsonSerialize()['owner']);
	}
}
