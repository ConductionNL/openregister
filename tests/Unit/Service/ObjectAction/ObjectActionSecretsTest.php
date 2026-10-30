<?php

/**
 * Held writeOnly inputs are redacted by key in stored run data, and found
 * in free text and flow state.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunCommit
 */
class ObjectActionSecretsTest extends TestCase {

	private const UUID = '2026aaaa-1111-4111-8111-000000002026';

	/**
	 * The stored per-place items lose the named input only.
	 *
	 * @return void
	 */
	public function testStoredPlaceItemsRedactTheInputAndKeepTheSubject(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026']);
		try {
			$run = new \OCA\OpenRegister\Db\FlowRun();
			$run->setContext([ObjectActionSecrets::CONTEXT_KEY => $ref]);
			$items = ['hop' => [['json' => ['uuid' => self::UUID, 'code' => '2026', 'inputs' => ['pin' => '2026', 'reason' => 'ok']], 'binary' => []]]];

			$commit = (new ReflectionClass(objectOrClass: FlowRunCommit::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(objectOrMethod: FlowRunCommit::class, method: 'storedPlaceItems');
			$stored = $method->invoke($commit, $run, $items);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$json = $stored['hop'][0]['json'];
		$this->assertSame(expected: self::UUID, actual: $json['uuid']);
		$this->assertSame(expected: ObjectActionSecrets::REDACTED, actual: $json['inputs']['pin']);
		$this->assertSame(expected: 'ok', actual: $json['inputs']['reason']);
		$this->assertSame(expected: ObjectActionSecrets::REDACTED, actual: $json['code'], message: 'a copy is redacted too');
	}//end testStoredPlaceItemsRedactTheInputAndKeepTheSubject()

	/**
	 * An error text loses a held value where it stands as a whole token only.
	 *
	 * @return void
	 */
	public function testErrorTextReplacesWholeTokensOnly(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026']);
		try {
			$text = ObjectActionSecrets::redactText(text: 'PIN 2026 refused on 2026-10-30 for ' . self::UUID, ref: $ref);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertSame(expected: 'PIN [redacted] refused on 2026-10-30 for ' . self::UUID, actual: $text);
	}//end testErrorTextReplacesWholeTokensOnly()

	/**
	 * A whole-string copy is redacted; timestamps, uuids, ints and a short secret are not touched.
	 *
	 * @return void
	 */
	public function testCopiesAreRedactedWholeStringsOnly(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '2026', 'digit' => '1']);
		try {
			$data = ObjectActionSecrets::redactCopies(
				data: ['json' => ['code' => '2026', 'created' => '2026-10-30T10:00:00+00:00', 'uuid' => self::UUID, 'n' => '1', 'count' => 2026]],
				ref: $ref
			);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertSame(
			expected: ['code' => '[redacted]', 'created' => '2026-10-30T10:00:00+00:00', 'uuid' => self::UUID, 'n' => '1', 'count' => 2026],
			actual: $data['json']
		);
	}//end testCopiesAreRedactedWholeStringsOnly()

	/**
	 * Flow state holding a held value is detected.
	 *
	 * @return void
	 */
	public function testFlowStateIsChecked(): void {
		$ref = ObjectActionSecrets::hold(values: ['pin' => '4321-9876']);
		try {
			$inState  = ObjectActionSecrets::occursIn(data: ['last' => ['code' => 'x4321-9876']], ref: $ref);
			$notState = ObjectActionSecrets::occursIn(data: ['count' => 4321], ref: $ref);
		} finally {
			ObjectActionSecrets::release(ref: $ref);
		}

		$this->assertTrue(condition: $inState);
		$this->assertFalse(condition: $notState);
	}//end testFlowStateIsChecked()
}//end class
