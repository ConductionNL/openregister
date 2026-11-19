<?php

/**
 * ArchivalDeclarationValidatorsTest
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

use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidatorInterface;
use OCA\OpenRegister\Service\Archival\ElementMappingValidatorInterface;
use OCA\OpenRegister\Service\Schemas\ArchivalDeclarationValidators;
use PHPUnit\Framework\TestCase;

/**
 * Each call builds a fresh validator, typed against its interface.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\ArchivalDeclarationValidators
 */
class ArchivalDeclarationValidatorsTest extends TestCase {

	public function testItBuildsAFreshElementMappingValidator(): void {
		$first = ArchivalDeclarationValidators::elementMapping();

		$this->assertInstanceOf(ElementMappingValidatorInterface::class, $first);
		$this->assertNotSame($first, ArchivalDeclarationValidators::elementMapping());
	}//end testItBuildsAFreshElementMappingValidator()

	public function testItBuildsAFreshArchivalValidator(): void {
		$first = ArchivalDeclarationValidators::archival();

		$this->assertInstanceOf(ArchivalAnnotationValidatorInterface::class, $first);
		$this->assertNotSame($first, ArchivalDeclarationValidators::archival());
	}//end testItBuildsAFreshArchivalValidator()
}//end class
