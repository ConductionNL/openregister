<?php

/**
 * Unit tests for TimelineEntriesController on an object that cannot be resolved.
 *
 * ObjectService::setObject() throws when the object does not exist or the caller
 * may not read it. The timeline routes must answer that with 404, the same as the
 * other object sub-resources, and must not touch the timeline at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Controller\TimelineEntriesController;
use OCA\OpenRegister\Notifications\Service\Timeline\ReferenceService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntrySearchService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\TimelineEntriesControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\ListQuery;

/**
 * TimelineEntriesUnknownObjectTest.
 */
class TimelineEntriesUnknownObjectTest extends TestCase {

	private ObjectService&MockObject $objects;

	private TimelineEntryService&MockObject $entries;

	private TimelineWriteService&MockObject $writer;

	private TimelineEntriesController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['message' => 'hello']);
		$this->objects = $this->createMock(ObjectService::class);
		$this->entries = $this->createMock(TimelineEntryService::class);
		$this->writer = $this->createMock(TimelineWriteService::class);
		$this->controller = TimelineEntriesControllerFactory::create(
			$request,
			$this->objects,
			$this->entries,
			$this->writer,
			$this->createMock(TimelineEntrySearchService::class),
			$this->createMock(ReferenceService::class),
			$this->createMock(TimelineVisibilityService::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	private function objectIsUnknown(): void {
		$this->objects->expects($this->once())->method('setObject')
			->with('00000000-0000-4000-8000-000000000000')
			->willThrowException(new DoesNotExistException('Object not found in magic table'));
		$this->objects->expects($this->never())->method('getObject');
	}

	public function testIndexOnUnknownObjectAnswers404WithoutListing(): void {
		$this->objectIsUnknown();
		$this->entries->expects($this->never())->method('listForObject');

		$response = $this->controller->index('reg', 'schema', '00000000-0000-4000-8000-000000000000');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Object not found'], $response->getData());
	}

	public function testCreateOnUnknownObjectAnswers404WithoutWriting(): void {
		$this->objectIsUnknown();
		$this->writer->expects($this->never())->method($this->anything());

		$response = $this->controller->create('reg', 'schema', '00000000-0000-4000-8000-000000000000');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Object not found'], $response->getData());
	}

	public function testKnownObjectIsStillListed(): void {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$this->objects->expects($this->once())->method('setObject')->with('11111111-1111-4111-8111-111111111111');
		$this->objects->method('getObject')->willReturn($object);
		$this->entries->expects($this->once())->method('listForObject')
			->with($object, $this->callback(static fn (ListQuery $q): bool => [$q->limit, $q->offset] === [50, 0]))
			->willReturn([]);

		$response = $this->controller->index('reg', 'schema', '11111111-1111-4111-8111-111111111111');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['results']);
	}
}
