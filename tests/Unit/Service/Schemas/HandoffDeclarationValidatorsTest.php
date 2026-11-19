<?php

/**
 * HandoffDeclarationValidatorsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Service\Handoff\HandoffAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Handoff\HandoffContractBindingValidatorInterface;
use OCA\OpenRegister\Service\Schemas\HandoffDeclarationValidators;
use PHPUnit\Framework\TestCase;

/**
 * Each call builds a fresh validator, typed against its interface.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\HandoffDeclarationValidators
 */
class HandoffDeclarationValidatorsTest extends TestCase {

	public function testItBuildsAFreshHandoffValidator(): void {
		$first = HandoffDeclarationValidators::handoff();

		$this->assertInstanceOf(HandoffAnnotationValidatorInterface::class, $first);
		$this->assertNotSame($first, HandoffDeclarationValidators::handoff());
	}//end testItBuildsAFreshHandoffValidator()

	public function testItBuildsAFreshContractBindingValidator(): void {
		$first = HandoffDeclarationValidators::handoffContractBinding();

		$this->assertInstanceOf(HandoffContractBindingValidatorInterface::class, $first);
		$this->assertNotSame($first, HandoffDeclarationValidators::handoffContractBinding());
	}//end testItBuildsAFreshContractBindingValidator()
}//end class
