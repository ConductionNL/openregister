<?php

/**
 * SemanticRoleHandler with the property shapes a decoded schema can carry:
 * stdClass properties, help as an object map, blank and non-text annotations,
 * and the first holder of a duplicated role winning in roles().
 *
 * SemanticRoleHandlerTest covers array-shaped properties and language fallback.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\SemanticRoleHandler
 */
class SemanticRoleHandlerShapesTest extends TestCase {

	public function testObjectPropertiesAreReadLikeArrays(): void {
		$properties = [
			'name' => (object)['type' => 'string', 'x-openregister-role' => ' title '],
			'owner' => (object)['type' => 'string', 'x-openregister-role' => 'assignee'],
		];

		$this->assertSame(['title' => 'name', 'assignee' => 'owner'], (new SemanticRoleHandler())->roles($properties));
	}

	public function testTheFirstHolderOfADuplicatedRoleIsUsedAndBlankOrUnknownRolesAreIgnored(): void {
		$properties = [
			'a' => ['x-openregister-role' => 'status'],
			'b' => ['x-openregister-role' => 'status'],
			'c' => ['x-openregister-role' => '   '],
			'd' => ['x-openregister-role' => 'owner'],
			'e' => ['x-openregister-role' => 5],
			'f' => 'not a property',
		];

		$this->assertSame(['status' => 'a'], (new SemanticRoleHandler())->roles($properties));
	}

	public function testBlankAndNonStringRolesAreNotViolations(): void {
		$properties = ['c' => ['x-openregister-role' => ' '], 'e' => ['x-openregister-role' => ['title']], 'f' => null];

		$this->assertSame([], (new SemanticRoleHandler())->violations($properties));
	}

	public function testAnInventedRoleAndADuplicateAreBothReportedInventedFirst(): void {
		$violations = (new SemanticRoleHandler())->violations([
			'a' => ['x-openregister-role' => 'term'],
			'b' => ['x-openregister-role' => 'colour'],
			'c' => ['x-openregister-role' => 'term'],
		]);

		$this->assertSame(
			[
				'The property "b" declares the role "colour", which is not one of: title, status, assignee, term.',
				'The properties "a" and "c" both declare the role "term", and a schema may declare each role once.',
			],
			$violations
		);
	}

	public function testHelpCanBeAnObjectMapOrAPlainString(): void {
		$handler = new SemanticRoleHandler();

		$this->assertSame('Uitleg', $handler->helpTextOf(['x-openregister-help' => (object)['nl' => ' Uitleg ', 'en' => 'Help']], 'nl'));
		$this->assertSame('Plain', $handler->helpTextOf((object)['x-openregister-help' => '  Plain  '], 'de'));
	}

	public function testBlankOrNonTextHelpIsNoHelp(): void {
		$handler = new SemanticRoleHandler();

		$this->assertNull($handler->helpTextOf(['x-openregister-help' => '   '], 'nl'));
		$this->assertNull($handler->helpTextOf(['x-openregister-help' => 42], 'nl'));
		$this->assertNull($handler->helpTextOf(['x-openregister-help' => ['nl' => ' ', 'en' => 3]], 'nl'));
		$this->assertNull($handler->helpTextOf('not a property', 'nl'));
	}

	public function testHelpTextsListsOnlyPropertiesThatHaveHelp(): void {
		$properties = [
			'a' => ['x-openregister-help' => ['fr' => 'Aide']],
			'b' => ['type' => 'string'],
			'c' => ['x-openregister-help' => 'Zelf'],
		];

		$this->assertSame(['a' => 'Aide', 'c' => 'Zelf'], (new SemanticRoleHandler())->helpTexts($properties, 'nl-BE'));
	}
}
