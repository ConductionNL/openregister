<?php

/**
 * Tests for the rectification, erasure and objection-refusal paths of
 * {@see \OCA\OpenRegister\Controller\DataSubjectRequestController}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/gdpr-data-subject-rights/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\DataSubjectRequestController;
use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestServiceInterface;
use OCA\OpenRegister\Service\Gdpr\ErasureRequest;
use OCA\OpenRegister\Service\Gdpr\ProcessingFlag;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DataSubjectRequestControllerRectifyEraseTest.
 */
#[CoversClass(DataSubjectRequestController::class)]
#[UsesClass(ErasureRequest::class)]
#[UsesClass(ProcessingFlag::class)]
class DataSubjectRequestControllerRectifyEraseTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Data-subject-request service mock.
	 *
	 * @var DataSubjectRequestServiceInterface&MockObject
	 */
	private DataSubjectRequestServiceInterface&MockObject $service;

	/**
	 * Controller under test.
	 *
	 * @var DataSubjectRequestController
	 */
	private DataSubjectRequestController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DataSubjectRequestServiceInterface::class);
		$this->controller = new DataSubjectRequestController($this->request, $this->service);
	}//end setUp()

	/**
	 * Stub the request params from a simple map.
	 *
	 * @param array<string,mixed> $params The params the request should answer.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);
	}//end withParams()

	public function testRectifyForwardsTheObjectAndChangesAndReturnsTheResult(): void {
		$this->withParams(['object' => 'obj-1', 'changes' => ['name' => 'Jansen']]);
		$this->service->expects($this->once())
			->method('rectify')
			->with('obj-1', ['name' => 'Jansen'])
			->willReturn(['id' => 'obj-1', 'name' => 'Jansen']);

		$response = $this->controller->rectify();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'obj-1', 'name' => 'Jansen'], $response->getData());
	}//end testRectifyForwardsTheObjectAndChangesAndReturnsTheResult()

	public function testRectifyReturns404WhenTheServiceRefusesTheObject(): void {
		$this->withParams(['object' => 'obj-1', 'changes' => ['name' => 'Jansen']]);
		$this->service->method('rectify')->willReturn(null);

		$response = $this->controller->rectify();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Object not found, not authorised, or immutable'], $response->getData());
	}//end testRectifyReturns404WhenTheServiceRefusesTheObject()

	public function testRectifyRejectsAMissingObjectWith400(): void {
		$this->withParams(['changes' => ['name' => 'Jansen']]);
		$this->service->expects($this->never())->method('rectify');

		$response = $this->controller->rectify();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'object and changes are required'], $response->getData());
	}//end testRectifyRejectsAMissingObjectWith400()

	public function testRectifyRejectsChangesThatAreNotAnArrayWith400(): void {
		$this->withParams(['object' => 'obj-1', 'changes' => 'name=Jansen']);
		$this->service->expects($this->never())->method('rectify');

		$response = $this->controller->rectify();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRectifyRejectsChangesThatAreNotAnArrayWith400()

	public function testEraseBuildsTheRequestFromTheParamsAndReturnsTheSummary(): void {
		$this->withParams(['subject' => 'bsn:123', 'type' => 'person', 'eraseMode' => 'whole-object', 'dryRun' => 'true']);
		$captured = null;
		$this->service->expects($this->once())
			->method('erase')
			->willReturnCallback(
				static function (ErasureRequest $request) use (&$captured): array {
					$captured = $request;
					return ['erased' => 2, 'dryRun' => true];
				}
			);

		$response = $this->controller->erase();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['erased' => 2, 'dryRun' => true], $response->getData());
		$this->assertSame('bsn:123', $captured->subjectId);
		$this->assertSame('person', $captured->type);
		$this->assertSame('whole-object', $captured->eraseMode);
		$this->assertTrue($captured->dryRun);
	}//end testEraseBuildsTheRequestFromTheParamsAndReturnsTheSummary()

	public function testEraseDefaultsToAPseudonymisingRealRunWithoutAType(): void {
		$this->withParams(['subject' => 'bsn:123', 'type' => '']);
		$captured = null;
		$this->service->method('erase')->willReturnCallback(
			static function (ErasureRequest $request) use (&$captured): array {
				$captured = $request;
				return [];
			}
		);

		$this->controller->erase();

		$this->assertNull($captured->type);
		$this->assertSame(DataSubjectRequestServiceInterface::ERASE_MODE_PSEUDONYMISE, $captured->eraseMode);
		$this->assertFalse($captured->dryRun);
	}//end testEraseDefaultsToAPseudonymisingRealRunWithoutAType()

	public function testEraseRejectsAMissingSubjectWith422(): void {
		$this->withParams([]);
		$this->service->expects($this->never())->method('erase');

		$response = $this->controller->erase();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'Missing required parameter: subject'], $response->getData());
	}//end testEraseRejectsAMissingSubjectWith422()

	public function testObjectionRejectsAMissingObjectWith400(): void {
		$this->withParams(['objected' => 'true']);
		$this->service->expects($this->never())->method('setObjection');

		$response = $this->controller->objection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'object is required'], $response->getData());
	}//end testObjectionRejectsAMissingObjectWith400()
}//end class
