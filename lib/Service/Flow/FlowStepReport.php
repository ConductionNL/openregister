<?php

/**
 * A step's own report into the run log, carried through the node context.
 *
 * The run log records what a node RECEIVED and RETURNED; a node whose work is
 * a side effect — a send, a call — has more to say than its pass-through items
 * can carry. This handle travels in the context like the token, the resume
 * state and the guard do (`IFlowNode::execute()` takes `$context` by value, so
 * only an object survives the copy), and the engine drains it onto the step's
 * own log entry after each hop.
 *
 * The report is per HOP: the engine takes it after each dispatch, so an entry
 * a node wrote can never leak onto a later step's log line.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Collects a node's own log detail for the current hop.
 */
class FlowStepReport {

	/**
	 * The context key this handle travels under.
	 */
	public const CONTEXT_KEY = '_stepReport';

	/**
	 * The detail written by the current hop's node.
	 *
	 * @var array<string, mixed>
	 */
	private array $detail = [];

	/**
	 * Record one piece of detail for the current step's log entry.
	 *
	 * A node MUST keep its report bounded itself — the run log is kept for
	 * months, and the engine does not re-sample what a node chose to write.
	 *
	 * @param string $key The detail's name within the entry's `report` map.
	 * @param mixed $value The detail; must be JSON-serialisable.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function put(string $key, mixed $value): void {
		$this->detail[$key] = $value;
	}//end put()

	/**
	 * Return and clear the current hop's detail.
	 *
	 * Clearing on read is what scopes the report to one hop: the engine takes
	 * it while writing the step's log entry, and the next node starts blank.
	 *
	 * @return array<string, mixed> The detail written since the last take.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function take(): array {
		$detail = $this->detail;
		$this->detail = [];

		return $detail;
	}//end take()
}//end class
