<?php

/**
 * Behaviour tests for ApiCapabilitiesService: public and session capabilities, limits and byte sizes.
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

use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\SecurityService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersion
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle
 */
class ApiCapabilitiesServiceBehaviourTest extends TestCase
{
    /** @var ApiVersionCatalogue&MockObject */
    private ApiVersionCatalogue $catalogue;

    /** @var IAppConfig&MockObject */
    private IAppConfig $config;

    protected function setUp(): void
    {
        $one   = ApiVersion::fromArray(['id' => '1', 'status' => 'deprecated', 'sunset' => '2027-01-01', 'successor' => '2']);
        $two   = ApiVersion::fromArray(['id' => '2', 'status' => 'supported']);
        $three = ApiVersion::fromArray(['id' => '0', 'status' => 'withdrawn', 'successor' => '2']);
        $this->catalogue = $this->createMock(ApiVersionCatalogue::class);
        $this->catalogue->method('served')->willReturn([$one, $two]);
        $this->catalogue->method('all')->willReturn(['0' => $three, '1' => $one, '2' => $two]);
        $this->catalogue->method('current')->willReturn($two);
        $this->config = $this->createMock(IAppConfig::class);
    }//end setUp()

    private function service(): ApiCapabilitiesService
    {
        $security = $this->createMock(SecurityService::class);
        $security->method('describeAuthRateLimit')->willReturn(['attempts' => 5]);
        return new ApiCapabilitiesService($this->catalogue, $security, $this->config);
    }//end service()

    public function testPublicCapabilitiesListEveryVersionButContractsOnlyForServedOnes(): void
    {
        $capabilities = $this->service()->publicCapabilities();

        $this->assertSame('2', $capabilities['currentVersion']);
        $this->assertSame('API-Version', $capabilities['versionHeader']);
        $this->assertSame(['0', '1', '2'], array_column($capabilities['apiVersions'], 'version'));
        $this->assertSame(
            ['1' => '/apps/openregister/api/versions/1/oas', '2' => '/apps/openregister/api/versions/2/oas'],
            $capabilities['contracts']
        );
        $this->assertSame(
            ['default' => QueryHandler::DEFAULT_PAGE_SIZE, 'maximum' => QueryHandler::MAX_PAGE_SIZE],
            $capabilities['limits']['pageSize']
        );
        $this->assertSame(['attempts' => 5], $capabilities['limits']['authentication']);
        $this->assertArrayNotHasKey('features', $capabilities);
    }//end testPublicCapabilitiesListEveryVersionButContractsOnlyForServedOnes()

    public function testSessionCapabilitiesAddSwitchesWithDefaultsForUnreadableOnesAndRejections(): void
    {
        $this->config->method('getValueBool')->willReturnCallback(
            static function (string $app, string $key, bool $default): bool {
                if ($key === 'flow_audit_enabled') {
                    throw new RuntimeException('type mismatch');
                }

                return $key === 'enforce_default_closed' ? true : $default;
            }
        );
        $this->catalogue->method('rejections')->willReturn([['id' => 'x', 'reason' => 'bad']]);

        $capabilities = $this->service()->sessionCapabilities();

        $this->assertSame(
            [
                'enforceDefaultClosed'  => true,
                'rbacInheritFromPublic' => true,
                'allowExternalSchemas'  => false,
                'flowAudit'             => false,
                'flowKillSwitch'        => false,
            ],
            $capabilities['features']
        );
        $this->assertSame([['id' => 'x', 'reason' => 'bad']], $capabilities['versionDeclarationRejections']);
    }//end testSessionCapabilitiesAddSwitchesWithDefaultsForUnreadableOnesAndRejections()

    public function testSessionCapabilitiesWithoutRejectionsLeaveTheKeyOut(): void
    {
        $this->config->method('getValueBool')->willReturnArgument(2);
        $this->catalogue->method('rejections')->willReturn([]);

        $this->assertArrayNotHasKey('versionDeclarationRejections', $this->service()->sessionCapabilities());
    }//end testSessionCapabilitiesWithoutRejectionsLeaveTheKeyOut()

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function sizeProvider(): array
    {
        return [
            'empty'      => ['  ', null],
            'garbage'    => ['lots', null],
            'bytes'      => ['512', 512],
            'kilobytes'  => ['8K', 8192],
            'megabytes'  => ['2 m', 2097152],
            'gigabytes'  => ['1G', 1073741824],
            'unlimited'  => ['-1', -1],
            'odd suffix' => ['10X', 10],
        ];
    }//end sizeProvider()

    #[DataProvider('sizeProvider')]
    public function testToBytesReadsPhpIniSizes(string $value, ?int $bytes): void
    {
        $this->assertSame($bytes, ApiCapabilitiesService::toBytes($value));
    }//end testToBytesReadsPhpIniSizes()

    public function testTheUploadCeilingIsTheSmallestPositiveIniLimit(): void
    {
        $limits = array_filter(
            [ApiCapabilitiesService::toBytes((string) ini_get('upload_max_filesize')), ApiCapabilitiesService::toBytes((string) ini_get('post_max_size'))],
            static fn(?int $b): bool => $b !== null && $b > 0
        );

        $ceiling = $this->service()->uploadCeilingBytes();

        if ($limits === []) {
            $this->assertNull($ceiling);
            return;
        }

        $this->assertContains($ceiling, $limits);
        foreach ($limits as $limit) {
            $this->assertLessThanOrEqual($limit, $ceiling);
        }
    }//end testTheUploadCeilingIsTheSmallestPositiveIniLimit()
}//end class
