<?php

declare(strict_types=1);

/**
 * Object helper units golden (characterisation) test
 *
 * Pins, on the code as it stood before the D06 complexity refactor, the
 * outcomes of small object-pipeline helpers that refactor touches: the slug
 * backfill writer's per-step outcomes and warnings, the cascade schema lookup
 * and list cascade, the facet cache scope ids and the version parser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillLedger;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillWriter;
use OCA\OpenRegister\Service\Object\ObjectSlugChangeSet;
use OCA\OpenRegister\Service\Object\ObjectVersionHandler;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden outcomes of slug-writer, cascade, facet-scope and version helpers.
 */
class ObjectHelperUnitsGoldenTest extends TestCase {
	/**
	 * One chunk: a step taken meanwhile, a written step, a step changed meanwhile, in order.
	 *
	 * @return void
	 */
	public function testSlugWriterChunkOutcomes(): void {
		$register = new Register();
		$register->setId(7);
		$register->setSlug('woo');
		$schema = new Schema();
		$schema->setId(11);
		$schema->setSlug('conv');

		$events = [];
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findSlugState')->willReturnCallback(
			function (RegisterSchema $table, mixed ...$rest) use (&$events): array {
				$args = [$table->register, $table->schema, ...$rest];
				$events[] = 'state:' . implode(',', $args[2]);
				return ['holders' => ['a' => ['u-other'], 'b' => ['u-2'], 'c' => []]];
			}
		);
		$magic->method('updateSlugColumn')->willReturnCallback(
			function (RegisterSchema $table, mixed ...$rest) use (&$events): int {
				$args = [$table->register, $table->schema, ...$rest];
				$events[] = 'update:' . $args[2] . '=' . $args[3];
				return $args[2] === 'u-2' ? 1 : 0;
			}
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function () use (&$events): void {
			$events[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function () use (&$events): void {
			$events[] = 'commit';
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message, array $context = []) use (&$events): void {
			$events[] = 'warn:' . $message . '|' . $context['objectUuid'];
		});

		$writer = new ObjectSlugBackfillWriter(
			magicMapper: $magic,
			db: $db,
			ledger: new ObjectSlugBackfillLedger(cacheHandler: $this->createMock(CacheHandler::class), audit: new \OCA\OpenRegister\Service\Object\AuditRecording(trail: $this->createMock(AuditTrailMapper::class), logger: $logger)),
			logger: $logger
		);

		$tally = ['skipped' => 0, 'applied' => []];
		$chunk = [
			['uuid' => 'u-1', 'old' => null, 'new' => 'a'],
			['uuid' => 'u-2', 'old' => null, 'new' => 'b'],
			['uuid' => 'u-3', 'old' => null, 'new' => 'c'],
			['uuid' => 'u-4', 'old' => null, 'new' => ''],
		];
		(new ReflectionMethod(ObjectSlugBackfillWriter::class, 'writeChunk'))->invokeArgs(
			$writer,
			[new ObjectSlugChangeSet($register, $schema, $chunk, []), &$tally]
		);

		$this->assertSame(
			[
				'state:a,b,c',
				'begin',
				'warn:[ObjectSlug] Backfill skipped object u-1 in register woo / schema conv: slug "a" was taken by another object while the backfill ran|u-1',
				'update:u-2=b',
				'update:u-3=c',
				'warn:[ObjectSlug] Backfill skipped object u-3 in register woo / schema conv: its slug changed while the backfill ran|u-3',
				'update:u-4=',
				'warn:[ObjectSlug] Backfill skipped object u-4 in register woo / schema conv: its slug changed while the backfill ran|u-4',
				'commit',
			],
			$events
		);
		$this->assertSame(3, $tally['skipped']);
		$this->assertSame([$chunk[1]], $tally['applied']);
	}//end testSlugWriterChunkOutcomes()

	/**
	 * The cascade schema lookup and the list cascade.
	 *
	 * @return void
	 */
	public function testCascadeHelpers(): void {
		$found = new Schema();
		$found->setSlug('person');
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$found]);
		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('isUuid')->willReturnCallback(
			static fn ($value): bool => is_string($value) === true && str_starts_with($value, 'uuid-')
		);
		$handler = new CascadingHandler(
			saveHandler: $this->createMock(SaveObjectInterface::class),
			schemaMapper: $schemaMapper,
			utilityHandler: $utility,
			logger: $this->createMock(LoggerInterface::class)
		);
		$cascadeSchema = new ReflectionMethod(CascadingHandler::class, 'cascadeSchema');

		$this->assertNull($cascadeSchema->invoke($handler, []));
		$this->assertNull($cascadeSchema->invoke($handler, ['$ref' => '']));
		$this->assertNull($cascadeSchema->invoke($handler, ['$ref' => 'person']));
		$this->assertNull($cascadeSchema->invoke($handler, ['$ref' => '#/components/schemas/']));
		$this->assertSame('person', $cascadeSchema->invoke($handler, ['$ref' => '#/components/schemas/person'])?->getSlug());

		$this->assertSame(
			['uuid-1', 'uuid-2'],
			$this->cascadeItems($handler, ['uuid-1', 'plain', 5, null, 'uuid-2', true])
		);
	}//end testCascadeHelpers()

	/**
	 * Run CascadingHandler::cascadeItems() with a target for a missing schema.
	 *
	 * @param CascadingHandler $handler The handler
	 * @param array            $items   The items
	 *
	 * @return array
	 */
	private function cascadeItems(CascadingHandler $handler, array $items): array {
		$targetClass = new ReflectionClass(\OCA\OpenRegister\Service\Object\CascadeTarget::class);
		$target = $targetClass->newInstance('parent-uuid', 1);
		return (new ReflectionMethod(CascadingHandler::class, 'cascadeItems'))->invoke($handler, $items, $target);
	}//end cascadeItems()

	/**
	 * Facet cache scope ids and the version parser.
	 *
	 * @return void
	 */
	public function testScopeIdsAndVersionParse(): void {
		$cache = (new ReflectionClass(FacetResponseCache::class))->newInstanceWithoutConstructor();
		$scopeIds = new ReflectionMethod(FacetResponseCache::class, 'scopeIdsFromQuery');
		$this->assertSame(
			['5', 'a', '7', 'b'],
			$scopeIds->invoke($cache, [5, null, 'a', '', [7, 'b', null, '', 1.5, ['x'], 'a'], 1.5, true, '5'])
		);
		$this->assertSame([], $scopeIds->invoke($cache, []));

		$version = new ObjectVersionHandler();
		$parse = new ReflectionMethod(ObjectVersionHandler::class, 'parse');
		$cases = [
			[null, null],
			['', null],
			['  ', null],
			['1.2.3', [1, 2, 3]],
			[' 01.0.10 ', [1, 0, 10]],
			['1.2', null],
			['1.2.3.4', null],
			['1.-2.3', null],
			['1.2.x', null],
			['1..3', null],
		];
		foreach ($cases as $i => [$raw, $expected]) {
			$this->assertSame($expected, $parse->invoke($version, $raw), "version case $i");
		}
	}//end testScopeIdsAndVersionParse()
}//end class
