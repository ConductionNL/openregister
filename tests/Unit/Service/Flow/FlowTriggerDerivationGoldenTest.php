<?php

/**
 * Golden pins for FlowTriggerDerivation over awkward flows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowTriggerDerivation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every public answer, for every pinned flow, exactly as recorded.
 */
class FlowTriggerDerivationGoldenTest extends TestCase {

	/**
	 * The recorded cases.
	 *
	 * @return array<string, array{0: array<string, mixed>}> The cases.
	 */
	public static function cases(): array {
		$path = __DIR__ . '/fixtures/trigger-derivation-golden/cases.json';
		$cases = [];
		foreach ((array)json_decode((string)file_get_contents($path), true) as $name => $case) {
			$cases[(string)$name] = [$case];
		}

		return $cases;
	}//end cases()

	/**
	 * The derivation answers exactly as recorded.
	 *
	 * @param array<string, mixed> $case The input and the recorded answers.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testDerivationMatchesGolden(array $case): void {
		$flow = new Flow();
		$flow->setNodes($case['input']['nodes']);
		$flow->setTrigger($case['input']['trigger']);
		$flow->setTriggerRegister($case['input']['register']);
		$flow->setTriggerSchema($case['input']['schema']);

		$derivation = new FlowTriggerDerivation();

		$this->assertSame($case['triggerNodes'], $derivation->triggerNodesOf(flow: $flow));
		$this->assertSame($case['objectTriggers'], $derivation->objectTriggersOf(flow: $flow));
		$this->assertSame($case['columnTrigger'], $derivation->columnTriggerOf(flow: $flow));
		$this->assertSame($case['compare'], $derivation->compare(flow: $flow));
	}//end testDerivationMatchesGolden()
}//end class
