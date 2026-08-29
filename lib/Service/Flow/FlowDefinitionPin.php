<?php

/**
 * Pins a run to the flow definition it started with.
 *
 * 🔴 THE DEFECT THIS EXISTS TO REMOVE. `FlowRunAdvancer` resolved the flow by
 * id on every pass, so a run suspended on a human step resumed against the
 * graph as it stood WHEN IT WOKE, not as it stood when it started. Edit a flow
 * while a case waits a week on an applicant and the run finishes down a path
 * its author never queued. ADR-098 Decision 6 names this the programme's
 * highest-risk defect and forbids human task nodes without the pin; dossiq
 * already ships two (`dossiq.askPerson`, `dossiq.requestDecision`).
 *
 * WHAT IS PINNED, AND WHAT DELIBERATELY IS NOT. Only the executable graph —
 * nodes, edges, limits, execution mode. NOT `owner`/`organisation`: those are
 * AUTHORIZATION, and authorization must re-resolve on every pass so that a
 * revoked grant actually stops the next hop. Freezing them would make
 * revocation cosmetic for exactly the long-suspended runs nobody is watching,
 * which is the failure {@see FlowRunService::queue()} already guards against
 * for delegation. Pin the shape of the work; never pin the right to do it.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowDefinitionMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Captures and restores the definition a run is pinned to.
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowDefinitionPin {
	/**
	 * The graph keys that are pinned.
	 *
	 * An explicit allowlist, not "everything except". A later key added to the
	 * resolved document — a counter, a timestamp, a cache marker — would
	 * otherwise join the hash and make every run pin a distinct definition,
	 * silently turning the dedupe off. Adding a key here is a deliberate act.
	 *
	 * @var string[]
	 */
	private const PINNED_KEYS = ['nodes', 'edges', 'limits', 'executionMode'];

	/**
	 * Constructor.
	 *
	 * @param FlowDefinitionMapper $mapper Stores and reads pinned definitions.
	 * @param LoggerInterface      $logger Records pins that could not be taken.
	 */
	public function __construct(
		private readonly FlowDefinitionMapper $mapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The canonical hash of a resolved flow document.
	 *
	 * 🔑 CANONICAL MEANS KEY ORDER CANNOT CHANGE THE ANSWER. `json_encode` of a
	 * PHP array preserves insertion order, so the same graph loaded through two
	 * code paths that built it in different orders would hash differently and
	 * store a duplicate row for identical content. Sorting recursively by key
	 * is what makes the hash a property of the CONTENT rather than of the
	 * traversal that produced it.
	 *
	 * @param array<string, mixed> $flow The resolved flow document.
	 *
	 * @return array{hash: string, json: string}|null The canonical form, or null when it cannot be encoded.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function canonicalise(array $flow): ?array {
		$subset = [];
		foreach (self::PINNED_KEYS as $key) {
			$subset[$key] = ($flow[$key] ?? null);
		}

		$this->sortRecursive(value: $subset);

		$json = json_encode($subset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (is_string($json) === false) {
			return null;
		}

		return ['hash' => hash('sha256', $json), 'json' => $json];

	}//end canonicalise()

	/**
	 * Pin a definition and return the hash a run should carry.
	 *
	 * Returns null rather than throwing when the definition cannot be stored.
	 * A run that cannot be pinned still runs — unpinned, exactly as every run
	 * did before this class existed. Refusing to queue would turn a storage
	 * hiccup into a halted workflow, which is a worse failure than the one
	 * being fixed.
	 *
	 * @param array<string, mixed> $flow   The resolved flow document.
	 * @param string               $flowId The flow uuid, for provenance.
	 *
	 * @return string|null The hash to pin, or null when it could not be pinned.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function pin(array $flow, string $flowId): ?string {
		$canonical = $this->canonicalise(flow: $flow);
		if ($canonical === null) {
			$this->logger->warning(
				'[FlowDefinitionPin] Could not encode flow "' . $flowId . '"; the run will be unpinned.'
			);
			return null;
		}

		try {
			$stored = $this->mapper->store(
				hash: $canonical['hash'],
				definition: $canonical['json'],
				flowUuid: $flowId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[FlowDefinitionPin] Could not store the definition for flow "' . $flowId
				. '"; the run will be unpinned: ' . $e->getMessage()
			);
			return null;
		}

		if ($stored === null) {
			return null;
		}

		return $canonical['hash'];

	}//end pin()

	/**
	 * The graph a hash names, or null when it is not there.
	 *
	 * 🔴 NO FALLBACK, EVER. An earlier draft of this class fell back to the
	 * flow's LIVE definition when the pinned row was missing. That is exactly
	 * the defect versioning exists to remove: it silently promotes an in-flight
	 * run onto a graph it did not start on, and the run's marking, its taken
	 * decisions and its log all belong to the version it started on. Returning
	 * null makes the caller decide, and the only correct decision is to fail
	 * the run naming the version — never to substitute another one.
	 *
	 * @param string|null $hash The definition hash.
	 *
	 * @return array<string, mixed>|null The graph, or null when unresolvable.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function graphFor(?string $hash): ?array {
		if ($hash === null || trim($hash) === '') {
			return null;
		}

		try {
			$pinned = $this->mapper->findByHash($hash);
		} catch (Throwable $e) {
			$pinned = null;
		}

		if ($pinned === null) {
			$this->logger->warning(
				'[FlowDefinitionPin] Definition "' . $hash . '" is missing.'
			);
			return null;
		}

		$decoded = $pinned->decoded();
		if ($decoded === []) {
			$this->logger->warning(
				'[FlowDefinitionPin] Definition "' . $hash . '" is unreadable.'
			);
			return null;
		}

		return $decoded;

	}//end graphFor()

	/**
	 * Sort an array recursively by key, in place.
	 *
	 * @param array<string, mixed> $value The array to sort.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function sortRecursive(array &$value): void {
		foreach ($value as &$item) {
			if (is_array($item) === true) {
				$this->sortRecursive(value: $item);
			}
		}

		unset($item);

		// LISTS KEEP THEIR ORDER. `nodes` and `edges` are lists, and their
		// order can carry meaning; ksort on a list would renumber nothing but
		// would still be wrong the day a caller relies on position. Only
		// associative arrays are sorted.
		if (array_is_list($value) === false) {
			ksort($value);
		}

	}//end sortRecursive()
}//end class
