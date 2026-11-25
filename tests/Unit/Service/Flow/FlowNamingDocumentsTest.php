<?php

/**
 * Unit tests for FlowNamingDocuments.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Flow\FlowNamingDocuments;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNamingDocuments
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses \OCA\OpenRegister\Db\Flow\FlowSelection
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class FlowNamingDocumentsTest extends TestCase {

	private function flow(string $uuid, string $name, ?string $triggerSchema = null, ?array $nodes = null): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setName($name);
		$flow->setTriggerSchema($triggerSchema);
		$flow->setNodes($nodes);
		return $flow;
	}

	public function testEveryFlowIsADocumentOfWhatCanNameAProperty(): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->expects($this->once())->method('findAllFlows')
			->with($this->anything(), $this->callback(fn (ListQuery $query): bool => $query->limit === 200 && $query->offset === 0))
			->willReturn([$this->flow('f-1', 'Triage', 'melding', [['id' => 'n1', 'config' => ['field' => 'omschrijving']]])]);

		$documents = (new FlowNamingDocuments($mapper, $this->createMock(LoggerInterface::class)))->namingDocuments();

		$this->assertSame(
			[[
				'kind' => 'flow',
				'id' => 'f-1',
				'name' => 'Triage',
				'content' => [
					'triggerSchema' => 'melding',
					'nodes' => [['id' => 'n1', 'config' => ['field' => 'omschrijving']]],
					'edges' => [],
					'annotations' => [],
				],
			]],
			$documents
		);
	}

	public function testFlowsAreReadPageByPageUntilAShortPage(): void {
		$full = array_map(fn (int $i): Flow => $this->flow('f-' . $i, 'F' . $i), range(1, 200));
		$offsets = [];
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willReturnCallback(function ($selection, ListQuery $query) use ($full, &$offsets): array {
			$offsets[] = $query->offset;
			return $query->offset === 0 ? $full : [$this->flow('f-last', 'Last')];
		});

		$documents = (new FlowNamingDocuments($mapper, $this->createMock(LoggerInterface::class)))->namingDocuments();

		$this->assertSame([0, 200], $offsets);
		$this->assertCount(201, $documents);
		$this->assertSame('f-last', $documents[200]['id']);
	}

	public function testAnUnreadablePageEndsTheListAndIsLogged(): void {
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->method('findAllFlows')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame([], (new FlowNamingDocuments($mapper, $logger))->namingDocuments());
	}
}
