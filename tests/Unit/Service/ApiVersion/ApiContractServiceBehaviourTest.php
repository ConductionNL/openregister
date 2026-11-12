<?php

/**
 * Behaviour tests for ApiContractService: the OpenAPI document narrowed and stamped per API version.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiVersion
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ApiVersion;

use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\OasService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiContractService
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersion
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle
 */
class ApiContractServiceBehaviourTest extends TestCase
{
    private function service(array $document, ?string $expectedRegister = null): ApiContractService
    {
        $oas = $this->createMock(OasService::class);
        $oas->expects($this->once())->method('createOas')->with($expectedRegister, false)->willReturn($document);
        return new ApiContractService($oas);
    }//end service()

    private static function paths(): array
    {
        return [
            '/api/objects'        => ['get' => ['summary' => 'list'], 'post' => ['summary' => 'create'], 'parameters' => [['name' => 'x']]],
            '/api/objects/{id}'   => ['delete' => 'not-an-operation-array', 'patch' => ['summary' => 'patch']],
            '/api/registers'      => ['get' => ['summary' => 'registers']],
            '/api/objects/broken' => 'not-a-path-item',
        ];
    }//end paths()

    public function testASupportedVersionKeepsOnlyItsPathsAndIsStamped(): void
    {
        $version = ApiVersion::fromArray(['id' => '2', 'status' => 'supported', 'pathPrefixes' => ['/api/objects']]);

        $document = $this->service(['openapi' => '3.1.0', 'paths' => self::paths()], 'reg-1')->documentFor($version, 'reg-1');

        $this->assertSame(['/api/objects', '/api/objects/{id}', '/api/objects/broken'], array_keys($document['paths']));
        $this->assertArrayNotHasKey('deprecated', $document['paths']['/api/objects']['get']);
        $this->assertSame(
            ['version' => '2', 'status' => 'supported', 'deprecated' => false, 'versionHeader' => 'API-Version'],
            $document['x-api-version']
        );
        $this->assertSame('3.1.0', $document['openapi']);
    }//end testASupportedVersionKeepsOnlyItsPathsAndIsStamped()

    public function testADeprecatedVersionMarksEveryOperationButLeavesTheRest(): void
    {
        $version = ApiVersion::fromArray(['id' => '1', 'status' => 'deprecated', 'sunset' => '2027-01-01', 'successor' => '2']);

        $paths = $this->service(['paths' => self::paths()])->documentFor($version)['paths'];

        $this->assertTrue($paths['/api/objects']['get']['deprecated']);
        $this->assertTrue($paths['/api/objects']['post']['deprecated']);
        $this->assertSame([['name' => 'x']], $paths['/api/objects']['parameters']);
        $this->assertSame('not-an-operation-array', $paths['/api/objects/{id}']['delete']);
        $this->assertTrue($paths['/api/objects/{id}']['patch']['deprecated']);
        $this->assertSame('not-a-path-item', $paths['/api/objects/broken']);
    }//end testADeprecatedVersionMarksEveryOperationButLeavesTheRest()

    public function testADocumentWithoutPathsGetsAnEmptyPathMap(): void
    {
        $version = ApiVersion::fromArray(['id' => '1', 'status' => 'supported']);

        $this->assertSame([], $this->service(['openapi' => '3.1.0'])->documentFor($version)['paths']);
    }//end testADocumentWithoutPathsGetsAnEmptyPathMap()
}//end class
