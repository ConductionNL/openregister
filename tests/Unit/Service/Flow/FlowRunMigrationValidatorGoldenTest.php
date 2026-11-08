<?php

/**
 * Characterisation test for FlowRunMigrationValidator's graph reading and remapping.
 *
 * Pins how a version's nodes are read from a malformed or keyed graph and
 * how a marking is remapped (mapping, kind match, unknown kinds, unmapped
 * places), so the validator can be split into smaller units without moving
 * a verdict.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use PHPUnit\Framework\TestCase;

/**
 * Golden verdicts for run migration validation.
 */
class FlowRunMigrationValidatorGoldenTest extends TestCase {

	/**
	 * A validator over the given version graphs (version number => graph, or a non-array).
	 *
	 * @param array<int, mixed> $graphs The graphs.
	 *
	 * @return FlowRunMigrationValidator The validator.
	 */
	private function validator(array $graphs): FlowRunMigrationValidator {
		$versions = $this->createMock(FlowVersionService::class);
		$versions->method('versionOf')->willReturnCallback(
			static function (string $flowUuid, int $number) use ($graphs): ?FlowVersion {
				if (array_key_exists($number, $graphs) === false) {
					return null;
				}

				$version = new FlowVersion();
				$version->setVersion($number);
				return $version;
			}
		);
		$versions->method('graphOfVersion')->willReturnCallback(
			static fn (FlowVersion $version): ?array => (is_array($graphs[$version->getVersion()]) === true ? $graphs[$version->getVersion()] : null)
		);

		return new FlowRunMigrationValidator($versions);

	}//end validator()

	/**
	 * A suspended run on version 1 with the given marking.
	 *
	 * @param array<string, int> $marking The marking.
	 *
	 * @return FlowRun The run.
	 */
	private function suspendedRun(array $marking): FlowRun {
		$run = new FlowRun();
		$run->setUuid('r1');
		$run->setFlowId('flow-1');
		$run->setFlowVersion(1);
		$run->setStatus('suspended');
		$run->setMarking($marking);

		return $run;

	}//end suspendedRun()

	/**
	 * A target graph that is missing, non-array, or has non-array nodes cannot be read.
	 *
	 * @return void
	 */
	public function testAnUnreadableTargetGraphIsRefused(): void {
		$validator = $this->validator([2 => 'broken', 3 => ['nodes' => 'broken']]);
		foreach ([2, 3, 9] as $target) {
			$verdict = $validator->validate($this->suspendedRun(['a' => 1]), $target);
			$this->assertFalse($verdict['ok']);
			$this->assertSame('Version '.$target.' of this flow could not be read, so nothing was migrated.', $verdict['reason']);
		}

	}//end testAnUnreadableTargetGraphIsRefused()

	/**
	 * Node ids come from `id`, else a string key; junk and blank ids are skipped.
	 *
	 * @return void
	 */
	public function testNodesAreKeyedByIdOrStringKey(): void {
		$validator = $this->validator(
			[
				1 => ['nodes' => [['id' => 'a', 'type' => 'action'], ['id' => 'b', 'type' => 'user-task'], ['id' => 'c']]],
				2 => [
					'nodes' => [
						'keyed' => ['type' => 'action'],
						'junk',
						['id' => '  ', 'type' => 'action'],
						['type' => 'user-task'],
						['id' => ' b ', 'kind' => 'user-task'],
						'c' => ['id' => 'cc', 'type' => 'gateway'],
					],
				],
			]
		);

		$verdict = $validator->validate($this->suspendedRun(['a' => 1, 'b' => 2, 'c' => 1]), 2, ['a' => 'keyed', 'c' => 'cc']);
		$this->assertSame(['ok' => true, 'marking' => ['keyed' => 1, 'b' => 2, 'cc' => 1], 'unmapped' => [], 'reason' => ''], $verdict);

		$miss = $validator->validate($this->suspendedRun(['a' => 1, 'c' => 1]), 2);
		$this->assertFalse($miss['ok']);
		$this->assertSame(['a', 'c'], $miss['unmapped']);
		$this->assertStringContainsString('Map them to a node', $miss['reason']);

	}//end testNodesAreKeyedByIdOrStringKey()

	/**
	 * A mapping onto another kind is unmapped; an unknown source graph is no mismatch.
	 *
	 * @return void
	 */
	public function testKindsMustMatchWhenBothAreKnown(): void {
		$target = ['nodes' => [['id' => 'x', 'type' => 'gateway'], ['id' => 'y', 'type' => 'user-task']]];
		$known  = $this->validator([1 => ['nodes' => [['id' => 'a', 'type' => 'user-task']]], 2 => $target]);

		$mismatch = $known->validate($this->suspendedRun(['a' => 1]), 2, ['a' => 'x']);
		$this->assertSame(['a'], $mismatch['unmapped']);
		$this->assertStringContainsString('Map it to a node', $mismatch['reason']);

		$this->assertTrue($known->validate($this->suspendedRun(['a' => 1]), 2, ['a' => 'y'])['ok']);

		$unknownSource = $this->validator([2 => $target]);
		$this->assertSame(['x' => 1], $unknownSource->validate($this->suspendedRun(['a' => 1]), 2, ['a' => 'x'])['marking']);

	}//end testKindsMustMatchWhenBothAreKnown()

	/**
	 * A finished run is refused before any graph is read.
	 *
	 * @return void
	 */
	public function testAFinishedRunIsRefused(): void {
		$run = $this->suspendedRun(['a' => 1]);
		$run->setStatus('completed');
		$verdict = $this->validator([])->validate($run, 2);

		$this->assertFalse($verdict['ok']);
		$this->assertStringStartsWith('This run is completed', $verdict['reason']);

	}//end testAFinishedRunIsRefused()
}//end class
