<?php

/**
 * OpenRegister RegisterSerializer schema-prefetch tests
 *
 * expandSchemas() resolved every schema id through SchemaMapper::find() — one
 * query per register-schema pair (~1,200 on the dev instance) for a single
 * `_extend[]=schemas` page load. serializeMany() now warms the request cache
 * with one batched lookup first.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegisterSerializerWarmCacheTest extends TestCase {
	/**
	 * Build a Schema with the given id.
	 *
	 * @param int $id Schema id.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setTitle('Schema ' . $id);

		return $schema;
	}//end schema()

	/**
	 * Build a Register carrying the given schema ids.
	 *
	 * @param int $id Register id.
	 * @param array $schemaIds Schema ids on the register.
	 *
	 * @return Register The register.
	 */
	private function register(int $id, array $schemaIds): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle('Register ' . $id);
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

	/**
	 * The batch resolves every numeric id, so find() must never be called.
	 *
	 * @return void
	 */
	public function testSchemasAreResolvedInOneBatchNotPerId(): void {
		$mapper = $this->createMock(SchemaMapper::class);

		// ONE batched call carrying the union of both registers' schema ids.
		$mapper->expects($this->once())
			->method('findMultipleOptimized')
			->with($this->callback(
				static function (array $ids): bool {
					sort($ids);
					return $ids === [1, 2, 3];
				}
			))
			->willReturn(
				[
					1 => $this->schema(1),
					2 => $this->schema(2),
					3 => $this->schema(3),
				]
			);

		// The N+1 path must not run at all.
		$mapper->expects($this->never())->method('find');

		$serializer = new RegisterSerializer($mapper, $this->createMock(LoggerInterface::class));

		$out = $serializer->serializeMany(
			[
				$this->register(10, [1, 2]),
				$this->register(11, [2, 3]),
			],
			['schemas']
		);

		$this->assertCount(2, $out);
	}//end testSchemasAreResolvedInOneBatchNotPerId()

	/**
	 * A non-numeric identifier (uuid/slug) cannot be batched on `id`, so it must
	 * still fall through to find().
	 *
	 * @return void
	 */
	public function testNonNumericIdentifierFallsBackToFind(): void {
		$mapper = $this->createMock(SchemaMapper::class);

		$mapper->expects($this->once())
			->method('findMultipleOptimized')
			->with([1])
			->willReturn([1 => $this->schema(1)]);

		// The slug is not batchable and must still be resolved individually.
		$mapper->expects($this->once())
			->method('find')
			->with($this->equalTo('my-slug'))
			->willReturn($this->schema(99));

		$serializer = new RegisterSerializer($mapper, $this->createMock(LoggerInterface::class));

		$serializer->serializeMany([$this->register(10, [1, 'my-slug'])], ['schemas']);
	}//end testNonNumericIdentifierFallsBackToFind()

	/**
	 * An id the batch cannot resolve stays orphan-handled: find() is still
	 * consulted and its DoesNotExistException must not break serialization.
	 *
	 * @return void
	 */
	public function testOrphanIdStillRetainedViaFindFallback(): void {
		$mapper = $this->createMock(SchemaMapper::class);

		// Batch returns nothing for the orphan id.
		$mapper->method('findMultipleOptimized')->willReturn([1 => $this->schema(1)]);

		$mapper->expects($this->once())
			->method('find')
			->with($this->equalTo(404))
			->willThrowException(new DoesNotExistException('gone'));

		$serializer = new RegisterSerializer($mapper, $this->createMock(LoggerInterface::class));

		$out = $serializer->serializeMany([$this->register(10, [1, 404])], ['schemas']);

		// Serialization still succeeds — the orphan is retained, not fatal.
		$this->assertCount(1, $out);
	}//end testOrphanIdStillRetainedViaFindFallback()

	/**
	 * Without `_extend[]=schemas` there is nothing to resolve, so no batch query
	 * should be issued at all.
	 *
	 * @return void
	 */
	public function testNoBatchWhenSchemasNotExtended(): void {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->expects($this->never())->method('findMultipleOptimized');
		$mapper->expects($this->never())->method('find');

		$serializer = new RegisterSerializer($mapper, $this->createMock(LoggerInterface::class));

		$serializer->serializeMany([$this->register(10, [1, 2])], []);
	}//end testNoBatchWhenSchemasNotExtended()
}//end class
