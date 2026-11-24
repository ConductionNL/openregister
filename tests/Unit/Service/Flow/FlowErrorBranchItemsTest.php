<?php

/**
 * The items a branched failure hands on, and the retry counter that survives a suspension.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-error-branch-and-step-retry/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\FlowErrorBranch;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowItemsFailed;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowStepAttempts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowErrorBranch
 * @covers \OCA\OpenRegister\Service\Flow\FlowItemsFailed
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepAttempts
 */
class FlowErrorBranchItemsTest extends TestCase {
	private static function branch(): FlowErrorBranch {
		return new FlowErrorBranch(step: 'map', attempt: 3, at: new DateTimeImmutable('2026-11-24T10:00:00+00:00'));
	}//end branch()

	public function testAWholeFailureTagsEveryInputAndKeepsItsData(): void {
		$result = self::branch()->itemsAfter(
			itemsIn: [FlowItems::item(json: ['id' => 1]), ['plain' => true], 'scalar'],
			error: new RuntimeException('down')
		);

		self::assertSame(3, $result['failed']);
		self::assertSame(1, $result['items'][0]['json']['id']);
		self::assertTrue($result['items'][1]['json']['plain']);
		self::assertSame('scalar', $result['items'][2]['json']['value'] ?? null);
		foreach ($result['items'] as $item) {
			self::assertSame('error', $item[FlowItems::OUTPUT]);
			self::assertSame(
				['message' => 'down', 'step' => 'map', 'attempt' => 3, 'at' => '2026-11-24T10:00:00+00:00'],
				$item['json']['error']
			);
		}
	}//end testAWholeFailureTagsEveryInputAndKeepsItsData()

	public function testAFailureWithNoInputStillSendsOneItemCarryingTheError(): void {
		$result = self::branch()->itemsAfter(itemsIn: [], error: new RuntimeException('down'));

		self::assertSame(1, $result['failed']);
		self::assertSame('down', $result['items'][0]['json']['error']['message']);
	}//end testAFailureWithNoInputStillSendsOneItemCarryingTheError()

	public function testAPartialFailureKeepsTheSucceededItemsUntagged(): void {
		$error = new FlowItemsFailed(
			succeeded: [FlowItems::item(json: ['id' => 1])],
			failed: [['item' => FlowItems::item(json: ['id' => 2]), 'error' => new RuntimeException('bad record')]]
		);

		$result = self::branch()->itemsAfter(itemsIn: [], error: $error);

		self::assertSame('1 item(s) failed: bad record', $error->getMessage());
		self::assertSame(1, $result['failed']);
		self::assertArrayNotHasKey('error', $result['items'][0]['json']);
		self::assertNull(FlowItems::outputOf(member: $result['items'][0]));
		self::assertSame('bad record', $result['items'][1]['json']['error']['message']);
	}//end testAPartialFailureKeepsTheSucceededItemsUntagged()

	public function testTheCounterLivesInItsOwnSlotAndSurvivesARoundTrip(): void {
		$state = new FlowResumeState();
		FlowStepAttempts::record(state: $state, step: 'call', failed: 2);

		self::assertSame([], $state->read(nodeId: 'call'), 'the node\'s own slot is untouched');
		$stored = FlowResumeState::fromArray(json_decode((string)json_encode($state->all()), true));
		self::assertSame(2, FlowStepAttempts::failed(state: $stored, step: 'call'));

		FlowStepAttempts::clear(state: $stored, step: 'call');
		self::assertSame(0, FlowStepAttempts::failed(state: $stored, step: 'call'));
		self::assertTrue($stored->isEmpty());
	}//end testTheCounterLivesInItsOwnSlotAndSurvivesARoundTrip()
}//end class
