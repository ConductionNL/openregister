<?php

/**
 * The reveals reach the trail, and reach it in a form the chain can seal.
 *
 * openregister#3882 shipped the collector and its collection point and
 * deliberately did not persist, so until this existed the feature COLLECTED
 * INTO NOTHING. Every test here is a way the other half could be wrong while
 * the audit page still fills up:
 *
 *  - 🔴 the VALUE of the protected property ending up in the row. The point of
 *    the row is that somebody saw a BSN; putting the BSN in it copies the very
 *    thing the property is protected for into a table built to be readable by
 *    auditors and impossible to delete, so the feature becomes a second and
 *    permanent disclosure of everything it audits;
 *  - a failed write taking the request down, which trades a recording problem
 *    for an availability one on a page somebody is entitled to see;
 *  - the collector not being emptied, so a second flush in one request writes
 *    the same rows again;
 *  - the overflow passing silently, leaving a trail that is short with nothing
 *    saying so;
 *  - rows built with no uuid, which `insertAuditTrails()` refuses outright —
 *    a mistake that would be a 500 in production and nothing here.
 *
 * WHAT THIS DOES NOT TEST, said plainly: it does not verify the hash chain.
 * That is `AuditHashService`'s and is covered by its own suite; this pins that
 * the rows are handed to the ONE mapper path that seals, rather than to a
 * second implementation of the hashing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Service\Rbac\RevealFlusher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins what reaches the trail, and what must never reach it.
 */
class RevealFlusherTest extends TestCase {

	private RevealCollector $collector;

	private AuditTrailMapper $mapper;

	private LoggerInterface $logger;

	/**
	 * Set up the collector and the doubled mapper.
	 *
	 * `onlyMethods` rather than `addMethods`: a double that can invent a method
	 * the real mapper lacks passes here while production 500s on the call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->collector = new RevealCollector();
		$this->mapper = $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['insertAuditTrails'])
			->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the flusher under test.
	 *
	 * @return RevealFlusher The flusher.
	 */
	private function flusher(): RevealFlusher {
		return new RevealFlusher(
			collector: $this->collector,
			mapper: $this->mapper,
			logger: $this->logger
		);
	}//end flusher()

	/**
	 * Every collected reveal becomes one row, handed to the sealing path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testEveryRevealBecomesOneSealedRow(): void {
		$this->collector->record('alice', 'object-1', 'bsn', 24, 14);
		$this->collector->record('alice', 'object-2', 'bsn', 24, 14);

		$captured = [];
		$this->mapper->expects($this->once())
			->method('insertAuditTrails')
			->willReturnCallback(
				static function (array $entries, int $chunkSize) use (&$captured): array {
					$captured = $entries;
					return $entries;
				}
			);

		$this->assertSame(2, $this->flusher()->flush());
		$this->assertCount(2, $captured);

		foreach ($captured as $row) {
			$this->assertInstanceOf(AuditTrail::class, $row);
			$this->assertSame(RevealCollector::ACTION, $row->getAction());
			$this->assertSame('alice', $row->getUser());
			$this->assertSame(24, $row->getSchema());
			$this->assertSame(14, $row->getRegister());
			// 🔴 `insertAuditTrails()` REFUSES a pre-built row with no uuid,
			// and that refusal is an exception in production and nothing at
			// all in a test that does not assert it.
			$this->assertNotEmpty($row->getUuid());
		}
	}//end testEveryRevealBecomesOneSealedRow()

	/**
	 * 🔴 The row names the property and never carries its value.
	 *
	 * The assertion this whole change turns on. An audit row that carried the
	 * BSN would put the protected value into a table built to be readable by
	 * auditors and impossible to delete.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testTheRowNamesThePropertyAndNeverItsValue(): void {
		$row = $this->flusher()->rowFor(
			[
				'user' => 'alice',
				'object' => 'object-1',
				'property' => 'bsn',
				'schema' => 24,
			]
		);

		$changed = $row->getChanged();
		$this->assertSame('bsn', $changed['property']);

		$serialised = json_encode($row->jsonSerialize());
		$this->assertStringNotContainsString(
			'123456782',
			$serialised,
			'no BSN-shaped value can be in the row, because none was ever put there'
		);
		$this->assertArrayNotHasKey('value', $changed);
		$this->assertArrayNotHasKey('new', $changed);
		$this->assertArrayNotHasKey('old', $changed);
	}//end testTheRowNamesThePropertyAndNeverItsValue()

	/**
	 * A process entry carries its run rather than an object.
	 *
	 * D-3. The two shapes are distinguishable in the trail without a second
	 * action.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAProcessEntryCarriesItsRun(): void {
		$row = $this->flusher()->rowFor(
			[
				'user' => 'retention-sweep',
				'object' => '',
				'property' => '',
				'process' => 'retention-sweep',
				'run' => 'run-7',
				'revealed' => 120000,
			]
		);

		$changed = $row->getChanged();
		$this->assertSame('retention-sweep', $changed['process']);
		$this->assertSame('run-7', $changed['run']);
		$this->assertSame(120000, $changed['revealed']);
		$this->assertArrayNotHasKey(
			'property',
			$changed,
			'a run names no single property, and an empty one would read as one'
		);
	}//end testAProcessEntryCarriesItsRun()

	/**
	 * Nothing collected writes nothing, and asks the mapper nothing.
	 *
	 * The control: a flusher that called the mapper with an empty list on every
	 * request would put a query on every page of the app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testNothingCollectedWritesNothing(): void {
		$this->mapper->expects($this->never())->method('insertAuditTrails');

		$this->assertSame(0, $this->flusher()->flush());
	}//end testNothingCollectedWritesNothing()

	/**
	 * 🔴 A second flush in one request writes nothing again.
	 *
	 * `take()` empties the collector, and a flusher that read without taking
	 * would write every row twice the moment anything flushed twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testASecondFlushWritesNothingAgain(): void {
		$this->collector->record('alice', 'object-1', 'bsn');

		$this->mapper->expects($this->once())
			->method('insertAuditTrails')
			->willReturnArgument(0);

		$this->assertSame(1, $this->flusher()->flush());
		$this->assertSame(0, $this->flusher()->flush());
	}//end testASecondFlushWritesNothingAgain()

	/**
	 * 🔴 A failed write does not fail the request.
	 *
	 * The reads have already happened and the data is already on its way to the
	 * reader. Throwing here turns a missing audit row into a 500 on a page
	 * somebody is entitled to see.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAFailedWriteDoesNotFailTheRequest(): void {
		$this->collector->record('alice', 'object-1', 'bsn');

		$this->mapper->method('insertAuditTrails')->willThrowException(
			new \RuntimeException('the database went away')
		);

		// And it is LOGGED at error, so a trail that is short says so
		// somewhere rather than simply being short.
		$this->logger->expects($this->atLeastOnce())->method('error');

		$this->assertSame(0, $this->flusher()->flush());
	}//end testAFailedWriteDoesNotFailTheRequest()

	/**
	 * A failed write still empties the collector.
	 *
	 * Otherwise the rows that did not land would be retried by the next flush
	 * of the same request, and any that DID land would be written twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAFailedWriteStillEmptiesTheCollector(): void {
		$this->collector->record('alice', 'object-1', 'bsn');
		$this->mapper->method('insertAuditTrails')->willThrowException(
			new \RuntimeException('the database went away')
		);

		$this->flusher()->flush();

		$this->assertSame(0, $this->collector->count());
	}//end testAFailedWriteStillEmptiesTheCollector()

	/**
	 * An overflowed request says so at error level.
	 *
	 * A trail that is quietly incomplete is worse than one that names where it
	 * stopped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testAnOverflowedRequestSaysSo(): void {
		for ($i = 0; $i <= RevealCollector::MAX_PER_REQUEST; $i++) {
			$this->collector->record('alice', 'object-' . $i, 'bsn');
		}

		$this->assertTrue($this->collector->overflowed());

		$this->mapper->method('insertAuditTrails')->willReturnArgument(0);
		$this->logger->expects($this->atLeastOnce())->method('error');

		$this->assertSame(RevealCollector::MAX_PER_REQUEST, $this->flusher()->flush());
	}//end testAnOverflowedRequestSaysSo()

	/**
	 * A reveal with no schema or register still writes a row.
	 *
	 * The identity of a reveal is (user, object, property); the register and
	 * the schema are context. Refusing a row for missing context would lose
	 * the fact over a detail, which is the wrong way round for an audit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function testARevealWithoutContextStillWrites(): void {
		$row = $this->flusher()->rowFor(
			['user' => 'alice', 'object' => 'object-1', 'property' => 'bsn']
		);

		$this->assertSame('object-1', $row->getObjectUuid());
		$this->assertNull($row->getSchema());
		$this->assertNull($row->getRegister());
		$this->assertNotEmpty($row->getUuid());
	}//end testARevealWithoutContextStillWrites()
}//end class
