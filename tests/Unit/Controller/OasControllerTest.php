<?php

/**
 * Unit tests for OasController.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/oas-validation/tasks.md#task-validation-modes
 */

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\OasController;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCA\OpenRegister\Service\OasService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OasController
 *
 * @package Unit\Controller
 */
class OasControllerTest extends TestCase
{

    private OasController $controller;

    private IRequest&MockObject $request;

    private OasService&MockObject $oasService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request    = $this->createMock(IRequest::class);
        $this->oasService = $this->createMock(OasService::class);

        $this->controller = new OasController(
            appName: 'openregister',
            request: $this->request,
            oasService: $this->oasService,
        );

    }//end setUp()

    public function testGenerateAllReturnsOasData(): void
    {
        $oasData = [
            'openapi' => '3.0.0',
            'info'    => ['title' => 'OpenRegister API'],
            'paths'   => [],
        ];

        $this->request->method('getParam')->willReturn(null);
        $this->oasService->method('createOas')->willReturn($oasData);

        $result = $this->controller->generateAll();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame($oasData, $result->getData());

    }//end testGenerateAllReturnsOasData()

    public function testGenerateAllReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->oasService->method('createOas')
            ->willThrowException(new Exception('OAS generation failed'));

        $result = $this->controller->generateAll();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('OAS generation failed', $data['error']);

    }//end testGenerateAllReturns500OnException()

    public function testGenerateReturnsOasForRegister(): void
    {
        $oasData = [
            'openapi' => '3.0.0',
            'info'    => ['title' => 'Register API'],
        ];

        $this->request->method('getParam')->willReturn(null);
        $this->oasService->method('createOas')
            ->with($this->equalTo('my-register'))
            ->willReturn($oasData);

        $result = $this->controller->generate('my-register');

        $this->assertSame(200, $result->getStatus());
        $this->assertSame($oasData, $result->getData());

    }//end testGenerateReturnsOasForRegister()

    public function testGenerateReturns500OnException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->oasService->method('createOas')
            ->willThrowException(new Exception('Register not found'));

        $result = $this->controller->generate('nonexistent');

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Register not found', $data['error']);

    }//end testGenerateReturns500OnException()

    public function testStrictModeReturns422WhenValidationFails(): void
    {
        $report = new OasValidationReport();
        $report->addError(
            path: 'servers[0].url',
            message: 'relative URL',
            code: OasValidationReport::CODE_RELATIVE_SERVER_URL,
        );

        $this->request->method('getParam')
            ->willReturnMap(
                    [
                        ['strict', null, 'true'],
                        ['validate', null, null],
                    ]
                    );

        $this->oasService->method('createOas')
            ->willThrowException(
                new OasValidationException(
                    message: 'Generated OAS failed strict validation: 1 error(s)',
                    report: $report,
                )
            );

        $result = $this->controller->generateAll();

        $this->assertSame(422, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertFalse($data['summary']['passed']);
        $this->assertSame(1, $data['summary']['errors']);

    }//end testStrictModeReturns422WhenValidationFails()

    public function testValidateModeAddsXValidationSummary(): void
    {
        $oasData = ['openapi' => '3.1.0', 'paths' => []];
        $report  = new OasValidationReport();

        $this->request->method('getParam')
            ->willReturnMap(
                    [
                        ['strict', null, null],
                        ['validate', null, 'true'],
                    ]
                    );

        $this->oasService->method('createOas')->willReturn($oasData);
        $this->oasService->method('getLastValidationReport')->willReturn($report);

        $result = $this->controller->generateAll();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('x-validation-summary', $data);
        $summary = $data['x-validation-summary'];
        $this->assertArrayHasKey('passed', $summary);
        $this->assertTrue($summary['passed']);

    }//end testValidateModeAddsXValidationSummary()

    public function testValidateModeOnSingleRegisterAddsXValidationSummary(): void
    {
        $oasData = ['openapi' => '3.1.0', 'paths' => []];
        $report  = new OasValidationReport();
        $report->addWarning(
            path: 'tags[0]',
            message: 'unused tag',
            code: OasValidationReport::CODE_UNUSED_TAG,
        );

        $this->request->method('getParam')
            ->willReturnMap(
                    [
                        ['strict', null, null],
                        ['validate', null, '1'],
                    ]
                    );

        $this->oasService->method('createOas')->willReturn($oasData);
        $this->oasService->method('getLastValidationReport')->willReturn($report);

        $result = $this->controller->generate('my-register');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('x-validation-summary', $data);
        $this->assertSame(1, $data['x-validation-summary']['warnings']);

    }//end testValidateModeOnSingleRegisterAddsXValidationSummary()
}//end class
