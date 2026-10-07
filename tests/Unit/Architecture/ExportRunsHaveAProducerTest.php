<?php

/**
 * The exports area is reachable, something writes to it, and something sweeps
 * it.
 *
 * 🔴 AN AREA WITH NO PRODUCER IS A TABLE THAT IS ALWAYS EMPTY, and it looks
 * exactly like an area that works: the endpoint answers 200, the list is `[]`,
 * and nothing anywhere fails. This repo has shipped a guard with a full green
 * suite and no call site three times in one day, so the producer is asserted
 * here rather than assumed.
 *
 * 🔴 AN EXPIRY THAT NO JOB READS IS A LABEL, NOT A RULE, so the sweep job's
 * declaration is asserted too. A retention nothing acts on is worse than no
 * retention, because it is written down.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Controller\ExportRunsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Structural: the area's route, its producers and its sweep.
 *
 * @coversNothing
 */
class ExportRunsHaveAProducerTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The contents of one file in the repository.
	 *
	 * @param string $relative The path, relative to the root.
	 *
	 * @return string The contents.
	 */
	private function read(string $relative): string {
		$contents = file_get_contents($this->root() . '/' . $relative);
		$this->assertIsString($contents, 'Could not read ' . $relative);

		return $contents;
	}//end read()

	/**
	 * The area is routed.
	 *
	 * @return void
	 */
	public function testTheAreaIsRouted(): void {
		$declared = require $this->root() . '/appinfo/routes.php';

		$found = false;
		foreach (($declared['routes'] ?? []) as $route) {
			if (($route['name'] ?? '') === 'exportRuns#index' && ($route['url'] ?? '') === '/api/exports') {
				$found = true;
			}
		}

		$this->assertTrue($found, 'The exports area has no route, so nobody can see what has left the building.');
	}//end testTheAreaIsRouted()

	/**
	 * The routed method exists and is public.
	 *
	 * @return void
	 */
	public function testTheRoutedMethodExists(): void {
		$reflection = new ReflectionClass(ExportRunsController::class);

		$this->assertTrue($reflection->hasMethod('index'), 'The route names a method the controller does not have.');
		$this->assertTrue($reflection->getMethod('index')->isPublic());
	}//end testTheRoutedMethodExists()

	/**
	 * The scheduled report runner writes a run.
	 *
	 * This is the producer the proposal names first, and the one with a real
	 * file for the sweep to delete.
	 *
	 * @return void
	 */
	public function testTheScheduledReportRunnerWritesARun(): void {
		$source = $this->read('lib/Service/ScheduledReportService.php');

		$this->assertStringContainsString(
			'exportRuns->record(',
			$source,
			'A scheduled report writes a file into somebody\'s Files and records nothing, so nobody can account for it.'
		);

		// The CALL, not only the helper. A recorder wired into a private method
		// that nothing calls is the guard-with-no-call-site shape all over
		// again, and grepping for the helper alone cannot tell them apart.
		$this->assertStringContainsString(
			'$this->recordRun(report:',
			$source,
			'The scheduled report runner defines a recordRun() that nothing calls.'
		);
	}//end testTheScheduledReportRunnerWritesARun()

	/**
	 * Running an export profile writes a run too.
	 *
	 * @return void
	 */
	public function testRunningAnExportProfileWritesARun(): void {
		$source = $this->read('lib/Controller/ExportProfilesController.php');

		$this->assertStringContainsString(
			'exportRuns->record(',
			$source,
			'An export served straight to a caller is recorded nowhere, so the area is blind to it.'
		);

		$this->assertStringContainsString(
			'$this->recordRun(profile:',
			$source,
			'The export profile run defines a recordRun() that nothing calls.'
		);
	}//end testRunningAnExportProfileWritesARun()

	/**
	 * The sweep job is declared, so the expiry is acted on.
	 *
	 * @return void
	 */
	public function testTheSweepJobIsDeclared(): void {
		$info = $this->read('appinfo/info.xml');

		$this->assertStringContainsString(
			'OCA\OpenRegister\BackgroundJob\SweepExpiredExportRunsJob',
			$info,
			'The sweep job is not declared, so an expired export keeps its file for ever.'
		);
	}//end testTheSweepJobIsDeclared()
}//end class
