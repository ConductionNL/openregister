<?php

/**
 * The team-page declaration a schema makes: when it opts in, what is wrong with it, and which teams an object names.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Reference;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Reference\TeamResourceDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TeamResourceDeclaration.
 *
 * @covers \OCA\OpenRegister\Reference\TeamResourceDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class TeamResourceDeclarationTest extends TestCase {

	/**
	 * A schema with the given configuration.
	 *
	 * @param array<string, mixed>|null $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private static function schemaWith(?array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schemaWith()

	/**
	 * A schema declaring nothing, or nothing usable, stays off team pages.
	 *
	 * @return void
	 */
	public function testASchemaDeclaringNothingStaysOff(): void {
		$this->assertNull(TeamResourceDeclaration::of(schema: self::schemaWith(null)));
		$this->assertNull(TeamResourceDeclaration::of(schema: self::schemaWith(['x-openregister-card' => ['icon' => 'x']])));
		$this->assertNull(TeamResourceDeclaration::of(schema: self::schemaWith([TeamResourceDeclaration::ANNOTATION => 'department'])));
		$this->assertNull(TeamResourceDeclaration::of(schema: self::schemaWith([TeamResourceDeclaration::ANNOTATION => ['teamProperty' => '  ']])));
		$this->assertNull(TeamResourceDeclaration::of(schema: self::schemaWith([TeamResourceDeclaration::ANNOTATION => ['teamProperty' => 5]])));
	}//end testASchemaDeclaringNothingStaysOff()

	/**
	 * A declared team property opts the schema in, trimmed.
	 *
	 * @return void
	 */
	public function testADeclaredTeamPropertyOptsIn(): void {
		$declaration = TeamResourceDeclaration::of(
			schema: self::schemaWith([TeamResourceDeclaration::ANNOTATION => ['teamProperty' => ' afdeling ']])
		);

		$this->assertNotNull($declaration);
		$this->assertSame('afdeling', $declaration->teamProperty);
	}//end testADeclaredTeamPropertyOptsIn()

	/**
	 * An absent block has no faults; a sound one has none either.
	 *
	 * @return void
	 */
	public function testAnAbsentOrSoundBlockHasNoFaults(): void {
		$properties = ['afdeling' => ['type' => 'string']];

		$this->assertSame([], TeamResourceDeclaration::faults(block: null, properties: $properties));
		$this->assertSame([], TeamResourceDeclaration::faults(block: ['teamProperty' => 'afdeling'], properties: $properties));
	}//end testAnAbsentOrSoundBlockHasNoFaults()

	/**
	 * A block that is not an object, names no property, or names an absent one, is faulted with a message naming it.
	 *
	 * @return void
	 */
	public function testAMalformedBlockIsFaulted(): void {
		$properties = ['afdeling' => ['type' => 'string']];

		$this->assertSame(
			['x-openregister-team-resources must be an object.'],
			TeamResourceDeclaration::faults(block: 'afdeling', properties: $properties)
		);
		$this->assertSame(
			['x-openregister-team-resources.teamProperty must name the property holding the owning team.'],
			TeamResourceDeclaration::faults(block: [], properties: $properties)
		);
		$this->assertSame(
			['x-openregister-team-resources.teamProperty "team" is not a property of this schema.'],
			TeamResourceDeclaration::faults(block: ['teamProperty' => 'team'], properties: $properties)
		);
	}//end testAMalformedBlockIsFaulted()

	/**
	 * The owning teams are read from the property: one id, a list, or none.
	 *
	 * @return void
	 */
	public function testTheOwningTeamsAreReadFromTheProperty(): void {
		$declaration = TeamResourceDeclaration::of(
			schema: self::schemaWith([TeamResourceDeclaration::ANNOTATION => ['teamProperty' => 'afdeling']])
		);
		$this->assertNotNull($declaration);

		$this->assertSame(['wijkteam'], $declaration->teamsOf(objectData: ['afdeling' => 'wijkteam']));
		$this->assertSame(['7'], $declaration->teamsOf(objectData: ['afdeling' => 7]));
		$this->assertSame(
			['wijkteam', 'vergunningen'],
			$declaration->teamsOf(objectData: ['afdeling' => ['wijkteam', ' vergunningen ', 'wijkteam', '', null, ['x']]])
		);
		$this->assertSame([], $declaration->teamsOf(objectData: ['afdeling' => '']));
		$this->assertSame([], $declaration->teamsOf(objectData: ['afdeling' => true]));
		$this->assertSame([], $declaration->teamsOf(objectData: ['team' => 'wijkteam']));
	}//end testTheOwningTeamsAreReadFromTheProperty()
}//end class
