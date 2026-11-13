<?php

/**
 * The organisation reconcile command: a dry run reports, --apply repairs and records.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ReconcileOrganisationCommand;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Organisation\OrganisationAnnotation;
use OCA\OpenRegister\Service\WriteCause;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks what the command reports, what it refuses to write without --apply, and what it records.
 *
 * @covers \OCA\OpenRegister\Command\ReconcileOrganisationCommand
 *
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\Organisation\OrganisationAnnotation
 * @uses \OCA\OpenRegister\Service\Organisation\OrganisationFromProperty
 * @uses \OCA\OpenRegister\Service\WriteCause
 */
class ReconcileOrganisationCommandTest extends TestCase {

	/**
	 * The organisation three publications name, and are not scoped to.
	 *
	 * @var string
	 */
	private const NAMED = 'org-omgevingsdienst';

	/**
	 * The organisation their rights follow today.
	 *
	 * @var string
	 */
	private const STAMPED = 'org-gemeente';

	/**
	 * Reset the cause stack, so one test's frame cannot label another's writes.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WriteCause::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * One publication naming an organisation, scoped to another.
	 *
	 * @param string      $uuid         Its uuid.
	 * @param string|null $named        The organisation its property names.
	 * @param string|null $organisation The organisation its rights follow.
	 *
	 * @return ObjectEntity The publication.
	 */
	private function publication(string $uuid, ?string $named, ?string $organisation): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject(['organization' => $named]);
		$object->setOrganisation($organisation);

		return $object;
	}//end publication()

	/**
	 * The command, over the given stored objects.
	 *
	 * @param array<int, ObjectEntity> $objects    What the table holds.
	 * @param bool                     $declaring  Whether the schema declares the annotation.
	 * @param MagicMapperInterface     ...$capture Optional pre-built tables double, to assert writes on.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(array $objects, bool $declaring = true, ?MagicMapperInterface $capture = null): CommandTester {
		$register = new Register();
		$register->setSlug('publications');
		$schema = new Schema();
		$schema->setSlug('publication');
		$schema->setProperties(['organization' => ['type' => 'string']]);
		$configuration = [];
		if ($declaring === true) {
			$configuration = [OrganisationAnnotation::ANNOTATION => [OrganisationAnnotation::FROM_PROPERTY => 'organization']];
		}

		$schema->setConfiguration($configuration);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$tables = ($capture ?? $this->createMock(MagicMapperInterface::class));
		$tables->method('findAllInRegisterSchemaTable')->willReturn($objects);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($registers);
		$catalog->method('schemas')->willReturn($schemas);
		$catalog->method('tables')->willReturn($tables);

		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('createAuditTrail')->willReturn(new AuditTrail());

		return new CommandTester(new ReconcileOrganisationCommand(catalog: $catalog, audit: $audit));
	}//end tester()

	/**
	 * A dry run lists every disagreement and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunChangesNothing(): void {
		$objects = [
			$this->publication(uuid: 'aaa', named: self::NAMED, organisation: self::STAMPED),
			$this->publication(uuid: 'bbb', named: self::NAMED, organisation: self::STAMPED),
			$this->publication(uuid: 'ccc', named: self::NAMED, organisation: null),
			$this->publication(uuid: 'ddd', named: self::NAMED, organisation: self::NAMED),
		];
		$tables = $this->createMock(MagicMapperInterface::class);
		$tables->expects($this->never())->method('update');

		$tester = $this->tester(objects: $objects, capture: $tables);
		$exit = $tester->execute(['--register' => 'publications', '--schema' => 'publication']);
		$output = $tester->getDisplay();

		$this->assertSame(expected: Command::SUCCESS, actual: $exit);
		$this->assertStringContainsString(needle: 'Disagreeing 3, changed 0', haystack: $output);
		$this->assertStringContainsString(needle: 'aaa: organization=' . self::NAMED, haystack: $output);
		$this->assertStringContainsString(needle: '@self.organisation=none', haystack: $output);
		$this->assertStringNotContainsString(needle: 'ddd', haystack: $output);
		$this->assertSame(expected: self::STAMPED, actual: $objects[0]->getOrganisation());
	}//end testADryRunChangesNothing()

	/**
	 * `--apply` writes each disagreeing object and records one audit row per object, caused `migration`.
	 *
	 * @return void
	 */
	public function testApplyWritesAnAuditRowPerObject(): void {
		$objects = [
			$this->publication(uuid: 'aaa', named: self::NAMED, organisation: self::STAMPED),
			$this->publication(uuid: 'bbb', named: self::NAMED, organisation: self::STAMPED),
			$this->publication(uuid: 'ccc', named: self::NAMED, organisation: self::NAMED),
		];

		$register = new Register();
		$register->setSlug('publications');
		$schema = new Schema();
		$schema->setSlug('publication');
		$schema->setProperties(['organization' => ['type' => 'string']]);
		$schema->setConfiguration(
			[OrganisationAnnotation::ANNOTATION => [OrganisationAnnotation::FROM_PROPERTY => 'organization']]
		);

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$written = [];
		$tables = $this->createMock(MagicMapperInterface::class);
		$tables->method('findAllInRegisterSchemaTable')->willReturn($objects);
		$tables->expects($this->exactly(2))->method('update')->willReturnCallback(
			function (mixed $entity, mixed $forRegister, mixed $forSchema, mixed $old) use (&$written): ObjectEntity {
				$written[] = [
					'uuid' => (string)$entity->getUuid(),
					'organisation' => (string)$entity->getOrganisation(),
					'was' => (string)$old->getOrganisation(),
					'register' => $forRegister,
					'schema' => $forSchema,
				];

				return $entity;
			}
		);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('registers')->willReturn($registers);
		$catalog->method('schemas')->willReturn($schemas);
		$catalog->method('tables')->willReturn($tables);

		$causes = [];
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->exactly(2))->method('createAuditTrail')->willReturnCallback(
			function (mixed $old, mixed $new, mixed $action) use (&$causes): AuditTrail {
				$causes[] = [WriteCause::current()['cause'], $action, (string)$new->getOrganisation()];

				return new AuditTrail();
			}
		);

		$tester = new CommandTester(new ReconcileOrganisationCommand(catalog: $catalog, audit: $audit));
		$exit = $tester->execute(['--register' => 'publications', '--schema' => 'publication', '--apply' => true]);

		$this->assertSame(expected: Command::SUCCESS, actual: $exit);
		$this->assertStringContainsString(needle: 'Disagreeing 2, changed 2', haystack: $tester->getDisplay());
		$this->assertStringNotContainsString(needle: 'dry run', haystack: $tester->getDisplay());
		$this->assertSame(
			expected: [
				['uuid' => 'aaa', 'organisation' => self::NAMED, 'was' => self::STAMPED, 'register' => $register, 'schema' => $schema],
				['uuid' => 'bbb', 'organisation' => self::NAMED, 'was' => self::STAMPED, 'register' => $register, 'schema' => $schema],
			],
			actual: $written
		);
		$this->assertSame(
			expected: [
				[WriteCause::MIGRATION, 'update', self::NAMED],
				[WriteCause::MIGRATION, 'update', self::NAMED],
			],
			actual: $causes
		);
		$this->assertSame(expected: self::NAMED, actual: $objects[0]->getOrganisation());
		$this->assertSame(expected: WriteCause::PERSON, actual: WriteCause::current()['cause']);
	}//end testApplyWritesAnAuditRowPerObject()

	/**
	 * A schema that declares nothing has nothing to reconcile, and is not walked.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheAnnotationIsNotWalked(): void {
		$tables = $this->createMock(MagicMapperInterface::class);
		$tables->expects($this->never())->method('update');

		$tester = $this->tester(objects: [], declaring: false, capture: $tables);
		$exit = $tester->execute(['--register' => 'publications', '--schema' => 'publication']);

		$this->assertSame(expected: Command::SUCCESS, actual: $exit);
		$this->assertStringContainsString(
			needle: OrganisationAnnotation::ANNOTATION . ' — nothing to reconcile',
			haystack: $tester->getDisplay()
		);
	}//end testASchemaWithoutTheAnnotationIsNotWalked()
}//end class
