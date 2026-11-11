<?php

/**
 * Golden coverage for how ObjectActionSecrets redacts a run's items and copies.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
use PHPUnit\Framework\TestCase;

/**
 * Locks what is redacted, and what is left alone.
 */
final class ObjectActionSecretsRedactionGoldenTest extends TestCase {

	/**
	 * The held reference of this test.
	 *
	 * @var string
	 */
	private string $ref = '';

	/**
	 * Hold one secret input.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->ref = ObjectActionSecrets::hold(['token' => 'sk-123', 'pin' => 47110815]);
	}//end setUp()

	/**
	 * Release it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ObjectActionSecrets::release($this->ref);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Secret inputs of each item are redacted; other inputs and non-items stay.
	 *
	 * @return void
	 */
	public function testSecretInputsOfEachItemAreRedacted(): void {
		$items = [
			['json' => ['inputs' => ['token' => 'sk-123', 'note' => 'keep']]],
			'not an item',
			['json' => ['other' => 1]],
		];

		$this->assertSame(
			[
				['json' => ['inputs' => ['token' => ObjectActionSecrets::REDACTED, 'note' => 'keep']]],
				'not an item',
				['json' => ['other' => 1]],
			],
			ObjectActionSecrets::redactItems($items, $this->ref)
		);
		$this->assertSame($items, ObjectActionSecrets::redactItems($items, null));
	}//end testSecretInputsOfEachItemAreRedacted()

	/**
	 * Copies of a secret are redacted, as text and as a number, anywhere in the data.
	 *
	 * @return void
	 */
	public function testCopiesOfASecretAreRedacted(): void {
		$redacted = ObjectActionSecrets::redactCopies(['a' => 'sk-123', 'b' => ['c' => 47110815], 'd' => 'fine'], $this->ref);

		$this->assertSame(ObjectActionSecrets::REDACTED, $redacted['a']);
		$this->assertSame(ObjectActionSecrets::REDACTED, $redacted['b']['c']);
		$this->assertSame('fine', $redacted['d']);
	}//end testCopiesOfASecretAreRedacted()
}//end class
