<?php

/**
 * A scheme run serialises its progress, and its refusal names it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationRunningException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The entity and the running-migration refusal.
 */
#[CoversClass(IdentifierSchemeRun::class)]
#[CoversClass(IdentifierSchemeMigrationRunningException::class)]
class IdentifierSchemeRunTest extends TestCase {

	/**
	 * A new run counts from zero and serialises its dates as ATOM.
	 *
	 * @return void
	 */
	public function testARunSerialisesItsProgress(): void {
		$run = new IdentifierSchemeRun();
		$this->assertSame([0, 0, 0, 0, 0], [$run->getTotal(), $run->getProcessed(), $run->getRewritten(), $run->getSkipped(), $run->getFailed()]);

		$run->setId(3);
		$run->setSequence('case');
		$run->setActiveSequence('case');
		$run->setProcessed(5);
		$run->setStarted(new DateTime('2026-11-14T10:00:00+00:00'));
		$json = $run->jsonSerialize();

		$this->assertSame(3, $json['id']);
		$this->assertSame(5, $json['processed']);
		$this->assertSame('2026-11-14T10:00:00+00:00', $json['started']);
		$this->assertNull($json['finished']);
		$this->assertArrayNotHasKey('activeSequence', $json, 'The lock column is internal.');
	}//end testARunSerialisesItsProgress()

	/**
	 * The refusal names the running run, or only the sequence when it is unknown.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheRunningRun(): void {
		$running = new IdentifierSchemeRun();
		$running->setId(8);
		$running->setSchemaId(2);
		$running->setProperty('letterNumber');
		$running->setStartedBy('anna');

		$named = new IdentifierSchemeMigrationRunningException(sequence: 'letter', running: $running);
		$bare  = new IdentifierSchemeMigrationRunningException(sequence: 'letter');

		$this->assertSame(
			'Sequence "letter" is already being migrated (run 8 on schema 2, property "letterNumber", started by anna); wait until that migration has finished.',
			$named->getMessage()
		);
		$this->assertSame($running, $named->getRunning());
		$this->assertSame('Sequence "letter" is already being migrated; wait until that migration has finished.', $bare->getMessage());
		$this->assertNull($bare->getRunning());
	}//end testTheRefusalNamesTheRunningRun()
}//end class
