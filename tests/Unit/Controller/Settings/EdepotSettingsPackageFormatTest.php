<?php

/**
 * Unit test for the SIP output-format setting of the e-Depot settings API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\EdepotSettingsController;
use OCA\OpenRegister\Service\Connection\ConnectionReporter;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EdepotSettingsControllerFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The output format (zip default | bagit) is chosen in the e-Depot settings:
 * read back with a zip default, stored when known, refused when not.
 *
 * @covers \OCA\OpenRegister\Controller\Settings\EdepotSettingsController
 * @uses \OCA\OpenRegister\Controller\Support\ConnectionReporting
 * @uses \OCA\OpenRegister\Controller\Support\EdepotSettingsDomain
 * @uses \OCA\OpenRegister\Controller\Support\EdepotSettingsControllerServices
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
 */
class EdepotSettingsPackageFormatTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function controller(array $params=[]): EdepotSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$transfer = $this->createMock(EdepotTransferService::class);
		$transfer->method('getTransportConfig')->willReturn(['transport' => 'rest_api']);
		$transfer->method('getAvailableProfiles')->willReturn(['default' => ['label' => 'Default']]);
		$transfer->method('isValidProfile')->willReturn(true);

		return EdepotSettingsControllerFactory::create(
			'openregister',
			$request,
			$this->appConfig,
			$transfer,
			$this->createMock(SftpTransport::class),
			$this->createMock(RestApiTransport::class),
			$this->createMock(OpenConnectorTransport::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ConnectionReporter::class)
		);
	}//end controller()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function storedFormats(): array {
		return [
			'bagit chosen' => ['bagit', 'bagit'],
			'zip chosen' => ['zip', 'zip'],
			'unknown value falls back to zip' => ['tar', 'zip'],
			'unset falls back to zip' => ['', 'zip'],
		];
	}//end storedFormats()

	#[DataProvider('storedFormats')]
	public function testTheSettingsAnswerTheStoredFormatWithAZipDefault(string $stored, string $answered): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('openregister', 'edepot_package_format', 'zip')
			->willReturn($stored);

		$data = $this->controller()->getEdepotSettings()->getData();

		$this->assertSame($answered, $data['packageFormat']);
		$this->assertSame(['zip', 'bagit'], $data['availablePackageFormats']);
	}//end testTheSettingsAnswerTheStoredFormatWithAZipDefault()

	public function testAKnownFormatIsStored(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'edepot_package_format', 'bagit')
			->willReturn(true);

		$response = $this->controller(['packageFormat' => 'bagit'])->updateEdepotSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}//end testAKnownFormatIsStored()

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function unknownFormats(): array {
		return [
			'another archive format' => ['tar', "Invalid package format 'tar'. Available: zip, bagit"],
			'wrong case' => ['BagIt', "Invalid package format 'BagIt'. Available: zip, bagit"],
			'a list' => [['bagit'], "Invalid package format 'array'. Available: zip, bagit"],
		];
	}//end unknownFormats()

	#[DataProvider('unknownFormats')]
	public function testAnUnknownFormatIsRefusedAndNothingIsStored(mixed $format, string $error): void {
		$this->appConfig->expects($this->never())->method('setValueString');

		$response = $this->controller(['packageFormat' => $format, 'host' => 'h'])->updateEdepotSettings();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => $error], $response->getData());
	}//end testAnUnknownFormatIsRefusedAndNothingIsStored()

	public function testSavingWithoutAFormatLeavesItAlone(): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'edepot_sftp_host', 'h')
			->willReturn(true);

		$this->assertSame(200, $this->controller(['host' => 'h'])->updateEdepotSettings()->getStatus());
	}//end testSavingWithoutAFormatLeavesItAlone()
}//end class
