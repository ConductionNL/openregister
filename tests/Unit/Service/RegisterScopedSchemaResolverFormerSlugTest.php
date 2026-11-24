<?php

/**
 * Unit tests for the former-slug fallback of RegisterScopedSchemaResolver.
 *
 * A renamed schema keeps answering at its old slug inside its register, the
 * current slug always wins, and the register stays the boundary.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @uses \OCA\OpenRegister\Db\Schema\FormerSlugs
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\SchemaNotInRegisterException
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#2.2
 */
class RegisterScopedSchemaResolverFormerSlugTest extends TestCase {

	private function register(array $schemaIds): Register {
		$register = new Register();
		$register->setId(18);
		$register->setSlug('meldingen-register');
		$register->setSchemas($schemaIds);
		return $register;
	}

	private function schema(int $id, string $slug, ?array $former = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setFormerSlugs($former);
		return $schema;
	}

	public function testAFormerSlugResolvesToTheRenamedSchemaOfTheRegister(): void {
		$signaal = $this->schema(7, 'signaal', ['melding']);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->expects(self::once())->method('findMultiple')
			->with([3, 7], false, false)
			->willReturn([$this->schema(3, 'document'), $signaal]);

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		self::assertSame($signaal, $resolver->resolveSchemaWithin($this->register([3, 7]), 'melding'));
	}

	public function testACurrentSlugWinsAndTheFormerSlugsAreNotRead(): void {
		$current = $this->schema(9, 'melding');
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn($current);
		$schemaMapper->expects(self::never())->method('findMultiple');

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		self::assertSame($current, $resolver->resolveSchemaWithin($this->register([7, 9]), 'melding'));
	}

	public function testOnlyTheRegistersOwnSchemasAreCandidates(): void {
		// The mapper is asked for the register's ids only; a renamed schema
		// elsewhere on the instance is never offered, so the slug is refused.
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->expects(self::once())->method('findMultiple')
			->with([3], false, false)
			->willReturn([$this->schema(3, 'document')]);

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		$this->expectException(SchemaNotInRegisterException::class);
		$resolver->resolveSchemaWithin($this->register([3]), 'melding');
	}

	public function testAnIdIsNeverResolvedThroughFormerSlugs(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('find')->willThrowException(new \Exception('absent'));
		$schemaMapper->expects(self::never())->method('findMultiple');

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		$this->expectException(SchemaNotInRegisterException::class);
		$resolver->resolveSchemaWithin($this->register([3]), '404');
	}

	public function testAFailingCandidateReadIsARefusalNotAnError(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findInIds')->willReturn(null);
		$schemaMapper->method('findMultiple')->willThrowException(new \RuntimeException('db down'));

		$resolver = new RegisterScopedSchemaResolver($this->createMock(RegisterMapper::class), $schemaMapper);

		$this->expectException(SchemaNotInRegisterException::class);
		$resolver->resolveSchemaWithin($this->register([3]), 'melding');
	}
}//end class
