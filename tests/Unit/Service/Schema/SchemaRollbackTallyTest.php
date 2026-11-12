<?php

/**
 * Unit tests for SchemaRollbackTally.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\SchemaRollbackTally;
use PHPUnit\Framework\TestCase;

class SchemaRollbackTallyTest extends TestCase {

	public function testItStartsAtZero(): void {
		$this->assertSame(['restored' => 0, 'conflicts' => 0], (new SchemaRollbackTally())->toArray());
	}

	public function testItCountsRestoresAndConflictsApart(): void {
		$tally = new SchemaRollbackTally();
		$tally->restore();
		$tally->conflict();
		$tally->conflict();

		$this->assertSame(['restored' => 1, 'conflicts' => 2], $tally->toArray());
	}
}
