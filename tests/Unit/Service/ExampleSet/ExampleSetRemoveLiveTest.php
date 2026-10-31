<?php

/**
 * Tests for what the first live remove of zuiddrecht left behind: every
 * attached file (deleted by id without its object, which throws from occ),
 * dossiq's cases (an archival schema), and no reason for either.
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
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
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
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetRemoveLiveTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'pub', schema: 'publication');
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			[
				'key'      => 'pub-1',
				'register' => 'pub',
				'schema'   => 'publication',
				'data'     => ['title' => 'Besluit'],
				'files'    => [['path' => 'demo/pub-1/01-besluit.pdf', 'share' => true]],
			],
			['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']],
		]]);
		$folder = $this->apps['alpha'].'/'.ExampleSetCatalogue::FOLDER.'/demo/pub-1';
		@mkdir($folder, 0777, true);
		file_put_contents($folder.'/01-besluit.pdf', '%PDF-1.4 example');

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function testAFileTheSetAttachedIsDeletedByItsNodeAndItsObjectGoesWithIt(): void {
		$objectId = $this->store->ids(register: 'pub', schema: 'publication')[0];

		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame([], $removed['failed']);
		$this->assertSame(1, $removed['removed']['files']);
		$this->assertSame([], $this->files[$objectId]);
		$this->assertSame([], $this->store->ids(register: 'pub', schema: 'publication'));
		$this->assertSame([], $this->store->ids(register: 'cases', schema: 'case'));
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function testAFileThatStaysIsNamedWithItsReasonAndKeepsItsObjectForTheNextRun(): void {
		$objectId               = $this->store->ids(register: 'pub', schema: 'publication')[0];
		$fileId                 = (int) array_key_first($this->files[$objectId]);
		$this->undeletableFiles = [$fileId];

		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(
			[
				'file 01-besluit.pdf on alpha/pub-1: File is not deletable by the current session',
				'pub publication alpha/pub-1 ('.$objectId.'): a file of it stays',
			],
			$removed['failed']
		);
		$this->assertSame([$objectId], $this->store->ids(register: 'pub', schema: 'publication'));
		$this->assertArrayHasKey('alpha/pub-1', $this->record->read(set: 'demo')['files']);

		$this->undeletableFiles = [];
		$again                  = $this->remover->remove(set: 'demo');
		$this->assertSame([], $again['failed']);
		$this->assertSame([], $this->store->ids(register: 'pub', schema: 'publication'));
	}

	/**
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function testAnObjectUnderALegalHoldStaysWithItsReason(): void {
		$caseId            = $this->store->ids(register: 'cases', schema: 'case')[0];
		$this->store->held = [$caseId];

		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(['cases case alpha/case-1 ('.$caseId.'): it is under an active legal hold'], $removed['failed']);
		$this->assertSame(['alpha/case-1'], array_keys($this->record->read(set: 'demo')['objects']));
	}
}
