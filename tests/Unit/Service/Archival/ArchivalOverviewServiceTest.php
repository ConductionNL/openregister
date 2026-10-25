<?php

/**
 * The archival overview counts, titles and labels what the Archival page shows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/archival-overview-page/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ArchivalOverviewService;
use OCA\OpenRegister\Service\Archival\DestructionListCreator;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ArchivalOverviewServiceTest extends TestCase {

	private DestructionListRepository&MockObject $lists;

	private RetentionService&MockObject $retention;

	private TransferRecordService&MockObject $transfers;

	private SelectielijstImportService&MockObject $selectielijst;

	private ObjectRetentionHandler&MockObject $settings;

	private DestructionListCreator&MockObject $creator;

	private ArchivalOverviewService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->lists = $this->createMock(DestructionListRepository::class);
		$this->retention = $this->createMock(RetentionService::class);
		$this->transfers = $this->createMock(TransferRecordService::class);
		$this->selectielijst = $this->createMock(SelectielijstImportService::class);
		$this->settings = $this->createMock(ObjectRetentionHandler::class);
		$this->creator = $this->createMock(DestructionListCreator::class);

		$pieter = $this->createMock(IUser::class);
		$pieter->method('getDisplayName')->willReturn('Pieter Jansen');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'pieter' ? $pieter : null));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(
			fn (string $one, string $many, int $count): string => str_replace('%n', (string) $count, ($count === 1 ? $one : $many))
		);

		$this->service = new ArchivalOverviewService(
			$this->lists,
			new DestructionReviewService(),
			$this->retention,
			$this->transfers,
			$this->selectielijst,
			$this->settings,
			$this->creator,
			$users,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function list(string $uuid, array $data, ?string $name = null): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid($uuid);
		$list->setName($name);
		$list->setObject($data);
		return $list;
	}

	private function held(string $uuid, string $name, string $reason): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setRetention(['legalHold' => ['active' => true, 'reason' => $reason, 'placedBy' => 'pieter', 'placedDate' => '2026-10-01T10:00:00+00:00', 'holds' => [['id' => 'h1']]]]);
		return $object;
	}

	public function testItCountsListsHoldsTransfersAndSelectionLists(): void {
		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->method('findLists')->willReturn([
			$this->list('l-1', ['status' => 'in_review', 'createdBy' => 'pieter', 'createdAt' => '2026-10-02T09:00:00+00:00', 'objects' => [['uuid' => 'a'], ['uuid' => 'b']], 'excluded' => [['uuid' => 'c']]], 'Vernietigingslijst 2026-Q3 Meldingen'),
			$this->list('l-2', ['status' => 'completed', 'createdBy' => 'lars', 'createdAt' => '2026-07-01T09:00:00+00:00', 'objects' => [['uuid' => 'd']]]),
		]);
		$this->retention->method('scanObjectsWithRetention')->willReturn([
			'objects' => [$this->held('o-1', 'Melding Lindelaan', 'Woo-verzoek 2026-14')],
			'scanned' => 40,
			'truncated' => false,
		]);
		$this->transfers->method('listTransferLists')->willReturn([
			['uuid' => 't-1', 'status' => 'completed', 'objectCount' => 2310, 'packageFormat' => 'bagit', 'createdAt' => '2026-09-30T08:00:00+00:00', 'transferResult' => ['completedAt' => '2026-10-01T08:00:00+00:00']],
			['uuid' => 't-2', 'status' => 'failed', 'objectReferences' => [['uuid' => 'x']], 'createdAt' => '2026-10-05T08:00:00+00:00', 'attempts' => [['attempt' => 1, 'timestamp' => '2026-10-05T09:00:00+00:00']]],
		]);
		$this->selectielijst->method('versions')->willReturn(['2020' => 412, '2017' => 380]);
		$this->settings->method('getArchivalSettingsOnly')->willReturn(['selectielijstVersion' => '2020']);

		$overview = $this->service->overview();

		$this->assertTrue($overview['configured']);
		$this->assertSame(
			['destructionLists' => 2, 'awaitingApproval' => 1, 'legalHolds' => 1, 'transfers' => 2, 'selectionListVersions' => 2],
			$overview['counts']
		);
		$this->assertSame(1, $overview['heldOffLists']);

		$first = $overview['destructionLists'][0];
		$this->assertSame('Vernietigingslijst 2026-Q3 Meldingen', $first['title']);
		$this->assertSame('Pieter Jansen', $first['createdByName']);
		$this->assertSame('Awaiting approval', $first['statusLabel']);
		$this->assertSame('2 records', $first['summary']);

		$second = $overview['destructionLists'][1];
		$this->assertSame('Destruction list of 2026-07-01', $second['title']);
		$this->assertSame('lars', $second['createdByName']);
		$this->assertSame('Destroyed', $second['statusLabel']);

		$this->assertSame('Melding Lindelaan', $overview['legalHolds'][0]['title']);
		$this->assertSame('Woo-verzoek 2026-14', $overview['legalHolds'][0]['reason']);
		$this->assertFalse($overview['legalHoldsTruncated']);

		$this->assertSame('2026-10-01T08:00:00+00:00', $overview['edepot']['lastTransferAt']);
		$this->assertSame('2310 records', $overview['edepot']['lastTransferRecords']);
		$this->assertSame('bagit', $overview['edepot']['packageFormat']);
		$this->assertSame('Failed', $overview['edepot']['lastFailure']);
		$this->assertSame('2026-10-05T09:00:00+00:00', $overview['edepot']['lastFailureAt']);
		$this->assertSame(1, $overview['transfers'][0]['recordCount']);

		$this->assertSame('2020', $overview['selectionList']['version']);
		$this->assertSame('412 categories', $overview['selectionList']['categories']);
		$this->assertSame('Active', $overview['selectionList']['statusLabel']);
	}

	public function testWithoutAConfiguredListRegisterItSaysSoAndStillReadsTheRest(): void {
		$this->lists->method('isConfigured')->willReturn(false);
		$this->lists->expects($this->never())->method('findLists');
		$this->retention->method('scanObjectsWithRetention')->willReturn(['objects' => [], 'scanned' => 0, 'truncated' => true]);
		$this->transfers->method('listTransferLists')->willReturn([]);
		$this->selectielijst->method('versions')->willReturn([]);
		$this->settings->method('getArchivalSettingsOnly')->willReturn([]);

		$overview = $this->service->overview();

		$this->assertFalse($overview['configured']);
		$this->assertSame([], $overview['destructionLists']);
		$this->assertTrue($overview['legalHoldsTruncated']);
		$this->assertNull($overview['edepot']['lastTransferAt']);
		$this->assertNull($overview['selectionList']['version']);
	}

	public function testTheHoldScanOnlyAcceptsRecordsWithAnActiveHold(): void {
		$this->lists->method('isConfigured')->willReturn(false);
		$this->transfers->method('listTransferLists')->willReturn([]);
		$this->selectielijst->method('versions')->willReturn([]);
		$this->settings->method('getArchivalSettingsOnly')->willReturn([]);

		$accept = null;
		$this->retention->method('scanObjectsWithRetention')->willReturnCallback(
			function (callable $predicate) use (&$accept): array {
				$accept = $predicate;
				return ['objects' => [], 'scanned' => 0, 'truncated' => false];
			}
		);

		$this->service->overview();

		$released = new ObjectEntity();
		$released->setRetention(['legalHold' => ['active' => false]]);
		$plain = new ObjectEntity();
		$plain->setRetention(['archiefactiedatum' => '2030-01-01']);

		$this->assertTrue($accept($this->held('o-1', 'x', 'y')));
		$this->assertFalse($accept($released));
		$this->assertFalse($accept($plain));
	}

	public function testDrawingUpAListNamesEveryEligibleRecord(): void {
		$this->retention->method('getObjectsOnPendingDestructionLists')->willReturn(['on-a-list']);
		$this->retention->expects($this->once())->method('findEligibleForDestruction')
			->with(['on-a-list'])
			->willReturn([$this->held('o-1', 'a', 'b'), $this->held('o-2', 'c', 'd')]);
		$this->creator->expects($this->once())->method('createFor')
			->with(['o-1', 'o-2'])
			->willReturn(['list' => ['uuid' => 'l-9', 'objects' => [[], []]], 'refused' => []]);

		$this->assertSame('l-9', $this->service->drawUpList()['list']['uuid']);
	}

	public function testDrawingUpWithNothingEligibleWritesNothing(): void {
		$this->retention->method('getObjectsOnPendingDestructionLists')->willReturn([]);
		$this->retention->method('findEligibleForDestruction')->willReturn([]);
		$this->creator->expects($this->never())->method('createFor');

		$this->assertSame(['list' => null, 'refused' => []], $this->service->drawUpList());
	}
}
