<?php

/**
 * ExpirySweepCommand: the dry run changes nothing and prints the counts.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use DateTime;
use OCA\OpenRegister\Command\ExpirySweepCommand;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Retention\ExpiredObjectStoreInterface;
use OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * REQ-EXP-003: `occ openregister:expiry:sweep --dry-run` prints the counts
 * per schema and writes nothing; without it the sweep runs.
 *
 * @covers \OCA\OpenRegister\Command\ExpirySweepCommand
 * @uses   \OCA\OpenRegister\Service\Retention\ExpiredObjectSweeper
 * @uses   \OCA\OpenRegister\Service\Retention\ExpiryDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class ExpirySweepCommandTest extends TestCase {

	/**
	 * A command over a store with one opted-in table holding a draft to delete and a submission to anonymise.
	 *
	 * @param ExpiredObjectStoreInterface $store The store mock.
	 *
	 * @return CommandTester
	 */
	private function tester(ExpiredObjectStoreInterface $store): CommandTester {
		$register = new Register();
		$register->setSlug('portal');
		$schema = new Schema();
		$schema->setSlug('portalIntakeSubmission');
		$schema->setProperties(['reference' => [], 'retentionMethod' => []]);
		$schema->setConfiguration(['x-openregister' => ['expiry' => ['action' => 'anonymise', 'keep' => ['reference'], 'actionField' => 'retentionMethod']]]);

		$objects = [];
		foreach (['delete', 'anonymise'] as $i => $method) {
			$object = new ObjectEntity();
			$object->setUuid('o-'.$i);
			$object->setExpires(new DateTime('2026-01-01'));
			$object->setObject(['reference' => 'R', 'retentionMethod' => $method]);
			$objects[] = $object;
		}

		$store->method('optedInTables')->willReturn([['register' => $register, 'schema' => $schema]]);
		$store->method('expired')->willReturn($objects);
		$store->method('countExpired')->willReturn(3);
		$store->method('delete')->willReturn(true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(500);

		return new CommandTester(
			new ExpirySweepCommand(
				sweeper: new ExpiredObjectSweeper(
					store: $store,
					retention: $this->createMock(RetentionServiceInterface::class),
					appConfig: $appConfig,
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);
	}//end tester()

	/**
	 * The dry run prints the counts and writes nothing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$store = $this->createMock(ExpiredObjectStoreInterface::class);
		$store->expects($this->never())->method('delete');
		$store->expects($this->never())->method('anonymise');
		$store->expects($this->never())->method('audit');
		$tester = $this->tester($store);

		$this->assertSame(0, $tester->execute(['--dry-run' => true]));
		$this->assertSame(
			"  portal / portalIntakeSubmission: 1 deleted, 1 anonymised, 0 kept for a legal hold, 0 failed, 1 left for the next run\nDry run: nothing was changed.\n",
			$tester->getDisplay()
		);
	}//end testADryRunWritesNothing()

	/**
	 * Without the flag the sweep writes.
	 *
	 * @return void
	 */
	public function testARealRunWrites(): void {
		$store = $this->createMock(ExpiredObjectStoreInterface::class);
		$store->expects($this->once())->method('delete')->willReturn(true);
		$store->expects($this->once())->method('anonymise');
		$store->expects($this->exactly(2))->method('audit');
		$tester = $this->tester($store);

		$this->assertSame(0, $tester->execute([]));
		$this->assertStringEndsWith("Sweep done.\n", $tester->getDisplay());
		$this->assertSame('openregister:expiry:sweep', ExpirySweepCommand::NAME);
	}//end testARealRunWrites()

	/**
	 * With no opted-in schema it says so.
	 *
	 * @return void
	 */
	public function testNoSchemaOptsIn(): void {
		$store = $this->createMock(ExpiredObjectStoreInterface::class);
		$store->method('optedInTables')->willReturn([]);
		$appConfig = $this->createMock(IAppConfig::class);
		$tester = new CommandTester(new ExpirySweepCommand(sweeper: new ExpiredObjectSweeper(store: $store, retention: $this->createMock(RetentionServiceInterface::class), appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class))));

		$this->assertSame(0, $tester->execute(['--dry-run' => true]));
		$this->assertSame("No schema opts in to expiry.\n", $tester->getDisplay());
	}//end testNoSchemaOptsIn()
}//end class
