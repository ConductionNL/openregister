<?php

/**
 * Golden characterisation test for ConfigurationKeyRegistry::refusalFor().
 *
 * Pins, on the code before refusalFor() was split into named checks, the exact
 * refusal for each way an address can be refused (unknown layer, reserved key,
 * undeclared key, instance setting at a lower layer), the order the checks run
 * in, and the addresses that are allowed (null).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\ConfigurationDeployment;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use PHPUnit\Framework\TestCase;

final class ConfigurationKeyRefusalGoldenTest extends TestCase {

	public function testEveryRefusalAndItsOrderIsPinned(): void {
		$registry = new ConfigurationKeyRegistry();
		$cases    = [
			['galaxy', 'rbac', 'unknown layer "galaxy"'],
			['galaxy', 'configuration_four_eyes', 'unknown layer "galaxy"'],
			[
				ConfigurationLayer::REGISTER,
				'configuration_four_eyes',
				'"configuration_four_eyes" is reserved: the approval requirement governs deployments and cannot be changed by one',
			],
			[
				ConfigurationLayer::INSTANCE,
				'configuration_drafting',
				'"configuration_drafting" is reserved: the drafting switch governs deployments and cannot be changed by one',
			],
			[ConfigurationLayer::INSTANCE, 'nope', '"nope" is not a declared configuration key and matches no open prefix'],
			[ConfigurationLayer::REGISTER, 'nope', '"nope" is not a declared configuration key and matches no open prefix'],
			[ConfigurationLayer::REGISTER, 'rbac', '"rbac" is an instance setting and cannot be set at the register layer'],
			[ConfigurationLayer::BUNDLE, 'retention', '"retention" is an instance setting and cannot be set at the bundle layer'],
			[ConfigurationLayer::INSTANCE, 'rbac', null],
			[ConfigurationLayer::REGISTER, 'register.anything', null],
			[ConfigurationLayer::INSTANCE, 'register.anything', null],
		];
		foreach ($cases as [$layer, $key, $expected]) {
			$this->assertSame($expected, $registry->refusalFor(layer: $layer, configKey: $key), $layer . ' ' . $key);
		}
	}
}//end class
