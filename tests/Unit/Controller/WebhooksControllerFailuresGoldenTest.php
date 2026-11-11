<?php

/**
 * Every 404 and 500 answer of WebhooksController: status, body, and the
 * error line it logs with its context keys.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use GuzzleHttp\Exception\TransferException;
use OCA\OpenRegister\Controller\WebhooksController;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookLog;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\WebhooksControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversNothing
 */
final class WebhooksControllerFailuresGoldenTest extends TestCase {
	/**
	 * The error lines logged, as [message, context keys, id or log id].
	 *
	 * @var array<int, array{string, array<int, string>, mixed}>
	 */
	private array $errors = [];

	/**
	 * The request, carrying a valid create/update body.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The webhook mapper.
	 *
	 * @var WebhookMapper&MockObject
	 */
	private WebhookMapper&MockObject $webhookMapper;

	/**
	 * The delivery-log mapper.
	 *
	 * @var WebhookLogMapper&MockObject
	 */
	private WebhookLogMapper&MockObject $webhookLogMapper;

	/**
	 * The delivery service.
	 *
	 * @var WebhookService&MockObject
	 */
	private WebhookService&MockObject $webhookService;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['name' => 'n', 'url' => 'https://example.org']);
		$this->webhookMapper = $this->createMock(WebhookMapper::class);
		$this->webhookLogMapper = $this->createMock(WebhookLogMapper::class);
		$this->webhookService = $this->createMock(WebhookService::class);
	}//end setUp()

	/**
	 * The controller, signed in as an administrator, logging errors into $errors.
	 *
	 * @return WebhooksController
	 */
	private function controller(): WebhooksController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, array_keys($context), ($context['id'] ?? $context['log_id'] ?? null)];
			}
		);

		return WebhooksControllerFactory::create(
			'openregister',
			$this->request,
			$this->webhookMapper,
			$this->webhookLogMapper,
			$this->webhookService,
			$logger,
			$session,
			$groups
		);
	}//end controller()

	/**
	 * A webhook with id 7.
	 *
	 * @return Webhook
	 */
	private function webhook(): Webhook {
		$webhook = new Webhook();
		$webhook->setId(7);
		$webhook->setName('hook');

		return $webhook;
	}//end webhook()

	/**
	 * An unknown id answers 404 with the same body on every route that looks one up.
	 *
	 * @return void
	 */
	public function testAnUnknownWebhookIs404(): void {
		$this->webhookMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->webhookMapper->method('updateFromArray')->willThrowException(new DoesNotExistException('gone'));
		$controller = $this->controller();

		foreach (['show', 'update', 'destroy', 'test', 'logs', 'logStats'] as $route) {
			$response = $controller->$route(7);
			$this->assertSame([404, ['error' => 'Webhook not found']], [$response->getStatus(), $response->getData()], $route);
		}

		$this->assertSame([], $this->errors);
	}//end testAnUnknownWebhookIs404()

	/**
	 * A failing lookup answers 500 and logs one error line per route.
	 *
	 * @return void
	 */
	public function testAFailingLookupIs500AndLogged(): void {
		$this->webhookMapper->method('find')->willThrowException(new Exception('db down'));
		$this->webhookMapper->method('updateFromArray')->willThrowException(new Exception('db down'));
		$this->webhookMapper->method('createFromArray')->willThrowException(new Exception('db down'));
		$this->webhookMapper->method('findAll')->willThrowException(new Exception('db down'));
		$this->webhookLogMapper->method('find')->willThrowException(new Exception('db down'));
		$this->webhookLogMapper->method('findAll')->willThrowException(new Exception('db down'));
		$this->webhookLogMapper->method('findByWebhook')->willThrowException(new Exception('db down'));
		$controller = $this->controller();

		$err = '[WebhooksController] Error ';
		$idTrace = ['file', 'line', 'id', 'trace'];
		$expected = [
			'index' => ['Failed to list webhooks', $err . 'listing webhooks: db down', ['file', 'line', 'trace'], null],
			'show' => ['Failed to retrieve webhook', $err . 'retrieving webhook: db down', $idTrace, 7],
			'create' => ['Failed to create webhook: db down', $err . 'creating webhook: db down', ['file', 'line', 'fields', 'trace'], null],
			'update' => ['Failed to update webhook: db down', $err . 'updating webhook: db down', ['file', 'line', 'id', 'fields', 'trace'], 7],
			'destroy' => ['Failed to delete webhook', $err . 'deleting webhook: db down', $idTrace, 7],
			'test' => ['Failed to test webhook: db down', $err . 'testing webhook: db down', $idTrace, 7],
			'logs' => ['Failed to retrieve webhook logs', $err . 'retrieving webhook logs: db down', $idTrace, 7],
			'logStats' => ['Failed to retrieve webhook log statistics', $err . 'retrieving webhook log statistics: db down', $idTrace, 7],
			'allLogs' => ['Failed to retrieve webhook logs: db down', $err . 'retrieving webhook logs: db down', ['file', 'line', 'trace'], null],
			'retry' => ['Failed to retry webhook', $err . 'retrying webhook: db down', ['file', 'line', 'log_id', 'trace'], 7],
		];
		foreach ($expected as $route => [$error, $message, $keys, $id]) {
			$this->errors = [];
			$response = match ($route) {
				'index', 'create', 'allLogs' => $controller->$route(),
				default => $controller->$route(7),
			};
			$this->assertSame([500, ['error' => $error]], [$response->getStatus(), $response->getData()], $route);
			$this->assertSame([[$message, $keys, $id]], $this->errors, $route);
		}
	}//end testAFailingLookupIs500AndLogged()

	/**
	 * A transport failure while testing answers 500 with a delivery-failed body.
	 *
	 * @return void
	 */
	public function testATransportFailureWhileTestingIs500(): void {
		$this->webhookMapper->method('find')->willReturn($this->webhook());
		$this->webhookService->method('deliverWebhook')->willThrowException(new TransferException('timeout'));

		$response = $this->controller()->test(7);

		$this->assertSame(
			[500, ['success' => false, 'message' => 'Test webhook delivery failed: timeout']],
			[$response->getStatus(), $response->getData()]
		);
		$this->assertSame([['[WebhooksController] Error testing webhook: timeout', ['file', 'line', 'id', 'trace'], 7]], $this->errors);
	}//end testATransportFailureWhileTestingIs500()
}//end class
