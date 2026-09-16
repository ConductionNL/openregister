<?php

/**
 * ApiContractServiceTest — each version's description covers only its own
 * routes, and a deprecated one says so in the OpenAPI-native spelling.
 *
 * The narrowing is the part that would otherwise be a sentence in a proposal
 * rather than a mechanism: a version declaring `/objects` must not publish the
 * register routes as though it served them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiVersion
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ApiVersion;

use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\OasService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiContractService
 */
class ApiContractServiceTest extends TestCase {

	/**
	 * A generated document with one object path and one register path.
	 *
	 * @return array<string, mixed> The document.
	 */
	private static function generated(): array {
		return [
			'openapi' => '3.1.0',
			'info' => ['title' => 'zaken API', 'version' => '0.0.4'],
			'paths' => [
				'/objects/zaken/meldingen' => [
					'get' => ['operationId' => 'ZakenGetAllMeldingen'],
					'post' => ['operationId' => 'ZakenCreateMelding'],
					'parameters' => [['name' => '_limit', 'in' => 'query']],
				],
				'/registers/1' => ['get' => ['operationId' => 'ZakenGetRegister']],
			],
		];
	}//end generated()

	/**
	 * Build the service over the generated document above.
	 *
	 * @return ApiContractService The service.
	 */
	private function service(): ApiContractService {
		$oasService = $this->createMock(OasService::class);
		$oasService->method('createOas')->willReturn(self::generated());

		return new ApiContractService($oasService);
	}//end service()

	public function testTheFirstVersionDescribesTheWholeSurface(): void {
		$whole = new ApiVersion(id: '1', status: ApiVersion::STATUS_SUPPORTED, pathPrefixes: ['/']);

		$document = $this->service()->documentFor(version: $whole);

		$this->assertSame(
			['/objects/zaken/meldingen', '/registers/1'],
			array_keys($document['paths'])
		);
	}//end testTheFirstVersionDescribesTheWholeSurface()

	public function testAVersionDescribesOnlyItsOwnRoutes(): void {
		$narrow = new ApiVersion(id: '2', status: ApiVersion::STATUS_SUPPORTED, pathPrefixes: ['/objects']);

		$document = $this->service()->documentFor(version: $narrow);

		$this->assertSame(['/objects/zaken/meldingen'], array_keys($document['paths']));
	}//end testAVersionDescribesOnlyItsOwnRoutes()

	public function testTheStampNamesTheStatusAndTheDeadline(): void {
		$deprecated = new ApiVersion(
			id: '1',
			status: ApiVersion::STATUS_DEPRECATED,
			deprecatedOn: '2026-09-01',
			sunset: '2027-03-01',
			successor: '2',
		);

		$document = $this->service()->documentFor(version: $deprecated);

		$this->assertSame('1', $document['x-api-version']['version']);
		$this->assertSame('deprecated', $document['x-api-version']['status']);
		$this->assertSame('2027-03-01', $document['x-api-version']['sunset']);
		$this->assertSame('2', $document['x-api-version']['successor']);
		$this->assertTrue($document['x-api-version']['deprecated']);
		$this->assertSame('API-Version', $document['x-api-version']['versionHeader']);
	}//end testTheStampNamesTheStatusAndTheDeadline()

	public function testEveryOperationOfADeprecatedVersionIsMarkedDeprecated(): void {
		$deprecated = new ApiVersion(
			id: '1',
			status: ApiVersion::STATUS_DEPRECATED,
			sunset: '2027-03-01',
			successor: '2',
		);

		$document = $this->service()->documentFor(version: $deprecated);
		$item = $document['paths']['/objects/zaken/meldingen'];

		$this->assertTrue($item['get']['deprecated'], 'A generated client warns its own author from this flag alone.');
		$this->assertTrue($item['post']['deprecated']);
		$this->assertArrayNotHasKey(
			'deprecated',
			$item['parameters'],
			'`parameters` is not an operation; marking it deprecated is nonsense a validator accepts in silence.'
		);
	}//end testEveryOperationOfADeprecatedVersionIsMarkedDeprecated()

	public function testASupportedVersionMarksNothingDeprecated(): void {
		$supported = new ApiVersion(id: '2', status: ApiVersion::STATUS_SUPPORTED);

		$document = $this->service()->documentFor(version: $supported);

		$this->assertArrayNotHasKey('deprecated', $document['paths']['/objects/zaken/meldingen']['get']);
		$this->assertFalse($document['x-api-version']['deprecated']);
	}//end testASupportedVersionMarksNothingDeprecated()

	public function testTheGeneratedInfoBlockIsLeftAlone(): void {
		$supported = new ApiVersion(id: '1', status: ApiVersion::STATUS_SUPPORTED);

		$document = $this->service()->documentFor(version: $supported);

		$this->assertSame(
			'0.0.4',
			$document['info']['version'],
			'info.version is the register version the generator writes; the contract version lives in x-api-version.'
		);
	}//end testTheGeneratedInfoBlockIsLeftAlone()
}//end class
