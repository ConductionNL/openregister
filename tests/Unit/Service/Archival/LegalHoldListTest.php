<?php

/**
 * Listing the records that carry an active legal hold, across every table a
 * retention decision can live in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCA\OpenRegister\Service\Archival\RetentionRowScanner;
use OCP\BackgroundJob\IJobList;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * LegalHoldService::listActiveHolds().
 */
class LegalHoldListTest extends TestCase {

	/**
	 * A record with a retention array.
	 *
	 * @param string $uuid      Its uuid.
	 * @param array  $retention Its retention.
	 *
	 * @return ObjectEntity
	 */
	private function record(string $uuid, array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('5');
		$object->setSchema('9');
		$object->setName('Zaak '.$uuid);
		$object->setRetention($retention);

		return $object;
	}//end record()

	/**
	 * The service over a scanner that offers the given records to the predicate.
	 *
	 * @param ObjectEntity[] $records   The records the scan walks.
	 * @param bool           $truncated Whether the scan stopped at its cap.
	 *
	 * @return LegalHoldService
	 */
	private function service(array $records, bool $truncated = false): LegalHoldService {
		$scanner = $this->createMock(RetentionRowScanner::class);
		$scanner->method('scan')->willReturnCallback(
			static fn (callable $accept): array => [
				'objects' => array_values(array_filter($records, $accept)),
				'scanned' => count($records),
				'truncated' => $truncated,
			]
		);

		return new LegalHoldService(
			$this->createMock(MagicMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IJobList::class),
			new NullLogger(),
			$scanner
		);
	}//end service()

	/**
	 * Only records with an active hold are listed, each with its active holds.
	 *
	 * @return void
	 */
	public function testListsTheHeldRecordsWithTheirActiveHolds(): void {
		$held = $this->record('z-1', ['legalHold' => ['active' => true, 'holds' => [['id' => 'h1', 'reason' => 'Bezwaar loopt', 'placedBy' => 'anouk', 'placedDate' => '2026-10-06', 'ownerKey' => 'dossiq:case:z-9']]]]);
		$released = $this->record('z-2', ['legalHold' => ['active' => false, 'history' => []]]);
		$none = $this->record('z-3', ['archiefnominatie' => 'vernietigen']);

		$list = $this->service(records: [$held, $released, $none])->listActiveHolds();

		$this->assertSame(1, $list['total']);
		$this->assertFalse($list['truncated']);
		$this->assertSame('z-1', $list['results'][0]['id']);
		$this->assertSame('Zaak z-1', $list['results'][0]['name']);
		$this->assertSame(['register' => '5', 'schema' => '9'], ['register' => $list['results'][0]['register'], 'schema' => $list['results'][0]['schema']]);
		$this->assertSame('Bezwaar loopt', $list['results'][0]['holds'][0]['reason']);
	}//end testListsTheHeldRecordsWithTheirActiveHolds()

	/**
	 * A scan stopped at its cap says so, rather than reading as the whole list.
	 *
	 * @return void
	 */
	public function testSaysWhenTheScanWasCut(): void {
		$list = $this->service(records: [], truncated: true)->listActiveHolds();
		$this->assertTrue($list['truncated']);
	}//end testSaysWhenTheScanWasCut()
}//end class
