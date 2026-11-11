<?php

/**
 * EveryItemField tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-outcome-is-written-onto-every-item-not-only-onto-the-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\Nodes\EveryItemField;
use PHPUnit\Framework\TestCase;

final class EveryItemFieldTest extends TestCase {
	public function testPlacesTheValueOnEveryArrayItemAndKeepsTheRest(): void {
		$items = [
			3 => ['json' => ['name' => 'a', 'task' => 'old'], 'binary' => ['f' => 1]],
			7 => ['binary' => []],
			9 => 'not an item',
		];

		$placed = (new EveryItemField(key: 'task', value: ['outcome' => 'approved']))->placedOn(items: $items);

		self::assertSame(
			[
				3 => ['json' => ['name' => 'a', 'task' => ['outcome' => 'approved']], 'binary' => ['f' => 1]],
				7 => ['binary' => [], 'json' => ['task' => ['outcome' => 'approved']]],
				9 => 'not an item',
			],
			$placed
		);
	}//end testPlacesTheValueOnEveryArrayItemAndKeepsTheRest()
}//end class
