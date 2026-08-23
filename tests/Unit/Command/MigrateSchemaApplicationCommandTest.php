<?php

/**
 * MigrateSchemaApplicationCommand unit tests
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\MigrateSchemaApplicationCommand;
use PHPUnit\Framework\TestCase;

/**
 * Locks planCollisions(): the rule that decides whether an app-id migration is
 * safe to perform or has to be refused.
 */
class MigrateSchemaApplicationCommandTest extends TestCase {

	/**
	 * Nothing under the target id means nothing can collide.
	 *
	 * This is the ordinary case — the rename has not been imported yet, which
	 * is exactly when this command should run.
	 *
	 * @return void
	 */
	public function testNoTargetSlugsMeansNoCollisions(): void {
		$this->assertSame(
			[],
			MigrateSchemaApplicationCommand::planCollisions(
				fromSlugs: ['case', 'caseType', 'bezwaar'],
				toSlugs: []
			)
		);

	}//end testNoTargetSlugsMeansNoCollisions()


	/**
	 * Disjoint slugs do not collide.
	 *
	 * @return void
	 */
	public function testDisjointSlugsDoNotCollide(): void {
		$this->assertSame(
			[],
			MigrateSchemaApplicationCommand::planCollisions(
				fromSlugs: ['case', 'caseType'],
				toSlugs: ['invoice', 'contract']
			)
		);

	}//end testDisjointSlugsDoNotCollide()


	/**
	 * A slug present under both ids is reported.
	 *
	 * This is the state after someone has already imported under the new app
	 * id: the importer forked the schema rather than finding the original, so
	 * moving the original on top would leave two rows sharing (slug,
	 * application) with no way to tell which one the objects belong to.
	 *
	 * @return void
	 */
	public function testSharedSlugIsReportedAsACollision(): void {
		$this->assertSame(
			['case'],
			MigrateSchemaApplicationCommand::planCollisions(
				fromSlugs: ['case', 'caseType'],
				toSlugs: ['case']
			)
		);

	}//end testSharedSlugIsReportedAsACollision()


	/**
	 * Matching is case-insensitive.
	 *
	 * findByApplicationAndSlug() looks schemas up on `lower(slug)`, so two
	 * spellings that differ only in case ARE the same schema to the importer.
	 * A case-sensitive comparison here would report "safe to migrate" for a
	 * pair that then collides — the refusal would be skipped precisely when it
	 * was needed.
	 *
	 * @return void
	 */
	public function testCollisionDetectionIsCaseInsensitive(): void {
		$this->assertSame(
			['casetype'],
			MigrateSchemaApplicationCommand::planCollisions(
				fromSlugs: ['CaseType'],
				toSlugs: ['casetype']
			)
		);

	}//end testCollisionDetectionIsCaseInsensitive()


	/**
	 * A slug repeated on the source side is reported once.
	 *
	 * @return void
	 */
	public function testDuplicateSourceSlugsAreReportedOnce(): void {
		$this->assertSame(
			['case'],
			MigrateSchemaApplicationCommand::planCollisions(
				fromSlugs: ['case', 'CASE', 'case'],
				toSlugs: ['case']
			)
		);

	}//end testDuplicateSourceSlugsAreReportedOnce()


}//end class
