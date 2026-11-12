<?php

/**
 * RunFlowOperationInterface names the operation's container id, and the class implements it.
 *
 * Nextcloud Flow stores the operation's class name with every rule and asks
 * the container for it when the rule fires, so the interface constant the
 * registration listener resolves must stay the class name. (Kept apart from
 * M05InterfaceBindingsTest, which pins registrar bindings: this interface
 * needs none, the container id is the class itself.)
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

use OCA\OpenRegister\WorkflowEngine\RunFlowOperation;
use OCA\OpenRegister\WorkflowEngine\RunFlowOperationInterface;
use OCP\WorkflowEngine\ISpecificOperation;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class M05RunFlowOperationIdTest extends TestCase {

	/**
	 * The constant is the class name, and the class implements the interface.
	 *
	 * @return void
	 */
	public function testTheOperationIdIsTheClassAndItImplementsTheInterface(): void {
		self::assertSame('OCA\OpenRegister\WorkflowEngine\RunFlowOperation', RunFlowOperationInterface::OPERATION_ID);
		self::assertContains(RunFlowOperationInterface::class, class_implements(RunFlowOperation::class));
		self::assertContains(ISpecificOperation::class, class_implements(RunFlowOperation::class));
	}//end testTheOperationIdIsTheClassAndItImplementsTheInterface()

	/**
	 * Outside lib/WorkflowEngine, no file names the concrete operation.
	 *
	 * @return void
	 */
	public function testNoFileOutsideTheComponentNamesTheClass(): void {
		$lib = dirname(__DIR__, 3) . '/lib';
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === false || str_contains($path, '/lib/WorkflowEngine/') === true) {
				continue;
			}

			if (preg_match('/\bRunFlowOperation\b(?!Interface)/', (string)file_get_contents($path)) === 1) {
				$found[] = substr($path, strlen($lib) + 1);
			}
		}

		self::assertSame([], $found);
	}//end testNoFileOutsideTheComponentNamesTheClass()
}//end class
