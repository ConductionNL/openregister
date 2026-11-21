<?php

/**
 * RetentionService::createDestructionList() stores the link conflicts the
 * finder reports: per entry `linkConflicts` and `linkConflictsTruncated`, and
 * `linkConflictCount` on the list, asked for once for the whole list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use OCA\OpenRegister\Service\Archival\DestructionListLinkConflicts;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\RetentionRules;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionStores;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\RetentionService
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Service\RetentionRules
 * @uses   \OCA\OpenRegister\Service\RetentionStores
 */
class RetentionServiceLinkConflictsTest extends TestCase {

	/**
	 * The service with a configured destruction list and the given conflict source.
	 *
	 * @param DestructionListLinkConflicts|null $linkConflicts The conflict source.
	 *
	 * @return RetentionService
	 */
	private function service(?DestructionListLinkConflicts $linkConflicts): RetentionService {
		$settings = $this->createMock(ArchivalSettingsReaderInterface::class);
		$settings->method('getArchivalSettingsOnly')->willReturn(['destructionListRegister' => 3, 'destructionListSchema' => 7]);

		return new RetentionService(
			new RetentionStores(
				new SchemaCatalog(
					registers: $this->createMock(RegisterMapperInterface::class),
					schemas: $this->createMock(SchemaMapperInterface::class),
					tables: $this->createMock(MagicMapperInterface::class)
				),
				$this->createMock(AuditTrailMapperInterface::class)
			),
			new RetentionRules(
				$settings,
				$this->createMock(RetentionRowScanner::class),
				$this->createMock(ArchiveActionDateCalculator::class),
				$this->createMock(SelectielijstResolver::class)
			),
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			null,
			$linkConflicts
		);
	}//end service()

	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject([]);
		return $object;
	}//end object()

	public function testEachEntryCarriesItsConflictsAndTheListTheirCount(): void {
		$case  = $this->object('case-1');
		$other = $this->object('case-2');
		$kept  = ['kind' => 'linked-kept-permanently', 'direction' => 'incoming', 'uuid' => 'besluit-1'];
		$hold  = ['kind' => 'linked-on-hold', 'direction' => 'outgoing', 'uuid' => 'doc-1'];

		$conflicts = $this->createMock(DestructionListLinkConflicts::class);
		$conflicts->expects($this->once())->method('forEntries')->with([$case, $other])->willReturn(
			[
				'case-1' => ['conflicts' => [$kept, $hold], 'truncated' => true],
				'case-2' => ['conflicts' => [], 'truncated' => false],
			]
		);

		$list = $this->service(linkConflicts: $conflicts)->createDestructionList([$case, $other]);

		$this->assertSame([$kept, $hold], $list['objects'][0]['linkConflicts']);
		$this->assertTrue($list['objects'][0]['linkConflictsTruncated']);
		$this->assertSame([], $list['objects'][1]['linkConflicts']);
		$this->assertFalse($list['objects'][1]['linkConflictsTruncated']);
		$this->assertSame(2, $list['linkConflictCount']);
	}

	public function testAListWithNoConflictsCarriesEmptyArraysAndACountOfZero(): void {
		$conflicts = $this->createMock(DestructionListLinkConflicts::class);
		$conflicts->method('forEntries')->willReturn([]);

		$list = $this->service(linkConflicts: $conflicts)->createDestructionList([$this->object('a'), $this->object('b')]);

		foreach ($list['objects'] as $entry) {
			$this->assertSame([], $entry['linkConflicts']);
			$this->assertFalse($entry['linkConflictsTruncated']);
		}

		$this->assertSame(0, $list['linkConflictCount']);
	}

	public function testWithoutAConflictSourceTheShapeIsTheSame(): void {
		$list = $this->service(linkConflicts: null)->createDestructionList([$this->object('a')]);

		$this->assertSame([], $list['objects'][0]['linkConflicts']);
		$this->assertFalse($list['objects'][0]['linkConflictsTruncated']);
		$this->assertSame(0, $list['linkConflictCount']);
	}
}
