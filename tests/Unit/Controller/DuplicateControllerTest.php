<?php

/**
 * DuplicateControllerTest
 *
 * Covers auth annotation presence, RBAC pass-through (delegates entirely to
 * DuplicateDetectionService::findDuplicates()), threshold/pagination param
 * handling, and that the endpoint never calls a write/merge path — it is
 * strictly side-effect-free.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-4
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\DuplicateController;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Quality\DuplicateDetectionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\DuplicateController
 */
class DuplicateControllerTest extends TestCase {

	private DuplicateDetectionService&MockObject $duplicates;

	/**
	 * @var IRequest&MockObject
	 */
	private $request;

	private DuplicateController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->duplicates = $this->createMock(DuplicateDetectionService::class);
		$this->controller = new DuplicateController(
			'openregister',
			$this->request,
			$this->duplicates
		);
	}//end setUp()

	/**
	 * ADR-029 / ADR-005: index() must declare @NoAdminRequired +
	 *
	 * @NoCSRFRequired via docblock and must NOT be @PublicPage.
	 *
	 * @return void
	 */
	public function testIndexCarriesAuthAnnotations(): void {
		$reflection = new ReflectionClass(DuplicateController::class);
		$doc = $reflection->getMethod('index')->getDocComment();

		$this->assertNotFalse($doc);
		$this->assertStringContainsString('@NoAdminRequired', $doc);
		$this->assertStringContainsString('@NoCSRFRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
	}//end testIndexCarriesAuthAnnotations()

	public function testIndexDelegatesToFindDuplicatesDescendingByScore(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['threshold', null, null],
				['limit', 20, 20],
				['offset', 0, 0],
			]
		);

		$pairs = [
			[
				'objectA' => '00000000-0000-0000-0000-000000000001',
				'objectB' => '00000000-0000-0000-0000-000000000002',
				'score' => 0.95,
				'matchedOn' => ['email'],
			],
		];

		$this->duplicates->expects($this->once())
			->method('findDuplicates')
			->with('reg', 'sch', null, null)
			->willReturn($pairs);

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->getData();
		$this->assertSame(1, $body['total']);
		$this->assertSame($pairs, $body['items']);
	}//end testIndexDelegatesToFindDuplicatesDescendingByScore()

	public function testIndexPassesThresholdThrough(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['threshold', null, '0.9'],
				['limit', 20, 20],
				['offset', 0, 0],
			]
		);

		$this->duplicates->expects($this->once())
			->method('findDuplicates')
			->with('reg', 'sch', null, 0.9)
			->willReturn([]);

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testIndexPassesThresholdThrough()

	public function testIndexPaginatesCandidatePairs(): void {
		$this->request->method('getParam')->willReturnMap(
			[
				['threshold', null, null],
				['limit', 20, '1'],
				['offset', 0, '1'],
			]
		);

		$pairs = [
			['objectA' => 'a', 'objectB' => 'b', 'score' => 0.99, 'matchedOn' => ['name']],
			['objectA' => 'c', 'objectB' => 'd', 'score' => 0.9, 'matchedOn' => ['name']],
			['objectA' => 'e', 'objectB' => 'f', 'score' => 0.86, 'matchedOn' => ['name']],
		];

		$this->duplicates->method('findDuplicates')->willReturn($pairs);

		$response = $this->controller->index('reg', 'sch');
		$body = $response->getData();

		$this->assertSame(3, $body['total']);
		$this->assertCount(1, $body['items']);
		$this->assertSame($pairs[1], $body['items'][0]);
	}//end testIndexPaginatesCandidatePairs()

	public function testIndexMapsNotAuthorizedToForbidden(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->duplicates->method('findDuplicates')->willThrowException(
			new NotAuthorizedException(message: 'denied')
		);

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testIndexMapsNotAuthorizedToForbidden()

	public function testIndexMapsRuntimeExceptionToNotFound(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->duplicates->method('findDuplicates')->willThrowException(new RuntimeException('missing'));

		$response = $this->controller->index('reg', 'sch');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testIndexMapsRuntimeExceptionToNotFound()

	/**
	 * Side-effect-free contract: the controller has no injected write/merge
	 * collaborator at all — DuplicateDetectionService is read-only and the
	 * controller only ever calls findDuplicates(). Reflection guards against
	 * a future accidental write-service injection.
	 *
	 * @return void
	 */
	public function testControllerHasNoWriteCollaborator(): void {
		$reflection = new ReflectionClass(DuplicateController::class);
		$constructor = $reflection->getConstructor();

		$this->assertNotNull($constructor);

		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type !== null) ? $type->getName() : '';
			$this->assertStringNotContainsStringIgnoringCase('merge', $name);
			$this->assertStringNotContainsStringIgnoringCase('survivorship', $name);
		}
	}//end testControllerHasNoWriteCollaborator()
}//end class
