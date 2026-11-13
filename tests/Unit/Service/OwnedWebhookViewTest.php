<?php

/**
 * An owned webhook delivers only what its owner may see (webhooks-for-owners, D-5).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-delivers-only-what-its-owner-may-see
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\MappingService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\OwnedWebhookView;
use OCA\OpenRegister\Service\WebhookDelivery;
use OCA\OpenRegister\Service\WebhookDispatchServices;
use OCA\OpenRegister\Service\WebhookEvent;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Service\WebhookStores;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class OwnedWebhookViewTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $disabled = [];

	/** @var array<int, array<string, mixed>> */
	private array $reads = [];

	private WebhookMapper&MockObject $webhooks;

	/**
	 * The object as the owner reads it: `bsn` is hidden from them.
	 *
	 * @return array<string, mixed>
	 */
	private static function ownersRead(): array {
		return ['@self' => ['id' => 'obj-1'], 'naam' => 'Jansen'];
	}//end ownersRead()

	/**
	 * The full event payload, with a property the owner may not see.
	 *
	 * @param string $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload(string $action = 'create'): array {
		return [
			'objectType' => 'object',
			'action' => $action,
			'object' => ['@self' => ['id' => 'obj-1'], 'naam' => 'Jansen', 'bsn' => '123456782'],
			'oldObject' => ['naam' => 'Janssen', 'bsn' => '123456782'],
			'objectUuid' => 'obj-1',
			'register' => '3',
			'schema' => '12',
			'timestamp' => '2026-11-13T10:00:00+00:00',
		];
	}//end payload()

	/**
	 * The view, with an owner and their reading.
	 *
	 * @param IUser|null $owner     The user the owner id resolves to.
	 * @param bool       $mayOwn    Whether the owner holds webhook.own.
	 * @param mixed      $read      What find() returns as the owner; a Throwable is thrown.
	 * @param bool       $matrixUp  Whether the action matrix can be read.
	 *
	 * @return OwnedWebhookView
	 */
	private function view(?IUser $owner, bool $mayOwn = true, mixed $read = 'default', bool $matrixUp = true): OwnedWebhookView {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'piet' ? $owner : null));

		$auth = $this->createMock(OpenRegisterActionAuthService::class);
		$auth->method('can')->willReturnCallback(
			static function (IUser $user, string $action) use ($mayOwn, $matrixUp): bool {
				if ($matrixUp === false) {
					throw new RuntimeException('matrix gone');
				}

				return $action === 'webhook.own' && $mayOwn;
			}
		);

		$this->webhooks = $this->createMock(WebhookMapper::class);
		$this->webhooks->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): Webhook {
				$this->disabled[] = ['id' => $id] + $data;
				return new Webhook();
			}
		);

		if ($read === 'default') {
			$read = new ObjectEntity();
			$read->setObject(self::ownersRead());
		}

		$objects = $this->createMock(ObjectService::class);
		$current = null;
		$objects->method('runAs')->willReturnCallback(
			static function (IUser $user, callable $operation) use (&$current) {
				$current = $user->getUID();
				try {
					return $operation();
				} finally {
					$current = null;
				}
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true) use ($read, &$current) {
				$this->reads[] = ['id' => $id, 'as' => $current, 'register' => $options?->register, 'schema' => $options?->schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($read instanceof \Throwable) {
					throw $read;
				}

				return $read;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ObjectService::class)->willReturn($objects);

		return new OwnedWebhookView(users: $users, actionAuth: $auth, webhooks: $this->webhooks, container: $container, logger: new NullLogger());
	}//end view()

	/**
	 * An owner.
	 *
	 * @param bool $enabled Whether the account is enabled.
	 *
	 * @return IUser
	 */
	private function piet(bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('piet');
		$user->method('isEnabled')->willReturn($enabled);

		return $user;
	}//end piet()

	/**
	 * A webhook, owned or not.
	 *
	 * @param string|null $owner The owner.
	 *
	 * @return Webhook
	 */
	private static function hook(?string $owner = 'piet'): Webhook {
		$hook = new Webhook();
		$hook->setId(7);
		$hook->setOwner($owner);

		return $hook;
	}//end hook()

	public function testAnAdministratorsWebhookDeliversTheEventUnchanged(): void {
		$event = new WebhookEvent(name: RegisterCreatedEvent::class, payload: ['register' => ['secret' => 'x']]);

		self::assertSame($event, $this->view(owner: null)->view(webhook: self::hook(owner: null), event: $event));
		self::assertSame($event, $this->view(owner: null)->view(webhook: self::hook(owner: '  '), event: $event));
		self::assertSame([], $this->reads);
	}//end testAnAdministratorsWebhookDeliversTheEventUnchanged()

	public function testACreatedObjectIsReReadAsTheOwnerAndTheHiddenPropertyIsGone(): void {
		$delivered = $this->view(owner: $this->piet())->view(webhook: self::hook(), event: new WebhookEvent(name: ObjectCreatedEvent::class, payload: self::payload()));

		self::assertNotNull($delivered);
		self::assertSame(ObjectCreatedEvent::class, $delivered->name);
		self::assertSame('Jansen', $delivered->payload['object']['naam']);
		self::assertArrayNotHasKey('bsn', $delivered->payload['object']);
		self::assertArrayNotHasKey('oldObject', $delivered->payload);
		self::assertStringNotContainsString('123456782', (string)json_encode($delivered->payload));
		self::assertSame('obj-1', $delivered->payload['objectUuid']);
		self::assertSame([['id' => 'obj-1', 'as' => 'piet', 'register' => '3', 'schema' => '12', 'rbac' => true, 'multitenancy' => true]], $this->reads);
	}//end testACreatedObjectIsReReadAsTheOwnerAndTheHiddenPropertyIsGone()

	public function testAnUpdateDropsTheEarlierVersion(): void {
		$payload = self::payload(action: 'update');
		unset($payload['object'], $payload['objectUuid']);
		$payload['newObject'] = ['@self' => ['id' => 'obj-1'], 'bsn' => '123456782'];

		$delivered = $this->view(owner: $this->piet())->view(webhook: self::hook(), event: new WebhookEvent(name: ObjectUpdatedEvent::class, payload: $payload));

		self::assertSame('Jansen', $delivered->payload['object']['naam']);
		self::assertArrayNotHasKey('bsn', $delivered->payload['object']);
		self::assertArrayNotHasKey('oldObject', $delivered->payload);
		self::assertArrayNotHasKey('newObject', $delivered->payload);
		self::assertSame('obj-1', $this->reads[0]['id']);
	}//end testAnUpdateDropsTheEarlierVersion()

	public function testADeletedObjectIsSentAsIdentifiersOnly(): void {
		$delivered = $this->view(owner: $this->piet())->view(webhook: self::hook(), event: new WebhookEvent(name: ObjectDeletedEvent::class, payload: self::payload(action: 'delete')));

		self::assertSame(
			['objectType' => 'object', 'action' => 'delete', 'objectUuid' => 'obj-1', 'register' => '3', 'schema' => '12', 'timestamp' => '2026-11-13T10:00:00+00:00'],
			$delivered->payload
		);
		self::assertSame([], $this->reads);
	}//end testADeletedObjectIsSentAsIdentifiersOnly()

	public function testAnObjectTheOwnerCannotReadIsNotDelivered(): void {
		foreach ([null, new RuntimeException('not allowed')] as $read) {
			$this->reads = [];
			self::assertNull($this->view(owner: $this->piet(), read: $read)->view(webhook: self::hook(), event: new WebhookEvent(name: ObjectCreatedEvent::class, payload: self::payload())));
			self::assertCount(1, $this->reads);
		}

		$payload = self::payload();
		unset($payload['objectUuid'], $payload['object']['@self']);
		self::assertNull($this->view(owner: $this->piet())->view(webhook: self::hook(), event: new WebhookEvent(name: ObjectCreatedEvent::class, payload: ['action' => 'create'])));
		self::assertSame([], $this->disabled, 'An unreadable object does not disable the webhook.');
	}//end testAnObjectTheOwnerCannotReadIsNotDelivered()

	public function testAnotherEventIsNotDelivered(): void {
		self::assertNull($this->view(owner: $this->piet())->view(webhook: self::hook(), event: new WebhookEvent(name: RegisterCreatedEvent::class, payload: ['register' => []])));
		self::assertSame([], $this->disabled);
	}//end testAnotherEventIsNotDelivered()

	public function testAnOwnerWhoMayNoLongerOwnDisablesTheWebhook(): void {
		$cases = [
			'deleted' => $this->view(owner: null),
			'disabled' => $this->view(owner: $this->piet(enabled: false)),
			'revoked' => $this->view(owner: $this->piet(), mayOwn: false),
			'matrix unreadable' => $this->view(owner: $this->piet(), matrixUp: false),
		];

		foreach ($cases as $case => $view) {
			$this->disabled = [];
			self::assertNull($view->view(webhook: self::hook(), event: new WebhookEvent(name: ObjectCreatedEvent::class, payload: self::payload())), $case);
			self::assertSame([['id' => 7, 'enabled' => false]], $this->disabled, $case);
		}

		self::assertSame([], $this->reads);
	}//end testAnOwnerWhoMayNoLongerOwnDisablesTheWebhook()

	public function testOnlyObjectEventsAreQueuedForAnOwnedWebhook(): void {
		self::assertTrue(OwnedWebhookView::mayQueue(webhook: self::hook(), eventName: ObjectDeletedEvent::class));
		self::assertFalse(OwnedWebhookView::mayQueue(webhook: self::hook(), eventName: RegisterCreatedEvent::class));
		self::assertTrue(OwnedWebhookView::mayQueue(webhook: self::hook(owner: null), eventName: RegisterCreatedEvent::class));
	}//end testOnlyObjectEventsAreQueuedForAnOwnedWebhook()

	/**
	 * A WebhookService whose client records each request's options and body.
	 *
	 * @param OwnedWebhookView|null $view   The owner view, or none.
	 * @param array<int, array>     $sent   Collects the requests.
	 * @param IJobList|null         $jobs   The job list.
	 *
	 * @return WebhookService
	 */
	private function service(?OwnedWebhookView $view, array &$sent, ?IJobList $jobs = null, array $hooks = []): WebhookService {
		$mapper = $this->createMock(WebhookMapper::class);
		$mapper->method('updateStatistics')->willReturnArgument(0);
		$mapper->method('findForEvent')->willReturn($hooks);
		$logs = $this->createMock(WebhookLogMapper::class);
		$logs->method('insert')->willReturnCallback(static fn (WebhookLog $log): WebhookLog => $log);

		$service = new WebhookService(
			stores: new WebhookStores(webhookMapper: $mapper, webhookLogMapper: $logs, mappingMapper: $this->createMock(MappingMapper::class), mappingService: $this->createMock(MappingService::class)),
			dispatch: new WebhookDispatchServices(jobList: ($jobs ?? $this->createMock(IJobList::class)), ownedView: $view),
			logger: new NullLogger()
		);

		$client = $this->createMock(GuzzleClient::class);
		$client->method('request')->willReturnCallback(
			static function (string $method, string $url, array $options) use (&$sent): GuzzleResponse {
				$sent[] = ['url' => $url, 'options' => $options];
				return new GuzzleResponse(200, [], 'ok');
			}
		);
		$property = (new \ReflectionClass($service))->getProperty('client');
		$property->setAccessible(true);
		$property->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * An owned webhook to a public address.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Webhook
	 */
	private static function deliverable(array $configuration = []): Webhook {
		$hook = self::hook();
		$hook->setUrl('https://93.184.216.34/hook');
		$hook->setMethod('POST');
		$hook->setEnabled(true);
		$hook->setConfigurationArray($configuration);
		$hook->setFiltersArray(['register' => '3']);

		return $hook;
	}//end deliverable()

	public function testTheServiceSendsTheOwnersViewAndNothingWithoutIt(): void {
		$sent  = [];
		$event = new WebhookEvent(name: ObjectCreatedEvent::class, payload: self::payload());

		self::assertTrue($this->service(view: $this->view(owner: $this->piet()), sent: $sent)->deliverWebhook(new WebhookDelivery(webhook: self::deliverable(), event: $event)));
		self::assertCount(1, $sent);
		$body = json_encode($sent[0]['options']);
		self::assertStringNotContainsString('123456782', (string)$body);
		self::assertStringContainsString('Jansen', (string)$body);

		$sent = [];
		self::assertFalse($this->service(view: null, sent: $sent)->deliverWebhook(new WebhookDelivery(webhook: self::deliverable(), event: $event)), 'Without the owner view an owned webhook sends nothing.');
		self::assertSame([], $sent);
	}//end testTheServiceSendsTheOwnersViewAndNothingWithoutIt()

	public function testAnOwnedWebhookNeverReachesAPrivateTarget(): void {
		$sent = [];
		$hook = self::deliverable(configuration: ['allowPrivateTargets' => true]);
		$hook->setUrl('http://127.0.0.1/hook');

		self::assertFalse($this->service(view: $this->view(owner: $this->piet()), sent: $sent)->deliverWebhook(new WebhookDelivery(webhook: $hook, event: new WebhookEvent(name: ObjectCreatedEvent::class, payload: self::payload()))));
		self::assertSame([], $sent);
	}//end testAnOwnedWebhookNeverReachesAPrivateTarget()

	public function testNoJobIsQueuedForAnOwnedWebhookOutsideItsScope(): void {
		$queued = [];
		$jobs   = $this->createMock(IJobList::class);
		$jobs->method('add')->willReturnCallback(
			static function (string $job, array $argument) use (&$queued): void {
				$queued[] = $argument['webhook_id'];
			}
		);
		$owned = self::deliverable();
		$owned->setId(8);
		$admin = self::hook(owner: null);
		$admin->setId(9);
		$sent    = [];
		$service = $this->service(view: null, sent: $sent, jobs: $jobs, hooks: [$owned, $admin]);

		$service->dispatchEvent(ObjectCreatedEvent::class, ['register' => '4', 'schema' => '12', 'objectUuid' => 'x']);
		$service->dispatchEvent(RegisterCreatedEvent::class, ['register' => '3']);
		$service->dispatchEvent(ObjectCreatedEvent::class, ['register' => '3', 'schema' => '12', 'objectUuid' => 'x']);

		self::assertSame([9, 9, 8, 9], $queued);
	}//end testNoJobIsQueuedForAnOwnedWebhookOutsideItsScope()
}//end class
