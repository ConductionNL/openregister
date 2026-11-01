<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for CalendarEventTransformer::transform().
 *
 * Compared with fixtures/calendar-event-transformer-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Calendar\CalendarEventTransformer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class CalendarEventTransformerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/calendar-event-transformer-golden.json';

	/**
	 * Case name => [object data, calendar config, schema properties].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function cases(): array {
		$data = [
			'start' => '2026-03-01T09:30:00+01:00',
			'end' => '2026-03-01T11:00:00+01:00',
			'day' => '2026-03-02',
			'title' => 'Hearing',
			'place' => 'Room 4',
			'state' => 'closed',
		];
		$dateProps = ['day' => ['type' => 'string', 'format' => 'date'], 'start' => ['type' => 'string', 'format' => 'date-time']];

		return [
			'no dtstart config' => [$data, [], $dateProps],
			'dtstart field empty' => [['start' => ''] + $data, ['dtstart' => 'start'], $dateProps],
			'dtstart field missing' => [$data, ['dtstart' => 'nope'], $dateProps],
			'minimal' => [$data, ['dtstart' => 'start'], $dateProps],
			'with end and title' => [$data, ['dtstart' => 'start', 'dtend' => 'end', 'titleTemplate' => '{{title}} at {{place}}'], $dateProps],
			'date format is all day' => [$data, ['dtstart' => 'day'], $dateProps],
			'explicit all day' => [$data, ['dtstart' => 'start', 'allDay' => true, 'dtend' => 'end'], $dateProps],
			'explicit not all day' => [$data, ['dtstart' => 'day', 'allDay' => false], $dateProps],
			'description and location' => [$data, ['dtstart' => 'start', 'descriptionTemplate' => 'About {{title}}', 'locationField' => 'place'], $dateProps],
			'location field empty' => [['place' => ''] + $data, ['dtstart' => 'start', 'locationField' => 'place'], $dateProps],
			'empty description template' => [$data, ['dtstart' => 'start', 'descriptionTemplate' => ''], $dateProps],
			'status mapping' => [$data, ['dtstart' => 'start', 'statusField' => 'state', 'statusMapping' => ['closed' => 'CANCELLED']], $dateProps],
			'status unmapped' => [$data, ['dtstart' => 'start', 'statusField' => 'state'], $dateProps],
			'no schema properties' => [$data, ['dtstart' => 'day'], []],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $data       Object data
	 * @param array<string, mixed> $config     Calendar config
	 * @param array<string, mixed> $properties Schema properties
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTransformMatchesGolden(array $data, array $config, array $properties): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('7');
		$object->setObject($data);
		$schema = new Schema();
		$schema->setId(3);
		$schema->setTitle('Hearings');
		$schema->setProperties($properties);

		$actual = json_decode(json_encode((new CalendarEventTransformer())->transform($object, $schema, $config)), true);

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testTransformMatchesGolden()
}//end class
