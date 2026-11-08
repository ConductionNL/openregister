<?php

/**
 * One chunk of a bulk save while it is processed: its result, its phase timings and its event targets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObjects;

/**
 * Mutable state handed between the steps of SaveObjects::processObjectsChunk().
 */
final class BulkChunkRun {

	/**
	 * The chunk's result: saved, updated, unchanged, invalid and errors rows, and statistics.
	 *
	 * @var array<string, mixed>
	 */
	public array $result;

	/**
	 * Milliseconds per phase, in the order the phases ran.
	 *
	 * @var array<string, float>
	 */
	public array $phaseMs = [];

	/**
	 * The "registerId/schemaId" pairs whose bulk-write events are on.
	 *
	 * @var array<string, true>
	 */
	public array $eventTargets = [];

	/**
	 * When the chunk started, from microtime(true).
	 *
	 * @var float
	 */
	public readonly float $startTime;

	/**
	 * The bulk save call the chunk belongs to.
	 *
	 * @var BulkSaveRun
	 */
	public readonly BulkSaveRun $run;

	/**
	 * Constructor: the chunk starts now.
	 *
	 * @param array<string, mixed> $result The chunk's empty result.
	 * @param BulkSaveRun          $run    The bulk save call the chunk belongs to.
	 */
	public function __construct(array $result, BulkSaveRun $run) {
		$this->startTime = microtime(true);
		$this->result    = $result;
		$this->run       = $run;
	}//end __construct()

	/**
	 * Record the milliseconds a phase took.
	 *
	 * @param string $phase The phase.
	 * @param float  $start When it started, from microtime(true).
	 *
	 * @return void
	 */
	public function finishPhase(string $phase, float $start): void {
		$this->phaseMs[$phase] = round((microtime(true) - $start) * 1000, 2);
	}//end finishPhase()
}//end class
