<?php

/**
 * OpenRegister DeferredChunkPacking
 *
 * Packs fitted deferred entries into consecutive chunks that stay within a job argument budget.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * One flush's packing state: the closed chunks, the open chunk and its encoded length.
 *
 * Built per flush by ListenerDeferralService, never shared.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
 */
final class DeferredChunkPacking {

	/**
	 * The closed chunks.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $chunks = [];

	/**
	 * The open chunk.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $current = [];

	/**
	 * The encoded length of the open chunk.
	 *
	 * @var integer
	 */
	private int $used = 0;

	/**
	 * Constructor.
	 *
	 * @param JobArgumentBudget $budget The room the entries of one job may take.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function __construct(
		private readonly JobArgumentBudget $budget,
	) {
	}//end __construct()

	/**
	 * Add a fitted entry to the open chunk, starting a new chunk when it would overflow.
	 *
	 * @param array{entry: array<string, mixed>, length: int} $fitted The fitted entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function add(array $fitted): void {
		if ($this->current !== [] && ($this->used + $fitted['length']) > $this->budget->room) {
			$this->chunks[] = $this->current;
			$this->current  = [];
			$this->used     = 0;
		}

		$this->current[] = $fitted['entry'];
		$this->used     += $fitted['length'];
	}//end add()

	/**
	 * The chunks packed so far, the open one included, in their original order.
	 *
	 * @return array<int, array<int, array<string, mixed>>> The chunks.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function chunks(): array {
		if ($this->current === []) {
			return $this->chunks;
		}

		return array_merge($this->chunks, [$this->current]);
	}//end chunks()
}//end class
