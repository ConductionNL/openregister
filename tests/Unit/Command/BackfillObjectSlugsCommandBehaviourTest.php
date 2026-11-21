<?php

/**
 * BackfillObjectSlugsCommand reports an unexpected backfill failure instead of
 * crashing, and still prints the result of a run that went through.
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

use OCA\OpenRegister\Service\Object\ObjectSlugBackfillRequest;
use OCA\OpenRegister\Command\BackfillObjectSlugsCommand;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\ObjectSlugBackfillServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Behaviour of the generic failure path around the backfill run.
 */
class BackfillObjectSlugsCommandBehaviourTest extends TestCase {

	/** @var ObjectSlugBackfillServiceInterface&MockObject */
	private ObjectSlugBackfillServiceInterface $backfill;

	private Register $register;

	private Schema $schema;

	protected function setUp(): void {
		$this->register = new Register();
		$this->register->setId(7);
		$this->register->setSchemas([11]);
		$this->schema = new Schema();
		$this->schema->setId(11);
		$this->backfill = $this->createMock(ObjectSlugBackfillServiceInterface::class);
	}

	private function tester(): CommandTester {
		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturn($this->register);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($this->schema);

		return new CommandTester(new BackfillObjectSlugsCommand(new SchemaCatalog($registers, $schemas, $this->createMock(MagicMapperInterface::class)), $this->backfill));
	}

	public function testUnexpectedFailureIsReportedAndExitsFailure(): void {
		$this->backfill->expects($this->once())->method('run')
			->with(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema, dryRun: false))
			->willThrowException(new RuntimeException('connection reset'));

		$tester = $this->tester();
		$exit   = $tester->execute(['register' => '7', 'schema' => '11']);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertSame('The slug backfill failed: connection reset', trim($tester->getDisplay()));
	}

	public function testSuccessfulRunPrintsTheSummary(): void {
		$this->backfill->expects($this->once())->method('run')
			->with(new ObjectSlugBackfillRequest(register: $this->register, schema: $this->schema, dryRun: true))
			->willReturn(
				[
					'duplicates' => [],
					'plan'       => [],
					'summary'    => ['scanned' => 4, 'assigned' => 0, 'collisions' => 0, 'unchanged' => 4, 'duplicates' => 0, 'skipped' => 0],
				]
			);

		$tester = $this->tester();
		$exit   = $tester->execute(['register' => '7', 'schema' => '11', '--dry-run' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString(
			'scanned=4 assigned=0 collisions=0 unchanged=4 duplicates=0 skipped=0 (dry run — nothing written)',
			$tester->getDisplay()
		);
		$this->assertStringNotContainsString('failed', $tester->getDisplay());
	}
}
