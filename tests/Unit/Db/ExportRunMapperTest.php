<?php

/**
 * The export-run list narrows by actor, period and known filter keys only.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ExportRunMapper;
use PHPUnit\Framework\TestCase;

/**
 * Walks findForActor() against a recording query builder.
 *
 * @covers \OCA\OpenRegister\Db\ExportRunMapper
 */
class ExportRunMapperTest extends TestCase {
	use FluentQueryBuilderTrait;

	/**
	 * An actor, a period and a known key each add a predicate; an unknown key is dropped.
	 *
	 * @return void
	 */
	public function testActorPeriodAndKnownKeysNarrowTheListAndUnknownKeysAreDropped(): void {
		$mapper = new ExportRunMapper(db: $this->connectionWith());

		$runs = $mapper->findForActor(
			actor: 'anna',
			filters: [
				'from' => new DateTime('2026-01-01'),
				'until' => new DateTime('2026-02-01'),
				'status' => 'ready',
				'bogus' => 'x',
				'source' => '',
			]
		);

		$this->assertSame([], $runs);
		$this->assertTrue($this->saw('expr.eq', 'actor'));
		$this->assertTrue($this->saw('expr.gte', 'produced_at'));
		$this->assertTrue($this->saw('expr.lte', 'produced_at'));
		$this->assertTrue($this->saw('expr.eq', 'status'));
		$this->assertFalse($this->saw('expr.eq', 'bogus'));
		$this->assertFalse($this->saw('expr.eq', 'source'));
	}//end testActorPeriodAndKnownKeysNarrowTheListAndUnknownKeysAreDropped()
}//end class
