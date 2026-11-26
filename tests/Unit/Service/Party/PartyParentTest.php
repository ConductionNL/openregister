<?php

/**
 * The party model answers the tree walk's party parent question: the parent
 * property and the declared depth, and nothing when the schema declares no
 * party or no parent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Party
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Party;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\PartyParentInterface;
use OCA\OpenRegister\Service\Flow\FlowWiring;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyParent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Party\PartyParent
 * @uses   \OCA\OpenRegister\Service\Party\PartyDefinition
 */
final class PartyParentTest extends TestCase {

	private function schema(mixed $party): Schema {
		$schema = new Schema();
		$configuration = [];
		if ($party !== null) {
			$configuration['x-openregister-party'] = $party;
		}

		$schema->setConfiguration($configuration);
		return $schema;
	}

	public function testASchemaWithoutAPartyAnnotationHasNoParent(): void {
		self::assertNull((new PartyParent())->parentOf(schema: $this->schema(party: null)));
	}

	public function testAPartyWithoutAParentPropertyHasNoParent(): void {
		self::assertNull((new PartyParent())->parentOf(schema: $this->schema(party: ['kind' => 'organisation', 'nameProperty' => 'naam'])));
	}

	public function testTheParentPropertyAndTheDeclaredDepthAreAnswered(): void {
		$answer = (new PartyParent())->parentOf(schema: $this->schema(party: ['kind' => 'organisation', 'parentProperty' => 'moeder', 'maxDepth' => 40]));

		// The depth is the annotation's own; the tree walk applies its ceiling.
		self::assertSame(['property' => 'moeder', 'maxDepth' => 40, 'nameProperty' => null], $answer);
	}

	public function testTheDeclaredNamePropertyIsAnswered(): void {
		$party  = ['kind' => 'organisation', 'parentProperty' => 'moeder', 'nameProperty' => 'handelsnaam'];
		$answer = (new PartyParent())->parentOf(schema: $this->schema(party: $party));

		// A tree of parties names its nodes by it when an object has no name of its own.
		self::assertSame(expected: 'handelsnaam', actual: $answer['nameProperty']);
	}

	public function testAMissingDepthFallsBackToThePartyModelsDefault(): void {
		$answer = (new PartyParent())->parentOf(schema: $this->schema(party: ['kind' => 'organisation', 'parentProperty' => 'moeder']));

		self::assertSame(['property' => 'moeder', 'maxDepth' => PartyDefinition::DEFAULT_MAX_DEPTH, 'nameProperty' => null], $answer);
	}

	public function testTheProcessesWiringBindsThePort(): void {
		self::assertSame(PartyParent::class, FlowWiring::BINDINGS[PartyParentInterface::class] ?? null);
		self::assertTrue(is_subclass_of(PartyParent::class, PartyParentInterface::class));
	}
}
