<?php

/**
 * OpenRegister SchemaLookupTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

use OCA\OpenRegister\Db\SchemaMapper\SchemaLookup;
use PHPUnit\Framework\TestCase;

/**
 * The cache key and not-found message of one schema lookup.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\SchemaLookup
 */
final class SchemaLookupTest extends TestCase {
	/**
	 * The cache key is the lowered id and the multitenancy flag; RBAC does not
	 * change the row a lookup loads, so both RBAC settings share one key.
	 *
	 * @return void
	 */
	public function testTheCacheKeyCarriesTheIdAndTheTenancyFlagOnly(): void {
		$lookup = new SchemaLookup(id: 'Publicatie', rbac: true, multitenancy: false);

		self::assertSame(':0', $lookup->cacheSuffix());
		self::assertSame('publicatie:0', $lookup->cacheKey());
		self::assertSame('42:1', (new SchemaLookup(id: 42, rbac: false, multitenancy: true))->cacheKey());
		self::assertSame(
			(new SchemaLookup(id: 42, rbac: true, multitenancy: true))->cacheKey(),
			(new SchemaLookup(id: 42, rbac: false, multitenancy: true))->cacheKey()
		);
		self::assertNotSame(
			(new SchemaLookup(id: 42, rbac: true, multitenancy: true))->cacheKey(),
			(new SchemaLookup(id: 42, rbac: true, multitenancy: false))->cacheKey()
		);
	}//end testTheCacheKeyCarriesTheIdAndTheTenancyFlagOnly()

	/**
	 * The not-found message names the id as given and both flags.
	 *
	 * @return void
	 */
	public function testTheNotFoundMessageNamesTheLookup(): void {
		self::assertSame(
			"Schema not found (id='publicatie', multitenancy=true, rbac=false)",
			(new SchemaLookup(id: 'publicatie', rbac: false, multitenancy: true))->notFoundMessage()
		);
		self::assertSame(
			'Schema not found (id=7, multitenancy=false, rbac=true)',
			(new SchemaLookup(id: 7, rbac: true, multitenancy: false))->notFoundMessage()
		);
	}//end testTheNotFoundMessageNamesTheLookup()
}//end class
