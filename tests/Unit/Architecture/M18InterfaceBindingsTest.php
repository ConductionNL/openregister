<?php

/**
 * M18 interface bindings: each new interface is bound and implemented.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\TaskBlockerMapper;
use OCA\OpenRegister\Db\TaskBlockerMapperInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M18InterfaceBindingsTest extends TestCase {

	/**
	 * The Db registrar binds the task-blocker interface to TaskBlockerMapper.
	 *
	 * @return void
	 */
	public function testTheTaskBlockerInterfaceIsBound(): void {
		$this->assertSame(TaskBlockerMapper::class, DbRegistrar::BINDINGS[TaskBlockerMapperInterface::class] ?? null);
	}//end testTheTaskBlockerInterfaceIsBound()

	/**
	 * TaskBlockerMapper implements the interface its outside callers type against.
	 *
	 * @return void
	 */
	public function testTaskBlockerMapperImplementsTheInterface(): void {
		$this->assertTrue(is_subclass_of(TaskBlockerMapper::class, TaskBlockerMapperInterface::class));
	}//end testTaskBlockerMapperImplementsTheInterface()

	/**
	 * No file outside lib/Db names the concrete TaskBlockerMapper.
	 *
	 * @return void
	 */
	public function testNoOutsideFileNamesTheConcreteMapper(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$offenders = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === false || str_starts_with($path, $lib . '/Db/') === true) {
				continue;
			}

			if (preg_match('/\bTaskBlockerMapper\b(?!Interface)/', (string)file_get_contents($path)) === 1) {
				$offenders[] = substr($path, strlen($lib) + 1);
			}
		}

		$this->assertSame([], $offenders);
	}//end testNoOutsideFileNamesTheConcreteMapper()
}//end class
