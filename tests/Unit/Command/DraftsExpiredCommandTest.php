<?php

/**
 * `occ openregister:drafts:expired` counts per schema and removes nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-an-administrator-must-be-able-to-see-what-the-draft-cleanup-would-remove
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use DateTime;
use OCA\OpenRegister\Command\DraftsExpiredCommand;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\DraftExpiry;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Decision 228: an administrator looks before the job runs.
 *
 * @covers \OCA\OpenRegister\Command\DraftsExpiredCommand
 * @uses \OCA\OpenRegister\Service\Object\DraftExpiry
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\DraftListLens
 * @uses \OCA\OpenRegister\Db\MagicMapper\DeletedLens
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 */
class DraftsExpiredCommandTest extends TestCase {

	/**
	 * The command over a real sweep, with one register, a schema without a setting and one that opts out.
	 *
	 * @param array<int, ObjectEntity> $drafts  The drafts of the schema without a setting.
	 * @param int                      $deletes Receives the number of deletes.
	 */
	private function command(array $drafts, int &$deletes): DraftsExpiredCommand {
		$case = new Schema();
		$case->setId(1);
		$case->setSlug('case');
		$memo = new Schema();
		$memo->setId(2);
		$memo->setSlug('memo');
		$memo->setConfiguration(['draftLifetimeDays' => false]);
		$register = new Register();
		$register->setId(10);
		$register->setSlug('service');
		$register->setSchemas([1, 2]);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([$register]);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(static fn (string|int $id): Schema => [1 => $case, 2 => $memo][(int)$id]);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjects')->willReturnCallback(static fn (array $query = []): array => (int)$query['_offset'] === 0 ? $drafts : []);
		$objects->method('deleteObject')->willReturnCallback(
			static function () use (&$deletes): bool {
				$deletes++;

				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(DraftExpiry::class)->willReturn(
			new DraftExpiry(objects: $objects, registers: $registers, schemas: $schemas, logger: $this->createMock(LoggerInterface::class))
		);

		return new DraftsExpiredCommand(container: $container);
	}//end command()

	/**
	 * A draft last changed that many days ago.
	 */
	private function draft(string $uuid, int $daysAgo): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setStatus('draft');
		$entity->setUpdated(new DateTime('-' . $daysAgo . ' days'));

		return $entity;
	}//end draft()

	/**
	 * Spec scenario: the command lists the count per schema with its lifetime, and removes nothing.
	 */
	public function testItListsTheCountPerSchemaAndRemovesNothing(): void {
		$deletes = 0;
		$tester = new CommandTester($this->command(drafts: [$this->draft('a', 45), $this->draft('b', 31), $this->draft('c', 3)], deletes: $deletes));

		$this->assertSame(0, $tester->execute([]));

		$output = $tester->getDisplay();
		$this->assertStringContainsString('Schemas whose drafts expire: 1. Nothing was removed.', $output);
		$this->assertStringContainsString('service / case: 2 drafts unchanged for more than 30 days', $output);
		$this->assertStringContainsString('"draftLifetimeDays": false', $output);
		$this->assertStringNotContainsString('memo', $output);
		$this->assertSame(0, $deletes);
		$this->assertSame('openregister:drafts:expired', DraftsExpiredCommand::NAME);
	}//end testItListsTheCountPerSchemaAndRemovesNothing()

	/**
	 * The same counts as JSON, for a script.
	 */
	public function testItPrintsJson(): void {
		$deletes = 0;
		$tester = new CommandTester($this->command(drafts: [$this->draft('a', 45)], deletes: $deletes));

		$this->assertSame(0, $tester->execute(['--json' => true]));

		$this->assertSame(
			['schemas' => 1, 'expired' => 1, 'perSchema' => [['register' => 'service', 'schema' => 'case', 'lifetimeDays' => 30, 'expired' => 1]]],
			json_decode($tester->getDisplay(), true)
		);
		$this->assertSame(0, $deletes);
	}//end testItPrintsJson()

	/**
	 * Nothing past its lifetime is said in so many words.
	 */
	public function testNothingExpiredIsSaid(): void {
		$deletes = 0;
		$tester = new CommandTester($this->command(drafts: [$this->draft('c', 3)], deletes: $deletes));

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('No draft is past its lifetime.', $tester->getDisplay());
	}//end testNothingExpiredIsSaid()

	/**
	 * The command is declared in appinfo/info.xml; without the line occ does not know it.
	 */
	public function testTheCommandIsDeclared(): void {
		$info = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		$this->assertNotFalse($info);
		$declared = array_map(static fn ($command): string => trim((string)$command), iterator_to_array($info->commands->command, false));

		$this->assertContains(DraftsExpiredCommand::class, $declared);
	}//end testTheCommandIsDeclared()
}//end class
