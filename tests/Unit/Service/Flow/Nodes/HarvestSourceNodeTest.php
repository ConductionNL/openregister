<?php

/**
 * HarvestSourceNode: refusals, the per-source lock and the run summary.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\Nodes\HarvestSourceNode;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\HarvestRun;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\HarvestSourceNode
 * @uses   \OCA\OpenRegister\Db\Source
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeCall
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStop
 * @uses   \OCA\OpenRegister\Service\Sync\HarvestRun
 */
class HarvestSourceNodeTest extends TestCase {

	/** @var SourceMapperInterface&MockObject */
	private SourceMapperInterface $sources;

	/** @var SourceFetcherLookupInterface&MockObject */
	private SourceFetcherLookupInterface $fetchers;

	/** @var HarvestPipelineServiceInterface&MockObject */
	private HarvestPipelineServiceInterface $pipeline;

	/** @var ILockingProvider&MockObject */
	private ILockingProvider $locks;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private HarvestSourceNode $node;

	protected function setUp(): void {
		$this->sources  = $this->createMock(SourceMapperInterface::class);
		$this->fetchers = $this->createMock(SourceFetcherLookupInterface::class);
		$this->pipeline = $this->createMock(HarvestPipelineServiceInterface::class);
		$this->locks    = $this->createMock(ILockingProvider::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new HarvestSourceNode(
			sources: $this->sources,
			fetchers: $this->fetchers,
			pipeline: $this->pipeline,
			locks: $this->locks,
			l10n: $l10n,
			urls: $this->createMock(IURLGenerator::class),
			logger: $this->logger
		);
	}

	private function source(bool $enabled=true, ?int $mappingId=4): Source {
		$source = new Source();
		$source->setId(9);
		$source->setUuid('src-uuid');
		$source->setType('opencatalogi.dcat');
		$source->setSyncEnabled($enabled);
		$source->setMappingId($mappingId);
		$source->setLastSyncToken('cp-1');
		return $source;
	}

	private function call(array $items=[['trigger' => 'manual']]): FlowNodeCall {
		return new FlowNodeCall(items: $items, config: ['sourceId' => 'src-uuid'], context: [FlowRunContext::CONTEXT_RUN => 'run-1']);
	}

	private function refusalOf(callable $act): FlowStop {
		try {
			$act();
		} catch (FlowStop $stop) {
			return $stop;
		}

		$this->fail('Expected a FlowStop');
	}

	public function testItIsTheHarvestSourceType(): void {
		$this->assertSame('openregister.harvest-source', $this->node->getId());
		$this->assertSame(['sourceId'], $this->node->configKeys());
		$this->assertSame('openregister/harvest/abc', HarvestSourceNode::lockKey('abc'));
	}

	public function testAConfigWithoutASourceIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['sourceId' => '  ']);
	}

	public function testNoItemsRunsNothing(): void {
		$this->sources->expects($this->never())->method('findForSystem');
		$this->assertSame([], $this->node->execute($this->call(items: [])));
	}

	public function testARunHarvestsUnderTheLockAndEmitsTheSummary(): void {
		$source  = $this->source();
		$fetcher = $this->createMock(SourceFetcherInterface::class);
		$this->sources->method('findForSystem')->with('src-uuid')->willReturn($source);
		$this->fetchers->method('get')->with('opencatalogi.dcat')->willReturn($fetcher);

		$order = [];
		$this->locks->expects($this->once())->method('acquireLock')
			->with('openregister/harvest/src-uuid', ILockingProvider::LOCK_EXCLUSIVE)
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'lock';
			});
		$this->pipeline->expects($this->once())->method('run')
			->with($this->callback(static fn (HarvestRun $run): bool => $run->source === $source && $run->fetcher === $fetcher
				&& $run->executionId === 'run-1' && $run->since === 'cp-1'))
			->willReturnCallback(static function () use (&$order): array {
				$order[] = 'run';
				return ['status' => 'partial', 'created' => 2];
			});
		$this->locks->expects($this->once())->method('releaseLock')
			->with('openregister/harvest/src-uuid', ILockingProvider::LOCK_EXCLUSIVE)
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'unlock';
			});
		$this->sources->expects($this->once())->method('update')->with($source)->willReturn($source);

		$this->assertSame([['status' => 'partial', 'created' => 2]], $this->node->execute($this->call()));
		$this->assertSame(['lock', 'run', 'unlock'], $order);
		$this->assertSame('partial', $source->getLastSyncStatus());
		$this->assertNotNull($source->getLastSyncDate());
	}

	public function testADisabledSourceFailsTheRun(): void {
		$this->sources->method('findForSystem')->willReturn($this->source(enabled: false));
		$this->locks->expects($this->never())->method('acquireLock');

		$stop = $this->refusalOf(fn () => $this->node->execute($this->call()));
		$this->assertSame('source-disabled', $stop->getMessage());
		$this->assertTrue($stop->isError());
	}

	public function testAMissingSourceFailsAsDisabled(): void {
		$this->sources->method('findForSystem')->willReturn(null);

		$this->assertSame('source-disabled', $this->refusalOf(fn () => $this->node->execute($this->call()))->getMessage());
	}

	public function testATypeWithoutAFetcherFailsTheRun(): void {
		$this->sources->method('findForSystem')->willReturn($this->source());
		$this->fetchers->method('get')->willReturn(null);

		$stop = $this->refusalOf(fn () => $this->node->execute($this->call()));
		$this->assertSame('fetcher-missing', $stop->getMessage());
		$this->assertTrue($stop->isError());
	}

	public function testASourceWithoutAMappingFailsTheRun(): void {
		$this->sources->method('findForSystem')->willReturn($this->source(mappingId: null));
		$this->fetchers->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));

		$this->assertSame('mapping-missing', $this->refusalOf(fn () => $this->node->execute($this->call()))->getMessage());
	}

	public function testAHeldLockSkipsTheRun(): void {
		$this->sources->method('findForSystem')->willReturn($this->source());
		$this->fetchers->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$this->locks->method('acquireLock')->willThrowException(new LockedException('openregister/harvest/src-uuid'));
		$this->pipeline->expects($this->never())->method('run');
		$this->locks->expects($this->never())->method('releaseLock');

		$stop = $this->refusalOf(fn () => $this->node->execute($this->call()));
		$this->assertSame('already-running', $stop->getMessage());
		$this->assertFalse($stop->isError());
	}

	public function testAFailedPipelineRecordsFailedAndReleasesTheLock(): void {
		$source = $this->source();
		$this->sources->method('findForSystem')->willReturn($source);
		$this->fetchers->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$this->pipeline->method('run')->willThrowException(new RuntimeException('upstream down'));
		$this->locks->expects($this->once())->method('releaseLock');
		$this->sources->expects($this->once())->method('update');

		try {
			$this->node->execute($this->call());
			$this->fail('Expected the pipeline failure');
		} catch (RuntimeException $e) {
			$this->assertSame('upstream down', $e->getMessage());
		}

		$this->assertSame('failed', $source->getLastSyncStatus());
	}

	public function testAFailedLastSyncWriteIsLoggedNotThrown(): void {
		$this->sources->method('findForSystem')->willReturn($this->source());
		$this->fetchers->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$this->pipeline->method('run')->willReturn(['status' => 'success']);
		$this->sources->method('update')->willThrowException(new RuntimeException('no right'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('no right'));

		$this->assertSame([['status' => 'success']], $this->node->execute($this->call()));
	}
}
