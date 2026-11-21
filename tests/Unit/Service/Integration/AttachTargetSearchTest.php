<?php

/**
 * The attach picker's search: what it offers and what it searches.
 *
 * 🔴 THE WRITE VERDICT IS THE PLATFORM'S. The search asks the permission
 * layer per schema and offers nothing it could not get an answer for, so the
 * picker never offers a target the write then refuses.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Files\Service\Integration\AttachTargetFilter;
use OCA\OpenRegister\Files\Service\Integration\AttachTargetSearch;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The attach picker's search.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md
 */
#[CoversClass(AttachTargetSearch::class)]
#[UsesClass(AttachTargetFilter::class)]
#[UsesClass(Register::class)]
#[UsesClass(Schema::class)]
#[UsesClass(ObjectEntity::class)]
class AttachTargetSearchTest extends TestCase {

	/**
	 * The registers.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * The schemas.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * The write verdicts.
	 *
	 * @var PermissionCheckInterface&MockObject
	 */
	private PermissionCheckInterface&MockObject $permissions;

	/**
	 * The object search.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	protected function setUp(): void {
		parent::setUp();
		$this->registers   = $this->createMock(RegisterMapperInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
	}//end setUp()

	private function search(): AttachTargetSearch {
		return new AttachTargetSearch(
			registerMapper: $this->registers,
			schemaMapper: $this->schemas,
			permissions: $this->permissions,
			objects: $this->objects,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end search()

	private function schema(int $id, string $slug, ?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setTitle(ucfirst($slug));
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}//end schema()

	/**
	 * One register `cases` (id 7) holding the given schemas.
	 *
	 * @param array<int,Schema> $schemas The schemas.
	 * @param array<string,bool> $writable Slug => write verdict.
	 *
	 * @return void
	 */
	private function arrange(array $schemas, array $writable): void {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('cases');
		$register->setSchemas(array_map(fn (Schema $s): int => (int)$s->getId(), $schemas));

		$this->registers->method('findAll')->willReturn([$register]);
		$this->schemas->method('findMultiple')->willReturn($schemas);
		$this->permissions->method('hasPermission')->willReturnCallback(
			fn (Schema $schema, string $action, ...$rest): bool => $action === 'update' && ($writable[$schema->getSlug()] ?? false)
		);
	}//end arrange()

	public function testOnlyWritableSchemasThatHoldFilesAreOffered(): void {
		$this->arrange(
			[$this->schema(1, 'case'), $this->schema(2, 'archive'), $this->schema(3, 'note', ['allowFiles' => false])],
			['case' => true, 'archive' => false, 'note' => true]
		);
		$this->objects->expects($this->never())->method('searchObjectsPaginated');

		$answer = $this->search()->find(search: '');

		$this->assertSame([['schema' => 'case', 'register' => 'cases', 'label' => 'Case']], $answer['targets']);
		$this->assertSame([], $answer['results']);
	}//end testOnlyWritableSchemasThatHoldFilesAreOffered()

	public function testTitlesAreSearchedInOfferableSchemasOnly(): void {
		$this->arrange([$this->schema(1, 'case'), $this->schema(2, 'archive')], ['case' => true]);

		$hit = new ObjectEntity();
		$hit->setUuid('u-1');
		$hit->setName('Bouwvergunning Dorpsstraat');

		$this->objects->expects($this->once())
			->method('searchObjectsPaginated')
			->with(['@self' => ['register' => 7, 'schema' => 1], '_search' => 'bouw', '_limit' => AttachTargetFilter::MAX_RESULTS])
			->willReturn(['results' => [$hit, ['@self' => ['id' => 'u-2'], 'name' => ''], 'not a row']]);

		$answer = $this->search()->find(search: ' bouw ');

		$this->assertSame(
			[
				['objectUuid' => 'u-1', 'title' => 'Bouwvergunning Dorpsstraat', 'schema' => 'case', 'register' => 'cases'],
				['objectUuid' => 'u-2', 'title' => 'u-2', 'schema' => 'case', 'register' => 'cases'],
			],
			$answer['results']
		);
	}//end testTitlesAreSearchedInOfferableSchemasOnly()

	public function testADeclarationNarrowsTheOfferAndTheSearch(): void {
		$this->arrange([$this->schema(1, 'case'), $this->schema(2, 'permit')], ['case' => true, 'permit' => true]);

		$this->objects->expects($this->once())
			->method('searchObjectsPaginated')
			->with($this->callback(fn (array $q): bool => $q['@self']['schema'] === 2))
			->willReturn(['results' => []]);

		$answer = $this->search()->find(search: 'x', declared: ['permit', 'archive']);

		$this->assertSame(['permit'], array_column($answer['targets'], 'schema'));
	}//end testADeclarationNarrowsTheOfferAndTheSearch()

	public function testASchemaWithoutAVerdictIsNotOffered(): void {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('cases');
		$register->setSchemas([1]);
		$this->registers->method('findAll')->willReturn([$register]);
		$this->schemas->method('findMultiple')->willReturn([$this->schema(1, 'case')]);
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('no session'));

		$this->assertSame(['targets' => [], 'results' => []], $this->search()->find(search: 'x'));
	}//end testASchemaWithoutAVerdictIsNotOffered()

	public function testAFailingSearchInOneSchemaLeavesTheOthers(): void {
		$this->arrange([$this->schema(1, 'case'), $this->schema(2, 'permit')], ['case' => true, 'permit' => true]);

		$hit = new ObjectEntity();
		$hit->setUuid('p-1');
		$hit->setName('Permit 1');
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query) use ($hit): array {
				if ($query['@self']['schema'] === 1) {
					throw new RuntimeException('index down');
				}

				return ['results' => [$hit]];
			}
		);

		$answer = $this->search()->find(search: 'permit');

		$this->assertSame(['p-1'], array_column($answer['results'], 'objectUuid'));
	}//end testAFailingSearchInOneSchemaLeavesTheOthers()

	public function testARegisterWithoutSchemasIsNotLookedInto(): void {
		$register = new Register();
		$register->setId(7);
		$register->setSchemas([]);
		$this->registers->method('findAll')->willReturn([$register]);
		$this->schemas->expects($this->never())->method('findMultiple');

		$this->assertSame(['targets' => [], 'results' => []], $this->search()->find(search: 'x'));
	}//end testARegisterWithoutSchemasIsNotLookedInto()
}//end class
