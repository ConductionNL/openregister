<?php

/**
 * The tab badges: how much of each sub-resource arrived after you last looked.
 *
 * Split out of ReadStateService, which owns the permission rule and the writes.
 * Counting is a different job: it reads an object's body and its files and
 * answers a number per sub-resource, and it needs no session and no write path.
 * Keeping it here is what lets the service stay about who may do what.
 *
 * The caller resolves the read-state row and hands it in, so this class never
 * decides whose counts it is producing. That is deliberate: a counter that
 * could pick a user could badge one person's tabs with another's news.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use DateTime;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use Psr\Log\LoggerInterface;

/**
 * Count what is unread per sub-resource, for one already-resolved reader.
 */
class UnreadCountCalculator {

	/**
	 * Constructor.
	 *
	 * @param SubstantiveChangeEvaluator $evaluator Declares an object's sub-resources.
	 * @param FileMapper $fileMapper Counts an object's files for the files badge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SubstantiveChangeEvaluator $evaluator,
		private readonly FileMapper $fileMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * How many entries of each sub-resource are unread for one reader.
	 *
	 * One map, from one read state row plus the object's own body, so a page
	 * renders every tab badge without a call per tab. A sub-resource the schema
	 * does not declare is absent from the map rather than zero, because "no
	 * badge" and "a badge reading nought" are different claims.
	 *
	 * @param ObjectEntity $object The object being rendered.
	 * @param ObjectReadState|null $state The reader's row, or null when never seen.
	 *
	 * @return array<string, int> Sub-resource name to its unread count.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function countsFor(ObjectEntity $object, ?ObjectReadState $state): array {
		$subSeen = ($state?->getSubSeen() ?? []);

		$counts = [];
		foreach ($this->evaluator->subResources(object: $object) as $name => $descriptor) {
			$since = $this->seenMoment(name: $name, subSeen: $subSeen, state: $state);
			$counts[$name] = $this->countSince(object: $object, descriptor: $descriptor, since: $since);
		}

		return $counts;

	}//end countsFor()

	/**
	 * The moment one sub-resource was last seen.
	 *
	 * Falls back to the object's own seen moment, so opening a case before its
	 * messages tab existed does not badge every historical message.
	 *
	 * @param string $name The sub-resource name.
	 * @param array<string, string> $subSeen The stored per-sub-resource moments.
	 * @param ObjectReadState|null $state The read state row, when there is one.
	 *
	 * @return DateTime|null The moment, or null when the object was never seen.
	 */
	private function seenMoment(string $name, array $subSeen, ?ObjectReadState $state): ?DateTime {
		$stamp = ($subSeen[$name] ?? null);
		if (is_string($stamp) === true && $stamp !== '') {
			try {
				return new DateTime($stamp);
			} catch (\Throwable $e) {
				// A stored stamp that will not parse is treated as never seen,
				// which badges rather than hides. The alternative is a silently
				// empty badge on a row nobody can explain.
				return null;
			}
		}

		return $state?->getLastSeenAt();

	}//end seenMoment()

	/**
	 * How many entries of one sub-resource arrived after a moment.
	 *
	 * @param ObjectEntity $object The object.
	 * @param array<string, string> $descriptor The sub-resource descriptor.
	 * @param DateTime|null $since The seen moment, or null when never seen.
	 *
	 * @return integer The unread count.
	 */
	private function countSince(ObjectEntity $object, array $descriptor, ?DateTime $since): int {
		if (($descriptor['kind'] ?? '') === SubstantiveChangeEvaluator::FILES) {
			return $this->countFilesSince(object: $object, since: $since);
		}

		$body = $object->getObject();
		if (is_array($body) === false) {
			return 0;
		}

		$entries = ($body[$descriptor['property'] ?? ''] ?? null);
		if (is_array($entries) === false) {
			return 0;
		}

		$field = (string)($descriptor['dateField'] ?? '');
		$count = 0;
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ($this->isAfter(value: ($entry[$field] ?? null), since: $since) === true) {
				$count++;
			}
		}

		return $count;

	}//end countSince()

	/**
	 * How many of the object's files changed after a moment.
	 *
	 * @param ObjectEntity $object The object.
	 * @param DateTime|null $since The seen moment, or null when never seen.
	 *
	 * @return integer The unread file count.
	 */
	private function countFilesSince(ObjectEntity $object, ?DateTime $since): int {
		try {
			$files = $this->fileMapper->getFilesForObject(object: $object);
		} catch (\Throwable $e) {
			// A folder lookup must never take out an object read. An absent
			// count reads as nought, which under-badges rather than failing.
			$this->logger->debug(
				sprintf('[ReadStateService] file count skipped for %s: %s', (string)$object->getUuid(), $e->getMessage())
			);
			return 0;
		}

		$count = 0;
		foreach ($files as $file) {
			if (is_array($file) === false) {
				continue;
			}

			if ($this->isAfter(value: ($file['mtime'] ?? null), since: $since) === true) {
				$count++;
			}
		}

		return $count;

	}//end countFilesSince()

	/**
	 * Whether a stored moment is later than the seen moment.
	 *
	 * Accepts the two shapes the sources actually carry: an ISO string on an
	 * object property, and a unix timestamp on a filecache row.
	 *
	 * @param mixed $value The entry's moment.
	 * @param DateTime|null $since The seen moment, or null when never seen.
	 *
	 * @return boolean True when the entry is newer than the seen moment.
	 */
	private function isAfter(mixed $value, ?DateTime $since): bool {
		$moment = $this->toMoment(value: $value);
		if ($moment === null) {
			return false;
		}

		if ($since === null) {
			// Never seen: every entry that carries a moment at all is new.
			return true;
		}

		return $moment > $since;

	}//end isAfter()

	/**
	 * Read a stored moment in either shape the sources carry.
	 *
	 * An object property holds an ISO string; a filecache row holds a unix
	 * timestamp. Anything that will not parse is null, which the caller reads
	 * as "not newer" and so under-badges rather than badging on a guess.
	 *
	 * @param mixed $value The entry's moment.
	 *
	 * @return DateTime|null The moment, or null when there is not one.
	 */
	private function toMoment(mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_int($value) === true || (is_string($value) === true && ctype_digit($value) === true)) {
			return (new DateTime())->setTimestamp((int)$value);
		}

		if (is_string($value) === false) {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (\Throwable $e) {
			return null;
		}

	}//end toMoment()

}//end class
