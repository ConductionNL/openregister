<?php

/**
 * Behaviour tests for ApiVersion: declaration parsing, lifecycle rules, paths, dates and publication.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\ApiVersion\ApiVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiVersion\ApiVersion
 * @uses   \OCA\OpenRegister\Service\ApiVersion\ApiVersionLifecycle
 */
class ApiVersionBehaviourTest extends TestCase
{
    public function testAFullDeclarationIsNormalisedAndPublished(): void
    {
        $version = ApiVersion::fromArray(
            [
                'id'           => ' 2 ',
                'status'       => ' Deprecated ',
                'deprecatedOn' => '2026-03-01T10:00:00Z',
                'sunset'       => '2027-01-31',
                'successor'    => 3,
                'pathPrefixes' => ['api/objects/', '', 5, '/api/objects', '/api/registers'],
                'description'  => '  Second contract ',
            ]
        );

        $this->assertSame(
            [
                'version'      => '2',
                'status'       => 'deprecated',
                'description'  => 'Second contract',
                'deprecatedOn' => '2026-03-01',
                'sunset'       => '2027-01-31',
                'successor'    => '3',
            ],
            $version->jsonSerialize()
        );
        $this->assertSame(['/api/objects', '/api/registers'], $version->pathPrefixes);
        $this->assertSame(['2', '3'], [$version->identifier(), $version->successorIdentifier()]);
        $this->assertSame('Sun, 01 Mar 2026 00:00:00 GMT', $version->deprecationHttpDate());
        $this->assertSame('Sun, 31 Jan 2027 00:00:00 GMT', $version->sunsetHttpDate());
        $this->assertTrue($version->isServed());
        $this->assertTrue($version->isDeprecated());
    }//end testAFullDeclarationIsNormalisedAndPublished()

    public function testAMinimalSupportedVersionServesEveryPathAndPublishesOnlyItsCore(): void
    {
        $version = ApiVersion::fromArray(['id' => '1', 'status' => 'supported', 'deprecatedOn' => '  ']);

        $this->assertSame(['version' => '1', 'status' => 'supported'], $version->jsonSerialize());
        $this->assertTrue($version->servesPath('anything/at/all'));
        $this->assertNull($version->deprecationHttpDate());
        $this->assertNull($version->successorIdentifier());
        $this->assertFalse($version->isDeprecated());
    }//end testAMinimalSupportedVersionServesEveryPathAndPublishesOnlyItsCore()

    public function testServesPathMatchesOnlyItsPrefixes(): void
    {
        $version = ApiVersion::fromArray(['id' => '2', 'status' => 'supported', 'pathPrefixes' => ['/api/objects']]);

        $this->assertTrue($version->servesPath('api/objects/5'));
        $this->assertTrue($version->servesPath('/api/objects'));
        $this->assertFalse($version->servesPath('/api/registers'));
    }//end testServesPathMatchesOnlyItsPrefixes()

    public function testAWithdrawnVersionIsNotServed(): void
    {
        $version = ApiVersion::fromArray(['id' => '1', 'status' => 'withdrawn', 'successor' => '2']);

        $this->assertFalse($version->isServed());
        $this->assertFalse($version->isDeprecated());
    }//end testAWithdrawnVersionIsNotServed()

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function refusedDeclarationProvider(): array
    {
        return [
            'prefixes not a list'       => [['id' => '1', 'status' => 'supported', 'pathPrefixes' => '/api'], 'pathPrefixes must be a list of path prefixes.'],
            'no usable prefix'          => [['id' => '1', 'status' => 'supported', 'pathPrefixes' => ['', ' ', 3]], 'An API version must declare at least one path prefix.'],
            'id not digits'             => [['id' => 'v2', 'status' => 'supported'], 'API version id must be one to three digits, got "v2".'],
            'missing id'                => [['status' => 'supported'], 'API version id must be one to three digits, got "".'],
            'bad successor'             => [['id' => '1', 'status' => 'withdrawn', 'successor' => 'next'], 'API version successor must be one to three digits, got "next".'],
            'unknown status'            => [['id' => '1', 'status' => 'beta'], 'API version status must be one of supported, deprecated, withdrawn, got "beta".'],
            'impossible date'           => [['id' => '1', 'status' => 'supported', 'sunset' => '2026-02-30'], 'API version sunset must be an ISO 8601 date, got "2026-02-30".'],
            'not a date'                => [['id' => '1', 'status' => 'supported', 'deprecatedOn' => 'soon'], 'API version deprecatedOn must be an ISO 8601 date, got "soon".'],
            'deprecated without sunset' => [['id' => '4', 'status' => 'deprecated'], 'Version 4 is deprecated without a sunset date; a deprecation nobody can plan around is a removal.'],
            'withdrawn without next'    => [['id' => '4', 'status' => 'withdrawn'], 'Version 4 is withdrawn without a successor; a refusal that names no way forward is an outage.'],
            'succeeds itself'           => [['id' => '4', 'status' => 'withdrawn', 'successor' => '4'], 'Version 4 cannot succeed itself.'],
        ];
    }//end refusedDeclarationProvider()

    #[DataProvider('refusedDeclarationProvider')]
    public function testAnIncompleteOrMalformedDeclarationIsRefused(array $declaration, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ApiVersion::fromArray($declaration);
    }//end testAnIncompleteOrMalformedDeclarationIsRefused()

    public function testToHttpDateIgnoresNullAndUnparseableInput(): void
    {
        $this->assertNull(ApiVersion::toHttpDate(null));
        $this->assertNull(ApiVersion::toHttpDate('31-01-2027'));
        $this->assertSame('Fri, 01 Jan 2027 00:00:00 GMT', ApiVersion::toHttpDate('2027-01-01'));
    }//end testToHttpDateIgnoresNullAndUnparseableInput()

    public function testNonStringValuesAreTreatedAsAbsent(): void
    {
        $version = ApiVersion::fromArray(['id' => 7, 'status' => 'supported', 'description' => ['x'], 'sunset' => false]);

        $this->assertSame(['version' => '7', 'status' => 'supported'], $version->jsonSerialize());
    }//end testNonStringValuesAreTreatedAsAbsent()
}//end class
