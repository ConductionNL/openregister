<?php

/**
 * BackfillObjectSlugsCommand unit tests (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-occ-openregisterbackfill-slugs-must-fill-missing-slugs-idempotently-and-must-not-silently-change-duplicates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\BackfillObjectSlugsCommand;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillService;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the console surface of `openregister:backfill-slugs`.
 */
class BackfillObjectSlugsCommandTest extends TestCase {

	private const UUID_A = '00000000-0000-0000-0000-00000000000a';

	/** @var RegisterMapper&MockObject */
	private RegisterMapper $registerMapper;

	/** @var SchemaMapper&MockObject */
	private SchemaMapper $schemaMapper;

	/** @var MagicMapper&MockObject */
	private MagicMapper $magicMapper;

	/** @var AuditTrailMapper&MockObject */
	private AuditTrailMapper $auditTrail;

	private Register $register;

	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSlug('woo');
		$this->register->setSchemas([11]);

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->magicMapper = $this->createMock(MagicMapper::class);
		$this->auditTrail = $this->createMock(AuditTrailMapper::class);
	}//end setUp()

	/**
	 * The command over a real service on mocked storage.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(): CommandTester {
		$logger = $this->createMock(LoggerInterface::class);
		$service = new ObjectSlugBackfillService(
			magicMapper: $this->magicMapper,
			slugHandler: new ObjectSlugHandler(
				magicMapper: $this->magicMapper,
				hydrator: new MetadataHydrationHandler(
					logger: $this->createMock(LoggerInterface::class),
					cacheHandler: $this->createMock(CacheHandler::class)
				),
				registerMapper: $this->registerMapper,
				logger: $logger
			),
			cacheHandler: $this->createMock(CacheHandler::class),
			db: $this->createMock(IDBConnection::class),
			auditTrail: $this->auditTrail,
			logger: $logger
		);

		return new CommandTester(new BackfillObjectSlugsCommand($this->registerMapper, $this->schemaMapper, $service));
	}//end tester()

	/**
	 * A schema.
	 *
	 * @param int   $id            The schema id.
	 * @param array $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id=11, array $configuration=['objectSlugField' => 'title']): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('convenanten');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	public function testASchemaWithoutSlugFieldExitsNonZeroWithAMessage(): void {
		$this->schemaMapper->method('findBySlugInIds')->willReturn($this->schema(11, ['objectNameField' => 'title']));
		$this->magicMapper->expects($this->never())->method('updateSlugColumn');

		$tester = $this->tester();
		$exit = $tester->execute(['register' => 'woo', 'schema' => 'convenanten']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('has no objectSlugField', $tester->getDisplay());
	}

	public function testAnUnresolvedRegisterExitsNonZeroWithAMessage(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('no such register'));
		$this->registerMapper = $registerMapper;

		$tester = $this->tester();
		$exit = $tester->execute(['register' => 'nope', 'schema' => 'convenanten']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('Register or schema not found: no such register', $tester->getDisplay());
	}

	public function testASchemaOutsideTheRegisterExitsNonZeroWithAMessage(): void {
		$this->schemaMapper->method('findBySlugInIds')->willReturn($this->schema(99));
		$this->magicMapper->expects($this->never())->method('findSlugBackfillPage');

		$tester = $this->tester();
		$exit = $tester->execute(['register' => 'woo', 'schema' => 'convenanten']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('is not in register woo', $tester->getDisplay());
	}

	public function testEachArgumentAcceptsAnIdAndFallsBackFromSlugToFind(): void {
		// A numeric schema argument skips the slug lookup and goes through find().
		$this->schemaMapper->expects($this->never())->method('findBySlugInIds');
		$this->schemaMapper->expects($this->once())->method('find')->with('11')->willReturn($this->schema());
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturn(['rows' => [], 'fetched' => 0, 'lastId' => 0]);

		$tester = $this->tester();
		$exit = $tester->execute(['register' => '7', 'schema' => '11']);

		$this->assertSame(0, $exit);
	}

	public function testAFailingWriteChunkExitsNonZeroWithTheAppliedCount(): void {
		// Review finding r2routes-f2: 501 rows are two chunks; the second one fails.
		$rows = [];
		for ($i = 1; $i <= 501; $i++) {
			$rows[] = ['id' => $i, 'uuid' => sprintf('00000000-0000-0000-0000-%012d', $i), 'slug' => null, 'created' => null, 'object' => ['title' => 'Row ' . $i]];
		}

		$this->schemaMapper->method('findBySlugInIds')->willReturn($this->schema());
		$this->magicMapper->method('findDuplicateSlugs')->willReturn([]);
		$this->magicMapper->method('findAllSlugs')->willReturn([]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			['rows' => $rows, 'fetched' => 501, 'lastId' => 501],
			['rows' => [], 'fetched' => 0, 'lastId' => 501]
		);
		$calls = 0;
		$this->magicMapper->method('updateSlugColumn')->willReturnCallback(
			function () use (&$calls): int {
				$calls++;
				if ($calls === 501) {
					throw new RuntimeException('deadlock');
				}

				return 1;
			}
		);

		$tester = $this->tester();
		$exit = $tester->execute(['register' => 'woo', 'schema' => 'convenanten']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('deadlock', $tester->getDisplay());
		$this->assertStringContainsString('applied=500', $tester->getDisplay());
	}

	public function testADryRunPrintsThePlanAndWritesNothing(): void {
		$this->schemaMapper->method('findBySlugInIds')->willReturn($this->schema());
		$uuidB = '00000000-0000-0000-0000-00000000000b';
		$uuidC = '00000000-0000-0000-0000-00000000000c';
		$this->magicMapper->method('findDuplicateSlugs')->willReturn(['dup' => [$uuidB, $uuidC]]);
		$this->magicMapper->method('findAllSlugs')->willReturn(['dup' => [$uuidB, $uuidC]]);
		$this->magicMapper->method('findSlugBackfillPage')->willReturnOnConsecutiveCalls(
			['rows' => [['id' => 1, 'uuid' => self::UUID_A, 'slug' => null, 'created' => null, 'object' => ['title' => 'Hello World']]], 'fetched' => 1, 'lastId' => 1],
			['rows' => [], 'fetched' => 0, 'lastId' => 1]
		);
		$this->magicMapper->expects($this->never())->method('updateSlugColumn');
		$this->auditTrail->expects($this->never())->method('createSlugBackfillEntry');

		$tester = $this->tester();
		$exit = $tester->execute(['register' => 'woo', 'schema' => 'convenanten', '--dry-run' => true]);

		$this->assertSame(0, $exit);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('duplicate "dup": ' . $uuidB . ', ' . $uuidC, $display);
		$this->assertStringContainsString(self::UUID_A . ': "" -> "hello-world"', $display);
		$this->assertStringContainsString('scanned=1 assigned=1 collisions=0 unchanged=0 duplicates=1 skipped=0 (dry run', $display);
	}

	public function testTheCommandHasNoRewriteOption(): void {
		// Robert Zondervan (2026-10-23): only empty slugs are filled; there is no flag that rewrites set slugs.
		$command = new BackfillObjectSlugsCommand($this->registerMapper, $this->schemaMapper, $this->createMock(ObjectSlugBackfillService::class));

		$this->assertSame(['dry-run'], array_keys($command->getDefinition()->getOptions()));
	}

}//end class
