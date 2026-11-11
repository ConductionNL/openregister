<?php

/**
 * FlowRunMigrationValidator: a half-arrived join keeps its edge suffix when its node is mapped, so it does not collapse into one place and fire early.
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
use OCA\OpenRegister\Service\Flow\FlowRunMigration;
use OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigrationValidator
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\FlowVersion
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunCommit
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunMarkingRemap
 */
class FlowRunMigrationEdgeSuffixBehaviourTest extends TestCase {

	public function testAJoinPlaceKeepsItsEdgeSuffixUnderTheMappedNode(): void {
		$graphs = [
			1 => ['nodes' => [['id' => 'merge', 'type' => 'join']]],
			2 => ['nodes' => [['id' => 'merge-v2', 'type' => 'join']]],
		];
		$versions = $this->createMock(FlowVersionService::class);
		$versions->method('versionOf')->willReturnCallback(static function (string $flowUuid, int $number): FlowVersion {
			$version = new FlowVersion();
			$version->setVersion($number);

			return $version;
		});
		$versions->method('graphOfVersion')->willReturnCallback(fn (FlowVersion $version): array => $graphs[$version->getVersion()]);

		$run = new FlowRun();
		$run->setFlowId('flow-1');
		$run->setFlowVersion(1);
		$run->setStatus('suspended');
		$run->setMarking(['merge#e1' => 1, 'merge#e2' => 1]);

		$verdict = (new FlowRunMigrationValidator($versions))->validate($run, self::onto(2, ['merge' => 'merge-v2']));

		self::assertTrue($verdict['ok']);
		self::assertSame(['merge-v2#e1' => 1, 'merge-v2#e2' => 1], $verdict['marking']);
	}//end testAJoinPlaceKeepsItsEdgeSuffixUnderTheMappedNode()

	/**
	 * A migration request onto a version, with an optional node mapping.
	 *
	 * @param int                   $version The version asked for.
	 * @param array<string, string> $mapping Old node id to new node id.
	 *
	 * @return FlowRunMigration
	 */
	private static function onto(int $version, array $mapping = []): FlowRunMigration {
		return (new FlowRunMigration(runUuid: '', targetVersion: $version, reason: '', actor: ''))->withPlan(mapping: $mapping, dryRun: false);
	}

}//end class
