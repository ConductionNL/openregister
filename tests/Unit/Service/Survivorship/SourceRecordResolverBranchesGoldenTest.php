<?php

/**
 * SourceRecordResolver characterisation — pins every branch of source
 * resolution: reverse-FK descriptor parsing, schema-id resolution and its
 * cache, the scoped query and its PHP re-filter, and embedded entries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/archive/2026-10-05-mdm-reverse-fk-source-resolution/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Service\Survivorship;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SourceRecordResolverBranchesGoldenTest extends TestCase {

	private ObjectService&MockObject $objectService;

	private SchemaMapper&MockObject $schemaMapper;

	private LoggerInterface&MockObject $logger;

	private SourceRecordResolver $resolver;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper  = $this->createMock(SchemaMapper::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
		$this->resolver      = new SourceRecordResolver($this->objectService, $this->schemaMapper, $this->logger);
	}//end setUp()

	/**
	 * Build an ObjectEntity carrying the given payload.
	 *
	 * @param array<string, mixed>|null $payload Object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(?array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		if ($payload !== null) {
			$entity->setObject($payload);
		}

		return $entity;
	}//end entity()

	/**
	 * A reverse-FK config.
	 *
	 * @param array<string, mixed> $extra Extra sourceLink keys.
	 *
	 * @return array<string, mixed>
	 */
	private function reverseFk(array $extra = []): array {
		return [
			'sourceLink' => array_merge(
				['mode' => 'reverseFk', 'sourceSchema' => 'srcSchema', 'referenceField' => 'master'],
				$extra
			),
		];
	}//end reverseFk()

	public function testDescriptorBranches(): void {
		$this->assertNull($this->resolver->reverseFkDescriptor(['sourceLink' => 'reverseFk']));
		$this->assertNull($this->resolver->reverseFkDescriptor(['sourceLink' => ['sourceSchema' => 'a', 'referenceField' => 'b']]));
		$this->assertNull($this->resolver->reverseFkDescriptor(['sourceLink' => ['mode' => 'embedded', 'sourceSchema' => 'a', 'referenceField' => 'b']]));
		$this->assertSame(
			['sourceSchema' => 'srcSchema', 'referenceField' => 'master', 'sourceRegister' => ''],
			$this->resolver->reverseFkDescriptor($this->reverseFk())
		);
	}//end testDescriptorBranches()

	public function testAMissingSchemaWarnsAndDegrades(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'x-openregister source resolution: reverseFk sourceLink is missing sourceSchema or referenceField; falling back to embedded mode.'
		);

		$this->assertNull($this->resolver->reverseFkDescriptor(['sourceLink' => ['mode' => 'reverseFk', 'referenceField' => 'b']]));
	}//end testAMissingSchemaWarnsAndDegrades()

	public function testSchemaQueryFilterBranches(): void {
		$schema = new Schema();
		$schema->setId(42);

		$this->schemaMapper->expects($this->exactly(3))
			->method('find')
			->willReturnCallback(
				function (string|int $ref, ?array $extend, bool $rbac, bool $multitenancy) use ($schema): Schema {
					$this->assertFalse($rbac);
					$this->assertFalse($multitenancy);
					if ($ref === 'golden-throws') {
						throw new RuntimeException('inside a save transaction');
					}

					return $schema;
				}
			);

		$this->assertSame('', $this->resolver->schemaQueryFilter(''));
		$this->assertSame('17', $this->resolver->schemaQueryFilter('17'));
		$this->assertSame('42', $this->resolver->schemaQueryFilter('golden-slug'));
		// Cached: no second lookup.
		$this->assertSame('42', $this->resolver->schemaQueryFilter('golden-slug'));
		// A throwing lookup falls back to the reference and is not cached.
		$this->assertSame('golden-throws', $this->resolver->schemaQueryFilter('golden-throws'));
		$this->assertSame('golden-throws', $this->resolver->schemaQueryFilter('golden-throws'));
	}//end testSchemaQueryFilterBranches()

	public function testReverseFkQueryIsScopedAndDefaultsTheRegister(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('no'));

		$calls = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $rbac, bool $multitenancy) use (&$calls): array {
				$calls[] = [$config, $rbac, $multitenancy];
				return [
					'not an entity',
					$this->entity(['master' => 'm-1', 'sourceSystem' => 'a']),
					$this->entity(['master' => 'm-2', 'sourceSystem' => 'stray']),
					$this->entity(null),
				];
			}
		);

		$this->assertSame(
			[['id' => null, 'master' => 'm-1', 'sourceSystem' => 'a']],
			$this->resolver->resolveSources(masterData: [], masterUuid: 'm-1', config: $this->reverseFk(), masterRegister: 'masterReg')
		);
		$this->assertSame(
			[['id' => null, 'master' => 'm-1', 'sourceSystem' => 'a']],
			$this->resolver->resolveSources(masterData: [], masterUuid: 'm-1', config: $this->reverseFk(['sourceRegister' => 'own']))
		);
		$this->resolver->resolveSources(masterData: [], masterUuid: 'm-1', config: $this->reverseFk());

		$this->assertSame(
			[
				[['filters' => ['schema' => 'srcSchema', 'master' => 'm-1', 'register' => 'masterReg']], true, true],
				[['filters' => ['schema' => 'srcSchema', 'master' => 'm-1', 'register' => 'own']], true, true],
				[['filters' => ['schema' => 'srcSchema', 'master' => 'm-1']], true, true],
			],
			$calls
		);
	}//end testReverseFkQueryIsScopedAndDefaultsTheRegister()

	public function testAFailingReverseFkQueryWarnsAndReturnsNothing(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('no'));
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Reverse-FK source resolution failed for master "m-1": boom'
		);

		$this->assertSame([], $this->resolver->resolveSources(masterData: [], masterUuid: 'm-1', config: $this->reverseFk()));
	}//end testAFailingReverseFkQueryWarnsAndReturnsNothing()

	public function testEmbeddedEntryBranches(): void {
		$finds = [];
		$this->objectService->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend, bool $files, mixed $register, mixed $schema, bool $rbac, bool $multitenancy) use (&$finds): ?ObjectEntity {
				$finds[] = [$id, $rbac, $multitenancy];
				return match ($id) {
					'found' => $this->entity(['sourceSystem' => 'ref']),
					'empty' => $this->entity(null),
					'missing' => null,
					default => throw new RuntimeException('not readable'),
				};
			}
		);

		$config = ['sourceLinkField' => 'sources'];
		$this->assertSame([], $this->resolver->resolveSources(masterData: ['sources' => 'x'], masterUuid: 'm', config: $config));
		$this->assertSame([], $this->resolver->resolveSources(masterData: [], masterUuid: 'm', config: $config));
		$this->assertSame(
			[['inline' => true], ['id' => null, 'sourceSystem' => 'ref'], ['id' => null]],
			$this->resolver->resolveSources(
				masterData: ['sources' => [['inline' => true], '', 7, null, 'found', 'missing', 'denied', 'empty']],
				masterUuid: 'm',
				config: $config
			)
		);
		$this->assertSame(
			[['found', true, true], ['missing', true, true], ['denied', true, true], ['empty', true, true]],
			$finds
		);
	}//end testEmbeddedEntryBranches()
}//end class
