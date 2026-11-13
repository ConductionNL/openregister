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
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
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
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Service\CauseFrame
 * @uses \OCA\OpenRegister\Service\ExampleSet\CastOrganisationsRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountTerms
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccountsInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppAndHistoryRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastOrganisations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFields
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeFollower
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChangeReadBack
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCheckRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFillScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentWrite
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetHistoryRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRequest
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerSetup
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstallerWriters
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLinkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNewAccount
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOwnRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeopleRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecordPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverObjects
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemoverSettings
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolveScope
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetResolvedObject
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowInstall
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetVouchedAccounts
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWorkRowKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWrittenValue
 * @uses \OCA\OpenRegister\Service\ExampleSet\FileAttachRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentDeclarations
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentOrdering
 * @uses \OCA\OpenRegister\Service\ExampleSet\FragmentPart
 * @uses \OCA\OpenRegister\Service\ExampleSet\LocalFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ObjectCheck
 * @uses \OCA\OpenRegister\Service\ExampleSet\RecordLedger
 * @uses \OCA\OpenRegister\Service\ExampleSet\RowInstallRun
 * @uses \OCA\OpenRegister\Service\File\FileTarget
 * @uses \OCA\OpenRegister\Service\File\FileUpload
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
		$document = $this->store->row(ref: new ObjectRef(register: 'docs', schema: 'documentVersion', id: $this->store->ids(register: 'docs', schema: 'documentVersion')[0]));
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
