<?php

/**
 * Cross-app contract: the detection threshold in the file settings body.
 *
 * filinq's anonymization-review-workbench reads `anonymisation.confidenceThreshold`
 * from `GET /api/settings/files` as a float. This pins the key, its type and the
 * refusal of a value outside 0 to 1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-the-confidence-threshold-is-an-organisation-setting-req-adi-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\Files\Controller\Settings\FileSettingsController;
use OCA\OpenRegister\Files\Controller\Support\FileSettingsControllerServices;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The body of GET /api/settings/files carries anonymisation.confidenceThreshold as a float.
 *
 * @covers \OCA\OpenRegister\Files\Controller\Settings\FileSettingsController
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 */
class FileSettingsContractTest extends TestCase {

	/**
	 * The stored fileManagement JSON.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The controller over a real FileSettingsHandler, answering the given request params.
	 *
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return FileSettingsController
	 */
	private function controller(array $params = []): FileSettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $key === 'fileManagement' ? $this->stored : $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'fileManagement') {
					$this->stored = $value;
				}

				return true;
			}
		);
		$handler = new FileSettingsHandler($appConfig);

		$settings = $this->createMock(AppSettingsInterface::class);
		$settings->method('getFileSettingsOnly')->willReturnCallback(fn (): array => $handler->getFileSettingsOnly());
		$settings->method('updateFileSettingsOnly')->willReturnCallback(fn (array $data): array => $handler->updateFileSettingsOnly($data));

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new FileSettingsController(
			$request,
			new FileSettingsControllerServices(
				container: $this->createMock(ContainerInterface::class),
				settingsService: $settings,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end controller()

	/**
	 * With nothing stored the body carries the default 0.5, a float.
	 *
	 * @return void
	 */
	public function testTheBodyCarriesTheThresholdAsAFloat(): void {
		$body = $this->controller()->getFileSettings()->getData();

		$this->assertArrayHasKey('anonymisation', $body);
		$this->assertIsFloat($body['anonymisation']['confidenceThreshold']);
		$this->assertSame(0.5, $body['anonymisation']['confidenceThreshold']);
	}//end testTheBodyCarriesTheThresholdAsAFloat()

	/**
	 * A saved threshold is what the next read returns, still a float.
	 *
	 * @return void
	 */
	public function testASavedThresholdIsRead(): void {
		$saved = $this->controller(params: ['anonymisation' => ['confidenceThreshold' => '0.8']])->updateFileSettings();
		$this->assertSame(200, $saved->getStatus());

		$this->assertSame(0.8, $this->controller()->getFileSettings()->getData()['anonymisation']['confidenceThreshold']);
	}//end testASavedThresholdIsRead()

	/**
	 * A value outside 0 to 1 is refused with 400 and the stored value stays.
	 *
	 * @return void
	 */
	public function testAnOutOfRangeThresholdIsA400(): void {
		$response = $this->controller(params: ['anonymisation' => ['confidenceThreshold' => 1.5]])->updateFileSettings();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'anonymisation.confidenceThreshold must be a number between 0 and 1'],
			$response->getData()
		);
		$this->assertSame('', $this->stored);
	}//end testAnOutOfRangeThresholdIsA400()
}//end class
