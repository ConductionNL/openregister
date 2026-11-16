<?php

/**
 * A migration target turns into a per-run migration request carrying its version, trimmed reason, actor and plan.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowRunMigrationTarget;
use OCA\OpenRegister\Service\Flow\SubjectRunsMigration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMigrationTarget
 * @covers \OCA\OpenRegister\Service\Flow\SubjectRunsMigration
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunMigration
 */
final class FlowRunMigrationTargetTest extends TestCase {

	/**
	 * One run's migration carries the version, the trimmed reason, the actor, the mapping and the dry-run flag.
	 *
	 * @return void
	 */
	public function testForRunBuildsTheRequestWithAPlan(): void {
		$target = new FlowRunMigrationTarget(
			targetVersion: 4,
			reason: "  hotfix for step b  \n",
			actor: 'alice',
			mapping: ['old-b' => 'new-b']
		);

		$migration = $target->forRun(runUuid: 'run-1', dryRun: true);

		self::assertSame('run-1', $migration->runUuid);
		self::assertSame(4, $migration->targetVersion);
		self::assertSame('hotfix for step b', $migration->reason);
		self::assertSame('alice', $migration->actor);
		self::assertSame(['old-b' => 'new-b'], $migration->mapping());
		self::assertTrue($migration->isDryRun());
	}//end testForRunBuildsTheRequestWithAPlan()

	/**
	 * Without a mapping the request carries an empty one, and a real run is not a dry run.
	 *
	 * @return void
	 */
	public function testForRunWithoutMappingIsAnEmptyPlan(): void {
		$target = new FlowRunMigrationTarget(targetVersion: 2, reason: 'move', actor: 'bob');

		$migration = $target->forRun(runUuid: 'run-2', dryRun: false);

		self::assertSame([], $migration->mapping());
		self::assertFalse($migration->isDryRun());
		self::assertSame(2, $migration->targetVersion);
	}//end testForRunWithoutMappingIsAnEmptyPlan()

	/**
	 * A subject's runs move to the numbered version, recording who asked as actor and in the reason.
	 *
	 * @return void
	 */
	public function testSubjectRunsMigrationTargetsTheNumberedVersion(): void {
		$request = new SubjectRunsMigration(subjectUuid: 'obj-1', targetDefinitionRef: '7', actorUid: 'carol');

		$target = $request->target();

		self::assertSame(7, $target->targetVersion);
		self::assertSame('Migrated with its subject by carol', $target->reason);
		self::assertSame('carol', $target->actor);
		self::assertSame([], $target->mapping);
		self::assertSame('obj-1', $request->subjectUuid);
	}//end testSubjectRunsMigrationTargetsTheNumberedVersion()

	/**
	 * A reference that is not a number (a flow uuid) reads as version 0, which the service refuses by name.
	 *
	 * @return void
	 */
	public function testANonNumericReferenceReadsAsVersionZero(): void {
		$request = new SubjectRunsMigration(subjectUuid: 'obj-1', targetDefinitionRef: 'a1b2-flow-uuid', actorUid: 'dave');

		self::assertSame(0, $request->target()->targetVersion);
		self::assertSame('a1b2-flow-uuid', $request->targetDefinitionRef);
	}//end testANonNumericReferenceReadsAsVersionZero()
}//end class
