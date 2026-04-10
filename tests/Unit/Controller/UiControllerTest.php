<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\UiController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UiControllerTest extends TestCase
{
    private UiController $controller;
    private IRequest&MockObject $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);

        $this->controller = new UiController(
            'openregister',
            $this->request
        );
    }

    /**
     * @dataProvider spaRouteProvider
     */
    public function testSpaRoutesReturnTemplateResponse(string $method): void
    {
        $result = $this->controller->$method();

        $this->assertInstanceOf(TemplateResponse::class, $result);
        $this->assertEquals('index', $result->getTemplateName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spaRouteProvider(): array
    {
        return [
            'registers'        => ['registers'],
            'registersDetails' => ['registersDetails'],
            'schemas'          => ['schemas'],
            'schemasDetails'   => ['schemasDetails'],
            'sources'          => ['sources'],
            'organisation'     => ['organisation'],
            'objects'          => ['objects'],
            'tables'           => ['tables'],
            'chat'             => ['chat'],
            'configurations'   => ['configurations'],
            'deleted'          => ['deleted'],
            'auditTrail'       => ['auditTrail'],
            'searchTrail'      => ['searchTrail'],
            'webhooks'         => ['webhooks'],
            'webhooksLogs'     => ['webhooksLogs'],
            'entities'         => ['entities'],
            'entitiesDetails'  => ['entitiesDetails'],
            'endpoints'        => ['endpoints'],
            'endpointLogs'     => ['endpointLogs'],
        ];
    }
}
