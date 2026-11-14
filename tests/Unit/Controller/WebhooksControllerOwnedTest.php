<?php

/**
 * The webhooks API for a holder of `webhook.own` (webhooks-for-owners, D-3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\Support\RequestListPage;
use OCA\OpenRegister\Controller\Support\TasksPlatform;
use OCA\OpenRegister\Controller\Support\WebhookLogQuery;
use OCA\OpenRegister\Controller\Support\WebhookOwnership;
use OCA\OpenRegister\Controller\Support\WebhooksControllerServices;
use OCA\OpenRegister\Controller\Support\WebhooksMappers;
use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Service\Webhook\OwnedWebhookValidator;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\WebhooksControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Administrator, owner, a holder of the right on someone else's webhook (404)
 * and a user without the right (403).
 */
#[CoversClass(WebhooksController::class)]
#[UsesClass(Webhook::class)]
#[UsesClass(WebhookLog::class)]
#[UsesClass(WebhookOwnership::class)]
#[UsesClass(WebhooksControllerServices::class)]
#[UsesClass(WebhooksMappers::class)]
#[UsesClass(TasksPlatform::class)]
#[UsesClass(RequestListPage::class)]
#[UsesClass(WebhookLogQuery::class)]
class WebhooksControllerOwnedTest extends TestCase {

	private IRequest&MockObject $request;

	private WebhookMapper&MockObject $webhookMapper;

	private WebhookLogMapper&MockObject $webhookLogMapper;

	private WebhookService&MockObject $webhookService;

	private OpenRegisterActionAuthService&MockObject $actionAuth;

	private OwnedWebhookValidator&MockObject $validator;

	/**
	 * Request parameters the request mock answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request          = $this->createMock(IRequest::class);
		$this->webhookMapper    = $this->createMock(WebhookMapper::class);
		$this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
		$this->webhookService   = $this->createMock(WebhookService::class);
		$this->actionAuth       = $this->createMock(OpenRegisterActionAuthService::class);
		$this->validator        = $this->createMock(OwnedWebhookValidator::class);

		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null) => ($this->params[$key] ?? $default)
		);
	}//end setUp()

	/**
	 * The controller as a signed-in user.
	 *
	 * @param string $uid       The user.
	 * @param bool   $admin     Whether they are an administrator.
	 * @param bool   $ownership Whether the ownership services are there.
	 *
	 * @return WebhooksController
	 */
	private function controllerAs(string $uid, bool $admin=false, bool $ownership=true): WebhooksController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $who) => $admin && $who === $uid);

		return WebhooksControllerFactory::create(
			'openregister',
			$this->request,
			$this->webhookMapper,
			$this->webhookLogMapper,
			$this->webhookService,
			$this->createMock(LoggerInterface::class),
			$session,
			$groups,
			$ownership === true ? new WebhookOwnership(actionAuth: $this->actionAuth, validator: $this->validator) : null,
		);
	}//end controllerAs()

	/**
	 * Let the action rights answer whether the user holds webhook.own.
	 *
	 * @param bool $holds Whether they do.
	 *
	 * @return void
	 */
	private function holdsRight(bool $holds): void {
		$this->actionAuth->method('can')->willReturnCallback(
			fn (IUser $user, string $action) => $holds && $action === 'webhook.own'
		);
	}//end holdsRight()

	/**
	 * A stored webhook.
	 *
	 * @param int         $id    Its id.
	 * @param string|null $owner Its owner; null for an administrator's.
	 *
	 * @return Webhook
	 */
	private function hook(int $id, ?string $owner): Webhook {
		$webhook = new Webhook();
		$webhook->setId($id);
		$webhook->setName('Hook ' . $id);
		$webhook->setUrl('https://receiver.example/' . $id);
		$webhook->setOwner($owner);
		$webhook->setEventsArray([ObjectCreatedEvent::class]);
		$webhook->setFiltersArray(['register' => '7', 'schema' => ['11']]);

		return $webhook;
	}//end hook()

	/**
	 * Let the mapper find these webhooks by id.
	 *
	 * @param Webhook ...$hooks The webhooks.
	 *
	 * @return void
	 */
	private function stored(Webhook ...$hooks): void {
		$byId = [];
		foreach ($hooks as $hook) {
			$byId[$hook->getId()] = $hook;
		}

		$this->webhookMapper->method('find')->willReturnCallback(
			function (int $id) use ($byId) {
				if (isset($byId[$id]) === false) {
					throw new DoesNotExistException('no webhook');
				}

				return $byId[$id];
			}
		);
	}//end stored()

	public function testAUserWithoutTheRightIsRefusedAsToday(): void {
		$this->holdsRight(false);
		$this->webhookMapper->expects($this->never())->method('findOwnedBy');
		$this->webhookMapper->expects($this->never())->method('findAll');
		$this->webhookMapper->expects($this->never())->method('find');
		$controller = $this->controllerAs('carol');

		$this->assertSame(403, $controller->index()->getStatus());
		$this->assertSame(403, $controller->show(1)->getStatus());
		$this->assertSame(403, $controller->create()->getStatus());
		$this->assertSame(403, $controller->update(1)->getStatus());
		$this->assertSame(403, $controller->destroy(1)->getStatus());
		$this->assertSame(403, $controller->test(1)->getStatus());
		$this->assertSame(403, $controller->logs(1)->getStatus());
		$this->assertSame(403, $controller->logStats(1)->getStatus());
		$this->assertSame(403, $controller->allLogs()->getStatus());
		$this->assertSame(403, $controller->retry(1)->getStatus());
	}//end testAUserWithoutTheRightIsRefusedAsToday()

	public function testActionRightsThatCannotBeReadRefuse(): void {
		$this->actionAuth->method('can')->willThrowException(new \RuntimeException('matrix unreadable'));
		$this->webhookMapper->expects($this->never())->method('findOwnedBy');

		$this->assertSame(403, $this->controllerAs('bob')->index()->getStatus());
	}//end testActionRightsThatCannotBeReadRefuse()

	public function testWithoutTheOwnershipServicesOnlyAnAdministratorManages(): void {
		$this->actionAuth->expects($this->never())->method('can');

		$this->assertSame(403, $this->controllerAs('bob', ownership: false)->index()->getStatus());
	}//end testWithoutTheOwnershipServicesOnlyAnAdministratorManages()

	public function testAnOwnerListsOnlyTheirOwnWebhooks(): void {
		$this->holdsRight(true);
		$this->webhookMapper->expects($this->never())->method('findAll');
		$this->webhookMapper->expects($this->once())->method('findOwnedBy')->with('bob')
			->willReturn([$this->hook(3, 'bob'), $this->hook(4, 'bob')]);
		$this->params = ['_limit' => '1'];

		$response = $this->controllerAs('bob')->index();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2, $data['total']);
		$this->assertCount(1, $data['results']);
		$this->assertSame(3, $data['results'][0]['id']);
		$this->assertSame('bob', $data['results'][0]['owner']);
	}//end testAnOwnerListsOnlyTheirOwnWebhooks()

	public function testAnAdministratorStillListsEveryWebhook(): void {
		$this->actionAuth->expects($this->never())->method('can');
		$this->webhookMapper->expects($this->never())->method('findOwnedBy');
		$this->webhookMapper->method('findAll')->willReturn([$this->hook(3, 'bob'), $this->hook(5, null)]);

		$response = $this->controllerAs('admin', admin: true)->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
	}//end testAnAdministratorStillListsEveryWebhook()

	public function testAnOwnerReadsTheirOwnWebhook(): void {
		$this->holdsRight(true);
		$this->stored($this->hook(3, 'bob'));

		$response = $this->controllerAs('bob')->show(3);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(3, $response->getData()->getId());
	}//end testAnOwnerReadsTheirOwnWebhook()

	public function testSomeoneElsesAndAnAdministratorsWebhookAreNotFound(): void {
		$this->holdsRight(true);
		$this->stored($this->hook(3, 'bob'), $this->hook(5, null));
		$this->webhookMapper->expects($this->never())->method('delete');
		$this->webhookMapper->expects($this->never())->method('updateFromArray');
		$this->webhookService->expects($this->never())->method('deliverWebhook');
		$this->webhookLogMapper->expects($this->never())->method('findByWebhook');
		$controller = $this->controllerAs('dave');

		foreach ([3, 5, 99] as $id) {
			$this->assertSame(404, $controller->show($id)->getStatus(), 'show ' . $id);
			$this->assertSame(404, $controller->update($id)->getStatus(), 'update ' . $id);
			$this->assertSame(404, $controller->destroy($id)->getStatus(), 'destroy ' . $id);
			$this->assertSame(404, $controller->test($id)->getStatus(), 'test ' . $id);
			$this->assertSame(404, $controller->logs($id)->getStatus(), 'logs ' . $id);
			$this->assertSame(404, $controller->logStats($id)->getStatus(), 'logStats ' . $id);
		}
	}//end testSomeoneElsesAndAnAdministratorsWebhookAreNotFound()

	public function testAnOwnerWhoLostTheRightNoLongerReachesTheirWebhook(): void {
		$this->holdsRight(false);
		$this->stored($this->hook(3, 'bob'));

		$this->assertSame(403, $this->controllerAs('bob')->show(3)->getStatus());
	}//end testAnOwnerWhoLostTheRightNoLongerReachesTheirWebhook()

	public function testAnOwnerCreatesAWebhookThatIsTheirsWhateverTheBodySays(): void {
		$this->holdsRight(true);
		$this->params = [
			'name' => 'Tasks',
			'url' => 'https://receiver.example/hook',
			'events' => [ObjectCreatedEvent::class],
			'filters' => ['register' => 'planninq', 'schema' => 'task'],
			'owner' => 'mallory',
			'organisation' => 'other-org',
			'_route' => 'x',
		];
		$this->validator->expects($this->once())->method('check')
			->with(
				$this->callback(fn (array $data) => isset($data['owner'], $data['organisation'], $data['_route']) === false && $data['name'] === 'Tasks'),
				'bob',
				true
			)
			->willReturnCallback(
				fn (array $data) => ['refusal' => null, 'data' => ['filters' => ['register' => '7', 'schema' => ['11']]] + $data]
			);
		$this->webhookMapper->expects($this->never())->method('createFromArray');
		$this->webhookMapper->expects($this->once())->method('createOwnedFromArray')
			->with($this->callback(fn (array $data) => $data['filters'] === ['register' => '7', 'schema' => ['11']]), 'bob')
			->willReturn($this->hook(8, 'bob'));

		$response = $this->controllerAs('bob')->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('bob', $response->getData()->getOwner());
	}//end testAnOwnerCreatesAWebhookThatIsTheirsWhateverTheBodySays()

	public function testARefusedSubscriptionIsA422NamingTheField(): void {
		$this->holdsRight(true);
		$this->params = ['name' => 'Tasks', 'url' => 'https://receiver.example/hook', 'events' => []];
		$this->validator->method('check')->willReturn(
			['refusal' => ['field' => 'events', 'message' => 'events: an owned webhook must name one or more events'], 'data' => []]
		);
		$this->webhookMapper->expects($this->never())->method('createOwnedFromArray');

		$response = $this->controllerAs('bob')->create();

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('events', $response->getData()['field']);
		$this->assertStringContainsString('events', $response->getData()['error']);
	}//end testARefusedSubscriptionIsA422NamingTheField()

	public function testAnAdministratorCreatesAnUnownedWebhookUnchecked(): void {
		$this->actionAuth->expects($this->never())->method('can');
		$this->validator->expects($this->never())->method('check');
		$this->params = ['name' => 'All', 'url' => 'https://receiver.example/all'];
		$this->webhookMapper->expects($this->never())->method('createOwnedFromArray');
		$this->webhookMapper->expects($this->once())->method('createFromArray')->willReturn($this->hook(9, null));

		$this->assertSame(201, $this->controllerAs('admin', admin: true)->create()->getStatus());
	}//end testAnAdministratorCreatesAnUnownedWebhookUnchecked()

	public function testAnOwnerUpdatesTheirWebhookWithItsResultingSubscriptionChecked(): void {
		$this->holdsRight(true);
		$this->stored($this->hook(3, 'bob'));
		$this->params = ['id' => 3, 'name' => 'Renamed', 'owner' => 'mallory', 'organisation' => 'other-org', 'events' => [ObjectCreatedEvent::class]];
		$this->validator->expects($this->once())->method('check')
			->with(
				[
					'events' => [ObjectCreatedEvent::class],
					'filters' => ['register' => '7', 'schema' => ['11']],
					'configuration' => [],
				],
				'bob',
				false
			)
			->willReturnCallback(fn (array $data) => ['refusal' => null, 'data' => $data]);
		$this->webhookMapper->expects($this->once())->method('updateFromArray')
			->with(
				3,
				[
					'name' => 'Renamed',
					'events' => [ObjectCreatedEvent::class],
					'filters' => ['register' => '7', 'schema' => ['11']],
				]
			)
			->willReturn($this->hook(3, 'bob'));

		$this->assertSame(200, $this->controllerAs('bob')->update(3)->getStatus());
	}//end testAnOwnerUpdatesTheirWebhookWithItsResultingSubscriptionChecked()

	public function testAnAdministratorEditingAnOwnedWebhookIsCheckedAsTheOwners(): void {
		$this->stored($this->hook(3, 'bob'));
		$this->params = ['filters' => []];
		$this->validator->expects($this->once())->method('check')
			->with($this->anything(), 'bob', false)
			->willReturn(['refusal' => ['field' => 'filters.register', 'message' => 'filters.register: required'], 'data' => []]);
		$this->webhookMapper->expects($this->never())->method('updateFromArray');

		$response = $this->controllerAs('admin', admin: true)->update(3);

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('filters.register', $response->getData()['field']);
	}//end testAnAdministratorEditingAnOwnedWebhookIsCheckedAsTheOwners()

	public function testAnOwnerDeletesTheirWebhook(): void {
		$this->holdsRight(true);
		$hook = $this->hook(3, 'bob');
		$this->stored($hook);
		$this->webhookMapper->expects($this->once())->method('delete')->with($hook);

		$this->assertSame(204, $this->controllerAs('bob')->destroy(3)->getStatus());
	}//end testAnOwnerDeletesTheirWebhook()

	public function testAnOwnersLogStatsCountOnlyTheirOwnRetries(): void {
		$this->holdsRight(true);
		$this->stored($this->hook(3, 'bob'));
		$this->webhookLogMapper->method('getStatistics')->willReturn(['total' => 2]);
		$this->webhookLogMapper->method('findFailedForRetry')->willReturn(
			[$this->log(1, 3, false), $this->log(2, 5, false), $this->log(3, 3, false)]
		);

		$response = $this->controllerAs('bob')->logStats(3);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['pendingRetries']);
	}//end testAnOwnersLogStatsCountOnlyTheirOwnRetries()

	public function testAnOwnerReadsOnlyTheirOwnWebhooksLogs(): void {
		$this->holdsRight(true);
		$this->webhookMapper->method('findOwnedBy')->with('bob')->willReturn([$this->hook(3, 'bob'), $this->hook(4, 'bob')]);
		$this->webhookLogMapper->expects($this->never())->method('findAll');
		$this->webhookLogMapper->method('findByWebhook')->willReturnCallback(
			fn (int $webhookId) => match ($webhookId) {
				3 => [$this->log(10, 3, true), $this->log(2, 3, false)],
				4 => [$this->log(7, 4, false)],
				default => [$this->log(99, $webhookId, true)],
			}
		);
		$this->params = ['success' => 'false'];

		$response = $this->controllerAs('bob')->allLogs();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(2, $response->getData()['total']);
		$this->assertSame([7, 2], array_map(fn (WebhookLog $log) => $log->getId(), $response->getData()['results']));
	}//end testAnOwnerReadsOnlyTheirOwnWebhooksLogs()

	public function testAnOwnerAskingForSomeoneElsesLogsGetsNotFound(): void {
		$this->holdsRight(true);
		$this->webhookMapper->method('findOwnedBy')->willReturn([$this->hook(3, 'bob')]);
		$this->webhookLogMapper->expects($this->never())->method('findByWebhook');
		$this->params = ['webhook_id' => '5'];

		$this->assertSame(404, $this->controllerAs('bob')->allLogs()->getStatus());
	}//end testAnOwnerAskingForSomeoneElsesLogsGetsNotFound()

	public function testAnOwnerCannotRetryOrProbeSomeoneElsesLog(): void {
		$this->holdsRight(true);
		$this->stored($this->hook(5, 'erin'));
		$this->webhookLogMapper->method('find')->willReturn($this->log(1, 5, true));
		$this->webhookService->expects($this->never())->method('deliverWebhook');

		$response = $this->controllerAs('bob')->retry(1);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Webhook log not found', $response->getData()['error']);
	}//end testAnOwnerCannotRetryOrProbeSomeoneElsesLog()

	public function testAnOwnerRetriesTheirOwnFailedDelivery(): void {
		$this->holdsRight(true);
		$this->stored($this->hook(3, 'bob'));
		$log = $this->log(1, 3, false);
		$log->setRequestBody('{"data":{"id":5}}');
		$this->webhookLogMapper->method('find')->willReturn($log);
		$this->webhookService->expects($this->once())->method('deliverWebhook')->willReturn(true);

		$this->assertSame(200, $this->controllerAs('bob')->retry(1)->getStatus());
	}//end testAnOwnerRetriesTheirOwnFailedDelivery()

	/**
	 * A delivery log entry.
	 *
	 * @param int  $id      Its id.
	 * @param int  $webhook Its webhook.
	 * @param bool $success Whether the delivery succeeded.
	 *
	 * @return WebhookLog
	 */
	private function log(int $id, int $webhook, bool $success): WebhookLog {
		$log = new WebhookLog();
		$log->setId($id);
		$log->setWebhook($webhook);
		$log->setSuccess($success);
		$log->setEventClass(ObjectCreatedEvent::class);
		$log->setAttempt(1);

		return $log;
	}//end log()
}//end class
