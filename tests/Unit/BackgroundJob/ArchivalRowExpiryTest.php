<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\BackgroundJob\ArchivalRowExpiry} and
 * {@see \OCA\OpenRegister\BackgroundJob\ArchivalSweep}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\ArchivalRowExpiry;
use OCA\OpenRegister\BackgroundJob\ArchivalSweep;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When one row of an archival sweep expires, and how unusable rows are skipped.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\ArchivalRowExpiry
 * @covers \OCA\OpenRegister\BackgroundJob\ArchivalSweep
 * @uses   \OCA\OpenRegister\Service\Archival\RetentionEvaluatorFactory
 * @uses   \OCA\OpenRegister\Service\Archival\RetentionEvaluator
 * @uses   \OCA\OpenRegister\Service\Archival\RetentionConditionEvaluator
 * @uses   \OCA\OpenRegister\Service\Archival\AnnotatedRow
 * @uses   \OCA\OpenRegister\Service\Archival\IndexedRule
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ArchivalRowExpiryTest extends TestCase {

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A sweep over register 3 / schema 7 with the given annotation.
	 *
	 * @param array<string, mixed> $annotation The archival annotation.
	 * @param string|null          $slug       The schema slug.
	 *
	 * @return ArchivalSweep
	 */
	private function sweep(array $annotation, ?string $slug='ledger'): ArchivalSweep {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug($slug);

		return new ArchivalSweep($register, $schema, $annotation, new DateTimeImmutable('2026-06-01T00:00:00Z'));

	}//end sweep()

	/**
	 * The sweep names its table and slug and starts with zero counts.
	 *
	 * @return void
	 */
	public function testSweepNamesTableAndCounts(): void {
		$sweep = $this->sweep(['retention' => ['default' => 'P1Y']]);

		$this->assertSame('ledger', $sweep->schemaSlug);
		$this->assertSame('openregister_table_3_7', $sweep->tableName);
		$this->assertSame(['scanned' => 0, 'expired' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0], $sweep->counts());

		$sweep->scanned = 4;
		$sweep->held    = 1;
		$this->assertSame(4, $sweep->counts()['scanned']);
		$this->assertSame(1, $sweep->counts()['held']);

	}//end testSweepNamesTableAndCounts()

	/**
	 * Without a slug, the sweep is named by the schema id.
	 *
	 * @return void
	 */
	public function testSweepFallsBackToSchemaId(): void {
		$this->assertSame('7', $this->sweep([], null)->schemaSlug);

	}//end testSweepFallsBackToSchemaId()

	/**
	 * The expiry is creation plus the default retention.
	 *
	 * @return void
	 */
	public function testExpiryIsCreatedPlusDefault(): void {
		$this->logger->expects($this->never())->method('warning');
		$expiry = (new ArchivalRowExpiry($this->logger))->expiryOf(
			$this->sweep(['retention' => ['default' => 'P1Y']]),
			['_uuid' => 'a', '_created' => '2020-02-03T04:05:06+00:00', 'kind' => 'x']
		);

		$this->assertInstanceOf(DateTimeImmutable::class, $expiry);
		$this->assertSame('2021-02-03T04:05:06+00:00', $expiry->format(DATE_ATOM));

	}//end testExpiryIsCreatedPlusDefault()

	/**
	 * Rules see the row's own fields but not its underscore metadata.
	 *
	 * @return void
	 */
	public function testRulesSeeDataFieldsOnly(): void {
		$annotation = ['retention' => ['default' => 'P1Y', 'rules' => [['condition' => 'code < 3', 'retention' => 'P10Y']]]];
		$expiry     = new ArchivalRowExpiry($this->logger);
		$sweep      = $this->sweep($annotation);

		$this->assertSame(
			'2030-01-01',
			$expiry->expiryOf($sweep, ['_created' => '2020-01-01T00:00:00+00:00', 'code' => 1])->format('Y-m-d')
		);
		$this->assertSame(
			'2021-01-01',
			$expiry->expiryOf($sweep, ['_created' => '2020-01-01T00:00:00+00:00', 'code' => 5])->format('Y-m-d')
		);

		$hidden = $this->sweep(['retention' => ['default' => 'P1Y', 'rules' => [['condition' => '_code < 3', 'retention' => 'P10Y']]]]);
		$this->assertSame(
			'2021-01-01',
			$expiry->expiryOf($hidden, ['_created' => '2020-01-01T00:00:00+00:00', '_code' => 1])->format('Y-m-d')
		);

	}//end testRulesSeeDataFieldsOnly()

	/**
	 * A row without `_created` is silently skipped.
	 *
	 * @return void
	 */
	public function testMissingCreatedIsSkippedQuietly(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull((new ArchivalRowExpiry($this->logger))->expiryOf($this->sweep(['retention' => ['default' => 'P1Y']]), ['_uuid' => 'x']));

	}//end testMissingCreatedIsSkippedQuietly()

	/**
	 * An unparseable `_created` is skipped with a warning naming the schema.
	 *
	 * @return void
	 */
	public function testUnparseableCreatedWarns(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringStartsWith('[ArchivalRetentionTask] Row with unparseable _created skipped on "ledger": '),
			['app' => 'openregister']
		);

		$this->assertNull(
			(new ArchivalRowExpiry($this->logger))->expiryOf($this->sweep(['retention' => ['default' => 'P1Y']]), ['_created' => 'not a date'])
		);

	}//end testUnparseableCreatedWarns()

	/**
	 * An annotation the evaluator refuses skips the row with a warning carrying the reason.
	 *
	 * @return void
	 */
	public function testUnusableAnnotationWarns(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[ArchivalRetentionTask] Skipping row on "ledger": RetentionEvaluator: annotation.retention.default is missing or not a string.',
			['app' => 'openregister']
		);

		$this->assertNull(
			(new ArchivalRowExpiry($this->logger))->expiryOf($this->sweep(['retention' => []]), ['_created' => '2020-01-01'])
		);

	}//end testUnusableAnnotationWarns()
}//end class
