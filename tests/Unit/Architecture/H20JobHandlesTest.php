<?php

/**
 * The deferred lifecycle and notification jobs are named through JobHandles.
 *
 * AutoTransitionQueue and AnnotationNotificationListener hand JobHandles
 * constants to the deferral service instead of the job classes. Each constant
 * must stay exactly the job's class name, or entries are queued under a name
 * nothing runs; and no lib file outside lib/BackgroundJob may name either job.
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

use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\BackgroundJob\AnnotationNotificationDispatchJob;
use OCA\OpenRegister\BackgroundJob\AutoTransitionJob;
use OCA\OpenRegister\BackgroundJob\JobHandles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class H20JobHandlesTest extends TestCase {

	private const LIB = __DIR__ . '/../../../lib';

	private const JOBS = [
		JobHandles::ANNOTATION_NOTIFICATION_DISPATCH => AnnotationNotificationDispatchJob::class,
		JobHandles::AUTO_TRANSITION => AutoTransitionJob::class,
	];

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function jobs(): array {
		$cases = [];
		foreach (self::JOBS as $handle => $class) {
			$cases[$class] = [$handle, $class];
		}

		return $cases;
	}//end jobs()

	#[DataProvider('jobs')]
	public function testTheHandleIsTheJobClass(string $handle, string $class): void {
		$this->assertSame($class, $handle);
		$this->assertContains(ActorForwardedJob::class, class_parents($class), $class . ' is an actor-forwarded job');
	}//end testTheHandleIsTheJobClass()

	#[DataProvider('jobs')]
	public function testNoFileOutsideBackgroundJobNamesTheJob(string $handle, string $class): void {
		$short = substr($class, (strrpos($class, '\\') + 1));
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = str_replace('\\', '/', $file->getPathname());
			if ($file->isFile() === false || str_ends_with($path, '.php') === false
				|| str_contains($path, '/lib/BackgroundJob/') === true
			) {
				continue;
			}

			$code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
			if (preg_match('/\b' . $short . '\b/', (string) $code) === 1) {
				$found[] = substr($path, (strpos($path, '/lib/') + 5));
			}
		}

		$this->assertSame([], $found, 'Use ' . $handle . ' through JobHandles instead.');
	}//end testNoFileOutsideBackgroundJobNamesTheJob()
}//end class
