<?php

/**
 * The activity leaf's feed for one object: the sources, fetched and merged.
 *
 * 🔑 {@see ActivityFeedMerge} DECIDES THE ORDER; THIS CLASS FETCHES. Keeping
 * the two apart is what lets every rule about order, bounds, cursors and the
 * read filter be driven without a database, and it is why that engine's tests
 * need no Nextcloud at all.
 *
 * 🔴 WHAT THIS CLASS FETCHES ITSELF IS WHAT IT OWNS. The audit trail is
 * OpenRegister's own table, so it is read here. The other four sources belong
 * to other owners — NC Activity to the Activity app through its provider,
 * files, notes and mail to their own leaves — and they are handed IN by the
 * caller that already holds them. A service that reached into four other
 * apps' tables to build one list would be four integrations nobody declared,
 * and each of them would break silently on an instance without that app.
 *
 * 🔴 THE TRAIL IS READ THROUGH THE OBJECT'S HISTORY FOR THIS CALLER. The
 * audit rows come from ObjectHistoryForReaders::page(), so the object is read
 * with the caller's RBAC and organisation, the doelbinding guard runs, and a
 * summary names only the properties the caller may see. A caller who may not
 * read the object gets no audit rows. Whether this caller may see the object
 * at all is still decided by the object read that got them here, and on a
 * schema that configures no authorization that read is open to every
 * authenticated account.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Integration\Providers\ActivityProvider;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the merged activity feed for one object.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedService {

	/**
	 * Constructor.
	 *
	 * @param ActivityFeedMerge $merge    Decides order, bounds and the cursor.
	 * @param ObjectHistoryForReaders $history The object's history, as this caller is given it.
	 * @param ActivityProvider  $activity The NC Activity rows marked for this object.
	 * @param LoggerInterface   $logger   Logger.
	 */
	public function __construct(
		private readonly ActivityFeedMerge $merge,
		private readonly ObjectHistoryForReaders $history,
		private readonly ActivityProvider $activity,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One page of the merged feed.
	 *
	 * @param ActivityFeedObject  $object  The object: register slug, schema slug and uuid.
	 * @param ActivityFeedOptions $options How the page is cut, and the rows handed in for `file`, `note`, `mail`.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,nextCursor:?int,counts:array<string,int>,degraded:array<int,string>}
	 *         The page, plus the sources that could not be read.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	public function page(ActivityFeedObject $object, ActivityFeedOptions $options = new ActivityFeedOptions()): array {
		$sources = $this->handedInSources(handedIn: $options->handedIn);
		$sources['audit'] = $this->auditSource(object: $object, bound: $this->merge->boundPerSource(options: $options->criteria));
		$sources['activity'] = $this->activitySource(object: $object);

		return $this->mergedPage(sources: $sources, options: $options->criteria);
	}//end page()

	/**
	 * Merge the sources into one page and name the ones that could not be read.
	 *
	 * @param array<string,array<int,array<string,mixed>>|null> $sources The rows per source, null when unreadable.
	 * @param array<string,mixed>                               $options The caller's options.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,nextCursor:?int,counts:array<string,int>,degraded:array<int,string>}
	 */
	private function mergedPage(array $sources, array $options): array {
		$degraded = array_keys(array_filter($sources, static fn (?array $rows): bool => $rows === null));
		$sources = array_map(static fn (?array $rows): array => ($rows ?? []), $sources);

		$page = $this->merge->page(bySource: $sources, options: $options);
		$page['degraded'] = $degraded;

		return $page;
	}//end mergedPage()

	/**
	 * The rows of the sources this service does not own, as handed in.
	 *
	 * Only the `file`, `note` and `mail` kinds are taken, and only when their
	 * rows are a list; anything else contributes nothing.
	 *
	 * @param array<string,mixed> $handedIn Rows for sources this service does not own.
	 *
	 * @return array<string,array<int,array<string,mixed>>> The rows per handed-in kind.
	 */
	private function handedInSources(array $handedIn): array {
		$sources = [];
		foreach (['file', 'note', 'mail'] as $kind) {
			$sources[$kind] = [];
			if (is_array($handedIn[$kind] ?? null) === true) {
				$sources[$kind] = $handedIn[$kind];
			}
		}

		return $sources;
	}//end handedInSources()

	/**
	 * The audit source, or null when it could not be read.
	 *
	 * A source that could not be read is NAMED rather than merged as
	 * nothing. An empty audit list and an unreadable one render the same
	 * way, and only one of them means the object has no history.
	 *
	 * @param ActivityFeedObject $object The object's register, schema and uuid.
	 * @param int                $bound  How many rows this source may contribute.
	 *
	 * @return array<int,array<string,mixed>>|null The rows, or null when unreadable.
	 */
	private function auditSource(ActivityFeedObject $object, int $bound): ?array {
		try {
			return $this->auditRows(object: $object, bound: $bound);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ActivityFeedService] the audit trail could not be read for the merged feed',
				['objectId' => $object->id, 'exception' => $e->getMessage()]
			);

			return null;
		}
	}//end auditSource()

	/**
	 * The NC Activity source, or null when it could not be read.
	 *
	 * @param ActivityFeedObject $object The object's register, schema and uuid.
	 *
	 * @return array<int,array<string,mixed>>|null The rows, or null when unreadable.
	 */
	private function activitySource(ActivityFeedObject $object): ?array {
		try {
			return $this->activity->list(
				register: $object->register,
				schema: $object->schema,
				objectId: $object->id,
				filters: []
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ActivityFeedService] the Activity rows could not be read for the merged feed',
				['objectId' => $object->id, 'exception' => $e->getMessage()]
			);

			return null;
		}
	}//end activitySource()

	/**
	 * The object's own audit rows, bounded and in the merge's shape.
	 *
	 * READS ARE FETCHED, not filtered out here. The toggle belongs to the
	 * merge, which is the one place that decides what a page holds; dropping
	 * them at the query would make the toggle unable to bring them back
	 * without a second, differently shaped read.
	 *
	 * @param ActivityFeedObject $object The object's register, schema and uuid.
	 * @param int                $bound  How many rows this source may contribute.
	 *
	 * @return array<int,array<string,mixed>> The rows, or none when the caller may not read the object.
	 *
	 * @throws \OCA\OpenRegister\Service\Audit\PurposeRefusedException When the schema asks for a purpose and none usable was named.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	private function auditRows(ActivityFeedObject $object, int $bound): array {
		$page = $this->history->page(
			register: $object->register,
			schema: $object->schema,
			id: $object->id,
			params: ['limit' => $bound]
		);
		if ($page === null) {
			return [];
		}

		$rows = [];
		foreach ($page['results'] as $entry) {
			$timestamp = strtotime((string)($entry['created'] ?? ''));
			if ($timestamp === false) {
				$timestamp = 0;
			}

			$action = (string)($entry['action'] ?? '');
			$changed = ($entry['changed'] ?? null);
			if (is_array($changed) === false) {
				$changed = null;
			}

			$rows[] = [
				'id' => (string)($entry['uuid'] ?? ''),
				'action' => $action,
				'timestamp' => $timestamp,
				'actor' => (string)($entry['userName'] ?? $entry['user'] ?? ''),
				'summary' => $this->summaryOf(action: $action, changed: $changed),
				// The trail has no page of its own to link to; the row is the
				// record. An empty url is the honest answer, and the surface
				// renders no link rather than a link to here.
				'url' => '',
			];
		}//end foreach

		return $rows;
	}//end auditRows()

	/**
	 * One line about what an audit entry did.
	 *
	 * The field names and not their values: a summary that printed what a
	 * field changed FROM and TO would put the contents of a protected field
	 * into a feed that is read by everyone who can read the object.
	 *
	 * @param string     $action  The audit action.
	 * @param array|null $changed The changed map, when the entry carries one.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	private function summaryOf(string $action, ?array $changed): string {
		if (is_array($changed) === false || $changed === []) {
			return $action;
		}

		$fields = array_slice(array_keys($changed), 0, 5);

		return $action . ': ' . implode(', ', $fields);
	}//end summaryOf()
}//end class
