<?php

/**
 * Pins every outcome ExportWholeSetAction::apply() gives, allowed and refused.
 *
 * The schema is read without RBAC or multitenancy and handed to the export
 * right as is (null when unreadable); a refusal is audited with the profile,
 * the rule, the reason, the ids and the actor before anything is written; a
 * committed row is appended and audited as completed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use OCA\OpenRegister\BulkAction\ExportWholeSetAction;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Export\ExportAuditRecorderInterface;
use OCA\OpenRegister\Service\Export\ExportProfileServiceInterface;
use OCA\OpenRegister\Service\Export\ExportProfileWriterInterface;
use OCA\OpenRegister\Service\Export\ExportRefusedException;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExportWholeSetActionOutcomeTest extends TestCase {

	private ExportProfileServiceInterface&MockObject $profiles;

	private ExportProfileWriterInterface&MockObject $writer;

	private ExportRightService&MockObject $rights;

	private ExportAuditRecorderInterface&MockObject $recorder;

	private SchemaMapperInterface&MockObject $schemas;

	private IRootFolder&MockObject $rootFolder;

	protected function setUp(): void {
		$this->profiles = $this->createMock(ExportProfileServiceInterface::class);
		$this->writer = $this->createMock(ExportProfileWriterInterface::class);
		$this->rights = $this->createMock(ExportRightService::class);
		$this->recorder = $this->createMock(ExportAuditRecorderInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}//end setUp()

	private function action(): ExportWholeSetAction {
		return new ExportWholeSetAction(
			$this->profiles,
			$this->writer,
			$this->rights,
			$this->recorder,
			$this->schemas,
			$this->rootFolder
		);
	}//end action()

	private function profile(): ExportProfile {
		$profile = new ExportProfile();
		$profile->setName('Datawarehouse');
		$profile->setWholeSet(true);
		$profile->setFields((string)json_encode(['zaaknummer']));
		$profile->setValueMode(ExportProfile::MODE_STORED);

		return $profile;
	}//end profile()

	private function object(string $schema = '19'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-1');
		$object->setRegister('7');
		$object->setSchema($schema);
		$object->setObject(['zaaknummer' => 'Z-001']);

		return $object;
	}//end object()

	private function actor(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('eigenaar-1');

		return $user;
	}//end actor()

	/**
	 * Files: Exports/ exists and holds the schema's file, written to $path.
	 *
	 * @param string $path A temp file the row lands in.
	 *
	 * @return void
	 */
	private function files(string $path): void {
		$file = $this->createMock(File::class);
		$file->method('fopen')->willReturn(fopen($path, 'a'));
		$exports = $this->createMock(Folder::class);
		$exports->method('nodeExists')->willReturn(true);
		$exports->method('get')->willReturn($file);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($exports);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
	}//end files()

	public function testAnUnreadableProfileFailsTheRow(): void {
		$this->profiles->method('find')->willThrowException(new RuntimeException('gone'));
		$this->rights->expects(self::never())->method('refusalForUid');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		self::assertSame('The export profile could not be read: gone', $result->getReason());
	}//end testAnUnreadableProfileFailsTheRow()

	public function testTheSchemaIsReadWithoutRbacAndHandedToTheRightForTheActor(): void {
		$this->profiles->method('find')->willReturn($this->profile());
		$schema = new Schema();
		$seen = [];
		$this->schemas->expects(self::once())->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use (&$seen, $schema): Schema {
				$seen = [$id, $_rbac, $_multitenancy];
				return $schema;
			}
		);
		$this->rights->expects(self::once())->method('refusalForUid')->with($schema, 'eigenaar-1')->willReturn(null);
		$this->writer->method('csvLineFor')->willReturn("\"Z-001\"\n");

		$result = $this->action()->apply($this->object(), ['profileId' => 42], false, $this->actor());

		self::assertSame([19, false, false], $seen);
		self::assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
		self::assertSame('Would be written to datawarehouse_schema-19.csv', $result->getReason());
	}//end testTheSchemaIsReadWithoutRbacAndHandedToTheRightForTheActor()

	public function testAnUnreadableSchemaReachesTheRightAsNull(): void {
		$this->profiles->method('find')->willReturn($this->profile());
		$this->schemas->method('find')->willThrowException(new RuntimeException('no table'));
		$this->rights->expects(self::once())->method('refusalForUid')->with(null, 'eigenaar-1')
			->willReturn(new ExportRefusedException('schema-unresolvable', 'An unreadable rule refuses.', 403));

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_REFUSED, $result->getOutcome());
		self::assertSame('schema-unresolvable', $result->getReason());
	}//end testAnUnreadableSchemaReachesTheRightAsNull()

	public function testANonNumericSchemaIsNeverLookedUpAndNamesAnUnknownFile(): void {
		$this->profiles->method('find')->willReturn($this->profile());
		$this->schemas->expects(self::never())->method('find');
		$this->rights->method('refusalForUid')->willReturn(null);
		$this->writer->method('csvLineFor')->willReturn("\"Z-001\"\n");

		$result = $this->action()->apply($this->object('zaken'), ['profileId' => 42], false, $this->actor());

		self::assertSame('Would be written to datawarehouse_schema-unknown.csv', $result->getReason());
	}//end testANonNumericSchemaIsNeverLookedUpAndNamesAnUnknownFile()

	public function testARefusalIsAuditedWithEveryFieldAndWritesNothing(): void {
		$this->profiles->method('find')->willReturn($this->profile());
		$this->rights->method('refusalForUid')->willReturn(new ExportRefusedException('export-right-missing', 'no export for you', 403));
		$this->recorder->expects(self::once())->method('recordRefused')
			->with('Datawarehouse', 'export-right-missing', 'no export for you', 7, 19, 'eigenaar-1');
		$this->recorder->expects(self::never())->method('recordCompleted');
		$this->writer->expects(self::never())->method('csvLineFor');
		$this->rootFolder->expects(self::never())->method('getUserFolder');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_REFUSED, $result->getOutcome());
		self::assertSame('export-right-missing', $result->getReason());
	}//end testARefusalIsAuditedWithEveryFieldAndWritesNothing()

	public function testACommittedRowIsAppendedAndAuditedAsCompleted(): void {
		$this->profiles->method('find')->willReturn($this->profile());
		$this->rights->method('refusalForUid')->willReturn(null);
		$this->writer->method('csvLineFor')->willReturn("\"Z-001\"\n");
		$path = (string)tempnam(sys_get_temp_dir(), 'r19wholeset');
		$this->files($path);
		$this->recorder->expects(self::never())->method('recordRefused');
		$this->recorder->expects(self::once())->method('recordCompleted')
			->with('Datawarehouse', 1, 'csv', ExportProfile::MODE_STORED, 7, 19, 'eigenaar-1');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome());
		self::assertSame('Written to datawarehouse_schema-19.csv', $result->getReason());
		self::assertSame("\"Z-001\"\n", (string)file_get_contents($path));
		unlink($path);
	}//end testACommittedRowIsAppendedAndAuditedAsCompleted()

	public function testARowThatCannotBeWrittenFailsAndIsNotAuditedAsCompleted(): void {
		$this->profiles->method('find')->willReturn($this->profile());
		$this->rights->method('refusalForUid')->willReturn(null);
		$this->writer->method('csvLineFor')->willReturn("\"Z-001\"\n");
		$this->rootFolder->method('getUserFolder')->willThrowException(new RuntimeException('quota'));
		$this->recorder->expects(self::never())->method('recordCompleted');

		$result = $this->action()->apply($this->object(), ['profileId' => 42], true, $this->actor());

		self::assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		self::assertSame('The row could not be written: quota', $result->getReason());
	}//end testARowThatCannotBeWrittenFailsAndIsNotAuditedAsCompleted()
}//end class
