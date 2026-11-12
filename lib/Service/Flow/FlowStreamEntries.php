<?php

/**
 * FlowStreamEntries
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;

/**
 * The stream walk's picture entries: one array per live stream, keyed by
 * stream id, built from stream rows or minted for tokens no row carries.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowRunCommit::streamIdFor and FlowStream::childPath
 * are pure id/path helpers; seeding needs their answers before any row exists.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamEntries {

	/**
	 * Constructor.
	 *
	 * @param string $runUuid The run whose streams these are.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(public readonly string $runUuid) {

	}//end __construct()

	/**
	 * The entries with a queued stream added for each unassigned token: the
	 * root first, then root children in ordinal order.
	 *
	 * @param array<string, array> $entries The live entries.
	 * @param array<int, string>   $places  The places, once per unassigned token.
	 *
	 * @return array<string, array> The entries.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function seed(array $entries, array $places): array {
		$rootId = FlowRunCommit::streamIdFor(runUuid: $this->runUuid, path: FlowStream::ROOT_PATH);
		$ordinal = 1;
		foreach ($places as $place) {
			if (isset($entries[$rootId]) === false) {
				$entries[$rootId] = $this->queued(ref: new FlowStreamRef(streamId: $rootId, place: $place));
				continue;
			}

			$ordinal++;
			$path = FlowStream::childPath(parentPath: FlowStream::ROOT_PATH, index: $ordinal);
			$id = FlowRunCommit::streamIdFor(runUuid: $this->runUuid, path: $path);
			$entries[$id] = $this->queued(ref: new FlowStreamRef(streamId: $id, path: $path, parent: $rootId, place: $place));
		}

		return $entries;
	}//end seed()

	/**
	 * The stream as its row would be minted: its known path, parent and
	 * place, or the root path when the entries do not hold it.
	 *
	 * @param array<string, array> $entries The entries.
	 * @param string               $id      The stream id.
	 *
	 * @return FlowStreamRef The stream.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function ref(array $entries, string $id): FlowStreamRef {
		$stream = ($entries[$id] ?? ['path' => FlowStream::ROOT_PATH, 'parent' => null, 'place' => null]);

		return new FlowStreamRef(streamId: $id, path: (string)$stream['path'], parent: $stream['parent'], place: $stream['place']);
	}//end ref()

	/**
	 * The entries of the live (non-terminal) rows, keyed by stream id.
	 *
	 * @param iterable<FlowStream> $rows The run's stream rows.
	 *
	 * @return array<string, array{id: string, path: string, parent: string|null, place: string|null, status: string}> The entries.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function live(iterable $rows): array {
		$entries = [];
		foreach ($rows as $row) {
			if ($row->isTerminal() === false) {
				$entries[(string)$row->getStreamId()] = self::entryOf(row: $row);
			}
		}

		return $entries;
	}//end live()

	/**
	 * One stream as the live list carries it.
	 *
	 * @param FlowStream $row The stream row.
	 *
	 * @return array{id: string, path: string, parent: mixed, place: mixed, status: string} The entry.
	 */
	private static function entryOf(FlowStream $row): array {
		return [
			'id' => (string)$row->getStreamId(),
			'path' => (string)$row->getOrdinalPath(),
			'parent' => $row->getParentStreamId(),
			'place' => $row->getPlace(),
			'status' => (string)$row->getStatus(),
		];
	}//end entryOf()

	/**
	 * How many entries sit on each place.
	 *
	 * @param array<string, array{place: string|null}> $entries The entries.
	 *
	 * @return array<string, int> Entries per place.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function covered(array $entries): array {
		$covered = [];
		foreach ($entries as $entry) {
			if ($entry['place'] !== null) {
				$covered[$entry['place']] = (($covered[$entry['place']] ?? 0) + 1);
			}
		}

		return $covered;
	}//end covered()

	/**
	 * The tokens no live stream carries, one entry per token, sorted by place.
	 *
	 * @param array<string, int> $marking The marking.
	 * @param array<string, int> $covered Live streams per place.
	 *
	 * @return array<int, string> The places, once per unassigned token.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function unassigned(array $marking, array $covered): array {
		$unassigned = [];
		foreach ($marking as $place => $tokens) {
			$spare = ((int)$tokens - ($covered[(string)$place] ?? 0));
			for ($i = 0; $i < $spare; $i++) {
				$unassigned[] = (string)$place;
			}
		}

		sort($unassigned, SORT_STRING);
		return $unassigned;
	}//end unassigned()

	/**
	 * The entry of a stream that has no row yet.
	 *
	 * @param FlowStreamRef $ref The stream.
	 *
	 * @return array{id: string, path: string, parent: string|null, place: string|null, status: string} The entry.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function queued(FlowStreamRef $ref): array {
		return ['id' => $ref->streamId, 'path' => $ref->path, 'parent' => $ref->parent, 'place' => $ref->place, 'status' => FlowRun::STATUS_QUEUED];
	}//end queued()
}//end class
