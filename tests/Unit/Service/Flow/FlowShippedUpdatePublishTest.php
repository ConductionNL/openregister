<?php

/**
 * An app upgrade that changes a shipped flow publishes it as the next version.
 *
 * Runs walk the PUBLISHED version. A re-import used to rewrite the flow's head
 * and stop, so the fix an upgrade shipped never ran (Rotterdam stack: version
 * 1 had 32 nodes, the head 147, every run walked the 32). These tests drive the
 * real FlowVersionService with the real FlowDefinitionPin hashing and the real
 * FlowSemanticVersion; only storage is doubled.
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
 * @spec openspec/changes/shipped-flow-update-is-published/specs/flow-definition-versioning/spec.md#requirement-a-changed-shipped-flow-is-published-as-the-next-version
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowDefinition;
use OCA\OpenRegister\Db\FlowDefinitionMapper;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionPin;
use OCA\OpenRegister\Service\Flow\FlowLifecycleGuard;
use OCA\OpenRegister\Service\Flow\FlowSemanticVersion;
use OCA\OpenRegister\Service\Flow\FlowTriggerIndex;
use OCA\OpenRegister\Service\Flow\FlowVersionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FlowShippedUpdatePublishTest extends TestCase {

	/** @var array<int, FlowVersion> Version rows by number, as stored. */
	private array $rows = [];

	private FlowDefinitionPin $pin;

	private function oldGraph(): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [['id' => 'e1', 'from' => 'start', 'to' => 'done']],
		];
	}

	private function newGraph(): array {
		return [
			'nodes' => [
				['id' => 'start', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'fetch', 'type' => 'openregister.set-fields', 'config' => ['set' => []]],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'start', 'to' => 'fetch'],
				['id' => 'e2', 'from' => 'fetch', 'to' => 'done'],
			],
		];
	}

	/**
	 * A flow whose version 1 was published with $old, and whose head now holds $head.
	 */
	private function flow(array $old, array $head, ?string $publishedBy = null, string $lifecycle = FlowVersion::STATUS_PUBLISHED): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes($head['nodes']);
		$flow->setEdges($head['edges']);
		$flow->setVersion(1);
		$flow->setLifecycleStatus($lifecycle);

		$v1 = new FlowVersion();
		$v1->setFlowUuid('flow-1');
		$v1->setVersion(1);
		$v1->setStatus(FlowVersion::STATUS_PUBLISHED);
		$v1->setPublishedBy($publishedBy);
		$v1->setSemver('1.0.0');
		$v1->setDefinitionHash($this->hashOf($old));
		$this->rows = [1 => $v1];

		return $flow;
	}

	private function hashOf(array $graph): string {
		$template = new Flow();
		$template->setNodes($graph['nodes']);
		$template->setEdges($graph['edges']);
		$service = $this->service();

		return $this->pin->canonicalise(flow: $service->graphOf(flow: $template))['hash'];
	}

	private function service(): FlowVersionService {
		$definitions = $this->createMock(FlowDefinitionMapper::class);
		$definitions->method('store')->willReturn(new FlowDefinition());
		$this->pin = new FlowDefinitionPin($definitions, $this->createMock(LoggerInterface::class));

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
		$versions->method('highestVersion')->willReturnCallback(fn (): int => max(array_merge([0], array_keys($this->rows))));
		$versions->method('insert')->willReturnCallback(
			function (FlowVersion $v): FlowVersion {
				$this->rows[(int)$v->getVersion()] = $v;
				return $v;
			}
		);
		$versions->method('update')->willReturnCallback(
			function (FlowVersion $v): FlowVersion {
				$this->rows[(int)$v->getVersion()] = $v;
				return $v;
			}
		);

		$flows = $this->createMock(FlowMapper::class);
		$flows->method('update')->willReturnArgument(0);

		return new FlowVersionService(
			$versions,
			$flows,
			$this->pin,
			$this->createMock(FlowLifecycleGuard::class),
			$this->createMock(FlowTriggerIndex::class),
			new FlowSemanticVersion(),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * The measured defect: an import-published flow whose shipped graph changed
	 * gets version 2 published with the new graph; version 1 is deprecated.
	 */
	public function testAChangedShippedGraphIsPublishedAsTheNextVersion(): void {
		$flow = $this->flow(old: $this->oldGraph(), head: $this->newGraph());
		$service = $this->service();

		$version = $service->publishShippedUpdate(flow: $flow);

		$this->assertNotNull($version);
		$this->assertSame(2, (int)$version->getVersion());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $version->getStatus());
		$this->assertSame($this->hashOf($this->newGraph()), $version->getDefinitionHash());
		$this->assertNull($version->getPublishedBy(), 'an import publishes as nobody, so the next upgrade may publish again');
		$this->assertSame(FlowVersion::STATUS_DEPRECATED, $this->rows[1]->getStatus());
		$this->assertSame(2, (int)$flow->getVersion());
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $flow->getLifecycleStatus());
	}

	/**
	 * A version a person published is never replaced by an upgrade.
	 */
	public function testAVersionAPersonPublishedIsLeftAlone(): void {
		$flow = $this->flow(old: $this->oldGraph(), head: $this->newGraph(), publishedBy: 'admin');

		$this->assertNull($this->service()->publishShippedUpdate(flow: $flow));
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $this->rows[1]->getStatus());
		$this->assertCount(1, $this->rows);
	}

	/**
	 * An open draft is not published under its author.
	 */
	public function testAnOpenDraftIsLeftAlone(): void {
		$flow = $this->flow(old: $this->oldGraph(), head: $this->newGraph(), lifecycle: FlowVersion::STATUS_DRAFT);

		$this->assertNull($this->service()->publishShippedUpdate(flow: $flow));
		$this->assertCount(1, $this->rows);
	}

	/**
	 * A re-import that changes nothing publishes nothing.
	 */
	public function testAnUnchangedGraphPublishesNothing(): void {
		$flow = $this->flow(old: $this->oldGraph(), head: $this->oldGraph());

		$this->assertNull($this->service()->publishShippedUpdate(flow: $flow));
		$this->assertCount(1, $this->rows);
		$this->assertSame(FlowVersion::STATUS_PUBLISHED, $this->rows[1]->getStatus());
	}

	/**
	 * A flow with no published version is not this method's business.
	 */
	public function testAFlowWithNoPublishedVersionIsLeftAlone(): void {
		$flow = $this->flow(old: $this->oldGraph(), head: $this->newGraph());
		$this->rows = [];

		$this->assertNull($this->service()->publishShippedUpdate(flow: $flow));
		$this->assertSame([], $this->rows);
	}
}
