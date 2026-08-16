<?php

/**
 * Tests for the run-level flow token.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowToken;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowToken
 */
class FlowTokenTest extends TestCase {
	/**
	 * A value written is a value read back.
	 *
	 * @return void
	 */
	public function testReadsBackAWrittenValue(): void {
		$token = new FlowToken();
		$token->set('correlationId', 'abc-123');

		$this->assertSame('abc-123', $token->get('correlationId'));
		$this->assertTrue($token->has('correlationId'));

	}//end testReadsBackAWrittenValue()

	/**
	 * An unheld key yields the default rather than an error.
	 *
	 * @return void
	 */
	public function testUnheldKeyYieldsTheDefault(): void {
		$token = new FlowToken();

		$this->assertNull($token->get('nope'));
		$this->assertSame('fallback', $token->get('nope', 'fallback'));
		$this->assertFalse($token->has('nope'));

	}//end testUnheldKeyYieldsTheDefault()

	/**
	 * The object handle is shared, which is what gives a step write access.
	 *
	 * A step receives `$context` by value; only because the token is an object
	 * does a write inside the step reach the run. This is the mechanism the
	 * whole design rests on, so it is asserted directly.
	 *
	 * @return void
	 */
	public function testWritesThroughAByValueContextCopy(): void {
		$token = new FlowToken();
		$context = ['token' => $token];

		// Exactly what a node does: receives the context by value and writes.
		$step = static function (array $ctx): void {
			$ctx[FlowToken::CONTEXT_KEY]->set('writtenByStep', true);
		};

		$step($context);

		$this->assertTrue($token->get('writtenByStep'));

	}//end testWritesThroughAByValueContextCopy()

	/**
	 * A run persisted before tokens existed still runs.
	 *
	 * @return void
	 */
	public function testAbsentStoredTokenYieldsAnEmptyToken(): void {
		$token = FlowToken::fromArray(null);

		$this->assertSame([], $token->all());

	}//end testAbsentStoredTokenYieldsAnEmptyToken()

	/**
	 * A corrupted column is not a reason to fail a run.
	 *
	 * @return void
	 */
	public function testMalformedStoredTokenYieldsAUsableToken(): void {
		foreach (['a scalar', 42, true] as $malformed) {
			$token = FlowToken::fromArray($malformed);
			$this->assertSame([], $token->all());
			$token->set('stillWorks', 1);
			$this->assertSame(1, $token->get('stillWorks'));
		}

	}//end testMalformedStoredTokenYieldsAUsableToken()

	/**
	 * Handing a token straight back returns the same instance.
	 *
	 * @return void
	 */
	public function testAnAlreadyHydratedTokenIsPassedThrough(): void {
		$original = new FlowToken(['k' => 'v']);

		$this->assertSame($original, FlowToken::fromArray($original));

	}//end testAnAlreadyHydratedTokenIsPassedThrough()

	/**
	 * A serialise/rehydrate round trip preserves the values — this is what
	 * carries a token across a suspension.
	 *
	 * @return void
	 */
	public function testSurvivesASerialiseRehydrateRoundTrip(): void {
		$before = new FlowToken();
		$before->set('resolvedRef', 'source-7');
		$before->set('attempt', 2);

		// Exactly what persistResult + execute do, through real JSON.
		$stored = json_decode(json_encode($before->jsonSerialize()), true);
		$after = FlowToken::fromArray($stored);

		$this->assertSame('source-7', $after->get('resolvedRef'));
		$this->assertSame(2, $after->get('attempt'));

	}//end testSurvivesASerialiseRehydrateRoundTrip()

	/**
	 * Merge lets the incoming values win, and leaves untouched keys alone.
	 *
	 * @return void
	 */
	public function testMergeLetsIncomingValuesWinAndPreservesTheRest(): void {
		$token = new FlowToken(['keep' => 'mine', 'shared' => 'parent']);
		$token->merge(['shared' => 'child', 'new' => 'from-child']);

		$this->assertSame('child', $token->get('shared'), 'the child is the more specific writer');
		$this->assertSame('mine', $token->get('keep'), 'an untouched key survives');
		$this->assertSame('from-child', $token->get('new'));

	}//end testMergeLetsIncomingValuesWinAndPreservesTheRest()

	/**
	 * A JSON round trip turns a list into integer keys; those are not a value
	 * bag and must not become entries.
	 *
	 * @return void
	 */
	public function testIntegerKeyedStorageIsNotTreatedAsValues(): void {
		$token = FlowToken::fromArray(['a', 'b', 'c']);

		$this->assertSame([], $token->all());

	}//end testIntegerKeyedStorageIsNotTreatedAsValues()
}//end class
