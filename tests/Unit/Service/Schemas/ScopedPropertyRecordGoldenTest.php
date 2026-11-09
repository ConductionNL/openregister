<?php

/**
 * Golden test: the promotion record and the ceiling count of scoped properties, pinned before a refactor.
 *
 * The promotion record is an audit entry: its keys, their order and their
 * values are pinned exactly, with and without a session user. The ceiling
 * count is pinned at its edges (numeric names, the property being edited,
 * a property at another scope) together with the allowed and refused
 * outcome of the ceiling check, and one mutation check that a full scope
 * still refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use OCA\OpenRegister\Service\Schemas\ScopedPropertyException;
use OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\ScopedPropertyGovernance
 */
final class ScopedPropertyRecordGoldenTest extends TestCase {

	/**
	 * Governance for a session user (or none) with a ceiling.
	 *
	 * @param string|null $uid     The session user's id, or null for no session user.
	 * @param int         $ceiling The administered ceiling.
	 *
	 * @return ScopedPropertyGovernance The governance.
	 */
	private function governance(?string $uid, int $ceiling = 2): ScopedPropertyGovernance {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default): int => ($key === ScopedPropertyGovernance::CEILING_KEY ? $ceiling : $default)
		);

		return new ScopedPropertyGovernance($session, $this->createMock(IGroupManager::class), $appConfig);
	}

	/**
	 * A schema with an id and the given properties.
	 *
	 * @param array<int|string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setProperties($properties);
		return $schema;
	}

	/**
	 * The promotion record, exactly, with a session user.
	 *
	 * @return void
	 */
	public function testThePromotionRecordIsExact(): void {
		$record = $this->governance('alice')->promotionRecord(
			schema: $this->schema(['salary' => ['scope' => 'team-a']]),
			property: 'salary',
			scope: 'team-a',
			stampedAt: new DateTimeImmutable('2026-09-18T10:00:00+02:00')
		);

		$this->assertSame(
			[
				'action'    => 'scoped_property_promoted',
				'schema'    => 42,
				'property'  => 'salary',
				'fromScope' => 'team-a',
				'actor'     => 'alice',
				'at'        => '2026-09-18T10:00:00+02:00',
			],
			$record
		);
	}

	/**
	 * Without a session user the record names no actor.
	 *
	 * @return void
	 */
	public function testThePromotionRecordWithoutASessionUserHasANullActor(): void {
		$record = $this->governance(null)->promotionRecord(
			schema: $this->schema([]),
			property: 'x',
			scope: 's',
			stampedAt: new DateTimeImmutable('2000-01-01T00:00:00+00:00')
		);

		$this->assertNull($record['actor']);
		$this->assertSame(['action', 'schema', 'property', 'fromScope', 'actor', 'at'], array_keys($record));
	}

	/**
	 * The count skips the edited property, other scopes and non-properties; numeric names compare as strings.
	 *
	 * @return void
	 */
	public function testTheCountAtItsEdges(): void {
		$governance = $this->governance('alice');
		$schema     = $this->schema(
			[
				0      => ['scope' => 'team-a'],
				'b'    => ['scope' => ' team-a '],
				'c'    => ['scope' => 'team-b'],
				'd'    => ['scope' => ['team-a']],
				'e'    => 'team-a',
			]
		);

		$this->assertSame(2, $governance->countAtScope(schema: $schema, scope: 'team-a'));
		$this->assertSame(1, $governance->countAtScope(schema: $schema, scope: 'team-a', except: '0'));
		$this->assertSame(1, $governance->countAtScope(schema: $schema, scope: 'team-a', except: 'b'));
		$this->assertSame(2, $governance->countAtScope(schema: $schema, scope: 'team-a', except: 'c'));
		$this->assertSame(0, $governance->countAtScope(schema: $schema, scope: 'team-c'));
	}

	/**
	 * Editing a property in a full scope is allowed; adding one is refused; a ceiling one higher allows it.
	 *
	 * @return void
	 */
	public function testTheCeilingAllowsAnEditAndRefusesAnAddition(): void {
		$schema = $this->schema([0 => ['scope' => 'team-a'], 'b' => ['scope' => 'team-a']]);

		$this->governance('alice')->assertBelowCeiling(schema: $schema, scope: 'team-a', property: '0');
		$this->governance('alice', 3)->assertBelowCeiling(schema: $schema, scope: 'team-a', property: 'new');

		try {
			$this->governance('alice')->assertBelowCeiling(schema: $schema, scope: 'team-a', property: 'new');
		} catch (ScopedPropertyException $exception) {
			$this->assertSame(
				"Scope 'team-a' already holds 2 scoped properties, which is its ceiling of 2, so 'new' is refused. "
				. 'Raise the ceiling or retire a field the scope no longer uses.',
				$exception->getMessage()
			);
			return;
		}

		$this->fail('A full scope admitted another property.');
	}
}
