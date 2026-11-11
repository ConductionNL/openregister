<?php

/**
 * Golden pins for the BPMN exporter's exact output.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the serialised XML byte for byte, so a refactor cannot move it.
 */
class FlowBpmnExporterGoldenTest extends TestCase {

	/**
	 * The flows, by golden file name.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: array<int, mixed>}> The cases.
	 */
	public static function flows(): array {
		return [
			'mixed' => [
				'mixed',
				[
					['id' => 'start', 'name' => 'Start', 'type' => 'openregister.trigger-manual', 'position' => ['x' => 10, 'y' => 20]],
					['id' => 'sched', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => ' 0 2 * * * '], 'x' => 300, 'y' => 40],
					['id' => 'sched2', 'name' => 'Geen cron', 'type' => 'openregister.trigger-schedule', 'config' => []],
					[
						'id' => 'obj',
						'name' => 'Op object',
						'type' => 'openregister.trigger-object',
						'config' => ['event' => 'created', 'register' => ' 5 ', 'schema' => ''],
					],
					['id' => 'obj2', 'name' => 'Elk object', 'type' => 'openregister.trigger-object'],
					['id' => 'kies', 'name' => 'Kies', 'type' => 'openregister.switch', 'position' => ['x' => 1]],
					['id' => 'wacht', 'name' => 'Wacht', 'type' => 'openregister.await-signal', 'config' => ['signal' => 'go']],
					['id' => 'pauze', 'name' => 'Pauze', 'type' => 'openregister.wait', 'config' => ['cron' => '* * * * *']],
					['id' => '9lives', 'name' => 'Cijfer', 'type' => 'openregister.send-email', 'config' => ['to' => 'a@b.nl', 'n' => [1, 2]]],
					['id' => 'with space', 'type' => 'openregister.unknown-thing'],
					['name' => 'Zonder id', 'type' => 'openregister.end'],
					'not-a-node',
				],
				[
					['id' => 'e1', 'from' => 'start', 'to' => 'kies'],
					['from' => 'kies', 'to' => 'wacht', 'condition' => ' data.x > 1 '],
					['id' => 'e3', 'from' => 'kies', 'to' => 'ghost'],
					['id' => 'e4', 'from' => 'wacht', 'to' => '9lives', 'condition' => '   '],
					['from' => 'with space', 'to' => 'obj'],
					'not-an-edge',
				],
			],
			'empty' => ['empty', [], []],
		];
	}//end flows()

	/**
	 * The export is identical to its golden file.
	 *
	 * @param string            $name  The golden file name.
	 * @param array<int, mixed> $nodes The nodes.
	 * @param array<int, mixed> $edges The edges.
	 *
	 * @return void
	 */
	#[DataProvider('flows')]
	public function testExportMatchesGolden(string $name, array $nodes, array $edges): void {
		$flow = new Flow();
		$flow->setUuid('0a1b2c3d-0000-4000-8000-00000000000' . strlen($name));
		$flow->setName('Golden ' . $name);
		$flow->setNodes($nodes);
		$flow->setEdges($edges);

		$exporter = new FlowBpmnExporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator());
		$path = __DIR__ . '/fixtures/exporter-golden/' . $name . '.xml';

		$this->assertStringEqualsFile($path, $exporter->export(flow: $flow));
	}//end testExportMatchesGolden()
}//end class
