<?php

/**
 * No shim survives the removal: the retired approval classes, routes and
 * events are gone from the runtime tree, and what remains of their names
 * lives only in migration and rollback code that reads the kept legacy
 * tables (flow-approval-consolidation task 4.1, acceptance criteria).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing The subject is the ABSENCE of the retired surface.
 */
class ApprovalRetirementSurfaceTest extends TestCase {

	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The files under lib/ that may still speak of the legacy TABLES,
	 * because reading them is their whole job.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_TABLE_READERS = [
		'lib/Repair/MigrateApprovalChainsToTasks.php',
		'lib/Command/RollbackApprovalMigrationCommand.php',
		'lib/Migration/Version1Date20260325000003.php',
		'lib/Migration/Version1Date20260714010000.php',
		'lib/Migration/Version1Date20260901180000.php',
	];

	public function testTheRetiredClassFilesAreGone(): void {
		$gone = [
			'lib/Db/ApprovalChain.php',
			'lib/Db/ApprovalChainMapper.php',
			'lib/Db/ApprovalStep.php',
			'lib/Db/ApprovalStepMapper.php',
			'lib/Service/ApprovalService.php',
			'lib/Controller/ApprovalController.php',
			'lib/Event/ApprovalStepInitiatedEvent.php',
			'lib/Event/ApprovalStepApprovedEvent.php',
			'lib/Event/ApprovalStepRejectedEvent.php',
			'lib/Event/ApprovalStepCompletedEvent.php',
			'src/components/workflow/ApprovalChainPanel.vue',
			'src/components/workflow/ApprovalStepList.vue',
		];
		foreach ($gone as $file) {
			self::assertFileDoesNotExist(self::ROOT . '/' . $file, $file . ' was retired without a facade and must stay gone');
		}
	}//end testTheRetiredClassFilesAreGone()

	public function testNoCodeImportsOrInstantiatesARetiredClass(): void {
		$offenders = [];
		$pattern = '/use\s+OCA\\\\OpenRegister\\\\(?:Db\\\\ApprovalChain|Db\\\\ApprovalStep|Service\\\\ApprovalService|Controller\\\\ApprovalController|Event\\\\ApprovalStep\w+Event)|new\s+Approval(?:Chain|Step|Service|Controller)|ApprovalStep\w*Event::class|ApprovalService::class/';
		foreach ($this->phpFilesUnder(dir: self::ROOT . '/lib') as $file) {
			$relative = substr($file, strlen(self::ROOT) + 1);
			if (preg_match($pattern, (string)file_get_contents($file)) === 1) {
				$offenders[] = $relative;
			}
		}

		self::assertSame([], $offenders, 'these files still bind to the retired approval runtime');
	}//end testNoCodeImportsOrInstantiatesARetiredClass()

	public function testTheLegacyTablesAreReadOnlyByMigrationAndRollbackCode(): void {
		$offenders = [];
		foreach ($this->phpFilesUnder(dir: self::ROOT . '/lib') as $file) {
			$relative = substr($file, strlen(self::ROOT) + 1);
			if (in_array($relative, self::LEGACY_TABLE_READERS, true) === true) {
				continue;
			}

			if (str_contains((string)file_get_contents($file), 'openregister_approval_') === true) {
				$offenders[] = $relative;
			}
		}

		self::assertSame([], $offenders, 'only migration and rollback code may touch the kept legacy tables');
	}//end testTheLegacyTablesAreReadOnlyByMigrationAndRollbackCode()

	public function testTheRetiredRoutesAreGoneAndTheSignalRouteExists(): void {
		$routes = (string)file_get_contents(self::ROOT . '/appinfo/routes.php');

		self::assertStringNotContainsString("'approval#", $routes, 'no route may point at the removed controller');
		self::assertStringNotContainsString('/api/approval-chains', $routes);
		self::assertStringNotContainsString('/api/approval-steps', $routes);
		self::assertStringContainsString('/api/flow-run-signals/{key}', $routes, 'the one added route');
	}//end testTheRetiredRoutesAreGoneAndTheSignalRouteExists()

	public function testNoListenerRegistrationForARetiredEventSurvives(): void {
		$application = (string)file_get_contents(self::ROOT . '/lib/AppInfo/Application.php');

		self::assertStringNotContainsString('ApprovalStepInitiatedEvent', $application);
		self::assertStringNotContainsString('ApprovalStepApprovedEvent', $application);
		self::assertStringNotContainsString('ApprovalStepRejectedEvent', $application);
		self::assertStringNotContainsString('ApprovalStepCompletedEvent', $application);
		self::assertStringContainsString('TaskSequenceCompletedEvent', $application, 'the replacement subscription');
	}//end testNoListenerRegistrationForARetiredEventSurvives()

	/**
	 * Every .php file under a directory, recursively.
	 *
	 * @param string $dir The directory.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function phpFilesUnder(string $dir): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $entry) {
			if ($entry->isFile() === true && $entry->getExtension() === 'php') {
				$files[] = $entry->getPathname();
			}
		}

		return $files;
	}//end phpFilesUnder()
}//end class
