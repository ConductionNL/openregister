<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\ObjectOwnershipController}.
 *
 * Two properties, on every method. A record the caller may not READ answers 404
 * and never 403, so the endpoints cannot be used to probe which record ids exist.
 * And a refusal keeps the status it was given: these are plain `Controller`
 * methods returning a `JSONResponse` precisely because `OCSMiddleware` turns a
 * 403 out of an OCS controller into an HTTP 200 carrying the refusal in its body,
 * which would make every refusal here read as success.
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
 *
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectOwnershipController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * ObjectOwnershipControllerTest.
 */
class ObjectOwnershipControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Object resolver mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Checked ownership write path mock.
	 *
	 * @var ObjectOwnershipService&MockObject
	 */
	private ObjectOwnershipService&MockObject $ownership;

	/**
	 * Controller under test.
	 *
	 * @var ObjectOwnershipController
	 */
	private ObjectOwnershipController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->ownership = $this->createMock(ObjectOwnershipService::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();
		$this->objectService->method('getRegister')->willReturn(1);
		$this->objectService->method('getSchema')->willReturn(1);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$this->controller = new ObjectOwnershipController(
			'openregister',
			$this->request,
			$this->objectService,
			$registerMapper,
			$schemaMapper,
			$this->ownership,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * Make the resolver answer with a real record.
	 *
	 * A real entity rather than a double: the owner and the authorization block
	 * are reached through the Entity magic accessors, which a generated mock does
	 * not implement.
	 *
	 * @param string|null $owner The stored owner.
	 * @param array<string,mixed>|null $authorization The stored authorization block.
	 *
	 * @return void
	 */
	private function resolvesToRecord(?string $owner, ?array $authorization = null): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setOwner($owner);
		$object->setAuthorization($authorization);
		$this->objectService->method('getObject')->willReturn($object);
	}//end resolvesToRecord()

	/**
	 * Make the resolver refuse, as it does for a record the caller cannot read.
	 *
	 * @return void
	 */
	private function resolvesToNothing(): void {
		$this->objectService->method('getObject')->willReturn(null);
	}//end resolvesToNothing()

	/**
	 * Answer one request parameter, and null for every other.
	 *
	 * @param array<string,mixed> $params The parameters to answer.
	 *
	 * @return void
	 */
	private function requestCarries(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);
	}//end requestCarries()

	public function testShowReturnsTheOwnerAndTheOwningGroup(): void {
		$this->resolvesToRecord('alice', ['scope' => 'private', 'ownerGroup' => 'redactie']);

		$response = $this->controller->show('zaken', 'zaak', 'uuid-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('alice', $response->getData()['owner']);
		$this->assertSame('redactie', $response->getData()['ownerGroup']);
	}//end testShowReturnsTheOwnerAndTheOwningGroup()

	public function testShowReportsNoOwningGroupWhenNoneIsNamed(): void {
		$this->resolvesToRecord('alice', null);

		$response = $this->controller->show('zaken', 'zaak', 'uuid-1');

		$this->assertSame('alice', $response->getData()['owner']);
		$this->assertNull($response->getData()['ownerGroup']);
	}//end testShowReportsNoOwningGroupWhenNoneIsNamed()

	public function testShowReturns404ForARecordTheCallerMayNotRead(): void {
		$this->resolvesToNothing();

		$response = $this->controller->show('zaken', 'zaak', 'someone-elses');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowReturns404ForARecordTheCallerMayNotRead()

	public function testClaimTakesTheRecordAndReportsTheOutcome(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries([]);

		$this->ownership->expects($this->once())
			->method('claim')
			->willReturn(
				[
					'uuid' => 'uuid-1',
					'previousOwner' => 'alice',
					'newOwner' => 'bob',
					'changed' => true,
					'children' => [],
				]
			);

		$response = $this->controller->claim('zaken', 'zaak', 'uuid-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('bob', $response->getData()['newOwner']);
		$this->assertTrue($response->getData()['changed']);
	}//end testClaimTakesTheRecordAndReportsTheOutcome()

	/**
	 * The body carries no owner, and the controller passes none.
	 *
	 * This is the security property at the HTTP boundary: a claim names no owner,
	 * so there is no parameter through which a caller could ask for one.
	 */
	public function testClaimNeverPassesACallerSuppliedOwner(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['owner' => 'mallory']);

		$this->ownership->expects($this->once())
			->method('claim')
			->with(
				$this->isInstanceOf(Register::class),
				$this->isInstanceOf(Schema::class),
				$this->isInstanceOf(ObjectEntity::class),
				false
			)
			->willReturn(
				[
					'uuid' => 'uuid-1',
					'previousOwner' => 'alice',
					'newOwner' => 'bob',
					'changed' => true,
					'children' => [],
				]
			);

		$this->controller->claim('zaken', 'zaak', 'uuid-1');
	}//end testClaimNeverPassesACallerSuppliedOwner()

	public function testClaimAnswers403WhenTheRulesRefuseTheCaller(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries([]);

		$this->ownership->method('claim')->willThrowException(
			new NotAuthorizedException('Only somebody who may edit this record can take ownership of it')
		);

		$response = $this->controller->claim('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('take ownership', $response->getData()['message']);
	}//end testClaimAnswers403WhenTheRulesRefuseTheCaller()

	public function testClaimAnswers404ForARecordTheCallerMayNotRead(): void {
		$this->resolvesToNothing();

		$response = $this->controller->claim('zaken', 'zaak', 'someone-elses');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testClaimAnswers404ForARecordTheCallerMayNotRead()

	public function testClaimAnswers500AndHidesTheDetailOnAnUnexpectedFailure(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries([]);

		$this->ownership->method('claim')->willThrowException(
			new \RuntimeException('connection to the storage at /srv/data failed')
		);

		$response = $this->controller->claim('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('/srv/data', $response->getData()['message']);
	}//end testClaimAnswers500AndHidesTheDetailOnAnUnexpectedFailure()

	public function testClaimPassesTheCascadeWhenTheRequestAsksForIt(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['cascade' => 'true']);

		$this->ownership->expects($this->once())
			->method('claim')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				true
			)
			->willReturn(
				[
					'uuid' => 'uuid-1',
					'previousOwner' => 'alice',
					'newOwner' => 'bob',
					'changed' => true,
					'children' => [],
				]
			);

		$this->controller->claim('zaken', 'zaak', 'uuid-1');
	}//end testClaimPassesTheCascadeWhenTheRequestAsksForIt()

	public function testAssignMovesTheRecordToTheNamedOwner(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['owner' => 'carol']);

		$this->ownership->expects($this->once())
			->method('assign')
			->willReturn(
				[
					'uuid' => 'uuid-1',
					'previousOwner' => 'alice',
					'newOwner' => 'carol',
					'changed' => true,
					'children' => [],
				]
			);

		$response = $this->controller->assign('zaken', 'zaak', 'uuid-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('carol', $response->getData()['newOwner']);
	}//end testAssignMovesTheRecordToTheNamedOwner()

	public function testAssignRequiresAnOwner(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries([]);

		$this->ownership->expects($this->never())->method('assign');

		$response = $this->controller->assign('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAssignRequiresAnOwner()

	public function testAssignAnswers403ForANonAdministrator(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['owner' => 'carol']);

		$this->ownership->method('assign')->willThrowException(
			new NotAuthorizedException('Only an administrator may assign a record to somebody else')
		);

		$response = $this->controller->assign('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAssignAnswers403ForANonAdministrator()

	public function testAssignAnswers400ForAUserThatDoesNotExist(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['owner' => 'nobody']);

		$this->ownership->method('assign')->willThrowException(
			new \InvalidArgumentException('No such user: nobody')
		);

		$response = $this->controller->assign('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAssignAnswers400ForAUserThatDoesNotExist()

	public function testSetOwnerGroupStoresTheGroup(): void {
		$this->resolvesToRecord('alice', ['scope' => 'private']);
		$this->requestCarries(['ownerGroup' => 'redactie']);

		$this->ownership->expects($this->once())
			->method('setOwnerGroup')
			->willReturn(['scope' => 'private', 'ownerGroup' => 'redactie']);

		$response = $this->controller->setOwnerGroup('zaken', 'zaak', 'uuid-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('redactie', $response->getData()['ownerGroup']);
	}//end testSetOwnerGroupStoresTheGroup()

	public function testSetOwnerGroupTakesTheGroupAwayOnNull(): void {
		$this->resolvesToRecord('alice', ['scope' => 'private', 'ownerGroup' => 'redactie']);
		$this->requestCarries([]);

		$this->ownership->expects($this->once())
			->method('setOwnerGroup')
			->willReturn(['scope' => 'private']);

		$response = $this->controller->setOwnerGroup('zaken', 'zaak', 'uuid-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertNull($response->getData()['ownerGroup']);
	}//end testSetOwnerGroupTakesTheGroupAwayOnNull()

	public function testSetOwnerGroupRefusesANonStringGroup(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['ownerGroup' => ['redactie']]);

		$this->ownership->expects($this->never())->method('setOwnerGroup');

		$response = $this->controller->setOwnerGroup('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSetOwnerGroupRefusesANonStringGroup()

	public function testSetOwnerGroupAnswers403ForAStranger(): void {
		$this->resolvesToRecord('alice');
		$this->requestCarries(['ownerGroup' => 'redactie']);

		$this->ownership->method('setOwnerGroup')->willThrowException(
			new NotAuthorizedException('Only the owner or an administrator may change who owns this record')
		);

		$response = $this->controller->setOwnerGroup('zaken', 'zaak', 'uuid-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSetOwnerGroupAnswers403ForAStranger()

	public function testReassignMovesManyRecords(): void {
		$this->requestCarries(['owner' => 'carol', 'objects' => ['uuid-1', 'uuid-2']]);

		$this->ownership->expects($this->once())
			->method('reassignMany')
			->with(['uuid-1', 'uuid-2'], 'carol', false)
			->willReturn(
				[
					'newOwner' => 'carol',
					'transferred' => [['uuid' => 'uuid-1'], ['uuid' => 'uuid-2']],
					'unchanged' => [],
					'failed' => [],
				]
			);

		$response = $this->controller->reassign();

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(2, $response->getData()['transferred']);
	}//end testReassignMovesManyRecords()

	public function testReassignRequiresAnOwner(): void {
		$this->requestCarries(['objects' => ['uuid-1']]);

		$this->ownership->expects($this->never())->method('reassignMany');

		$response = $this->controller->reassign();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testReassignRequiresAnOwner()

	public function testReassignRequiresAtLeastOneRecord(): void {
		$this->requestCarries(['owner' => 'carol', 'objects' => []]);

		$this->ownership->expects($this->never())->method('reassignMany');

		$response = $this->controller->reassign();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testReassignRequiresAtLeastOneRecord()

	public function testReassignAnswers403ForANonAdministrator(): void {
		$this->requestCarries(['owner' => 'carol', 'objects' => ['uuid-1']]);

		$this->ownership->method('reassignMany')->willThrowException(
			new NotAuthorizedException('Only an administrator may assign a record to somebody else')
		);

		$response = $this->controller->reassign();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReassignAnswers403ForANonAdministrator()

	public function testReassignAnswers500AndHidesTheDetail(): void {
		$this->requestCarries(['owner' => 'carol', 'objects' => ['uuid-1']]);

		$this->ownership->method('reassignMany')->willThrowException(
			new \RuntimeException('pg_dump at /var/lib/postgresql exploded')
		);

		$response = $this->controller->reassign();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('postgresql', $response->getData()['message']);
	}//end testReassignAnswers500AndHidesTheDetail()

}//end class
