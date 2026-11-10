<?php

/**
 * Unit tests for OasController on a register that does not exist.
 *
 * An unknown register id is a 404 for the caller, not a server error, and the
 * answer must not carry the database query the mapper failed on.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Oas;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\OasController;
use OCA\OpenRegister\Controller\Support\OasControllerServices;
use OCA\OpenRegister\Service\OasServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * OasUnknownRegisterTest.
 */
class OasUnknownRegisterTest extends TestCase {

	private OasServiceInterface&MockObject $oasService;

	private OasController $controller;

	protected function setUp(): void {
		parent::setUp();
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn(null);
		$this->oasService = $this->createMock(OasServiceInterface::class);
		$this->controller = new OasController($request, new OasControllerServices($this->oasService));
	}

	public function testUnknownRegisterAnswers404WithoutTheQuery(): void {
		$this->oasService->expects($this->once())->method('createOas')
			->with('999999999', false)
			->willThrowException(new DoesNotExistException('Did expect one result but found none when executing: query "SELECT * FROM registers"'));

		$response = $this->controller->generate('999999999');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Register not found'], $response->getData());
	}

	public function testOtherFailureStaysA500(): void {
		$this->oasService->expects($this->once())->method('createOas')
			->with('7', false)
			->willThrowException(new RuntimeException('generator broke'));

		$response = $this->controller->generate('7');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'generator broke'], $response->getData());
	}
}
