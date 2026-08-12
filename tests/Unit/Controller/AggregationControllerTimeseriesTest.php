<?php

/**
 * Unit tests for AggregationController::timeseries() — the ad-hoc
 * time-bucket aggregation entry point.
 *
 * Covers the 400 validation paths, the 403 / 404 translations, and
 * a happy-path runner-mocked response. The validator is
 * unit-tested separately (TimeseriesRequestValidatorTest).
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/add-time-bucket-aggregation/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\AggregationController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @coversDefaultClass \OCA\OpenRegister\Controller\AggregationController
 */
class AggregationControllerTimeseriesTest extends TestCase {

	private AggregationController $controller;

	private AggregationRunner&MockObject $runner;

	private TimeseriesRequestValidator&MockObject $validator;

	private IRequest&MockObject $request;

	/**
	 * Boot the SUT with three mocks: the runner (to assert dispatch),
	 * the validator (to swap in concrete throw / pass behaviour per
	 * test), and the request (so we can stub getParam).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->runner = $this->createMock(AggregationRunner::class);
		$this->validator = $this->createMock(TimeseriesRequestValidator::class);
		$this->controller = new AggregationController(
			'openregister',
			$this->request,
			$this->runner,
			$this->validator
		);
	}//end setUp()

	/**
	 * Schema-not-found → 404. The validator is not consulted.
	 *
	 * @return void
	 */
	public function testReturns404WhenSchemaCannotBeResolved(): void {
		$this->runner->method('findSchema')->willThrowException(
			new RuntimeException('Schema "bogus" not found.')
		);

		$response = $this->controller->timeseries('reg', 'bogus');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$body = $response->getData();
		$this->assertIsArray($body);
		$this->assertSame('Schema "bogus" not found.', $body['error'] ?? null);
	}//end testReturns404WhenSchemaCannotBeResolved()

	/**
	 * Validation failure → 400 + `{error}` body. Runner is not invoked.
	 *
	 * @return void
	 */
	public function testReturns400WhenValidatorRejectsInput(): void {
		$schema = $this->createMock(Schema::class);
		$this->runner->method('findSchema')->willReturn($schema);
		$this->validator->method('validate')->willThrowException(
			new InvalidArgumentException('`field` is required')
		);
		$this->runner->expects($this->never())->method('runAdhocByRef');

		$response = $this->controller->timeseries('reg', 'sch');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$body = $response->getData();
		$this->assertIsArray($body);
		$this->assertSame('`field` is required', $body['error'] ?? null);
	}//end testReturns400WhenValidatorRejectsInput()

	/**
	 * NotAuthorized from runner → 403 + structured body.
	 *
	 * @return void
	 */
	public function testReturns403WhenRunnerDeniesAccess(): void {
		$schema = $this->createMock(Schema::class);
		$query = AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status']);
		$this->runner->method('findSchema')->willReturn($schema);
		$this->validator->method('validate')->willReturn($query);
		$this->runner->method('runAdhocByRef')->willThrowException(
			new NotAuthorizedException(message: 'denied')
		);

		$response = $this->controller->timeseries('reg', 'sch');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$body = $response->getData();
		$this->assertSame('denied', $body['error'] ?? null);
	}//end testReturns403WhenRunnerDeniesAccess()

	/**
	 * Happy path: validator passes, runner returns groups, controller
	 * passes the body through verbatim.
	 *
	 * @return void
	 */
	public function testHappyPathReturnsGroups(): void {
		$schema = $this->createMock(Schema::class);
		$query = AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status']);
		$this->runner->method('findSchema')->willReturn($schema);
		$this->validator->method('validate')->willReturn($query);
		$this->runner->method('runAdhocByRef')->willReturn(
			[
				'groups' => [
					['key' => 'active', 'value' => 20],
					['key' => 'archived', 'value' => 10],
				],
				'backend' => 'postgres',
				'cached' => false,
			]
		);

		$response = $this->controller->timeseries('reg', 'sch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$body = $response->getData();
		$this->assertIsArray($body);
		$this->assertSame('postgres', $body['backend']);
		$this->assertCount(2, $body['groups']);
		$this->assertSame('active', $body['groups'][0]['key']);
		$this->assertSame(20, $body['groups'][0]['value']);
	}//end testHappyPathReturnsGroups()

	/**
	 * Register-not-found from runner → 404 (the runner's
	 * `runAdhocByRef` resolves both register and schema; either
	 * missing surfaces as RuntimeException).
	 *
	 * @return void
	 */
	public function testReturns404WhenRegisterCannotBeResolved(): void {
		$schema = $this->createMock(Schema::class);
		$query = AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status']);
		$this->runner->method('findSchema')->willReturn($schema);
		$this->validator->method('validate')->willReturn($query);
		$this->runner->method('runAdhocByRef')->willThrowException(
			new RuntimeException('Register "bogus" not found.')
		);

		$response = $this->controller->timeseries('bogus', 'sch');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testReturns404WhenRegisterCannotBeResolved()
}//end class
