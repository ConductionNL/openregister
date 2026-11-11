<?php

/**
 * Golden test for the CalendarLink, TalkLink and OpenProjectLink entities.
 *
 * Pins each entity's field types, as the constructor registers them, and the
 * exact jsonSerialize() output (keys, order, values, types) for an empty row,
 * a full row hydrated from database strings, and the edge values the
 * serialisers branch on. Dates carry an explicit offset so the output does not
 * depend on the PHP build's default timezone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\TalkLink;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\CalendarLink
 * @covers \OCA\OpenRegister\Db\TalkLink
 * @covers \OCA\OpenRegister\Db\OpenProjectLink
 */
class LinkEntitiesJsonGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/link-entities-json-golden.json';

	private const AT = '2001-02-03T04:05:06+00:00';

	public function testFieldTypesAndJsonMatchGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $entity) {
			$actual[$name] = [
				'types' => $entity->getFieldTypes(),
				'json' => $this->typed(value: $entity->jsonSerialize()),
			];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertNotEmpty($expected);
		foreach ($expected as $name => $outcome) {
			$this->assertNotEmpty($outcome['json'], $name);
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testFieldTypesAndJsonMatchGolden()

	/**
	 * @return array<string, Entity>
	 */
	private function cases(): array {
		return [
			'calendar, empty' => new CalendarLink(),
			'calendar, full' => CalendarLink::fromRow($this->calendarRow()),
			'calendar, calendar id zero, summary empty' => CalendarLink::fromRow(['id' => 2, 'calendar_id' => '0', 'summary' => '', 'tagged_with_xor' => '0']),
			'talk, empty' => new TalkLink(),
			'talk, full' => TalkLink::fromRow($this->talkRow(lastMessage: '{"message":"hi","actor":"bob"}')),
			'talk, empty message and token' => TalkLink::fromRow(array_merge($this->talkRow(lastMessage: ''), ['room_token' => ''])),
			'talk, malformed message' => TalkLink::fromRow($this->talkRow(lastMessage: '{"message":')),
			'talk, scalar message' => TalkLink::fromRow($this->talkRow(lastMessage: '"just text"')),
			'talk, list message' => TalkLink::fromRow($this->talkRow(lastMessage: '[1,2]')),
			'open project, empty' => new OpenProjectLink(),
			'open project, full' => OpenProjectLink::fromRow($this->openProjectRow()),
		];
	}//end cases()

	private function calendarRow(): array {
		return [
			'id' => 1,
			'object_uuid' => 'obj-1',
			'register_id' => '3',
			'schema_id' => '4',
			'calendar_uri' => 'personal',
			'calendar_id' => '12',
			'event_uid' => 'uid-1',
			'event_uri' => 'uid-1.ics',
			'summary' => 'Hearing',
			'dtstart' => self::AT,
			'dtend' => '2001-02-03T05:05:06+01:00',
			'location' => 'Room 1',
			'linked_by' => 'alice',
			'linked_at' => self::AT,
			'tagged_with_xor' => '1',
		];
	}//end calendarRow()

	private function talkRow(string $lastMessage): array {
		return [
			'id' => 5,
			'object_uuid' => 'obj-1',
			'register_id' => '3',
			'schema_id' => '4',
			'room_token' => 'abc123',
			'room_id' => '9',
			'room_name' => 'Case room',
			'room_type' => '2',
			'subtitle' => 'Sub',
			'participant_count' => '4',
			'last_message_data' => $lastMessage,
			'last_activity' => self::AT,
			'linked_by' => 'alice',
			'linked_at' => self::AT,
		];
	}//end talkRow()

	private function openProjectRow(): array {
		return [
			'id' => 7,
			'object_uuid' => 'obj-1',
			'register_id' => '3',
			'schema_id' => '4',
			'work_package_id' => '42',
			'subject' => 'Fix it',
			'type' => 'Task',
			'status' => 'New',
			'priority' => 'High',
			'assignee' => 'bob',
			'project' => 'proj',
			'url' => 'https://op.example/wp/42',
			'cached_at' => self::AT,
			'linked_by' => 'alice',
			'linked_at' => self::AT,
		];
	}//end openProjectRow()

	/**
	 * Values with their PHP type, so `'3'` and `3` do not pin the same.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, each scalar as `type:value`.
	 */
	private function typed(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->typed(value: $item), $value);
		}

		return get_debug_type($value) . ':' . var_export($value, true);
	}//end typed()
}//end class
