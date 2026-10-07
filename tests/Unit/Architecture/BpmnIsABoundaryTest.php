<?php

/**
 * Interchange is a boundary, not an execution semantic.
 *
 * 🔴 NOTHING IN THE ENGINE MAY REACH INTO `Bpmn\`. The whole safety argument
 * for an own serializer over a declared subset is that the subset cannot leak
 * into what runs: if a run path ever asked the BPMN code a question, the
 * standard's vocabulary would start deciding behaviour, and the next
 * "interchange" change would be a change to the engine.
 *
 * The direction is one way on purpose. `Bpmn\` reads the flow document; the
 * flow document knows nothing about BPMN.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class BpmnIsABoundaryTest extends TestCase {

	/**
	 * The engine's own directory.
	 *
	 * @var string
	 */
	private const ENGINE = __DIR__ . '/../../../lib/Service/Flow';

	/**
	 * No file under lib/Service/Flow, outside Bpmn/, may name the Bpmn namespace.
	 *
	 * @return void
	 */
	public function testNothingInTheEngineReachesIntoBpmn(): void {
		$offenders = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ENGINE));

		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$path = (string)$file->getRealPath();
			if (str_contains($path, DIRECTORY_SEPARATOR . 'Bpmn' . DIRECTORY_SEPARATOR) === true) {
				continue;
			}

			$source = (string)file_get_contents($path);
			if (str_contains($source, 'Service\\Flow\\Bpmn') === true) {
				$offenders[] = basename($path);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'the engine must not depend on the interchange boundary: ' . implode(', ', $offenders)
		);
	}//end testNothingInTheEngineReachesIntoBpmn()

	/**
	 * And the boundary itself holds nothing that runs.
	 *
	 * A node registry lookup is fine; queueing, advancing or firing is not.
	 *
	 * @return void
	 */
	public function testTheBoundaryDoesNotRunAnything(): void {
		$forbidden = ['FlowRunService', 'FlowAdvancer', 'FlowFiring', '->queue(', '->advance('];
		$offenders = [];

		foreach ((array)glob(self::ENGINE . '/Bpmn/*.php') as $path) {
			$source = (string)file_get_contents((string)$path);
			foreach ($forbidden as $needle) {
				if (str_contains($source, $needle) === true) {
					$offenders[] = basename((string)$path) . ' → ' . $needle;
				}
			}
		}

		$this->assertSame([], $offenders, 'interchange must never execute: ' . implode(', ', $offenders));
	}//end testTheBoundaryDoesNotRunAnything()
}//end class
