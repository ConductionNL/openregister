<?php

/**
 * Characterisation of FlowVersionService::publish().
 *
 * Pins what a publish does before its constructor was bundled and its body
 * split: the previous version deprecated, the head row published with the
 * actor, the hash and the derived semantic version, the flow's head mirrored,
 * the trigger rows reindexed inside one committed transaction; and the
 * refusals: an unstorable definition refused before any transaction, a
 * failing write rolled back and rethrown, a "minor" bump over a removal refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowPublishOptions;
use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowVersionGraphs;
use OCA\OpenRegister\Service\Flow\FlowVersionRows;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

final class FlowVersionPublishGoldenTest extends TestCase {

	/** @var array<int, FlowVersion> Version rows by number, as stored. */
	private array $rows = [];

	/** @var array<int, string> The transaction calls, in order. */
	private array $tx = [];

	/** @var array<int, string> The trigger reindexes, by flow uuid. */
	private array $reindexed = [];

	/** @var FlowDefinitionMapper&MockObject */
	private FlowDefinitionMapper $definitions;

	/** @var FlowMapper&MockObject */
	private FlowMapper $flows;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private function twoSteps(): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'fetch', 'type' => 'openregister.set-fields', 'config' => ['set' => []]],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'done'],
				['id' => 'e2', 'from' => 'start', 'to' => 'fetch'],
				['id' => 'e3', 'from' => 'fetch', 'to' => 'done'],
			],
		];
	}

	private function oneStep(): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [['id' => 'e1', 'from' => 'start', 'to' => 'done']],
		];
	}

	protected function setUp(): void {
		$this->definitions = $this->createMock(FlowDefinitionMapper::class);
		$this->flows = $this->createMock(FlowMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * Version 1 published with $old (its graph stored under its hash), version 2 the draft head holding $head.
	 */
	private function flow(array $old, array $head): Flow {
		$pin = new FlowDefinitionPin($this->createMock(FlowDefinitionMapper::class), $this->createMock(LoggerInterface::class));
		$canonical = $pin->canonicalise(flow: ['nodes' => $old['nodes'], 'edges' => $old['edges'], 'limits' => [], 'executionMode' => Flow::MODE_ASYNC]);
		$stored = new FlowDefinition();
		$stored->setDefinition($canonical['json']);
		$this->definitions->method('findByHash')->willReturn($stored);
		$this->definitions->method('store')->willReturn(new FlowDefinition());

		$v1 = new FlowVersion();
		$v1->setId(1);
		$v1->setFlowUuid('flow-1');
		$v1->setVersion(1);
		$v1->setStatus(FlowVersion::STATUS_PUBLISHED);
		$v1->setSemver('1.0.0');
		$v1->setDefinitionHash($canonical['hash']);
		$this->rows = [1 => $v1];

		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes($head['nodes']);
		$flow->setEdges($head['edges']);
		$flow->setVersion(2);
		$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);

		return $flow;
	}

	private function service(): FlowVersionService {
		$versions = $this->createMock(FlowVersionMapper::class);
		$versions->method('findPublished')->willReturnCallback(
			function (): ?FlowVersion {
				foreach ($this->rows as $row) {
					if ($row->getStatus() === FlowVersion::STATUS_PUBLISHED) {
						return $row;
					}
				}

				return null;
			}
		);
		$versions->method('find')->willReturnCallback(fn (string $uuid, int $version): ?FlowVersion => ($this->rows[$version] ?? null));
		$store = function (FlowVersion $v): FlowVersion {
			$this->rows[(int)$v->getVersion()] = $v;
			return $v;
		};
		$versions->method('insert')->willReturnCallback($store);
		$versions->method('update')->willReturnCallback($store);

		$triggers = $this->createMock(FlowTriggerIndex::class);
		$triggers->method('reindex')->willReturnCallback(
			function (Flow $flow): int {
				$this->reindexed[] = (string)$flow->getUuid();
				return 1;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void { $this->tx[] = 'begin'; });
		$db->method('commit')->willReturnCallback(function (): void { $this->tx[] = 'commit'; });
		$db->method('rollBack')->willReturnCallback(function (): void { $this->tx[] = 'rollBack'; });

		return new FlowVersionService(
			new FlowVersionRows($versions, $this->flows, $db, $this->logger),
			new FlowVersionGraphs(new FlowDefinitionPin($this->definitions, $this->createMock(LoggerInterface::class)), $this->createMock(FlowLifecycleGuard::class), $triggers, new FlowSemanticVersion())
		);
	}

	public function testAPublishDeprecatesThePreviousAndMirrorsTheNewVersionOntoTheHead(): void {
		$flow = $this->flow(old: $this->oneStep(), head: $this->twoSteps());
		$this->flows->expects($this->once())->method('update')->with($flow)->willReturnArgument(0);

		$version = $this->service()->publish($flow, new FlowPublishOptions(publishedBy: 'alice'));

		$this->assertSame(['begin', 'commit'], $this->tx);
		$this->assertSame(FlowVersion::STATUS_DEPRECATED, $this->rows[1]->getStatus());
		$this->assertNotNull($this->rows[1]->getDeprecatedAt());
		$this->assertSame(2, (int)$version->getVersion());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $version->getStatus());
		$this->assertSame('alice', $version->getPublishedBy());
		$this->assertNotNull($version->getPublishedAt());
		$this->assertSame('1.1.0', $version->getSemver(), 'An added step is a minor change.');
		$this->assertSame(FlowSemanticVersion::SOURCE_DERIVED, $version->getSemverSource());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $flow->getLifecycleStatus());
		$this->assertSame(2, (int)$flow->getVersion());
		$this->assertSame('1.1.0', $flow->getSemver());
		$this->assertSame(FlowSemanticVersion::SOURCE_DERIVED, $flow->getSemverSource());
		$this->assertSame(['flow-1'], $this->reindexed);
	}

	public function testARemovalIsAMajorVersion(): void {
		$flow = $this->flow(old: $this->twoSteps(), head: $this->oneStep());
		$this->flows->method('update')->willReturnArgument(0);

		$this->assertSame('2.0.0', $this->service()->publish($flow, new FlowPublishOptions(publishedBy: null))->getSemver());
	}

	public function testAMinorBumpOverARemovalIsRefusedAndRolledBack(): void {
		$flow = $this->flow(old: $this->twoSteps(), head: $this->oneStep());
		$this->logger->expects($this->once())->method('error');

		try {
			$this->service()->publish($flow, new FlowPublishOptions(publishedBy: 'alice', bump: 'minor'));
			$this->fail('A minor bump over a removal must be refused.');
		} catch (UnexpectedValueException $refused) {
			$this->assertSame(['begin', 'rollBack'], $this->tx);
		}

		$this->assertSame(FlowVersion::STATUS_DRAFT, $flow->getLifecycleStatus());
		$this->assertSame([], $this->reindexed);
	}

	public function testAFailingWriteIsRolledBackLoggedAndRethrown(): void {
		$flow = $this->flow(old: $this->oneStep(), head: $this->twoSteps());
		$this->flows->method('update')->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('Publish failed for flow "flow-1": disk full'));

		try {
			$this->service()->publish($flow, new FlowPublishOptions(publishedBy: 'alice'));
			$this->fail('The write failure must be rethrown.');
		} catch (RuntimeException $failure) {
			$this->assertSame('disk full', $failure->getMessage());
		}

		$this->assertSame(['begin', 'rollBack'], $this->tx);
		$this->assertSame([], $this->reindexed);
	}

	public function testAnUnstorableDefinitionIsRefusedBeforeAnyTransaction(): void {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes($this->oneStep()['nodes']);
		$flow->setEdges($this->oneStep()['edges']);
		$flow->setLifecycleStatus(FlowVersion::STATUS_DRAFT);
		$this->definitions->method('store')->willThrowException(new RuntimeException('no table'));

		try {
			$this->service()->publish($flow, new FlowPublishOptions(publishedBy: 'alice'));
			$this->fail('An unstorable definition must be refused.');
		} catch (FlowLifecycleRefused $refused) {
			$this->assertSame(FlowLifecycleRefused::REASON_IMMUTABLE, $refused->getReason());
		}

		$this->assertSame([], $this->tx);
	}
}
