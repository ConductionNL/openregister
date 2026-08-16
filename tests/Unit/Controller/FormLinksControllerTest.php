<?php

/**
 * Contract tests for {@see \OCA\OpenRegister\Controller\FormLinksController}.
 *
 * Covers the two unlink endpoints: removing a form (and every submission-level
 * link beneath it) and removing a single submission row. Both resolve the
 * object first, so both must answer 404 when the register/schema/id tuple does
 * not resolve, and both report the service's removal outcome rather than a bare
 * success flag.
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
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\FormLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FormLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * FormLinksControllerTest.
 */
class FormLinksControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Form link service mock.
	 *
	 * @var FormLinkService&MockObject
	 */
	private FormLinkService&MockObject $service;

	/**
	 * Object resolver mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Controller under test.
	 *
	 * @var FormLinksController
	 */
	private FormLinksController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(FormLinkService::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('setObject')->willReturnSelf();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new FormLinksController(
			'openregister',
			$this->request,
			$this->service,
			$this->objectService,
			$l10n,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * Make the object resolver answer with an object carrying the given uuid.
	 *
	 * @param string $uuid The resolved object's uuid.
	 *
	 * @return void
	 */
	private function resolvesTo(string $uuid): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getUuid')->willReturn($uuid);
		$this->objectService->method('getObject')->willReturn($object);
	}//end resolvesTo()

	public function testDestroyFormReportsHowManyLinksWereRemoved(): void {
		$this->resolvesTo('uuid-1');

		$this->service->expects($this->once())
			->method('unlinkForm')
			->with('uuid-1', 42)
			->willReturn(3);

		$response = $this->controller->destroyForm('zaken', 'zaak', 'uuid-1', '42');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(3, $response->getData()['removed']);
	}//end testDestroyFormReportsHowManyLinksWereRemoved()

	public function testDestroyFormReturns404WhenTheObjectDoesNotResolve(): void {
		$this->objectService->method('getObject')->willReturn(null);
		$this->service->expects($this->never())->method('unlinkForm');

		$response = $this->controller->destroyForm('zaken', 'zaak', 'nope', '42');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame('Object not found', $response->getData()['error']);
	}//end testDestroyFormReturns404WhenTheObjectDoesNotResolve()

	public function testDestroySubmissionReportsTheServiceOutcome(): void {
		$this->resolvesTo('uuid-1');

		$this->service->expects($this->once())
			->method('unlinkSubmission')
			->with('uuid-1', 42, 7)
			->willReturn(true);

		$response = $this->controller->destroySubmission('zaken', 'zaak', 'uuid-1', '42', '7');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testDestroySubmissionReportsTheServiceOutcome()

	public function testDestroySubmissionReports400WhenTheServiceThrows(): void {
		$this->resolvesTo('uuid-1');
		$this->service->method('unlinkSubmission')
			->willThrowException(new Exception('Submission not linked'));

		$response = $this->controller->destroySubmission('zaken', 'zaak', 'uuid-1', '42', '7');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Submission not linked', $response->getData()['error']);
	}//end testDestroySubmissionReports400WhenTheServiceThrows()
}//end class
