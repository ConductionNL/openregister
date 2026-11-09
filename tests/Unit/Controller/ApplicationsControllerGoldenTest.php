<?php

/**
 * Golden tests for ApplicationsController.
 *
 * Pins the observable behaviour of every endpoint (status, body, mapper and
 * service arguments, log message and log context) so a refactor of the
 * controller can be proven behaviour-preserving.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\ApplicationsController;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapper;
use OCA\OpenRegister\Service\ApplicationService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApplicationsControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for ApplicationsController.
 */
class ApplicationsControllerGoldenTest extends TestCase {
	private IRequest&MockObject $request;
	private ApplicationService&MockObject $service;
	private ApplicationMapper&MockObject $mapper;
	private LoggerInterface&MockObject $logger;
	private ApplicationsController $controller;

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ApplicationService::class);
		$this->mapper = $this->createMock(ApplicationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);
		$this->controller = ApplicationsControllerFactory::create(
			'openregister',
			$this->request,
			$this->service,
			$this->mapper,
			$this->logger
		);
	}

	/**
	 * Asserts exactly one error was logged with the given message and context.
	 *
	 * The `line` entry is checked for type only: it is a source line number.
	 *
	 * @param string               $message  Expected message.
	 * @param array<string, mixed> $expected Expected context without `file`/`line`.
	 */
	private function assertLoggedOnce(string $message, array $expected): void {
		$this->assertCount(1, $this->logged);
		[$actualMessage, $context] = $this->logged[0];
		$this->assertSame($message, $actualMessage);
		$this->assertSame(array_merge(['file', 'line'], array_keys($expected)), array_keys($context));
		$this->assertStringEndsWith('lib/Controller/ApplicationsController.php', $context['file']);
		$this->assertIsInt($context['line']);
		unset($context['file'], $context['line']);
		$this->assertSame($expected, $context);
	}

	public function testIndexPagesAndStripsReservedParams(): void {
		$this->request->method('getParams')->willReturn(
			['_limit' => '10', '_offset' => '1', '_page' => '3', '_search' => 's', '_route' => 'r', 'name' => 'x']
		);
		$this->mapper->expects($this->once())->method('findAll')
			->with(10, 20, ['name' => 'x'])->willReturn(['a']);

		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => ['a']], $response->getData());
		$this->assertSame([], $this->logged);
	}

	public function testIndexKeepsOffsetWithoutLimit(): void {
		$this->request->method('getParams')->willReturn(['_offset' => '7', '_page' => '4']);
		$this->mapper->expects($this->once())->method('findAll')->with(null, 7, [])->willReturn([]);

		$this->assertSame(['results' => []], $this->controller->index()->getData());
	}

	public function testIndexWithoutPaginationPassesNulls(): void {
		$this->request->method('getParams')->willReturn(['_limit' => null, 'owner' => 'me']);
		$this->mapper->expects($this->once())->method('findAll')
			->with(null, null, ['owner' => 'me'])->willReturn([]);

		$this->assertSame(200, $this->controller->index()->getStatus());
	}

	public function testIndexFailure(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->mapper->method('findAll')->willThrowException(new Exception('db down'));

		$response = $this->controller->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve applications'], $response->getData());
		$this->assertCount(1, $this->logged);
		$this->assertSame('[ApplicationsController] Failed to get applications', $this->logged[0][0]);
		$this->assertSame(['file', 'line', 'error', 'trace'], array_keys($this->logged[0][1]));
		$this->assertSame('db down', $this->logged[0][1]['error']);
		$this->assertIsString($this->logged[0][1]['trace']);
	}

	public function testShowSuccess(): void {
		$application = new Application();
		$this->service->expects($this->once())->method('find')->with(5)->willReturn($application);

		$response = $this->controller->show(5);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($application, $response->getData());
	}

	public function testShowFailure(): void {
		$this->service->method('find')->willThrowException(new Exception('nope'));

		$response = $this->controller->show(5);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Application not found'], $response->getData());
		$this->assertLoggedOnce('[ApplicationsController] Failed to get application', ['id' => 5, 'error' => 'nope']);
	}

	public function testCreateStripsRouteOnly(): void {
		$application = new Application();
		$this->request->method('getParams')->willReturn(['_route' => 'r', 'name' => 'n', 'id' => 3]);
		$this->service->expects($this->once())->method('create')
			->with(['name' => 'n', 'id' => 3])->willReturn($application);

		$response = $this->controller->create();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame($application, $response->getData());
	}

	public function testCreateFailure(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('create')->willThrowException(new Exception('bad'));

		$response = $this->controller->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to create application: bad'], $response->getData());
		$this->assertSame('[ApplicationsController] Failed to create application', $this->logged[0][0]);
		$this->assertSame(['file', 'line', 'error', 'trace'], array_keys($this->logged[0][1]));
		$this->assertSame('bad', $this->logged[0][1]['error']);
	}

	public function testUpdateStripsImmutableFields(): void {
		$application = new Application();
		$this->request->method('getParams')->willReturn(
			['_route' => 'r', 'id' => 1, 'organisation' => 'o', 'owner' => 'w', 'created' => 'c', 'name' => 'n']
		);
		$this->service->expects($this->once())->method('update')
			->with(9, ['name' => 'n'])->willReturn($application);

		$response = $this->controller->update(9);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($application, $response->getData());
	}

	public function testUpdateFailure(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('update')->willThrowException(new Exception('conflict'));

		$response = $this->controller->update(9);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to update application: conflict'], $response->getData());
		$this->assertLoggedOnce(
			'[ApplicationsController] Failed to update application',
			['id' => 9, 'error' => 'conflict']
		);
	}

	public function testPatchDelegatesToUpdate(): void {
		$application = new Application();
		$this->request->method('getParams')->willReturn(['owner' => 'w', 'title' => 't']);
		$this->service->expects($this->once())->method('update')
			->with(4, ['title' => 't'])->willReturn($application);

		$response = $this->controller->patch(4);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($application, $response->getData());
	}

	public function testDestroySuccess(): void {
		$this->service->expects($this->once())->method('delete')->with(2);

		$response = $this->controller->destroy(2);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['message' => 'Application deleted successfully'], $response->getData());
	}

	public function testDestroyFailure(): void {
		$this->service->method('delete')->willThrowException(new Exception('gone'));

		$response = $this->controller->destroy(2);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Failed to delete application'], $response->getData());
		$this->assertLoggedOnce(
			'[ApplicationsController] Failed to delete application',
			['id' => 2, 'error' => 'gone']
		);
	}
}
