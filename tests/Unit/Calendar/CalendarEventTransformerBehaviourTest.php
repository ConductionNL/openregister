<?php

/**
 * Behaviour tests for CalendarEventTransformer branches the main suite leaves open.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use OCA\OpenRegister\Calendar\CalendarDefinition;
use OCA\OpenRegister\Calendar\CalendarEventTransformer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Malformed all-day input, array interpolation and a missing status value.
 */
class CalendarEventTransformerBehaviourTest extends TestCase {

	private CalendarEventTransformer $transformer;

	/**
	 * Build the transformer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->transformer = new CalendarEventTransformer();
	}//end setUp()

	/**
	 * A schema with one date-time start field.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setTitle('Afspraken');
		$schema->setProperties(['start' => ['type' => 'string', 'format' => 'date-time']]);
		return $schema;
	}//end schema()

	/**
	 * An object carrying the given data.
	 *
	 * @param array<string, mixed> $data Object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setObject($data);
		$object->setUuid('u-1');
		$object->setRegister(9);
		return $object;
	}//end object()

	/**
	 * An unparseable all-day value falls back to the epoch DATE, not a DATE-TIME.
	 *
	 * @return void
	 */
	public function testMalformedAllDayValueFallsBackToEpochDate(): void {
		$this->assertSame(
			['19700101', ['VALUE' => 'DATE']],
			$this->transformer->formatDateValue('not a date at all', true)
		);
	}//end testMalformedAllDayValueFallsBackToEpochDate()

	/**
	 * Paired control: the same malformed value as a timed event falls back to epoch DATE-TIME.
	 *
	 * @return void
	 */
	public function testMalformedTimedValueFallsBackToEpochDateTime(): void {
		$this->assertSame(
			['19700101T000000Z', ['VALUE' => 'DATE-TIME']],
			$this->transformer->formatDateValue('not a date at all', false)
		);
	}//end testMalformedTimedValueFallsBackToEpochDateTime()

	/**
	 * An array value interpolated into a template is JSON-encoded, not "Array".
	 *
	 * @return void
	 */
	public function testArrayValueIsJsonEncodedInTemplate(): void {
		$this->assertSame(
			'Tags: ["a","b"] / x',
			$this->transformer->interpolateTemplate('Tags: {{ tags }} / {{name}}', ['tags' => ['a', 'b'], 'name' => 'x'])
		);
	}//end testArrayValueIsJsonEncodedInTemplate()

	/**
	 * A configured status mapping with no status value on the object reads CONFIRMED.
	 *
	 * @return void
	 */
	public function testMissingStatusValueWithMappingIsConfirmed(): void {
		$config = [
			'dtstart' => 'start',
			'statusField' => 'state',
			'statusMapping' => ['cancelled' => 'CANCELLED'],
		];

		$result = $this->transformer->transform(
			$this->object(['start' => '2026-03-25T10:00:00Z']),
			new CalendarDefinition($this->schema(), $config)
		);

		$this->assertSame(['CONFIRMED', []], $result['objects'][0]['STATUS']);
	}//end testMissingStatusValueWithMappingIsConfirmed()

	/**
	 * Paired control: a present mapped status value is honoured.
	 *
	 * @return void
	 */
	public function testPresentStatusValueWithMappingIsMapped(): void {
		$config = [
			'dtstart' => 'start',
			'statusField' => 'state',
			'statusMapping' => ['cancelled' => 'CANCELLED'],
		];

		$result = $this->transformer->transform(
			$this->object(['start' => '2026-03-25T10:00:00Z', 'state' => 'cancelled']),
			new CalendarDefinition($this->schema(), $config)
		);

		$this->assertSame(['CANCELLED', []], $result['objects'][0]['STATUS']);
	}//end testPresentStatusValueWithMappingIsMapped()
}//end class
