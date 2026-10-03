<?php

/**
 * Unit tests for ExportWholeSetAction — the datawarehouse extract as a bulk act.
 *
 * The rehearsal is the case worth guarding: the bulk engine calls the same
 * method to preview and to commit, and a preview that wrote rows would be a
 * promise rather than a rehearsal. The second is the flag: a filtered profile
 * carrying `wholeSet` is a report somebody mislabelled, and running it over
 * every register overnight is the wrong answer to it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BulkAction
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\BulkAction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed PHPUnit doubles, the type IS the documentation.

use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExportWholeSetActionTest extends TestCase {
	private ExportProfileService&MockObject $profiles;

	private ExportProfileWriter&MockObject $writer;

	private ExportRightService&MockObject $rights;

	private ExportAuditRecorder&MockObject $recorder;

	private IRootFolder&MockObject $rootFolder;

	protected function setUp(): void {
		$this->profiles = $this->createMock(ExportProfileService::class);
		$this->writer = $this->createMock(ExportProfileWriter::class);
		$this->rights = $this->createMock(ExportRightService::class);
		$this->recorder = $this->createMock(ExportAuditRecorder::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	private function action(): ExportWholeSetAction {
		return new ExportWholeSetAction(
			$this->profiles,
			$this->writer,
			$this->rights,
			$this->recorder,
			$this->createMock(SchemaMapper::class),
			$this->rootFolder
		);
	}//end action()

	private function profile(bool $wholeSet, ?string $filters = null): ExportProfile {
		$profile = new ExportProfile();
		$profile->setName('Datawarehouse');
		$profile->setWholeSet($wholeSet);
		$profile->setFilters($filters);
		$profile->setFields((string)json_encode(['zaaknummer']));
		$profile->setValueMode(ExportProfile::MODE_STORED);

		return $profile;
	}//end profile()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-1');
		$object->setRegister('7');
		$object->setSchema('19');
		$object->setObject(['zaaknummer' => 'Z-001']);

		return $object;
	}//end object()

	private function actor(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('eigenaar-1');

		return $user;
	}//end actor()

	public function testTheActionIsRegisteredUnderAStableId(): void {
		self::assertSame('openregister:export-whole-set', $this->action()->getId());
		self::assertFalse($this->action()->requiresJustification());
		self::assertSame([], $this->action()->getGuards());
	}//end testTheActionIsRegisteredUnderAStableId()

	public function testAJobWithoutAProfileIsRefusedBeforeItIsCreated(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('profileId');

		$this->action()->validateParameters([]);
	}//end testAJobWithoutAProfileIsRefusedBeforeItIsCreated()

	public function testAFilteredProfileIsNotAWholeSetJob(): void {
		$this->profiles->method('find')->willReturn(
			$this->profile(true, (string)json_encode(['status' => 'open']))
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('not a whole-set profile');

		$this->action()->validateParameters(['profileId' => 42]);
	}//end testAFilteredProfileIsNotAWholeSetJob()

	public function testAWholeSetProfileIsAccepted(): void {
		$this->profiles->method('find')->willReturn($this->profile(true));

		$this->action()->validateParameters(['profileId' => 42]);

		self::assertTrue(true);
	}//end testAWholeSetProfileIsAccepted()

	public function testTheRehearsalWritesNothing(): void {
		$this->profiles->method('find')->willReturn($this->profile(true));
		$this->rights->method('refusalForUid')->willReturn(null);
		$this->writer->method('csvLineFor')->willReturn("\"Z-001\"\n");

		// The one assertion that separates a rehearsal from a commit.
		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->recorder->expects(self::never())->method('recordCompleted');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], false, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
		self::assertStringContainsString('Would be written', (string)$result->getReason());
	}//end testTheRehearsalWritesNothing()

	public function testAnObjectTheProfileCannotProjectIsSkippedNotFailed(): void {
		$this->profiles->method('find')->willReturn($this->profile(true));
		$this->rights->method('refusalForUid')->willReturn(null);
		$this->writer->method('csvLineFor')->willReturn('');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
	}//end testAnObjectTheProfileCannotProjectIsSkippedNotFailed()

	public function testARowTheActorMayNotExportIsRefusedAndRecorded(): void {
		$this->profiles->method('find')->willReturn($this->profile(true));
		$this->rights->method('refusalForUid')->willReturn(
			new ExportRefusedException('export-right-missing', 'no export for you', 403)
		);
		$this->recorder->expects(self::once())->method('recordRefused');
		$this->rootFolder->expects(self::never())->method('getUserFolder');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_REFUSED, $result->getOutcome());
		self::assertSame('export-right-missing', $result->getReason());
	}//end testARowTheActorMayNotExportIsRefusedAndRecorded()

	public function testAJobWithNoActorIsRefusedRatherThanRunningAsNobody(): void {
		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, null);

		self::assertSame(BulkJobMember::OUTCOME_REFUSED, $result->getOutcome());
		self::assertSame('not-authenticated', $result->getReason());
	}//end testAJobWithNoActorIsRefusedRatherThanRunningAsNobody()
}//end class
