<?php

/**
 * A timeline entry's fields serialize as a JSON object, `{}` when there are none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Db\TimelineEntry
 */
final class TimelineEntryFieldsTest extends TestCase {

	/**
	 * A plain note carries no fields and answers `{}`, not `[]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function testAnEntryWithoutFieldsAnswersAnEmptyObject(): void {
		$entry = new TimelineEntry();

		$this->assertSame('{}', json_encode($entry->jsonSerialize()['fields']));

		$entry->setFields([]);
		$this->assertSame('{}', json_encode($entry->jsonSerialize()['fields']));
	}//end testAnEntryWithoutFieldsAnswersAnEmptyObject()

	/**
	 * Fields that are there are answered as they are.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function testFieldsAreAnsweredAsStored(): void {
		$entry = new TimelineEntry();
		$entry->setFields(['duration' => 15, 'outcome' => 'reached']);

		$this->assertSame('{"duration":15,"outcome":"reached"}', json_encode($entry->jsonSerialize()['fields']));
	}//end testFieldsAreAnsweredAsStored()
}//end class
