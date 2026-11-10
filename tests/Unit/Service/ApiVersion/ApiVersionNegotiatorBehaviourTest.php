<?php

/**
 * Behaviour tests for ApiVersionNegotiator: where a requested version is read from, in which order.
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

use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiator
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersionNegotiation
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersion
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle
 */
class ApiVersionNegotiatorBehaviourTest extends TestCase
{
    /** @var ApiVersionCatalogue&MockObject */
    private ApiVersionCatalogue $catalogue;

    private ApiVersion $current;

    private ApiVersion $two;

    protected function setUp(): void
    {
        $this->current   = ApiVersion::fromArray(['id' => '1', 'status' => 'supported']);
        $this->two       = ApiVersion::fromArray(['id' => '2', 'status' => 'supported']);
        $this->catalogue = $this->createMock(ApiVersionCatalogue::class);
        $this->catalogue->method('current')->willReturn($this->current);
        $this->catalogue->method('get')->willReturnCallback(
            fn(string $id): ?ApiVersion => ['1' => $this->current, '2' => $this->two][$id] ?? null
        );
    }//end setUp()

    private function request(string $versionHeader, string $accept, mixed $path): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn(string $name): string => ['API-Version' => $versionHeader, 'Accept' => $accept][$name] ?? ''
        );
        $request->method('getPathInfo')->willReturn($path);
        return $request;
    }//end request()

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed, 3: ?string, 4: string}>
     */
    public static function requestProvider(): array
    {
        return [
            'nothing asked'           => ['', '', '/index.php/apps/openregister/api/objects', null, 'default'],
            'path info false'         => ['', '', false, null, 'default'],
            'header wins over all'    => [' v2 ', 'application/json; version=1', '/api/v1/objects', '2', 'header'],
            'header kept verbatim'    => ['two', '', '', 'two', 'header'],
            'accept parameter'        => ['', 'application/json;Version="v2"', '/api/v1/x', '2', 'accept'],
            'accept without version'  => ['', 'application/json', '/api/openregister/v2/objects', '2', 'path'],
            'path with app segment'   => ['', '', '/api/openregister/v2', '2', 'path'],
            'path not versioned'      => ['', '', '/api/objects/v2x', null, 'default'],
        ];
    }//end requestProvider()

    #[DataProvider('requestProvider')]
    public function testTheRequestedVersionIsReadFromHeaderThenAcceptThenPath(
        string $header,
        string $accept,
        mixed $path,
        ?string $requested,
        string $source
    ): void {
        $negotiation = (new ApiVersionNegotiator($this->catalogue))->negotiate($this->request($header, $accept, $path));

        $this->assertSame($requested, $negotiation->requestedIdentifier());
        $this->assertSame($source, $negotiation->source);
    }//end testTheRequestedVersionIsReadFromHeaderThenAcceptThenPath()

    public function testAnUnaskedRequestGetsTheCurrentVersionAndAnAskedOneItsOwn(): void
    {
        $negotiator = new ApiVersionNegotiator($this->catalogue);

        $this->assertSame($this->current, $negotiator->negotiate($this->request('', '', ''))->answeringVersion());
        $this->assertSame($this->two, $negotiator->negotiate($this->request('2', '', ''))->answeringVersion());
        $this->assertNull($negotiator->negotiate($this->request('9', '', ''))->answeringVersion());
    }//end testAnUnaskedRequestGetsTheCurrentVersionAndAnAskedOneItsOwn()

    public function testAcceptableIdentifiersAreTheServedOnes(): void
    {
        $this->catalogue->method('servedIdentifiers')->willReturn(['1', '2']);

        $this->assertSame(['1', '2'], (new ApiVersionNegotiator($this->catalogue))->acceptableIdentifiers());
    }//end testAcceptableIdentifiersAreTheServedOnes()
}//end class
