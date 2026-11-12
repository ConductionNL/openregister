<?php

/**
 * Unit tests for SchemaRunBatch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Service\Schema\SchemaRunBatch;
use PHPUnit\Framework\TestCase;

class SchemaRunBatchTest extends TestCase {

	private function object(int $id): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId($id);
		return $object;
	}

	public function testNothingReachesTheRunBeforeClose(): void {
		$run = new SchemaRun();
		$run->setCursor(5);
		$run->setReport(['valid' => 2]);
		$batch = new SchemaRunBatch(run: $run, schema: new Schema(), emptyReport: ['valid' => 0, 'invalid' => 0]);

		$batch->reach($this->object(9));
		$batch->reach($this->object(3));
		$batch->tally('valid');
		$batch->tally('invalid');

		$this->assertSame(5, $run->getCursor());
		$this->assertSame(['valid' => 2], $run->getReport());

		$batch->close();

		$this->assertSame(9, $run->getCursor());
		$this->assertSame(['valid' => 3, 'invalid' => 1], $run->getReport());
	}

	public function testARunWithoutAReportStartsFromTheEmptyOne(): void {
		$run = new SchemaRun();
		$run->setReport(null);
		$batch = new SchemaRunBatch(run: $run, schema: new Schema(), emptyReport: ['migrated' => 0, 'failed' => 0]);

		$batch->tally('failed');
		$batch->close();

		$this->assertSame(['migrated' => 0, 'failed' => 1], $run->getReport());
		$this->assertSame(0, $run->getCursor());
	}

	public function testMarkProcessedCountsOnTheRunAtOnce(): void {
		$run = new SchemaRun();
		$run->setProcessed(4);
		$batch = new SchemaRunBatch(run: $run, schema: new Schema(), emptyReport: []);

		$batch->markProcessed();

		$this->assertSame(5, $run->getProcessed());
	}
}
