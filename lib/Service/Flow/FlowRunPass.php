<?php

/**
 * FlowRunPass: one pass of a run over its flow document and its subject.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;

/**
 * The run, the flow document it walks and the object it is about.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
final class FlowRunPass {

	/**
	 * Constructor.
	 *
	 * @param FlowRun              $run     The run.
	 * @param array<string, mixed> $flow    The flow document.
	 * @param object               $subject The object the run is about.
	 */
	public function __construct(
		public readonly FlowRun $run,
		public readonly array $flow,
		public readonly object $subject,
	) {
	}//end __construct()

	/**
	 * The same pass over another document: the run's pinned graph.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 *
	 * @return self The pass.
	 */
	public function withFlow(array $flow): self {
		return new self(run: $this->run, flow: $flow, subject: $this->subject);
	}//end withFlow()
}//end class
