<?php

/**
 * The register API's bundle port forwards each call to the configuration
 * service with the arguments the register API passed before.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Kernel\Contracts\Core\BundleImport;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterBundlesInterface;
use OCA\OpenRegister\Service\Configuration\RegisterBundleBindingInterface;
use OCA\OpenRegister\Service\Configuration\RegisterBundles;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RegisterBundlesTest extends TestCase {

	private ConfigurationServiceInterface&MockObject $configurations;

	private RegisterBundleBindingInterface&MockObject $binding;

	private RegisterBundles $bundles;

	protected function setUp(): void {
		parent::setUp();
		$this->configurations = $this->createMock(ConfigurationServiceInterface::class);
		$this->binding        = $this->createMock(RegisterBundleBindingInterface::class);
		$this->bundles        = new RegisterBundles(configurations: $this->configurations, binding: $this->binding);
	}//end setUp()

	public function testTheUploadedBundleIsTheConfigurationServicesAnswer(): void {
		$this->configurations->expects($this->once())
			->method('getUploadedJson')
			->with(['register' => 'r'], [['name' => 'f.json']])
			->willReturn(['components' => []]);

		$this->assertSame(['components' => []], $this->bundles->uploadedBundle(params: ['register' => 'r'], files: [['name' => 'f.json']]));
	}//end testTheUploadedBundleIsTheConfigurationServicesAnswer()

	public function testAnUploadErrorIsReturnedAsTheResponse(): void {
		$error = new JSONResponse(data: ['error' => 'no file'], statusCode: 400);
		$this->configurations->method('getUploadedJson')->willReturn($error);

		$this->assertSame($error, $this->bundles->uploadedBundle(params: [], files: null));
	}//end testAnUploadErrorIsReturnedAsTheResponse()

	public function testABundleIsImportedWithoutAConfigurationOnTheGivenTerms(): void {
		$this->configurations->expects($this->once())
			->method('importFromJson')
			->with(['info' => []], null, 'alice', 'myapp', '1.2.0', true)
			->willReturn(['registers' => [], 'schemas' => [], 'objects' => []]);

		$result = $this->bundles->importBundle(
			bundle: ['info' => []],
			import: new BundleImport(owner: 'alice', appId: 'myapp', version: '1.2.0', force: true)
		);

		$this->assertSame(['registers' => [], 'schemas' => [], 'objects' => []], $result);
	}//end testABundleIsImportedWithoutAConfigurationOnTheGivenTerms()

	public function testAnAnonymousImportPassesNulls(): void {
		$this->configurations->expects($this->once())
			->method('importFromJson')
			->with([], null, null, null, null, false)
			->willReturn([]);

		$this->bundles->importBundle(bundle: [], import: new BundleImport(owner: null, appId: null, version: null, force: false));
	}//end testAnAnonymousImportPassesNulls()

	public function testARegisterIsExportedAsItsConfiguration(): void {
		$register = new Register();
		$this->configurations->expects($this->once())
			->method('exportConfig')
			->with($register, true)
			->willReturn(['openapi' => '3.0.0']);

		$this->assertSame(['openapi' => '3.0.0'], $this->bundles->exportRegister(register: $register, includeObjects: true));
	}//end testARegisterIsExportedAsItsConfiguration()

	public function testTheScopeRefusalIsTheBindingChecksAnswer(): void {
		$register = new Register();
		$bundle   = ['components' => ['mappings' => []]];
		$this->binding->expects($this->once())
			->method('refusalFor')
			->with($register, $bundle)
			->willReturn('The bundle reaches outside the register.');

		$this->assertSame('The bundle reaches outside the register.', $this->bundles->refusalFor(register: $register, bundle: $bundle));
	}//end testTheScopeRefusalIsTheBindingChecksAnswer()

	public function testABundleInsideTheRegisterIsNotRefused(): void {
		$this->binding->method('refusalFor')->willReturn(null);

		$this->assertNull($this->bundles->refusalFor(register: new Register(), bundle: []));
	}//end testABundleInsideTheRegisterIsNotRefused()

	public function testThePortIsBoundToTheAdapterInConfigurationsBindings(): void {
		$this->assertSame(RegisterBundles::class, ContractBindings::BINDINGS[RegisterBundlesInterface::class]);
	}//end testThePortIsBoundToTheAdapterInConfigurationsBindings()
}//end class
