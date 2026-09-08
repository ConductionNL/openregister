<?php

/**
 * What a run looks like over the API, subjects included.
 *
 * 🔑 `subjects` SERIALISES AS AN EMPTY SET, NEVER AS A MISSING KEY. A reader
 * that has to tell "no subjects" apart from "this build predates subjects"
 * ends up writing the fallback twice and getting it wrong once.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FlowRun::jsonSerialize()}.
 *
 * @covers \OCA\OpenRegister\Db\FlowRun
 */
final class FlowRunSerialisationTest extends TestCase {

	/**
	 * A run with nothing set serialises without dates and with an empty set.
	 *
	 * @return void
	 */
	public function testARunWithNoDatesSerialisesThemAsNull(): void {
		$json = (new FlowRun())->jsonSerialize();

		$this->assertNull($json['resumeAt']);
		$this->assertNull($json['created']);
		$this->assertNull($json['updated']);
		// 🔴 AN OBJECT, NEVER AN EMPTY ARRAY. `json_encode` turns an empty PHP
		// array into `[]`, so a run declaring nothing would serve a JSON ARRAY
		// where a populated one serves a MAP, and a typed client cannot read
		// both. Asserting the CAST is what keeps the wire shape one thing.
		$this->assertEquals(new \stdClass(), $json['subjects'], 'an empty set, never an empty array');
		$this->assertSame('{}', json_encode($json['subjects']), 'and it encodes as an object');
	}//end testARunWithNoDatesSerialisesThemAsNull()

	/**
	 * 🔑 EVERY DATE IS SERIALISED IN ITS OWN RIGHT.
	 *
	 * All three are separately nullable, so one of them being formatted is no
	 * evidence about the other two.
	 *
	 * @return void
	 */
	public function testEachDateIsFormattedWhenItIsSet(): void {
		$run = new FlowRun();
		$run->setResumeAt(new DateTime('2026-01-02T03:04:05+00:00'));
		$run->setCreated(new DateTime('2026-02-03T04:05:06+00:00'));
		$run->setUpdated(new DateTime('2026-03-04T05:06:07+00:00'));

		$json = $run->jsonSerialize();

		$this->assertStringStartsWith('2026-01-02T03:04:05', $json['resumeAt']);
		$this->assertStringStartsWith('2026-02-03T04:05:06', $json['created']);
		$this->assertStringStartsWith('2026-03-04T05:06:07', $json['updated']);
	}//end testEachDateIsFormattedWhenItIsSet()

	/**
	 * A declared subject set survives serialisation, by role.
	 *
	 * @return void
	 */
	public function testTheDeclaredSubjectsSurviveSerialisation(): void {
		$run = new FlowRun();
		$run->setSubjects(['case' => ['uuid' => 'obj-1', 'register' => '3', 'schema' => '7']]);

		$this->assertSame('obj-1', ((array)$run->jsonSerialize()['subjects'])['case']['uuid']);
	}//end testTheDeclaredSubjectsSurviveSerialisation()
}//end class
