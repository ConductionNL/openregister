<?php

/**
 * A hand-sent seed reaches a run as the items FlowItems would make of it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunService::seedItemsFrom
 */
final class FlowRunServiceSeedItemsTest extends TestCase {

	/**
	 * Seeds as a request may carry them.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function seeds(): array {
		return [
			'absent shape' => ['not a list'],
			'empty' => [[]],
			'one bare record' => [['a' => 1]],
			'an item list' => [[['json' => ['a' => 1]], ['b' => 2], 'skipped']],
		];
	}//end seeds()

	/**
	 * The service answers exactly what FlowItems::normalise answers.
	 *
	 * @param mixed $seed The seed as sent.
	 *
	 * @return void
	 */
	#[DataProvider('seeds')]
	public function testTheSeedIsNormalisedAsFlowItemsDoes(mixed $seed): void {
		$service = (new ReflectionClass(FlowRunService::class))->newInstanceWithoutConstructor();
		$this->assertSame(FlowItems::normalise(value: $seed), $service->seedItemsFrom(value: $seed));
	}//end testTheSeedIsNormalisedAsFlowItemsDoes()
}//end class
