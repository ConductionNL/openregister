<?php

/**
 * Unit tests for RegisterScopedSchemaResolver.
 *
 * Pins WHY the boundary exists and where it stops. A slug is ambiguous
 * instance-wide — several registers legitimately carry a `TimeEntry`, and
 * resolving one globally is what served another app's schema into a leaf
 * app's forms and aggregations. A numeric id or uuid is unique by
 * construction, so scoping it protects nothing and can only refuse a caller
 * whose register carries a stale `schemas` list; that refusal turned
 * `POST /api/objects/{registerId}/{schemaId}` into a 404 for existing
 * clients, which these tests exist to prevent recurring.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use OCA\OpenRegister\Service\RegisterScopedSchemaResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\RegisterScopedSchemaResolver
 */
class RegisterScopedSchemaResolverTest extends TestCase {

	private function register(array $schemaIds): Register {
		$register = new Register();
		$register->setId(18);
		$register->setSlug('hrmq');
		$register->setSchemas($schemaIds);
		return $register;
	}

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		return $schema;
	}

	/**
	 * A slug the register carries resolves within it, never instance-wide.
	 */
	public function testCarriedSlugResolvesWithinTheRegister(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn($this->schema(9466, 'TimeEntry'));
		$schemaMapper->expects(self::never())->method('find');

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		self::assertSame(9466, $resolver->resolveSchemaWithin($this->register([9466]), 'TimeEntry')->getId());
	}

	/**
	 * A slug the register does NOT carry is refused even though other
	 * registers have it — this is the collision the boundary exists for.
	 */
	public function testUncarriedSlugIsRefusedRatherThanResolvedElsewhere(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('countBySlug')->willReturn(3);
		$schemaMapper->expects(self::never())->method('find');

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		$this->expectException(SchemaNotInRegisterException::class);
		$resolver->resolveSchemaWithin($this->register([1, 2]), 'TimeEntry');
	}

	/**
	 * A NUMERIC id resolves globally when the register's list is stale — the
	 * regression that 404'd POST /api/objects/{registerId}/{schemaId}.
	 */
	public function testNumericIdResolvesGloballyWhenTheMembershipListIsStale(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('find')->willReturn($this->schema(29, 'persoon'));

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		self::assertSame(29, $resolver->resolveSchemaWithin($this->register([]), 29)->getId());
	}

	/**
	 * A numeric id supplied as a STRING behaves identically — the router hands
	 * path segments over as strings.
	 */
	public function testNumericStringIdAlsoResolvesGlobally(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('find')->willReturn($this->schema(29, 'persoon'));

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		self::assertSame(29, $resolver->resolveSchemaWithin($this->register([]), '29')->getId());
	}

	/**
	 * A uuid is unique by construction and resolves globally too.
	 */
	public function testUuidResolvesGlobally(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('find')->willReturn($this->schema(29, 'persoon'));

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		$uuid = '6dfe55cc-6e73-40e7-88c5-b6e446172a07';
		self::assertSame(29, $resolver->resolveSchemaWithin($this->register([]), $uuid)->getId());
	}

	/**
	 * A unique identifier that genuinely does not exist still refuses —
	 * the global fallback is a widening for AMBIGUITY, not for absence.
	 */
	public function testUnknownNumericIdStillRefuses(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));
		$schemaMapper->method('countBySlug')->willReturn(0);

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		$this->expectException(SchemaNotInRegisterException::class);
		$resolver->resolveSchemaWithin($this->register([]), 4242);
	}
}
