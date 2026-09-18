<?php

/**
 * Who may add a scoped property, how many, and what promotion keeps.
 *
 * 🔑 THE GATE IS THE SCOPE, NOT THE ADMIN FLAG. Gating on admin would mean
 * either every team waits on an administrator, which is the friction the
 * feature exists to remove, or administrators are handed out until the flag
 * means nothing. The group that OWNS the scope is the group that may add to it,
 * which is the same answer the read rule gives, so nobody can create a field
 * they would not then be allowed to see.
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

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyException;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `ScopedPropertyGovernance`.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance
 */
class ScopedPropertyGovernanceTest extends TestCase {

	/**
	 * Governance with the given caller and configuration.
	 *
	 * @param string|null   $userId  The caller.
	 * @param array<string> $groups  Their groups.
	 * @param int|null      $ceiling The configured ceiling, or null for the default.
	 *
	 * @return ScopedPropertyGovernance The service.
	 */
	private function governanceFor(
		?string $userId,
		array $groups = [],
		?int $ceiling = null,
	): ScopedPropertyGovernance {
		$session = $this->createMock(IUserSession::class);
		if ($userId === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default) use ($ceiling): int {
				if ($key === ScopedPropertyGovernance::CEILING_KEY && $ceiling !== null) {
					return $ceiling;
				}

				return $default;
			}
		);

		return new ScopedPropertyGovernance($session, $groupManager, $appConfig);
	}//end governanceFor()

	/**
	 * A schema holding the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(11);
		$schema->setProperties($properties);

		return $schema;
	}//end schemaWith()

	/**
	 * A schema with the given number of properties at one scope.
	 *
	 * @param int    $count The number.
	 * @param string $scope The scope.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWithScopedProperties(int $count, string $scope): Schema {
		$properties = [];
		for ($i = 0; $i < $count; $i++) {
			$properties['field' . $i] = ['type' => 'string', 'scope' => $scope];
		}

		return $this->schemaWith($properties);
	}//end schemaWithScopedProperties()

	/**
	 * A member of the scope may add to it.
	 *
	 * @return void
	 */
	public function testAMemberOfTheScopeMayAdd(): void {
		$this->assertTrue($this->governanceFor('alice', ['team-a'])->mayAddAtScope('team-a'));
	}//end testAMemberOfTheScopeMayAdd()

	/**
	 * 🔴 A USER OUTSIDE THE SCOPE IS REFUSED, WHICH IS THE SPEC'S SCENARIO.
	 *
	 * @return void
	 */
	public function testAUserOutsideTheScopeIsRefused(): void {
		$this->assertFalse($this->governanceFor('bob', ['team-b'])->mayAddAtScope('team-a'));

		$this->expectException(ScopedPropertyException::class);
		$this->governanceFor('bob', ['team-b'])->assertMayAddAtScope(scope: 'team-a', path: 'salary');
	}//end testAUserOutsideTheScopeIsRefused()

	/**
	 * An anonymous caller is refused.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->assertFalse($this->governanceFor(null)->mayAddAtScope('team-a'));
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * 🔑 AN ADMIN IS ADMITTED, BUT THE FLAG IS NOT WHAT THE GATE ASKS FOR.
	 *
	 * The flag being sufficient is fine; the flag being REQUIRED is the thing
	 * refused, and the test above is what proves the gate is the scope: a
	 * non-admin member of the scope passes.
	 *
	 * @return void
	 */
	public function testAnAdminIsAdmittedWithoutBeingTheGate(): void {
		$this->assertTrue($this->governanceFor('root', ['admin'])->mayAddAtScope('team-a'));
		$this->assertTrue(
			$this->governanceFor('alice', ['team-a'])->mayAddAtScope('team-a'),
			'A non-admin member must pass, or the gate really is the admin flag.'
		);
	}//end testAnAdminIsAdmittedWithoutBeingTheGate()

	/**
	 * A scope at its ceiling refuses the next property, naming the ceiling.
	 *
	 * "Refused" alone sends the author to an administrator with nothing to say.
	 *
	 * @return void
	 */
	public function testAScopeAtItsCeilingRefusesAndNamesIt(): void {
		$governance = $this->governanceFor('alice', ['team-a'], 3);
		$schema     = $this->schemaWithScopedProperties(3, 'team-a');

		try {
			$governance->assertBelowCeiling(schema: $schema, scope: 'team-a', property: 'salary');
			$this->fail('A scope at its ceiling must refuse the next property.');
		} catch (ScopedPropertyException $e) {
			$this->assertStringContainsString('3', $e->getMessage());
			$this->assertStringContainsString('team-a', $e->getMessage());
		}
	}//end testAScopeAtItsCeilingRefusesAndNamesIt()

	/**
	 * A scope below its ceiling is allowed.
	 *
	 * The control: without it, a method that always throws would pass the test
	 * above.
	 *
	 * @return void
	 */
	public function testAScopeBelowItsCeilingIsAllowed(): void {
		$governance = $this->governanceFor('alice', ['team-a'], 3);

		$governance->assertBelowCeiling(
			schema: $this->schemaWithScopedProperties(2, 'team-a'),
			scope: 'team-a',
			property: 'salary'
		);

		$this->expectNotToPerformAssertions();
	}//end testAScopeBelowItsCeilingIsAllowed()

	/**
	 * Another scope's properties do not count against this one.
	 *
	 * The ceiling is per scope. Counting every scoped property would let one
	 * busy team exhaust the allowance of every other.
	 *
	 * @return void
	 */
	public function testTheCeilingIsPerScope(): void {
		$governance = $this->governanceFor('alice', ['team-a'], 2);

		$this->assertSame(
			1,
			$governance->countAtScope(
				schema: $this->schemaWith([
					'a' => ['scope' => 'team-a'],
					'b' => ['scope' => 'team-b'],
					'c' => ['scope' => 'team-b'],
				]),
				scope: 'team-a'
			)
		);
	}//end testTheCeilingIsPerScope()

	/**
	 * Editing an existing property does not count it twice.
	 *
	 * Without this, a scope at its ceiling could never edit any of the fields
	 * it already has.
	 *
	 * @return void
	 */
	public function testEditingAnExistingPropertyIsNotCountedTwice(): void {
		$governance = $this->governanceFor('alice', ['team-a'], 2);

		$governance->assertBelowCeiling(
			schema: $this->schemaWith([
				'a' => ['scope' => 'team-a'],
				'b' => ['scope' => 'team-a'],
			]),
			scope: 'team-a',
			property: 'b'
		);

		$this->expectNotToPerformAssertions();
	}//end testEditingAnExistingPropertyIsNotCountedTwice()

	/**
	 * A ceiling of zero is treated as one rather than refusing everything.
	 *
	 * Zero would refuse every scoped property while reading like "no limit",
	 * which is the most confusing possible value.
	 *
	 * @return void
	 */
	public function testACeilingOfZeroIsNotTakenLiterally(): void {
		$this->assertSame(1, $this->governanceFor('alice', ['team-a'], 0)->ceiling());
	}//end testACeilingOfZeroIsNotTakenLiterally()

	/**
	 * A scoped property with no values is reported as unused.
	 *
	 * @return void
	 */
	public function testAPropertyWithNoValuesIsReportedUnused(): void {
		$report = $this->governanceFor('alice', ['team-a'])->unusedReport(
			schema: $this->schemaWith(['salary' => ['scope' => 'team-a'], 'name' => ['type' => 'string']]),
			counts: ['salary' => 0],
			asOf: new DateTimeImmutable('2026-09-18')
		);

		$this->assertCount(1, $report, 'Only scoped properties belong in this report.');
		$this->assertSame('salary', $report[0]['property']);
		$this->assertSame('unused', $report[0]['state']);
	}//end testAPropertyWithNoValuesIsReportedUnused()

	/**
	 * 🔴 A PROPERTY WITH NO COUNT IS UNKNOWN, NOT UNUSED.
	 *
	 * Absent evidence is not evidence of absence, and retiring a field on it
	 * would delete data somebody is relying on.
	 *
	 * @return void
	 */
	public function testAPropertyWithNoCountIsUnknownNotUnused(): void {
		$report = $this->governanceFor('alice', ['team-a'])->unusedReport(
			schema: $this->schemaWith(['salary' => ['scope' => 'team-a']]),
			counts: [],
			asOf: new DateTimeImmutable('2026-09-18')
		);

		$this->assertSame('unknown', $report[0]['state']);
		$this->assertNull($report[0]['values']);
	}//end testAPropertyWithNoCountIsUnknownNotUnused()

	/**
	 * A property with values is not reported as unused.
	 *
	 * @return void
	 */
	public function testAPropertyWithValuesIsInUse(): void {
		$report = $this->governanceFor('alice', ['team-a'])->unusedReport(
			schema: $this->schemaWith(['salary' => ['scope' => 'team-a']]),
			counts: ['salary' => 40],
			asOf: new DateTimeImmutable('2026-09-18')
		);

		$this->assertSame('in use', $report[0]['state']);
	}//end testAPropertyWithValuesIsInUse()

	/**
	 * 🔴 PROMOTION DROPS THE SCOPE AND CHANGES NOTHING ELSE.
	 *
	 * That is what keeps the forty values: they live on the objects keyed by
	 * the property NAME. Renaming the property, or rebuilding it from a
	 * template, would leave forty objects holding a key nothing reads any more,
	 * and the loss would be silent because the objects would still save.
	 *
	 * @return void
	 */
	public function testPromotionKeepsTheNameAndEverythingElse(): void {
		$promoted = $this->governanceFor('alice', ['team-a'])->promote(
			schema: $this->schemaWith([
				'salary' => ['type' => 'number', 'title' => 'Salaris', 'scope' => 'team-a', 'facetable' => true],
			]),
			property: 'salary'
		);

		$this->assertArrayHasKey('salary', $promoted, 'The name is the key the stored values are under.');
		$this->assertArrayNotHasKey('scope', $promoted['salary']);
		$this->assertSame('number', $promoted['salary']['type']);
		$this->assertSame('Salaris', $promoted['salary']['title']);
		$this->assertTrue($promoted['salary']['facetable']);
	}//end testPromotionKeepsTheNameAndEverythingElse()

	/**
	 * Promoting something that is not scoped is refused.
	 *
	 * Promoting it anyway would report an act that did not happen.
	 *
	 * @return void
	 */
	public function testPromotingAnUnscopedPropertyIsRefused(): void {
		$this->expectException(ScopedPropertyException::class);

		$this->governanceFor('alice', ['team-a'])->promote(
			schema: $this->schemaWith(['name' => ['type' => 'string']]),
			property: 'name'
		);
	}//end testPromotingAnUnscopedPropertyIsRefused()

	/**
	 * The promotion record names its actor.
	 *
	 * "Who promoted this" is the question anyone reading the trail later is
	 * actually asking.
	 *
	 * @return void
	 */
	public function testThePromotionRecordNamesItsActor(): void {
		$record = $this->governanceFor('alice', ['team-a'])->promotionRecord(
			schema: $this->schemaWith(['salary' => ['scope' => 'team-a']]),
			property: 'salary',
			scope: 'team-a',
			at: new DateTimeImmutable('2026-09-18T10:00:00+00:00')
		);

		$this->assertSame('scoped_property_promoted', $record['action']);
		$this->assertSame('alice', $record['actor']);
		$this->assertSame('salary', $record['property']);
		$this->assertSame('team-a', $record['fromScope']);
	}//end testThePromotionRecordNamesItsActor()
}//end class
