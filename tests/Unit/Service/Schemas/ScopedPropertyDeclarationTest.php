<?php

/**
 * A property scoped to a team, and the enforcement that makes the word mean it.
 *
 * 🔴 THE FAILURE THIS SUITE EXISTS FOR IS AN INERT DECLARATION. An author
 * writes `scope: team-a`, the key validates, the vocabulary publishes it, and
 * the field stays readable by everybody. They believe the field is team-scoped
 * PRECISELY BECAUSE the platform accepted the word. That is why `scope` was
 * deliberately not shipped without its enforcement, and why the tests below
 * assert the enforcement rather than the key.
 *
 * 🔑 THE SHARPEST ONE IS `testAScopeAloneTripsTheShortCircuit`. Compiling a
 * scope into an authorization block is not enough on its own, because
 * `hasPropertyAuthorization()` is a short-circuit that five call sites use to
 * skip property filtering entirely. Without it the compiler would be correct
 * and never called.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyDeclaration;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyException;
use PHPUnit\Framework\TestCase;

/**
 * `scope` and what it compiles into.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\ScopedPropertyDeclaration
 * @covers \OCA\OpenRegister\Db\Schema::getPropertyAuthorization
 * @covers \OCA\OpenRegister\Db\Schema::hasPropertyAuthorization
 */
class ScopedPropertyDeclarationTest extends TestCase {

	/**
	 * A schema with one property.
	 *
	 * @param array<string, mixed> $property The property configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithProperty(array $property): Schema {
		$schema = new Schema();
		$schema->setProperties(['salary' => $property]);

		return $schema;
	}//end schemaWithProperty()

	/**
	 * A property with no scope is left entirely alone.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutAScopeIsUnchanged(): void {
		$this->assertNull(ScopedPropertyDeclaration::fromProperty(['type' => 'string']));
		$this->assertNull($this->schemaWithProperty(['type' => 'string'])->getPropertyAuthorization('salary'));
		$this->assertFalse($this->schemaWithProperty(['type' => 'string'])->hasPropertyAuthorization());
	}//end testAPropertyWithoutAScopeIsUnchanged()

	/**
	 * 🔴 A SCOPE ALONE TRIPS THE SHORT-CIRCUIT THAT RUNS THE FILTERING.
	 *
	 * `hasPropertyAuthorization()` gates property filtering on the render,
	 * query, export and OAS paths. If a scope did not answer it, the compiled
	 * authorization below would be correct and never consulted, and the field
	 * would go out to everybody while the schema said it was team-only.
	 *
	 * @return void
	 */
	public function testAScopeAloneTripsTheShortCircuit(): void {
		$schema = $this->schemaWithProperty(['type' => 'string', 'scope' => 'team-a']);

		$this->assertTrue(
			$schema->hasPropertyAuthorization(),
			'Without this the property filter never runs and the scope is decorative.'
		);
		$this->assertArrayHasKey('salary', $schema->getPropertiesWithAuthorization());
	}//end testAScopeAloneTripsTheShortCircuit()

	/**
	 * A scope compiles into the authorization the existing enforcement reads.
	 *
	 * Read is in the block on purpose. A scope that governed only writes would
	 * leave the value on screen for everyone, which is the inert failure with
	 * extra steps.
	 *
	 * @return void
	 */
	public function testAScopeCompilesIntoReadAndUpdateAuthorization(): void {
		$authorization = $this->schemaWithProperty(
			['type' => 'string', 'scope' => 'team-a']
		)->getPropertyAuthorization('salary');

		$this->assertSame(['read' => ['team-a'], 'update' => ['team-a']], $authorization);
	}//end testAScopeCompilesIntoReadAndUpdateAuthorization()

	/**
	 * An explicit authorization block still wins, and is not merged into.
	 *
	 * @return void
	 */
	public function testAnExplicitAuthorizationBlockIsUntouched(): void {
		$authorization = $this->schemaWithProperty([
			'type' => 'string',
			'authorization' => ['read' => ['hr']],
		])->getPropertyAuthorization('salary');

		$this->assertSame(['read' => ['hr']], $authorization);
	}//end testAnExplicitAuthorizationBlockIsUntouched()

	/**
	 * Declaring both is refused rather than merged.
	 *
	 * Two sources for one question, where the quiet resolution is whichever the
	 * code happens to read first.
	 *
	 * @return void
	 */
	public function testDeclaringBothAScopeAndAnAuthorizationIsRefused(): void {
		$this->expectException(ScopedPropertyException::class);

		ScopedPropertyDeclaration::assert(
			property: [
				'type' => 'string',
				'scope' => 'team-a',
				'authorization' => ['read' => ['hr']],
			],
			path: 'salary'
		);
	}//end testDeclaringBothAScopeAndAnAuthorizationIsRefused()

	/**
	 * An empty or non-string scope is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyScopeIsRefused(): void {
		$this->expectException(ScopedPropertyException::class);

		ScopedPropertyDeclaration::assert(property: ['scope' => '   '], path: 'salary');
	}//end testAnEmptyScopeIsRefused()

	/**
	 * A scope that cannot name a group is refused.
	 *
	 * A name no group can carry matches nobody, so accepting it publishes a
	 * scope that silently denies everybody: the opposite failure, equally
	 * quiet.
	 *
	 * @return void
	 */
	public function testAScopeThatCannotNameAGroupIsRefused(): void {
		$this->expectException(ScopedPropertyException::class);

		ScopedPropertyDeclaration::assert(property: ['scope' => 'team/a;drop'], path: 'salary');
	}//end testAScopeThatCannotNameAGroupIsRefused()

	/**
	 * A scope is trimmed rather than refused for surrounding space.
	 *
	 * @return void
	 */
	public function testASurroundedScopeIsTrimmed(): void {
		$this->assertSame('team-a', ScopedPropertyDeclaration::fromProperty(['scope' => '  team-a  ']));
	}//end testASurroundedScopeIsTrimmed()

	/**
	 * Every action the declaration claims to govern is in the compiled block.
	 *
	 * Derived from the constant rather than restated, so the two cannot drift.
	 *
	 * @return void
	 */
	public function testEveryDeclaredActionIsCompiled(): void {
		$block = ScopedPropertyDeclaration::authorizationFor('team-a');

		foreach (ScopedPropertyDeclaration::ACTIONS as $action) {
			$this->assertSame(['team-a'], $block[$action] ?? null, $action . ' is declared but not compiled');
		}

		$this->assertSame(count(ScopedPropertyDeclaration::ACTIONS), count($block));
	}//end testEveryDeclaredActionIsCompiled()
}//end class
