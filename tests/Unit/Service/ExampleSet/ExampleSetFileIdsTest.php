<?php

/**
 * Tests for `{{file:key.n}}`: a record naming a file the set attached to an
 * object declared before it (filinq's document record on the Lindelaan
 * dossier's file).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetFileIdsTest extends ExampleSetTestCase {
	/**
	 * A dossier carrying two files, then a record naming the second.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'docs', schema: 'dossier');
		$this->store->offer(register: 'docs', schema: 'documentVersion');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			[
				'key'      => 'dossier-1',
				'register' => 'docs',
				'schema'   => 'dossier',
				'data'     => ['name' => 'Fietspad Lindelaan'],
				'files'    => [['path' => 'demo/dossier-1/01-advies.pdf'], ['path' => 'demo/dossier-1/02-raadsvoorstel.pdf']],
			],
			[
				'key'      => 'document-2',
				'register' => 'docs',
				'schema'   => 'documentVersion',
				'data'     => ['fileId' => '{{file:dossier-1.1}}', 'documentName' => 'Raadsvoorstel'],
			],
		]]);
		$folder = $this->apps['alpha'].'/'.ExampleSetCatalogue::FOLDER.'/demo/dossier-1';
		@mkdir($folder, 0777, true);
		file_put_contents($folder.'/01-advies.pdf', '%PDF-1.4 advies');
		file_put_contents($folder.'/02-raadsvoorstel.pdf', '%PDF-1.4 raadsvoorstel');
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 *
	 * @return void
	 */
	public function testARecordNamesTheIdOfAFileAnEarlierObjectCarries(): void {
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode(value: $report));

		$dossier  = $this->store->ids(register: 'docs', schema: 'dossier')[0];
		$attached = array_map(callback: 'intval', array: array_keys(array: $this->files[$dossier]));
		$document = $this->store->get(register: 'docs', schema: 'documentVersion', id: $this->store->ids(register: 'docs', schema: 'documentVersion')[0]);
		$this->assertSame(expected: $attached[1], actual: $document['fileId']);
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 *
	 * @return void
	 */
	public function testACheckStandsInAWholeNumberForAFileNotYetAttached(): void {
		$checked = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: [], actual: $checked['refusals']);
	}
}//end class
