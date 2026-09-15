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
	 * The check endpoint carries the same auth posture as the listing: signed
	 * in, never public. It reads a register's objects, so an anonymous caller
	 * asking "is there already one like this" would be a disclosure channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCheckCarriesAuthAnnotations(): void {
		$reflection = new ReflectionClass(DuplicateController::class);
		$doc = $reflection->getMethod('check')->getDocComment();

		$this->assertNotFalse($doc);
		$this->assertStringContainsString('@NoAdminRequired', $doc);
		$this->assertStringContainsString('@NoCSRFRequired', $doc);
		$this->assertStringNotContainsString('@PublicPage', $doc);
	}//end testCheckCarriesAuthAnnotations()

	/**
	 * The candidate is the posted body minus the routing and metadata keys,
	 * so a form can post the shape it would have created and get an answer
	 * about that shape rather than about `_route`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCheckStripsRoutingKeysFromTheCandidate(): void {
		$this->request->method('getParams')->willReturn(
			[
				'register' => 'reg',
				'schema' => 'sch',
				'_route' => 'openregister.duplicate.check',
				'id' => 'should-not-travel',
				'requester' => 'bsn:123',
				'subject' => 'Kapvergunning',
			]
		);
		$this->request->method('getParam')->willReturnArgument(1);

		$matches = [
			[
				'uuid' => 'stored-1',
				'score' => 0.97,
				'matchedOn' => ['requester'],
				'matchedRules' => [['field' => 'requester', 'method' => 'exact', 'similarity' => 1.0]],
			],
		];

		$this->duplicates->expects($this->once())
			->method('checkCandidate')
			->with('reg', 'sch', ['requester' => 'bsn:123', 'subject' => 'Kapvergunning'], null, null)
			->willReturn($matches);
		$this->duplicates->method('effectiveThreshold')->willReturn(0.85);

		$response = $this->controller->check('reg', 'sch');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($matches, $body['matches']);
		$this->assertSame(1, $body['total']);
		$this->assertSame(0.85, $body['threshold']);
	}//end testCheckStripsRoutingKeysFromTheCandidate()

	/**
	 * A caller-supplied threshold reaches the scorer and is not mistaken for
	 * a candidate field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCheckPassesThresholdThroughAndKeepsItOutOfTheCandidate(): void {
		$this->request->method('getParams')->willReturn(['threshold' => '0.9', 'requester' => 'bsn:123']);
		$this->request->method('getParam')->willReturnMap([['threshold', null, '0.9']]);

		$this->duplicates->expects($this->once())
			->method('checkCandidate')
			->with('reg', 'sch', ['requester' => 'bsn:123'], null, 0.9)
			->willReturn([]);
		$this->duplicates->method('effectiveThreshold')->willReturn(0.9);

		$response = $this->controller->check('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(0, $response->getData()['total']);
	}//end testCheckPassesThresholdThroughAndKeepsItOutOfTheCandidate()

	/**
	 * A refusal to read is a 403, and an unresolvable register/schema a 404,
	 * matching the listing rather than inventing a second dialect.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dedup-check-before-create/specs/duplicate-detection/spec.md#requirement-a-candidate-can-be-checked-against-the-stored-objects-before-it-is-saved
	 */
	public function testCheckMapsFailuresLikeTheListing(): void {
		$this->request->method('getParams')->willReturn(['requester' => 'bsn:123']);
		$this->request->method('getParam')->willReturnArgument(1);

		$this->duplicates->method('checkCandidate')->willThrowException(
			new NotAuthorizedException(message: 'denied')
		);
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->check('reg', 'sch')->getStatus());

		$other = $this->createMock(DuplicateDetectionService::class);
		$other->method('checkCandidate')->willThrowException(new RuntimeException('missing'));
		$controller = new DuplicateController('openregister', $this->request, $other);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->check('reg', 'sch')->getStatus());
	}//end testCheckMapsFailuresLikeTheListing()

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
