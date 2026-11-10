<?php

/**
 * The destruction execution job is queued through its contract, not its class.
 *
 * Callers outside lib/BackgroundJob queue an approved destruction list with
 * DestructionExecutionJobInterface::JOB_CLASS. The constant must keep naming the
 * job the job list runs, and the job must keep implementing the contract, or a
 * queued list is never executed and nothing says so.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\BackgroundJob\DestructionExecutionJob;
use OCA\OpenRegister\BackgroundJob\DestructionExecutionJobInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class H02InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The job implements its contract, and the contract names the job.
	 *
	 * @return void
	 */
	public function testTheJobImplementsItsContractAndTheContractNamesTheJob(): void {
		$this->assertTrue(is_subclass_of(DestructionExecutionJob::class, DestructionExecutionJobInterface::class));
		$this->assertSame(DestructionExecutionJob::class, DestructionExecutionJobInterface::JOB_CLASS);
		$this->assertSame('destructionListUuid', DestructionExecutionJobInterface::ARGUMENT_LIST_UUID);
	}//end testTheJobImplementsItsContractAndTheContractNamesTheJob()

	/**
	 * No code outside lib/BackgroundJob names the concrete job class.
	 *
	 * Comments may still mention it; only a use line or a class reference counts.
	 *
	 * @return void
	 */
	public function testNoFileOutsideTheComponentNamesTheJobClass(): void {
		$offenders = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = str_replace('\\', '/', (string)$file);
			if (str_ends_with($path, '.php') === false || str_contains($path, '/lib/BackgroundJob/') === true) {
				continue;
			}

			$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string)file_get_contents($path));
			if (preg_match('/\bDestructionExecutionJob\b(?!Interface)/', $code) === 1) {
				$offenders[] = substr($path, (strpos($path, '/lib/') + 5));
			}
		}

		$this->assertSame([], $offenders, 'These files name DestructionExecutionJob instead of DestructionExecutionJobInterface.');
	}//end testNoFileOutsideTheComponentNamesTheJobClass()
}//end class
