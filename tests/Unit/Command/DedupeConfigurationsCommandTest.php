<?php

/**
 * Unit tests for the configuration dedupe plan (#2072).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DedupeConfigurationsCommand;
use PHPUnit\Framework\TestCase;

/**
 * Locks planDeletions(): per app (newest-first rows), keep the newest and mark
 * the rest for deletion; single-row apps and app-less rows are left alone.
 */
class DedupeConfigurationsCommandTest extends TestCase {

	/**
	 * Rows supplied newest-first: keep the newest id, delete the older ones.
	 *
	 * @return void
	 */
	public function testKeepsNewestAndDeletesOlderPerApp(): void {
		$rows = [
			['id' => 4381, 'app' => 'softwarecatalog'],
			['id' => 117, 'app' => 'softwarecatalog'],
			['id' => 81, 'app' => 'softwarecatalog'],
			['id' => 7, 'app' => 'softwarecatalog'],
		];

		$plan = DedupeConfigurationsCommand::planDeletions(rows: $rows);

		$this->assertSame(['softwarecatalog'], array_keys($plan));
		$this->assertSame(4381, $plan['softwarecatalog']['keep']);
		$this->assertSame([117, 81, 7], $plan['softwarecatalog']['delete']);

	}//end testKeepsNewestAndDeletesOlderPerApp()

	/**
	 * An app with a single row produces no deletions.
	 *
	 * @return void
	 */
	public function testSingleRowAppIsNotDeduped(): void {
		$plan = DedupeConfigurationsCommand::planDeletions(
			rows: [['id' => 96, 'app' => 'openconnector']]
		);

		$this->assertSame([], $plan);

	}//end testSingleRowAppIsNotDeduped()

	/**
	 * Rows without an app are ignored (nothing to dedupe against).
	 *
	 * @return void
	 */
	public function testAppLessRowsAreIgnored(): void {
		$plan = DedupeConfigurationsCommand::planDeletions(
			rows: [
				['id' => 5, 'app' => ''],
				['id' => 4, 'app' => null],
				['id' => 3],
			]
		);

		$this->assertSame([], $plan);

	}//end testAppLessRowsAreIgnored()

	/**
	 * Multiple apps are planned independently.
	 *
	 * @return void
	 */
	public function testMultipleAppsPlannedIndependently(): void {
		$rows = [
			['id' => 4380, 'app' => 'docudesk'],
			['id' => 4379, 'app' => 'docudesk'],
			['id' => 110, 'app' => 'procest'],
			['id' => 74, 'app' => 'procest'],
			['id' => 10, 'app' => 'procest'],
			['id' => 96, 'app' => 'openconnector'],
		];

		$plan = DedupeConfigurationsCommand::planDeletions(rows: $rows);

		$this->assertSame(4380, $plan['docudesk']['keep']);
		$this->assertSame([4379], $plan['docudesk']['delete']);
		$this->assertSame(110, $plan['procest']['keep']);
		$this->assertSame([74, 10], $plan['procest']['delete']);
		$this->assertArrayNotHasKey('openconnector', $plan);

	}//end testMultipleAppsPlannedIndependently()

}//end class
