<?php

/**
 * Unit tests for action inputs: defaults, errors, the writeOnly split and transition inputs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use PHPUnit\Framework\TestCase;

class ObjectActionInputsTest extends TestCase {

	/**
	 * The inputs service over the real validator.
	 *
	 * @return ObjectActionInputs The service.
	 */
	private function inputs(): ObjectActionInputs {
		return new ObjectActionInputs(new OasRequestValidator());
	}//end inputs()

	public function testDefaultsFillOnlyWhatTheCallerLeftOut(): void {
		$object = new ObjectEntity();
		$object->setObject(['status' => 'open', 'owner' => ['name' => 'An']]);
		$schema = [
			'properties' => [
				'given' => ['default' => 'x'],
				'who' => ['default' => '@user'],
				'state' => ['default' => '@object.status'],
				'name' => ['default' => '@object.owner.name'],
				'plain' => ['default' => 7],
				'none' => ['type' => 'string'],
				'odd' => 'not-an-array',
			],
		];

		$this->assertSame(
			['given' => 'kept', 'who' => 'alice', 'state' => 'open', 'name' => 'An', 'plain' => 7],
			$this->inputs()->withDefaults($schema, ['given' => 'kept'], $object, 'alice')
		);
	}//end testDefaultsFillOnlyWhatTheCallerLeftOut()

	public function testObjectDefaultsWithoutAnObjectAndUserWithoutAUserAreNull(): void {
		$schema = ['properties' => ['who' => ['default' => '@user'], 'state' => ['default' => '@object.status']]];

		$this->assertSame(['who' => null, 'state' => null], $this->inputs()->withDefaults($schema, [], null, null));
		$this->assertSame([], $this->inputs()->withDefaults(null, [], null, null));
	}//end testObjectDefaultsWithoutAnObjectAndUserWithoutAUserAreNull()

	public function testNowIsADateOrATimestampByFormat(): void {
		$schema = ['properties' => ['day' => ['default' => '@now', 'format' => 'date'], 'at' => ['default' => '@now']]];
		$filled = $this->inputs()->withDefaults($schema, [], null, null);

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $filled['day']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $filled['at']);
	}//end testNowIsADateOrATimestampByFormat()

	public function testAnActionWithoutInputsRefusesEveryGivenField(): void {
		$this->assertSame(
			[['field' => 'a', 'message' => 'This action takes no inputs.'], ['field' => '3', 'message' => 'This action takes no inputs.']],
			$this->inputs()->errors(null, ['a' => 1, 3 => 2])
		);
		$this->assertSame([], $this->inputs()->errors(null, []));
	}//end testAnActionWithoutInputsRefusesEveryGivenField()

	public function testErrorsNameTheFieldThatFailed(): void {
		$schema = ['type' => 'object', 'properties' => ['from' => ['type' => 'string']], 'required' => ['from']];

		$errors = $this->inputs()->errors($schema, ['from' => 5]);
		$this->assertNotSame([], $errors);
		$this->assertSame(['from'], array_values(array_unique(array_column($errors, 'field'))));
		$this->assertSame([], $this->inputs()->errors($schema, ['from' => 'today']));
	}//end testErrorsNameTheFieldThatFailed()

	public function testTransitionInputsBecomeAJsonSchema(): void {
		$schema = $this->inputs()->fromTransitionInputs(
			[
				['field' => 'reason', 'required' => true, 'label' => 'Reason'],
				['field' => 'level', 'options' => [['value' => 'low'], ['value' => 'high']]],
				['field' => '', 'required' => true],
				['label' => 'nameless'],
				['field' => 'note', 'required' => 'yes'],
			]
		);

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['reason'], $schema['required']);
		$this->assertEquals(
			(object)[
				'reason' => (object)['title' => 'Reason'],
				'level' => (object)['enum' => ['low', 'high']],
				'note' => (object)[],
			],
			$schema['properties']
		);
		$this->assertEquals(['type' => 'object', 'properties' => (object)[]], $this->inputs()->fromTransitionInputs([]));
		$this->assertArrayNotHasKey('required', $this->inputs()->fromTransitionInputs([['field' => 'a']]));
	}//end testTransitionInputsBecomeAJsonSchema()

	public function testSplitAndDigest(): void {
		[$storable, $secret] = $this->inputs()->split(['a' => 1, 'pin' => '1234'], ['pin']);

		$this->assertSame(['a' => 1], $storable);
		$this->assertSame(['pin' => '1234'], $secret);
		$this->assertSame($this->inputs()->digest(['b' => 2, 'a' => 1]), $this->inputs()->digest(['a' => 1, 'b' => 2]));
		$this->assertSame('sha256:' . hash('sha256', '{"a":1}'), $this->inputs()->digest(['a' => 1]));
	}//end testSplitAndDigest()
}//end class
