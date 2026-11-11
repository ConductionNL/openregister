<?php

/**
 * Behaviour tests for IpRange::matches() with a malformed network part.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ApiCaller
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

namespace OCA\OpenRegister\Tests\Unit\Service\ApiCaller;

use OCA\OpenRegister\Service\ApiCaller\IpRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ApiCaller\IpRange
 */
class IpRangeMalformedNetworkTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function rangeProvider(): array
    {
        return [
            'hostname as network'  => ['10.0.0.1', 'intranet/8', false],
            'empty network'        => ['10.0.0.1', '/8', false],
            'valid network'        => ['10.0.0.1', ' 10.0.0.0/8 ', true],
            'mapped v4 in network' => ['10.0.0.1', '::ffff:10.0.0.0/8', true],
        ];
    }//end rangeProvider()

    #[DataProvider('rangeProvider')]
    public function testOnlyAParseableNetworkCanMatch(string $address, string $range, bool $matches): void
    {
        $this->assertSame($matches, IpRange::matches($address, $range));
    }//end testOnlyAParseableNetworkCanMatch()
}//end class
