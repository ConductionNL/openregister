<?php

/**
 * Unit tests for PhotoSettingsController.
 *
 * @category Test
 * @package  Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-6
 */

declare(strict_types=1);

namespace Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\PhotoSettingsController;
use OCA\OpenRegister\Service\PhotoService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for PhotoSettingsController.
 */
class PhotoSettingsControllerTest extends TestCase
{

    private PhotoSettingsController $controller;

    private IRequest&MockObject $request;

    private IConfig&MockObject $config;

    /**
     * Set up mocks and controller under test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config  = $this->createMock(IConfig::class);

        $this->controller = new PhotoSettingsController(
            appName: 'openregister',
            request: $this->request,
            config: $this->config
        );
    }//end setUp()

    /**
     * Test getPhotoSettings returns stripGps false by default.
     */
    public function testGetPhotoSettingsDefaultFalse(): void
    {
        $this->config->method('getAppValue')->willReturn('false');

        $result = $this->controller->getPhotoSettings();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertFalse($result->getData()['stripGps']);
    }//end testGetPhotoSettingsDefaultFalse()

    /**
     * Test getPhotoSettings returns stripGps true when setting is on.
     */
    public function testGetPhotoSettingsReturnsTrueWhenEnabled(): void
    {
        $this->config->method('getAppValue')->willReturn('true');

        $result = $this->controller->getPhotoSettings();

        $this->assertTrue($result->getData()['stripGps']);
    }//end testGetPhotoSettingsReturnsTrueWhenEnabled()

    /**
     * Test updatePhotoSettings saves the setting and returns updated value.
     */
    public function testUpdatePhotoSettingsSavesAndReturns(): void
    {
        $this->request->method('getParams')->willReturn(['stripGps' => true]);

        $this->config->expects($this->once())
            ->method('setAppValue');

        $result = $this->controller->updatePhotoSettings();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(Http::STATUS_OK, $result->getStatus());
        $this->assertTrue($result->getData()['stripGps']);
    }//end testUpdatePhotoSettingsSavesAndReturns()
}//end class
