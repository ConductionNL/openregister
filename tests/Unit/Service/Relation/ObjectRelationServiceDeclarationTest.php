<?php

/**
 * Characterisation tests for ObjectRelationService::declarationFor().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Relation;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectRelationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Relation\ObjectRelationService;
use OCA\OpenRegister\Service\Relation\ObjectRelationStores;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectRelationServiceDeclarationTest extends TestCase {

	/**
	 * The schema finds the mapper saw: [id, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $finds = [];

	private function service(?Schema $schema): ObjectRelationService {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($schema): Schema {
				$this->finds[] = [$id, $_rbac, $_multitenancy];
				return $schema ?? throw new DoesNotExistException('gone');
			}
		);

		return new ObjectRelationService(
			stores: new ObjectRelationStores(
				mapper: $this->createMock(ObjectRelationMapper::class),
				schemaMapper: $schemas,
				relationTypes: new RelationTypeResolver()
			),
			logger: $this->createMock(LoggerInterface::class),
			userSession: null
		);
	}//end service()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(['parent' => ['type' => 'string', '$ref' => 'case', 'title' => 'Parent case']]);
		return $schema;
	}//end schema()

	public function testNoSchemaIdIsNoDeclarationAndNoRead(): void {
		$this->assertNull($this->service($this->schema())->declarationFor(null, 'parent'));
		$this->assertSame([], $this->finds);
	}//end testNoSchemaIdIsNoDeclarationAndNoRead()

	public function testAMissingSchemaIsNoDeclaration(): void {
		$this->assertNull($this->service(null)->declarationFor(7, 'parent'));
		$this->assertSame([[7, false, false]], $this->finds);
	}//end testAMissingSchemaIsNoDeclaration()

	public function testAReferencePropertyReadsItsDescriptorUnscoped(): void {
		$declaration = $this->service($this->schema())->declarationFor(7, 'parent.uuid', 'en');
		$this->assertSame('parent', $declaration['property']);
		$this->assertSame('Parent case', $declaration['label']);
		$this->assertSame([[7, false, false]], $this->finds);
	}//end testAReferencePropertyReadsItsDescriptorUnscoped()

	public function testAPlainPropertyHasNoDeclaration(): void {
		$this->assertNull($this->service($this->schema())->declarationFor(7, 'title'));
	}//end testAPlainPropertyHasNoDeclaration()
}//end class
