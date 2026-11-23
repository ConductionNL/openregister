<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ConsumersController;
use OCA\OpenRegister\Controller\Support\ConsumersControllerServices;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsumersControllerTest extends TestCase {
	private ConsumersController $controller;
	private IRequest&MockObject $request;
	private ConsumerMapper&MockObject $consumerMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->consumerMapper = $this->createMock(ConsumerMapper::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function (string $text) {
			return $text;
		});

		$catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$catalogue->method('verbs')->willReturn(['read', 'update']);

		$this->controller = new ConsumersController(
			$this->request,
			new ConsumersControllerServices(
				$this->consumerMapper,
				$l10n,
				new TokenGrantValidator($catalogue),
				$catalogue
			)
		);
	}

	public function testIndexSuccess(): void {
		$consumers = [new Consumer(), new Consumer()];
		$this->consumerMapper->method('findAll')->willReturn($consumers);
		$this->consumerMapper->method('getTotalCallCount')->willReturn(100);

		$result = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertArrayHasKey('results', $data);
		$this->assertCount(2, $data['results']);
		$this->assertEquals(100, $data['total']);
	}

	public function testShowSuccess(): void {
		$consumer = new Consumer();
		$this->consumerMapper->method('find')->willReturn($consumer);

		$result = $this->controller->show(1);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testShowNotFound(): void {
		$this->consumerMapper->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->show(999);

		$this->assertEquals(404, $result->getStatus());
		$data = $result->getData();
		$this->assertEquals('Consumer not found', $data['error']);
	}

	public function testCreateSuccess(): void {
		$this->request->method('getParams')->willReturn([
			'name' => 'Test Consumer',
			'_route' => 'some.route',
		]);

		$consumer = new Consumer();
		$this->consumerMapper->method('createFromArray')->willReturn($consumer);

		$result = $this->controller->create();

		$this->assertEquals(201, $result->getStatus());
	}

	public function testUpdateSuccess(): void {
		$this->request->method('getParams')->willReturn([
			'name' => 'Updated',
			'_route' => 'some.route',
			'id' => 1,
		]);

		$consumer = new Consumer();
		$this->consumerMapper->method('updateFromArray')->willReturn($consumer);

		$result = $this->controller->update(1);

		$this->assertEquals(200, $result->getStatus());
	}

	public function testUpdateNotFound(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->consumerMapper->method('updateFromArray')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->update(999);

		$this->assertEquals(404, $result->getStatus());
	}

	public function testDestroySuccess(): void {
		$consumer = new Consumer();
		$this->consumerMapper->method('find')->willReturn($consumer);

		$result = $this->controller->destroy(1);

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals([], $result->getData());
	}

	public function testDestroyNotFound(): void {
		$this->consumerMapper->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$result = $this->controller->destroy(999);

		$this->assertEquals(404, $result->getStatus());
	}

	public function testPatchDelegatesToUpdate(): void {
		$this->request->method('getParams')->willReturn([]);
		$consumer = new Consumer();
		$this->consumerMapper->method('updateFromArray')->willReturn($consumer);

		$result = $this->controller->patch(1);

		$this->assertEquals(200, $result->getStatus());
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedGrants(): array {
		return [
			'not an object'  => ['read'],
			'no verbs'       => [['verbs' => [], 'expiresAt' => '2999-01-01T00:00:00+00:00']],
			'unknown verb'   => [['verbs' => ['fly'], 'expiresAt' => '2999-01-01T00:00:00+00:00']],
			'no end date'    => [['verbs' => ['read']]],
			'lapsed'         => [['verbs' => ['read'], 'expiresAt' => '2000-01-01T00:00:00+00:00']],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedGrants')]
	public function testCreateRefusesAGrantThatCannotBeIssued(mixed $grant): void {
		$this->request->method('getParams')->willReturn(['name' => 'c', 'configuration' => ['grant' => $grant]]);
		$this->consumerMapper->expects($this->never())->method('createFromArray');

		$this->assertSame(400, $this->controller->create()->getStatus());
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedGrants')]
	public function testUpdateRefusesAGrantThatCannotBeIssued(mixed $grant): void {
		$this->request->method('getParams')->willReturn(['configuration' => ['grant' => $grant]]);
		$this->consumerMapper->expects($this->never())->method('updateFromArray');

		$this->assertSame(400, $this->controller->update(1)->getStatus());
	}

	public function testCreateSavesAGrantThatCanBeIssued(): void {
		$grant = ['verbs' => ['read'], 'expiresAt' => '2999-01-01T00:00:00+00:00'];
		$this->request->method('getParams')->willReturn(['name' => 'c', 'configuration' => ['grant' => $grant]]);
		$this->consumerMapper->expects($this->once())->method('createFromArray')->willReturn(new Consumer());

		$this->assertSame(201, $this->controller->create()->getStatus());
	}
}
