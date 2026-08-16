<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SearchController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchControllerTest extends TestCase {
	private SearchController $controller;
	private IRequest&MockObject $request;
	private ObjectService&MockObject $objectService;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->controller = new SearchController(
			'openregister',
			$this->request,
			$this->objectService
		);
	}

	public function testSearchSuccess(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				[
					'uuid' => 'obj-1',
					'name' => 'Test Object',
				],
			],
			'total' => 1,
			'@self' => [],
		]);

		$result = $this->controller->search();

		$this->assertEquals(200, $result->getStatus());
		$data = $result->getData();
		$this->assertCount(1, $data['results']);
		$this->assertEquals(1, $data['total']);
		$this->assertEquals('obj-1', $data['results'][0]['id']);
		$this->assertEquals('openregister', $data['results'][0]['source']);
	}

	public function testSearchEmptyQuery(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', ''],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [],
			'total' => 0,
		]);

		$result = $this->controller->search();

		$this->assertEquals(200, $result->getStatus());
		$this->assertEquals(0, $result->getData()['total']);
	}

	public function testSearchFormatsResultsCorrectly(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['id' => 'fallback-id'],
			],
			'total' => 1,
		]);

		$result = $this->controller->search();

		$data = $result->getData();
		$item = $data['results'][0];
		$this->assertEquals('fallback-id', $item['id']);
		$this->assertEquals('Unknown', $item['name']);
		$this->assertEquals('object', $item['type']);
		$this->assertEquals('openregister', $item['source']);
	}

	// ── ObjectEntity rows ──
	//
	// Every test above hands the controller plain arrays. searchObjectsPaginated()
	// also returns ObjectEntity instances — ObjectService::collectNamesForResults()
	// branches on `instanceof ObjectEntity` before it branches on is_array() — and
	// the formatter was broken for exactly that shape: ObjectEntity declares
	// getUuid()/getName() only as `@method`, served through Entity::__call(), so
	// method_exists() was FALSE, the entity branch was never taken, and the array
	// branch could not read an object either. Every hit came back id: null,
	// name: 'Unknown'. Nothing on this path routes through getObject(), so there
	// was no fallback to recover the uuid.

	public function testSearchFormatsObjectEntityRowsWithTheirRealUuidAndName(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		$entity = new ObjectEntity();
		$entity->setUuid('entity-uuid-1');
		$entity->setName('Entity Display Name');

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [$entity],
			'total' => 1,
		]);

		$data = $this->controller->search()->getData();
		$item = $data['results'][0];

		$this->assertSame('entity-uuid-1', $item['id']);
		$this->assertSame('Entity Display Name', $item['name']);
		$this->assertSame('object', $item['type']);
		$this->assertSame('openregister', $item['source']);
	}

	public function testSearchFallsBackToUnknownForAnEntityWithoutAName(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		$entity = new ObjectEntity();
		$entity->setUuid('entity-uuid-2');

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [$entity],
			'total' => 1,
		]);

		$item = $this->controller->search()->getData()['results'][0];

		$this->assertSame('entity-uuid-2', $item['id']);
		$this->assertSame('Unknown', $item['name']);
	}

	/**
	 * A receiver that is neither an Entity nor an array must not fatal. This pins
	 * the reason the guard is `instanceof Entity && property_exists()` rather than
	 * is_callable(), which is unconditionally TRUE on any __call class and would
	 * turn this row into a BadFunctionCallException.
	 */
	public function testSearchDoesNotFatalOnANonEntityObjectRow(): void {
		$this->request->method('getParam')
			->willReturnMap([
				['query', '', 'test'],
				['offset', 0, 0],
				['limit', 25, 25],
				['_search', [], []],
			]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [new \stdClass()],
			'total' => 1,
		]);

		$item = $this->controller->search()->getData()['results'][0];

		$this->assertNull($item['id']);
		$this->assertSame('Unknown', $item['name']);
	}
}
