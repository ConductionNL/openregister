<?php

/**
 * Golden test: what a scoped property admits and refuses, pinned before a refactor.
 *
 * A scope compiles into the property's read and update authorization, and the
 * governance decides how many a scope may hold and what a promotion drops. Both
 * are access rules, so every allowed and every refused outcome is pinned here
 * with the exact sentence, before the units that produce them are split up.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
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

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyDeclaration;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyException;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the allowed and refused outcomes of ScopedPropertyDeclaration and ScopedPropertyGovernance.
 */
class ScopedPropertyAccessGoldenTest extends TestCase {

	/**
	 * Governance with a fixed ceiling and the default unused period.
	 *
	 * @param int $ceiling The administered ceiling.
	 *
	 * @return ScopedPropertyGovernance The governance.
	 */
	private function governance(int $ceiling = 2): ScopedPropertyGovernance {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default) use ($ceiling): int {
				if ($key === ScopedPropertyGovernance::CEILING_KEY) {
					return $ceiling;
				}

				return $default;
			}
		);

		return new ScopedPropertyGovernance(
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$appConfig
		);
	}//end governance()

	/**
	 * A schema holding the given properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * Every refused declaration and its exact sentence.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}> Property and message.
	 */
	public static function refusedDeclarations(): array {
		return [
			'not a string' => [
				['scope' => 5],
				"'scope' at 'p/x' must name one team or unit as a non-empty string.",
			],
			'blank' => [
				['scope' => '  '],
				"'scope' at 'p/x' must name one team or unit as a non-empty string.",
			],
			'cannot name a group' => [
				['scope' => 'team/a'],
				"'scope' at 'p/x' is 'team/a', which cannot name a group. A scope that matches no group denies everybody, silently.",
			],
			'scope and authorization' => [
				['scope' => 'team-a', 'authorization' => ['read' => ['x']]],
				"'scope' at 'p/x' declares both 'scope' and 'authorization'. A scope IS an authorization block, so declaring both "
				. 'leaves two answers to one question and the quiet resolution is whichever the code reads first. Keep one.',
			],
		];
	}//end refusedDeclarations()

	/**
	 * A malformed scope is refused with its sentence, by both entry points.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $message  The expected sentence.
	 *
	 * @return void
	 */
	#[DataProvider('refusedDeclarations')]
	public function testARefusedDeclarationNamesWhy(array $property, string $message): void {
		$entryPoints = [
			'fromProperty' => static fn () => ScopedPropertyDeclaration::fromProperty(property: $property, path: 'p/x'),
			'assert'       => static fn () => ScopedPropertyDeclaration::assert(property: $property, path: 'p/x'),
		];
		foreach ($entryPoints as $method => $call) {
			try {
				$call();
				$this->fail($method . ' admitted a malformed scope');
			} catch (ScopedPropertyException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testARefusedDeclarationNamesWhy()

	/**
	 * A sound scope is admitted, trimmed, and an empty authorization does not block it.
	 *
	 * @return void
	 */
	public function testASoundDeclarationIsAdmitted(): void {
		$this->assertNull(ScopedPropertyDeclaration::fromProperty(property: ['type' => 'string']));
		$this->assertSame('team-a', ScopedPropertyDeclaration::fromProperty(property: ['scope' => ' team-a ']));
		$this->assertSame('Team 1.b_c', ScopedPropertyDeclaration::fromProperty(property: ['scope' => 'Team 1.b_c', 'authorization' => []]));
		$this->assertSame(['read' => ['team-a'], 'update' => ['team-a']], ScopedPropertyDeclaration::authorizationFor(scope: 'team-a'));
	}//end testASoundDeclarationIsAdmitted()

	/**
	 * The ceiling counts trimmed scopes, skips the edited property and refuses at the ceiling.
	 *
	 * @return void
	 */
	public function testTheCeilingAdmitsBelowAndRefusesAt(): void {
		$governance = $this->governance(ceiling: 2);
		$schema     = $this->schema(
			[
				'a' => ['scope' => ' team-a'],
				'b' => ['scope' => 'team-a'],
				'c' => ['scope' => 'team-b'],
				'd' => 'not a property',
				'e' => ['scope' => 3],
			]
		);

		$this->assertSame(2, $governance->countAtScope(schema: $schema, scope: 'team-a'));
		$this->assertSame(1, $governance->countAtScope(schema: $schema, scope: 'team-a', except: 'b'));
		$governance->assertBelowCeiling(schema: $schema, scope: 'team-a', property: 'b');
		$governance->assertBelowCeiling(schema: $schema, scope: 'team-b', property: 'new');

		$this->expectException(ScopedPropertyException::class);
		$this->expectExceptionMessage(
			"Scope 'team-a' already holds 2 scoped properties, which is its ceiling of 2, so 'new' is refused. "
			. 'Raise the ceiling or retire a field the scope no longer uses.'
		);
		$governance->assertBelowCeiling(schema: $schema, scope: 'team-a', property: 'new');
	}//end testTheCeilingAdmitsBelowAndRefusesAt()

	/**
	 * The unused report states in use, unused and unknown, and skips unscoped properties.
	 *
	 * @return void
	 */
	public function testTheUnusedReportStatesEachProperty(): void {
		$schema = $this->schema(
			[
				'used'    => ['scope' => ' team-a '],
				'unused'  => ['scope' => 'team-a'],
				'unknown' => ['scope' => 'team-b'],
				'plain'   => ['type' => 'string'],
				'blank'   => ['scope' => ' '],
				'odd'     => 'x',
			]
		);
		$asOf   = new DateTimeImmutable('2026-01-02T03:04:05+00:00');

		$report = $this->governance()->unusedReport(schema: $schema, counts: ['used' => 4, 'unused' => 0, 'plain' => 0], asOf: $asOf);

		$row = static fn (string $p, string $s, ?int $v, string $state): array => [
			'property' => $p, 'scope' => $s, 'values' => $v, 'state' => $state,
			'unusedAfterDays' => ScopedPropertyGovernance::DEFAULT_UNUSED_DAYS, 'asOf' => '2026-01-02T03:04:05+00:00',
		];
		$this->assertSame(
			[$row('used', 'team-a', 4, 'in use'), $row('unused', 'team-a', 0, 'unused'), $row('unknown', 'team-b', null, 'unknown')],
			$report
		);
	}//end testTheUnusedReportStatesEachProperty()

	/**
	 * A promotion drops only the scope; a missing or unscoped property is refused.
	 *
	 * @return void
	 */
	public function testPromotionAdmitsAScopedPropertyAndRefusesOthers(): void {
		$governance = $this->governance();
		$schema     = $this->schema(['a' => ['type' => 'string', 'scope' => 'team-a', 'title' => 'A'], 'b' => ['type' => 'string']]);

		$this->assertSame(
			['a' => ['type' => 'string', 'title' => 'A'], 'b' => ['type' => 'string']],
			$governance->promote(schema: $schema, property: 'a')
		);

		$refusals = [
			'missing' => "There is no property 'missing' to promote.",
			'b'       => "'b' is not a scoped property, so there is nothing to promote it from. "
				. 'Promoting it anyway would report an act that did not happen.',
		];
		foreach ($refusals as $property => $message) {
			try {
				$governance->promote(schema: $schema, property: $property);
				$this->fail('promoted ' . $property);
			} catch (ScopedPropertyException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testPromotionAdmitsAScopedPropertyAndRefusesOthers()
}//end class
