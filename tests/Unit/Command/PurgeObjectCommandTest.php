<?php

/**
 * Unit tests for the sanctioned administrative purge.
 *
 * The HTTP trash endpoint refuses an archival record outright. This command is
 * the one path that can still destroy one, so what it refuses without --force
 * is as much a part of the contract as what it destroys with it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\PurgeObjectCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Contract tests for `occ openregister:objects:purge`.
 */
class PurgeObjectCommandTest extends TestCase {

	/**
	 * Object mapper double.
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper&MockObject $objectMapper;

	/**
	 * Schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * UUIDs actually destroyed.
	 *
	 * @var string[]
	 */
	private array $purged = [];

	/**
	 * Build the command under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->purged = [];

		$this->objectMapper->method('delete')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->purged[] = (string)$entity->getUuid();

				return $entity;
			}
		);
	}//end setUp()

	/**
	 * Run the command against one prepared object.
	 *
	 * @param bool     $trashed  Whether the object carries deletion metadata.
	 * @param bool     $archival Whether its schema declares x-openregister-archival.
	 * @param string[] $options  Extra console options, e.g. ['--force' => true].
	 *
	 * @return CommandTester The finished tester.
	 */
	private function runPurge(bool $trashed, bool $archival, array $options = []): CommandTester {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('172');
		if ($trashed === true) {
			$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);
		}

		$schema = new Schema();
		$schema->setSlug('case');
		$configuration = [];
		if ($archival === true) {
			$configuration = ['x-openregister-archival' => ['retention' => ['default' => 'P30D']]];
		}

		$schema->setConfiguration($configuration);

		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->willReturn($schema);

		$tester = new CommandTester(
			new PurgeObjectCommand(objectMapper: $this->objectMapper, schemaMapper: $this->schemaMapper)
		);
		$tester->execute(array_merge(['uuid' => ['obj-1'], '--apply' => true], $options));

		return $tester;
	}//end runPurge()

	/**
	 * Without --force an archival record is refused, exactly as over HTTP.
	 *
	 * @return void
	 */
	public function testRefusesAnArchivalRecordWithoutForce(): void {
		$tester = $this->runPurge(trashed: true, archival: true);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('x-openregister-archival', $tester->getDisplay());
		$this->assertSame([], $this->purged);
	}//end testRefusesAnArchivalRecordWithoutForce()

	/**
	 * With --force it is destroyed, and the output says what it was.
	 *
	 * @return void
	 */
	public function testForcePurgesAnArchivalRecord(): void {
		$tester = $this->runPurge(trashed: true, archival: true, options: ['--force' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('ARCHIVAL RECORD', $tester->getDisplay());
		$this->assertSame(['obj-1'], $this->purged);
	}//end testForcePurgesAnArchivalRecord()

	/**
	 * A live object is refused without --force here too: the command is an
	 * administrative escape hatch, not a shortcut around the lifecycle.
	 *
	 * @return void
	 */
	public function testRefusesALiveObjectWithoutForce(): void {
		$tester = $this->runPurge(trashed: false, archival: false);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('live', $tester->getDisplay());
		$this->assertSame([], $this->purged);
	}//end testRefusesALiveObjectWithoutForce()

	/**
	 * An ordinary trashed object needs no flag.
	 *
	 * @return void
	 */
	public function testPurgesAnOrdinaryTrashedObject(): void {
		$tester = $this->runPurge(trashed: true, archival: false);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertSame(['obj-1'], $this->purged);
	}//end testPurgesAnOrdinaryTrashedObject()

	/**
	 * Dry run is the default: without --apply nothing is written.
	 *
	 * @return void
	 */
	public function testDryRunDestroysNothing(): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('172');
		$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);

		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration([]);

		$this->objectMapper->method('find')->willReturn($object);
		$this->schemaMapper->method('find')->willReturn($schema);

		$tester = new CommandTester(
			new PurgeObjectCommand(objectMapper: $this->objectMapper, schemaMapper: $this->schemaMapper)
		);
		$tester->execute(['uuid' => ['obj-1']]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('would purge', $tester->getDisplay());
		$this->assertSame([], $this->purged);
	}//end testDryRunDestroysNothing()
}//end class
