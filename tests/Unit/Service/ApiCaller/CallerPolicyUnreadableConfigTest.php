<?php

/**
 * Behaviour tests for CallerPolicy when the administered maps cannot be read or are blank.
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

use OCA\OpenRegister\Service\ApiCaller\CallerPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An unreadable map is treated as not configured, exactly like an empty one;
 * the paired test shows a readable map does restrict.
 *
 * @covers \OCA\OpenRegister\Service\ApiCaller\CallerPolicy
 * @uses   \OCA\OpenRegister\Service\ApiCaller\IpRange
 */
class CallerPolicyUnreadableConfigTest extends TestCase
{
    public function testAnUnreadableMapImposesNoLimitAndNoBinding(): void
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willThrowException(new RuntimeException('lazy config not loaded'));
        $policy = new CallerPolicy($config);

        $this->assertNull($policy->limitFor('alice'));
        $this->assertNull($policy->addressesFor('alice'));
        $this->assertTrue($policy->allowsAddress('alice', '203.0.113.9'));
    }//end testAnUnreadableMapImposesNoLimitAndNoBinding()

    public function testAReadableBindingRestrictsTheSameCaller(): void
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key): string => $key === CallerPolicy::ADDRESSES_KEY ? '{"alice":["10.0.0.0/8"]}' : '   '
        );
        $policy = new CallerPolicy($config);

        $this->assertNull($policy->limitFor('alice'));
        $this->assertFalse($policy->allowsAddress('alice', '203.0.113.9'));
        $this->assertTrue($policy->allowsAddress('alice', '10.1.2.3'));
    }//end testAReadableBindingRestrictsTheSameCaller()
}//end class
