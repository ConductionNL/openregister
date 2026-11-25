<?php

/**
 * FlowNamingDocuments: every flow as the document a rename preview reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowNamingDocumentsInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads every flow, page by page, as its trigger schema, nodes, edges and annotations.
 *
 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
 */
class FlowNamingDocuments implements FlowNamingDocumentsInterface {

	/**
	 * Flows read per page.
	 */
	private const PAGE = 200;

	/**
	 * Most pages read; beyond this the rest of the flows are not offered.
	 */
	private const MAX_PAGES = 25;

	/**
	 * Constructor.
	 *
	 * @param FlowMapperInterface $flows  The flows.
	 * @param LoggerInterface     $logger Logs an unreadable page.
	 */
	public function __construct(
		private readonly FlowMapperInterface $flows,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every flow as a document; an unreadable page ends the list.
	 *
	 * @return list<array{kind: string, id: mixed, name: string|null, content: array<mixed>}>
	 *
	 * @spec openspec/changes/modelling-rename-without-loss/tasks.md#1.2
	 */
	public function namingDocuments(): array {
		$documents = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$flows = $this->flows->findAllFlows(query: new ListQuery(limit: self::PAGE, offset: $page * self::PAGE));
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[FlowNamingDocuments] Could not list the flows; the rename preview does not check them',
					context: ['error' => $e->getMessage()]
				);
				break;
			}

			foreach ($flows as $flow) {
				$documents[] = self::document(flow: $flow);
			}

			if (count($flows) < self::PAGE) {
				break;
			}
		}

		return $documents;
	}//end namingDocuments()

	/**
	 * One flow as a document.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array{kind: string, id: mixed, name: string|null, content: array<mixed>}
	 */
	private static function document(Flow $flow): array {
		return [
			'kind' => 'flow',
			'id' => ($flow->getUuid() ?? $flow->getId()),
			'name' => $flow->getName(),
			'content' => [
				'triggerSchema' => $flow->getTriggerSchema(),
				'nodes' => ($flow->getNodes() ?? []),
				'edges' => ($flow->getEdges() ?? []),
				'annotations' => ($flow->getAnnotations() ?? []),
			],
		];
	}//end document()
}//end class
