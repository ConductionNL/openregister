<?php

/**
 * Unit tests for the declared destruction scope.
 *
 * Covers the preview with counts, the instance that declares nothing and so
 * destroys exactly what it destroyed before, the scope naming a member nobody
 * can honour (which refuses before touching anything), and the one property
 * that has to hold by construction: the evidence of the destruction is never
 * inside the scope.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCA\OpenRegister\Service\Deletion\DestructionScopeReader;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DestructionScopeServiceTest extends TestCase {
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-100');

		return $object;
	}//end object()

	private function schema(array $scope): Schema {
		$schema = new Schema();
		$schema->setArchive([DestructionScope::SCHEMA_KEY => $scope]);

		return $schema;
	}//end schema()

	public function testWhatHangsOffTheObjectIsCountedBeforeItGoes(): void {
		$notes = $this->createMock(NoteService::class);
		$notes->method('countNotesForObject')->willReturn(4);

		$files = $this->createMock(FileService::class);
		$files->method('getFiles')->willReturn([['id' => 1], ['id' => 2]]);

		$relations = $this->createMock(ObjectRelationCleanupService::class);
		$relations->method('countTimelineLinks')->willReturn(11);

		$service = new DestructionScopeService(
			$this->createMock(AuditTrailMapper::class),
			new NullLogger(),
			new DestructionScopeReader(),
			$notes,
			$files,
			null,
			$relations
		);

		$preview = $service->preview(
			$this->object(),
			$this->schema([DestructionScope::NOTES, DestructionScope::FILES, DestructionScope::TIMELINE])
		);

		self::assertSame(4, $preview['counts'][DestructionScope::NOTES]);
		self::assertSame(2, $preview['counts'][DestructionScope::FILES]);
		self::assertSame(11, $preview['counts'][DestructionScope::TIMELINE]);
		self::assertSame(17, $preview['total']);
		self::assertTrue($preview['destroyable']);
	}//end testWhatHangsOffTheObjectIsCountedBeforeItGoes()

	public function testAnInstanceDeclaringNoScopeDestroysWhatItDestroysToday(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects(self::never())->method('tombstoneForObject');

		$notes = $this->createMock(NoteService::class);
		$notes->expects(self::never())->method('deleteNotesForObject');

		$service = new DestructionScopeService($mapper, new NullLogger(), new DestructionScopeReader(), $notes);

		$report = $service->destroy($this->object(), null);
		self::assertSame([], $report['scope']);
		self::assertSame(0, $report['total']);
		self::assertSame([], $report['failed']);
	}//end testAnInstanceDeclaringNoScopeDestroysWhatItDestroysToday()

	public function testAScopeNobodyCanHonourRefusesBeforeTouchingAnything(): void {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects(self::never())->method('tombstoneForObject');

		$service = new DestructionScopeService($mapper, new NullLogger(), new DestructionScopeReader());

		$this->expectException(DestructionRefusedException::class);
		$service->destroy($this->object(), $this->schema([DestructionScope::VERSIONS, DestructionScope::NOTES]));
	}//end testAScopeNobodyCanHonourRefusesBeforeTouchingAnything()

	public function testAnUnknownMemberIsReportedAndRefuses(): void {
		$service = new DestructionScopeService($this->createMock(AuditTrailMapper::class), new NullLogger(), new DestructionScopeReader());

		$preview = $service->preview($this->object(), $this->schema(['everything', DestructionScope::VERSIONS]));
		self::assertSame(['everything'], $preview['unknown']);
		self::assertFalse($preview['destroyable']);
	}//end testAnUnknownMemberIsReportedAndRefuses()

	public function testTheProofOfDestructionIsExcludedByConstruction(): void {
		// No member of the vocabulary can name the destruction record, so no
		// schema can declare it into a scope.
		self::assertNotContains(DestructionScope::DESTRUCTION_ACTION, DestructionScope::MEMBERS);
		self::assertTrue(DestructionScope::isEvidence(DestructionScope::DESTRUCTION_ACTION));

		// And every audit-touching member excludes it from the same one list.
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('countForObject')->willReturn(7);
		$mapper->expects(self::exactly(2))
			->method('tombstoneForObject')
			->with(
				self::equalTo('zaak-100'),
				self::anything(),
				self::equalTo(DestructionScope::EVIDENCE_ACTIONS)
			)
			->willReturn(7);

		$service = new DestructionScopeService($mapper, new NullLogger(), new DestructionScopeReader());
		$report = $service->destroy(
			$this->object(),
			$this->schema([DestructionScope::VERSIONS, DestructionScope::AUDIT_CONTENT])
		);

		self::assertSame(14, $report['total']);
	}//end testTheProofOfDestructionIsExcludedByConstruction()

	public function testTheActReportsWhatWentAfterwards(): void {
		$notes = $this->createMock(NoteService::class);
		$notes->method('countNotesForObject')->willReturn(4);
		$notes->expects(self::once())->method('deleteNotesForObject')->with('zaak-100');

		$tasks = $this->createMock(TaskService::class);
		$tasks->method('getTasksForObject')->willReturn(
			[
				['calendarId' => 1, 'id' => 'a'],
				['calendarId' => 1, 'id' => 'b'],
			]
		);
		$tasks->expects(self::exactly(2))->method('deleteTask');

		$service = new DestructionScopeService(
			$this->createMock(AuditTrailMapper::class),
			new NullLogger(),
			new DestructionScopeReader(),
			$notes,
			null,
			$tasks
		);

		$report = $service->destroy(
			$this->object(),
			$this->schema([DestructionScope::NOTES, DestructionScope::TASKS])
		);

		self::assertSame(4, $report['destroyed'][DestructionScope::NOTES]);
		self::assertSame(2, $report['destroyed'][DestructionScope::TASKS]);
		self::assertSame(6, $report['total']);
	}//end testTheActReportsWhatWentAfterwards()
}//end class
