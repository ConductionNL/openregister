<?php

/**
 * ConfigurationSettingsController archival settings tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use Exception;
use OCA\OpenRegister\Controller\Settings\ConfigurationSettingsController;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The archival settings read and write go to the settings service, and a
 * failure answers 500 with the service's message.
 */
class ConfigurationSettingsControllerArchivalTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The settings service double.
	 *
	 * @var AppSettingsInterface&MockObject
	 */
	private AppSettingsInterface&MockObject $settings;

	/**
	 * The controller under test.
	 *
	 * @var ConfigurationSettingsController
	 */
	private ConfigurationSettingsController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request    = $this->createMock(IRequest::class);
		$this->settings   = $this->createMock(AppSettingsInterface::class);
		$this->controller = new ConfigurationSettingsController(
			'openregister',
			$this->request,
			$this->settings,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * getArchivalSettings() answers the service's settings.
	 *
	 * @return void
	 */
	public function testGetArchivalSettingsAnswersTheSettings(): void {
		$this->settings->method('getArchivalSettingsOnly')->willReturn(['destructionSchedule' => 'weekly']);

		$response = $this->controller->getArchivalSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['destructionSchedule' => 'weekly'], $response->getData());
	}//end testGetArchivalSettingsAnswersTheSettings()

	/**
	 * A failing read answers 500 with the message.
	 *
	 * @return void
	 */
	public function testGetArchivalSettingsFailureIs500(): void {
		$this->settings->method('getArchivalSettingsOnly')->willThrowException(new Exception('config unreadable'));

		$response = $this->controller->getArchivalSettings();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'config unreadable'], $response->getData());
	}//end testGetArchivalSettingsFailureIs500()

	/**
	 * updateArchivalSettings() passes the request parameters and answers the result.
	 *
	 * @return void
	 */
	public function testUpdateArchivalSettingsPassesTheParams(): void {
		$this->request->method('getParams')->willReturn(['selectielijst' => 'gemeenten-2020']);
		$this->settings->expects($this->once())
			->method('updateArchivalSettingsOnly')
			->with(['selectielijst' => 'gemeenten-2020'])
			->willReturn(['selectielijst' => 'gemeenten-2020', 'saved' => true]);

		$response = $this->controller->updateArchivalSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['selectielijst' => 'gemeenten-2020', 'saved' => true], $response->getData());
	}//end testUpdateArchivalSettingsPassesTheParams()

	/**
	 * A failing write answers 500 with the message.
	 *
	 * @return void
	 */
	public function testUpdateArchivalSettingsFailureIs500(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->settings->method('updateArchivalSettingsOnly')->willThrowException(new Exception('invalid schedule'));

		$response = $this->controller->updateArchivalSettings();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'invalid schedule'], $response->getData());
	}//end testUpdateArchivalSettingsFailureIs500()
}//end class
