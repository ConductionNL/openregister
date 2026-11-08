<?php

/**
 * A deletion analysis as the caller may see it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\DeletionGraph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\DeletionGraph;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Dto\DeletionAnalysis;

/**
 * Redacts the objects of a deletion analysis that the caller cannot read.
 *
 * The integrity walk reads past the read filters on purpose: every referring
 * object must count, or a RESTRICT could be deleted past. What it found is not
 * the caller's to read, though. Each target or blocker the caller's own read
 * does not find is replaced by one entry per schema that only counts them, and
 * its uuid is blanked out of every chain. Whether the object may be deleted is
 * unchanged.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ReadableDeletionAnalysis {

	/**
	 * At most this many objects are looked up; any further object counts as unreadable.
	 */
	private const MAX_LOOKUPS = 500;

	/**
	 * What a blanked uuid reads as in a chain.
	 */
	private const HIDDEN = '(not readable)';

	/**
	 * Whether the caller reads each uuid looked up so far.
	 *
	 * @var array<string, bool>
	 */
	private array $readable = [];

	/**
	 * Start a redaction for the signed-in caller.
	 *
	 * @param MagicMapperInterface $mapper The mapper whose default read is the caller's own.
	 */
	public function __construct(private readonly MagicMapperInterface $mapper) {
	}//end __construct()

	/**
	 * The analysis without the objects the caller cannot read.
	 *
	 * @param DeletionAnalysis $analysis The analysis of the unscoped walk.
	 *
	 * @return DeletionAnalysis
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function analysisFor(DeletionAnalysis $analysis): DeletionAnalysis {
		$entries = array_merge($analysis->cascadeTargets, $analysis->nullifyTargets, $analysis->defaultTargets, $analysis->blockers);
		foreach ($entries as $entry) {
			$uuid = (string) ($entry['objectUuid'] ?? '');
			if ($uuid !== '') {
				$this->callerReads(uuid: $uuid);
			}
		}

		return new DeletionAnalysis(
			deletable: $analysis->deletable,
			cascadeTargets: $this->redactEntries(entries: $analysis->cascadeTargets),
			nullifyTargets: $this->redactEntries(entries: $analysis->nullifyTargets),
			defaultTargets: $this->redactEntries(entries: $analysis->defaultTargets),
			blockers: $this->redactEntries(entries: $analysis->blockers),
			chainPaths: array_map(fn ($chain) => $this->redactChain(chain: $chain), $analysis->chainPaths)
		);
	}//end analysisFor()

	/**
	 * The entries the caller reads, then one counting entry per schema for the rest.
	 *
	 * @param array<int, array<string, mixed>> $entries The targets or blockers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function redactEntries(array $entries): array {
		$kept   = [];
		$hidden = [];
		foreach ($entries as $entry) {
			$uuid = (string) ($entry['objectUuid'] ?? '');
			if ($uuid !== '' && $this->callerReads(uuid: $uuid) === false) {
				self::countHidden(hidden: $hidden, entry: $entry);
				continue;
			}

			$kept[] = $this->redactEntry(entry: $entry);
		}

		return array_merge($kept, self::hiddenSummaries(hidden: $hidden));
	}//end redactEntries()

	/**
	 * Count an unreadable entry under its schema.
	 *
	 * @param array $hidden The counted entries per schema (by reference).
	 * @param array $entry  The unreadable entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function countHidden(array &$hidden, array $entry): void {
		$schema = (string) ($entry['schema'] ?? '');
		$hidden[$schema] ??= [
			'objectUuid' => '',
			'schema' => ($entry['schema'] ?? null),
			'action' => ($entry['action'] ?? null),
			'reason' => 'not readable',
			'count' => 0,
		];
		$hidden[$schema]['count']++;
	}//end countHidden()

	/**
	 * The counted entries, without an action key where none was named.
	 *
	 * @param array $hidden The counted entries per schema.
	 *
	 * @return list<array> The summaries
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private static function hiddenSummaries(array $hidden): array {
		$summaries = [];
		foreach ($hidden as $counted) {
			if ($counted['action'] === null) {
				unset($counted['action']);
			}

			$summaries[] = $counted;
		}

		return $summaries;
	}//end hiddenSummaries()

	/**
	 * A kept entry with the uuids the caller cannot read blanked out of its chain and source.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function redactEntry(array $entry): array {
		if (is_array($entry['chain'] ?? null) === true) {
			$entry['chain'] = $this->redactChain(chain: $entry['chain']);
		}

		$source = (string) ($entry['sourceUuid'] ?? '');
		if ($source !== '' && $this->callerReads(uuid: $source) === false) {
			$entry['sourceUuid'] = self::HIDDEN;
		}

		return $entry;
	}//end redactEntry()

	/**
	 * A chain with every token that names an object the caller cannot read blanked.
	 *
	 * Only tokens looked up for an entry are judged: every object a chain names is
	 * the root, which the caller reads, or an entry of the analysis, and every
	 * entry is looked up before any chain is redacted.
	 *
	 * @param mixed $chain The chain, a list of steps.
	 *
	 * @return mixed
	 */
	private function redactChain(mixed $chain): mixed {
		if (is_array($chain) === false) {
			return $chain;
		}

		return array_map(fn ($step) => $this->redactStep(step: $step), $chain);
	}//end redactChain()

	/**
	 * One chain step with every token that names an unreadable object blanked.
	 *
	 * @param mixed $step The step, a string of space-separated tokens.
	 *
	 * @return mixed
	 */
	private function redactStep(mixed $step): mixed {
		if (is_string($step) === false) {
			return $step;
		}

		return preg_replace_callback(
			'/(?<!\S)\S+(?!\S)/u',
			function (array $token): string {
				if (($this->readable[$token[0]] ?? true) === false) {
					return self::HIDDEN;
				}

				return $token[0];
			},
			$step
		);
	}//end redactStep()

	/**
	 * Whether the caller's own read finds the object.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return bool False when the read does not find it by its uuid, fails, or the lookup budget is spent.
	 */
	private function callerReads(string $uuid): bool {
		if (array_key_exists($uuid, $this->readable) === true) {
			return $this->readable[$uuid];
		}

		$readable = false;
		if (count($this->readable) < self::MAX_LOOKUPS) {
			try {
				// The read also matches ids, slugs and uris: only the object itself counts.
				$readable = ($this->mapper->find(identifier: $uuid)->getUuid() === $uuid);
			} catch (\Throwable $e) {
				$readable = false;
			}
		}

		$this->readable[$uuid] = $readable;
		return $readable;
	}//end callerReads()
}//end class
