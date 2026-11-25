<?php

/**
 * Tests for the reindex of an administrator's selection: the one selection, the ceiling and the run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Operations;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\BackgroundJob\ConfigurationJobHandlesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobSelection;
use OCA\OpenRegister\Service\BulkJob\BulkSelectionResolver;
use OCA\OpenRegister\Service\Operations\ReindexProgress;
use OCA\OpenRegister\Service\Operations\ReindexRefusedException;
use OCA\OpenRegister\Service\Operations\ReindexSelection;
use OCA\OpenRegister\Service\Operations\ReindexSelectionService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ReindexSelectionService::class)]
#[CoversClass(ReindexSelection::class)]
#[CoversClass(ReindexProgress::class)]
#[CoversClass(ReindexRefusedException::class)]
#[UsesClass(BulkJobSelection::class)]
#[UsesClass(ObjectEntity::class)]
final class ReindexSelectionTest extends TestCase {

	private BulkSelectionResolver&MockObject $resolver;

	private ObjectOperationsInterface&MockObject $objects;

	private TextExtractionServiceInterface&MockObject $extractor;

	private FileMapperInterface&MockObject $files;

	private IAppConfig&MockObject $config;

	private IJobList&MockObject $jobs;

	private IUserManager&MockObject $users;

	protected function setUp(): void {
		$this->resolver = $this->createMock(BulkSelectionResolver::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->extractor = $this->createMock(TextExtractionServiceInterface::class);
		$this->files = $this->createMock(FileMapperInterface::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->users = $this->createMock(IUserManager::class);
	}

	private function service(): ReindexSelectionService {
		return new ReindexSelectionService(
			$this->resolver,
			$this->objects,
			$this->extractor,
			$this->files,
			$this->config,
			$this->jobs,
			$this->users
		);
	}

	/**
	 * @return list<string>
	 */
	private function uuids(int $count): array {
		return array_map(static fn (int $i): string => sprintf('uuid-%05d', $i), range(1, $count));
	}

	private function refusalOf(array $params): ?string {
		try {
			ReindexSelection::fromRequest(params: $params);
		} catch (ReindexRefusedException $refusal) {
			return $refusal->getMessage();
		}

		return null;
	}

	public function testExactlyOneSelectionIsAccepted(): void {
		// The three selections are each accepted, as their own kind.
		$this->assertSame(ReindexSelection::KIND_SCHEMA, ReindexSelection::fromRequest(params: ['register' => '3', 'schema' => 7])->kind);
		$filtered = ReindexSelection::fromRequest(params: ['register' => 3, 'schema' => 7, 'filters' => ['wooVerzoek' => '2026-118']]);
		$this->assertSame(ReindexSelection::KIND_FILTER, $filtered->kind);
		$this->assertSame(['wooVerzoek' => '2026-118'], $filtered->filters);
		$ids = ReindexSelection::fromRequest(params: ['ids' => ['a', 'b', 'a', ' c ']]);
		$this->assertSame(ReindexSelection::KIND_IDS, $ids->kind);
		$this->assertSame(['a', 'b', 'c'], $ids->ids);

		// Two selections, or none, or half of one, are refused.
		$this->assertStringContainsString('not both', (string)$this->refusalOf(params: ['ids' => ['a'], 'register' => 3, 'schema' => 7]));
		$this->assertStringContainsString('not both', (string)$this->refusalOf(params: ['ids' => ['a'], 'filters' => ['x' => 1]]));
		$this->assertNotNull($this->refusalOf(params: []));
		$this->assertNotNull($this->refusalOf(params: ['register' => 3]));
		$this->assertNotNull($this->refusalOf(params: ['schema' => 7]));
		$this->assertNotNull($this->refusalOf(params: ['register' => 'three', 'schema' => 7]));
		$this->assertNotNull($this->refusalOf(params: ['ids' => []]));
		$this->assertNotNull($this->refusalOf(params: ['ids' => 'a']));
		$this->assertNotNull($this->refusalOf(params: ['ids' => ['a', '']]));
		$this->assertNotNull($this->refusalOf(params: ['register' => 3, 'schema' => 7, 'filters' => 'x']));

		// The service queues nothing for a refused selection.
		$this->jobs->expects($this->never())->method('add');
		$this->config->expects($this->never())->method('setValueArray');
		$this->expectException(ReindexRefusedException::class);
		$this->service()->start(params: ['ids' => ['a'], 'register' => 3, 'schema' => 7], actor: 'admin');
	}

	public function testMoreThanFiftyThousandIsRefused(): void {
		$this->resolver->expects($this->once())->method('queryUuids')
			->with($this->callback(static fn (BulkJobSelection $s): bool => $s->registerId === 3 && $s->schemaId === 7), ReindexSelectionService::MAX_OBJECTS)
			->willReturn($this->uuids(count: 50001));
		$this->jobs->expects($this->never())->method('add');
		$this->config->expects($this->never())->method('setValueArray');

		try {
			$this->service()->start(params: ['register' => 3, 'schema' => 7], actor: 'admin');
			$this->fail('A selection of more than 50,000 objects must be refused.');
		} catch (ReindexRefusedException $refusal) {
			$this->assertStringContainsString('Rebuild the whole index', $refusal->getMessage());
			$this->assertStringContainsString('50,000', $refusal->getMessage());
		}
	}

	public function testExactlyFiftyThousandIsQueued(): void {
		$this->resolver->method('queryUuids')->willReturn($this->uuids(count: 50000));
		$this->jobs->expects($this->once())->method('add');

		$queued = $this->service()->start(params: ['register' => 3, 'schema' => 7, 'filters' => ['status' => 'open']], actor: 'admin');

		$this->assertSame(50000, $queued['total']);
		$this->assertSame(ReindexSelection::KIND_FILTER, $queued['kind']);
	}

	public function testAnEmptySelectionIsRefused(): void {
		$this->resolver->method('queryUuids')->willReturn([]);
		$this->jobs->expects($this->never())->method('add');

		$this->expectException(ReindexRefusedException::class);
		$this->expectExceptionMessage('no objects');
		$this->service()->start(params: ['register' => 3, 'schema' => 7], actor: 'admin');
	}

	public function testStartStoresTheSelectionAndQueuesTheRunWithItsActor(): void {
		$this->resolver->method('idsOf')->with(['ids' => ['a', 'b']])->willReturn(['a', 'b']);
		$stored = null;
		$this->config->expects($this->once())->method('setValueArray')
			->willReturnCallback(function (string $app, string $key, array $value, bool $lazy) use (&$stored): bool {
				$this->assertSame('openregister', $app);
				$this->assertStringStartsWith(ReindexSelectionService::STORE_PREFIX, $key);
				$this->assertTrue($lazy);
				$stored = ['key' => $key, 'value' => $value];
				return true;
			});
		$queuedArgument = null;
		$this->jobs->expects($this->once())->method('add')
			->willReturnCallback(function (string $job, mixed $argument) use (&$queuedArgument): void {
				$this->assertSame(ConfigurationJobHandlesInterface::REINDEX_SELECTION, $job);
				$queuedArgument = $argument;
			});

		$queued = $this->service()->start(params: ['ids' => ['a', 'b']], actor: 'admin');

		$this->assertSame(['run' => $queued['run'], 'actor' => 'admin'], $queuedArgument);
		$this->assertSame(ReindexSelectionService::STORE_PREFIX.$queued['run'], $stored['key']);
		$this->assertSame(['kind' => 'ids', 'register' => null, 'schema' => null, 'filters' => [], 'ids' => ['a', 'b']], $stored['value']);
		$this->assertSame(2, $queued['total']);
	}

	public function testTheRunReportsProgressAndFailures(): void {
		$uuids = $this->uuids(count: 250);
		$this->config->method('getValueArray')->willReturn(['kind' => 'ids', 'ids' => $uuids]);
		$this->config->expects($this->once())->method('deleteKey')->with('openregister', ReindexSelectionService::STORE_PREFIX.'run1');
		$admin = $this->createMock(IUser::class);
		$this->users->method('get')->with('admin')->willReturn($admin);
		$this->objects->expects($this->once())->method('runAs')
			->willReturnCallback(function (IUser $user, callable $operation) use ($admin): mixed {
				$this->assertSame($admin, $user);
				return $operation();
			});
		$this->resolver->method('idsOf')->willReturn($uuids);
		// uuid-00042 is no longer readable; the rest come back.
		$this->resolver->method('hydrate')->willReturnCallback(static function (array $batch): array {
			$found = [];
			foreach ($batch as $uuid) {
				if ($uuid === 'uuid-00042') {
					continue;
				}

				$object = new ObjectEntity();
				$object->setUuid($uuid);
				$object->setId((int)substr($uuid, 5));
				$found[$uuid] = $object;
			}

			return $found;
		});
		// Object 7 fails to extract; object 1 has two files, re-extracted with force.
		$this->extractor->method('extractObject')->willReturnCallback(function (int $objectId, bool $forceReExtract): void {
			$this->assertTrue($forceReExtract);
			if ($objectId === 7) {
				throw new RuntimeException('the reader gave up');
			}
		});
		$this->files->method('getFileIdsForObjects')->willReturnCallback(
			static fn (array $uuids): array => [$uuids[0] => ($uuids[0] === 'uuid-00001' ? [11, 12] : [])]
		);
		$reextracted = [];
		$this->extractor->method('reextractFile')->willReturnCallback(function (int $fileId) use (&$reextracted): void {
			$reextracted[] = $fileId;
		});

		$reports = [];
		$progress = $this->service()->run(runKey: 'run1', actor: 'admin', onProgress: function (array $details) use (&$reports): void {
			$reports[] = $details;
		});

		$this->assertSame([0, 100, 200, 250], array_column($reports, 'processed'));
		$this->assertSame([250, 250, 250, 250], array_column($reports, 'total'));
		$final = $progress->toArray();
		$this->assertSame($final, end($reports));
		$this->assertSame(250, $final['processed']);
		$this->assertSame(2, $final['failed']);
		$this->assertSame(
			[
				['id' => 'uuid-00007', 'reason' => 'the reader gave up'],
				['id' => 'uuid-00042', 'reason' => 'The object was not found, or is no longer readable.'],
			],
			$final['failures']
		);
		$this->assertFalse($final['failuresTruncated']);
		$this->assertSame([11, 12], $reextracted);
	}

	public function testARunWhoseSelectionIsGoneFailsAndClearsItsKey(): void {
		$this->config->method('getValueArray')->willReturn([]);
		$this->config->expects($this->once())->method('deleteKey');
		$this->objects->expects($this->never())->method('runAs');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no longer stored');
		$this->service()->run(runKey: 'gone', actor: 'admin', onProgress: static function (): void {
		});
	}

	public function testARunWhoseAdministratorIsGoneFails(): void {
		$this->config->method('getValueArray')->willReturn(['kind' => 'ids', 'ids' => ['a']]);
		$this->users->method('get')->willReturn(null);
		$this->config->expects($this->once())->method('deleteKey');
		$this->objects->expects($this->never())->method('runAs');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no longer exists');
		$this->service()->run(runKey: 'r', actor: 'gone', onProgress: static function (): void {
		});
	}

	public function testAStoredSelectionReadsBackAsTheSameSelection(): void {
		$selection = new ReindexSelection(kind: ReindexSelection::KIND_FILTER, registerId: 3, schemaId: 7, filters: ['a' => 'b']);
		$this->assertEquals($selection, ReindexSelection::fromArray(stored: $selection->toArray()));

		$bulk = $selection->bulkSelection();
		$this->assertSame(['query' => ['a' => 'b']], $bulk->criteria);
		$this->assertSame(3, $bulk->registerId);
		$ids = (new ReindexSelection(kind: ReindexSelection::KIND_IDS, ids: ['x']))->bulkSelection();
		$this->assertSame(['ids' => ['x']], $ids->criteria);
		$this->assertNull($ids->schemaId);

		$this->expectException(ReindexRefusedException::class);
		ReindexSelection::fromArray(stored: ['kind' => 'everything']);
	}

	public function testFailuresBeyondTheListedLimitAreCountedButNotListed(): void {
		$progress = new ReindexProgress(total: 600);
		for ($i = 0; $i < 600; $i++) {
			$progress->failedOn(id: 'id-'.$i, reason: 'no');
		}

		$details = $progress->toArray();
		$this->assertSame(600, $details['failed']);
		$this->assertSame(600, $details['processed']);
		$this->assertCount(ReindexProgress::MAX_LISTED_FAILURES, $details['failures']);
		$this->assertTrue($details['failuresTruncated']);
	}
}
