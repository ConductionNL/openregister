<?php

/**
 * Which page of the inbox a read answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * An inbox page window: the page size clamped to 1..500 and a non-negative
 * offset, and the envelope a page is answered in.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
 */
final class TaskInboxPage {

	/**
	 * The page size, clamped to 1..500.
	 *
	 * @var integer
	 */
	public readonly int $limit;

	/**
	 * The page offset, never negative.
	 *
	 * @var integer
	 */
	public readonly int $offset;

	/**
	 * Constructor.
	 *
	 * @param int $limit  The requested page size.
	 * @param int $offset The requested page offset.
	 */
	public function __construct(int $limit = 25, int $offset = 0) {
		$this->limit = max(1, min($limit, 500));
		$this->offset = max(0, $offset);
	}//end __construct()

	/**
	 * The page envelope: the rows, the total and this window.
	 *
	 * @param array<int, array<string, mixed>> $results The rows.
	 * @param int                              $total   The total the same predicates count.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int, limit: int, offset: int} The page.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-the-inbox-answers-what-is-waiting-for-me-in-one-query
	 */
	public function envelope(array $results, int $total): array {
		return [
			'results' => $results,
			'total' => $total,
			'limit' => $this->limit,
			'offset' => $this->offset,
		];
	}//end envelope()
}//end class
