<?php

/**
 * Unit test for FlowPreflightStep: the app a node type belongs to, the shape of
 * a finding, and which config keys the dialect check blocks or warns about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
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

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowPreflightStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowPreflightStep
 */
class FlowPreflightStepTest extends TestCase {

	/**
	 * A step of the given type with the given config.
	 *
	 * @param array<string, mixed> $config The config.
	 * @param string               $type   The node type.
	 *
	 * @return FlowPreflightStep
	 */
	private function step(array $config, string $type='openregister.send-email'): FlowPreflightStep {
		return new FlowPreflightStep(entry: ['id' => 's1', 'type' => $type, 'config' => $config], type: $type, stepId: 's1');
	}//end step()

	/**
	 * The app is the part before the first dot; a type without one, or starting with one, has none.
	 *
	 * @return void
	 */
	public function testTheAppIsThePrefixOfTheType(): void {
		$this->assertSame('openregister', $this->step(config: [], type: 'openregister.send-email')->app());
		$this->assertSame('procest', $this->step(config: [], type: 'procest.case.close')->app());
		$this->assertSame('', $this->step(config: [], type: 'send-email')->app());
		$this->assertSame('', $this->step(config: [], type: '.send-email')->app());
	}//end testTheAppIsThePrefixOfTheType()

	/**
	 * A step without config reads as an empty config.
	 *
	 * @return void
	 */
	public function testAMissingConfigIsEmpty(): void {
		$step = new FlowPreflightStep(entry: ['id' => 's1'], type: 'x.y', stepId: 's1');
		$this->assertSame([], $step->config());
		$this->assertSame(['blocking' => [], 'warnings' => []], $step->dialectFindings(known: []));
	}//end testAMissingConfigIsEmpty()

	/**
	 * A finding names type, app, step and reason, and a detail only when given.
	 *
	 * @return void
	 */
	public function testAFindingCarriesADetailOnlyWhenGiven(): void {
		$step = $this->step(config: []);
		$this->assertSame(['type' => 'openregister.send-email', 'app' => 'openregister', 'step' => 's1', 'reason' => 'r'], $step->finding(reason: 'r'));
		$this->assertSame(
			['type' => 'openregister.send-email', 'app' => 'openregister', 'step' => 's1', 'reason' => 'r', 'detail' => 'd'],
			$step->finding(reason: 'r', detail: 'd')
		);
	}//end testAFindingCarriesADetailOnlyWhenGiven()

	/**
	 * Known keys and annotations pass; unread keys block, naming them and what the node reads.
	 *
	 * @return void
	 */
	public function testUnreadKeysBlock(): void {
		$step = $this->step(config: ['to' => 'a@b.nl', '$note' => 'x', 'colour' => 'red', 7 => 'n']);

		$this->assertSame(
			[
				'blocking' => [
					[
						'type'   => 'openregister.send-email',
						'app'    => 'openregister',
						'step'   => 's1',
						'reason' => FlowNodePreflight::REASON_CONFIG_UNKNOWN_KEY,
						'detail' => 'colour, 7',
						'reads'  => 'to, subject',
					],
				],
				'warnings' => [],
			],
			$step->dialectFindings(known: ['to', 'subject'])
		);
		$this->assertSame(['blocking' => [], 'warnings' => []], $this->step(config: ['to' => 'x', '$a' => 1])->dialectFindings(known: ['to']));
	}//end testUnreadKeysBlock()

	/**
	 * A node-level onError that says the default only warns; any other value blocks; neither counts as an unread key.
	 *
	 * @return void
	 */
	public function testAMisplacedOnErrorWarnsOnlyWhenItIsTheDefault(): void {
		$warn = $this->step(config: ['onError' => 'stop'])->dialectFindings(known: []);
		$this->assertSame([], $warn['blocking']);
		$this->assertSame(
			[['type' => 'openregister.send-email', 'app' => 'openregister', 'step' => 's1', 'reason' => FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED, 'detail' => 'stop']],
			$warn['warnings']
		);

		$block = $this->step(config: ['onError' => 'continue'])->dialectFindings(known: []);
		$this->assertSame([], $block['warnings']);
		$this->assertSame(
			[['type' => 'openregister.send-email', 'app' => 'openregister', 'step' => 's1', 'reason' => FlowNodePreflight::REASON_CONFIG_ONERROR_MISPLACED, 'detail' => 'continue']],
			$block['blocking']
		);
	}//end testAMisplacedOnErrorWarnsOnlyWhenItIsTheDefault()
}//end class
