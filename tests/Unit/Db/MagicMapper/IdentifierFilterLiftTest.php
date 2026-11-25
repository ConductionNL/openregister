<?php

/**
 * IdentifierFilterLiftTest: a plain `id` or `uuid` filter selects by identifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\IdentifierFilterLift;
use PHPUnit\Framework\TestCase;

/**
 * An undeclared `id`/`uuid` key moves into `@self.uuid`; a declared property,
 * an explicit `@self.uuid` and other filters are left alone.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\IdentifierFilterLift
 */
class IdentifierFilterLiftTest extends TestCase {

	private const UUID = 'ee02000a-0000-4000-8000-000000000525';

	private const OTHER = 'ee02000a-0000-4000-8000-000000000553';

	/**
	 * A plain `id` filter becomes the `_uuid` metadata filter.
	 *
	 * @return void
	 */
	public function testAnIdFilterSelectsByIdentifier(): void {
		$query = IdentifierFilterLift::lift(query: ['id' => self::UUID, 'givenName' => 'Joep'], properties: ['givenName' => ['type' => 'string']]);

		self::assertSame(['givenName' => 'Joep', '@self' => ['uuid' => self::UUID]], $query);
	}

	/**
	 * A list of ids, as a join hands over, stays a list.
	 *
	 * @return void
	 */
	public function testAListOfIdsSelectsEachOfThem(): void {
		$query = IdentifierFilterLift::lift(query: ['id' => [self::UUID, self::OTHER]], properties: []);

		self::assertSame(['@self' => ['uuid' => [self::UUID, self::OTHER]]], $query);
	}

	/**
	 * A plain `uuid` filter moves the same way, next to the call's own `@self` keys.
	 *
	 * @return void
	 */
	public function testAUuidFilterJoinsTheOtherMetadataFilters(): void {
		$query = IdentifierFilterLift::lift(query: ['@self' => ['register' => 5], 'uuid' => self::UUID], properties: null);

		self::assertSame(['@self' => ['register' => 5, 'uuid' => self::UUID]], $query);
	}

	/**
	 * A schema that declares an `id` property keeps filtering on it.
	 *
	 * @return void
	 */
	public function testADeclaredIdPropertyIsLeftAlone(): void {
		$query = ['id' => 'K-12'];

		self::assertSame($query, IdentifierFilterLift::lift(query: $query, properties: ['id' => ['type' => 'string']]));
	}

	/**
	 * An explicit `@self.uuid` wins over the lifted key.
	 *
	 * @return void
	 */
	public function testAnExplicitSelfUuidWins(): void {
		$query = IdentifierFilterLift::lift(query: ['@self' => ['uuid' => self::OTHER], 'id' => self::UUID], properties: []);

		self::assertSame(['@self' => ['uuid' => self::OTHER]], $query);
	}

	/**
	 * A value that names no identifier (empty, an operator bag) is dropped, as
	 * the undeclared key was before, and adds no condition.
	 *
	 * @return void
	 */
	public function testAValueThatNamesNoIdentifierAddsNoCondition(): void {
		self::assertSame([], IdentifierFilterLift::lift(query: ['id' => ''], properties: []));
		self::assertSame([], IdentifierFilterLift::lift(query: ['id' => ['in' => [self::UUID]]], properties: []));
		self::assertSame([], IdentifierFilterLift::lift(query: ['uuid' => [self::UUID, 7]], properties: []));
	}

	/**
	 * A query without an identifier filter is returned as it came.
	 *
	 * @return void
	 */
	public function testAQueryWithoutIdentifierIsUnchanged(): void {
		$query = ['givenName' => 'Joep', '_limit' => 5];

		self::assertSame($query, IdentifierFilterLift::lift(query: $query, properties: []));
	}
}
