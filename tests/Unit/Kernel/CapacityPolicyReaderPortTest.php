<?php

/**
 * A relation decision reads the capacity policy through a Kernel port; Configuration answers it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\CapacityPolicy;
use OCA\OpenRegister\Kernel\Contracts\CapacityPolicyReaderInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Decision\RelationDecisionService;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The port is bound to the settings handler that holds the policy, and the decision service types against it.
 *
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Kernel\Contracts\CapacityPolicy
 */
final class CapacityPolicyReaderPortTest extends TestCase {

	public function testConfigurationBindsThePortToTheFileSettingsHandler(): void {
		$this->assertSame(FileSettingsHandler::class, ContractBindings::BINDINGS[CapacityPolicyReaderInterface::class]);
		$this->assertTrue(is_subclass_of(FileSettingsHandler::class, CapacityPolicyReaderInterface::class));
	}

	public function testTheDecisionServiceTypesAgainstThePortAndKeepsItOptional(): void {
		$parameter = null;
		foreach ((new ReflectionClass(RelationDecisionService::class))->getConstructor()?->getParameters() ?? [] as $candidate) {
			if ($candidate->getName() === 'fileSettings') {
				$parameter = $candidate;
			}
		}

		$this->assertNotNull($parameter);
		$type = $parameter->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(CapacityPolicyReaderInterface::class, $type->getName());
		$this->assertTrue($parameter->allowsNull());
		$this->assertNull($parameter->getDefaultValue());
	}

	public function testThePortAnswersTheShippedDefaultWhenNothingIsStored(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		$reader = new FileSettingsHandler(appConfig: $appConfig);

		$this->assertSame(CapacityPolicy::fromStored(stored: null)->rules(), $reader->capacityPolicy()->rules());
	}
}//end class
