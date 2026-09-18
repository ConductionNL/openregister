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
 * 🔴 ACCESS IS NOT ENFORCED HERE, AND SAYING SO IS THE POINT. The feed is
 * read for an object the caller has already resolved; every row is about that
 * object and carries no rights of its own. Whether this caller may see the
 * object at all is decided by the object read that got them here, which is
 * MagicRbacHandler's business — and on a schema that configures no
 * authorization, that read is open to every authenticated account. A feed
 * mounted on an object anybody can read is a feed anybody can read. Closing
 * that is the schema's job, not this class's, and a check added here would be
 * a second answer to a question the platform already answers.
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

use OCA\OpenRegister\Db\AuditTrailMapper;
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
	 * @param AuditTrailMapper  $audit    OpenRegister's own trail for the object.
	 * @param ActivityProvider  $activity The NC Activity rows marked for this object.
	 * @param LoggerInterface   $logger   Logger.
	 */
	public function __construct(
		private readonly ActivityFeedMerge $merge,
		private readonly AuditTrailMapper $audit,
		private readonly ActivityProvider $activity,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * One page of the merged feed.
	 *
	 * @param string                                       $register Register slug of the object.
	 * @param string                                       $schema   Schema slug of the object.
	 * @param string                                       $objectId The object's uuid.
	 * @param array<string,mixed>                          $options  `pageSize`, `includeReads`, `kinds`, `from`, `until`, `before`.
	 * @param array<string,array<int,array<string,mixed>>> $handedIn Rows for sources this service does not own: `file`, `note`, `mail`.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,nextCursor:?int,counts:array<string,int>,degraded:array<int,string>}
	 *         The page, plus the sources that could not be read.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	public function page(
		string $register,
		string $schema,
		string $objectId,
		array $options = [],
		array $handedIn = [],
	): array {
		$bound = $this->merge->boundPerSource(options: $options);
		$degraded = [];

		$sources = [];
		foreach (['file', 'note', 'mail'] as $kind) {
			$sources[$kind] = [];
			if (is_array($handedIn[$kind] ?? null) === true) {
				$sources[$kind] = $handedIn[$kind];
			}
		}

		try {
			$sources['audit'] = $this->auditRows(objectId: $objectId, bound: $bound, options: $options);
		} catch (Throwable $e) {
			// A source that could not be read is NAMED rather than merged as
			// nothing. An empty audit list and an unreadable one render the
			// same way, and only one of them means the object has no history.
			$degraded[] = 'audit';
			$sources['audit'] = [];
			$this->logger->warning(
				'[ActivityFeedService] the audit trail could not be read for the merged feed',
				['objectId' => $objectId, 'exception' => $e->getMessage()]
			);
		}

		try {
			$sources['activity'] = $this->activity->list(
				register: $register,
				schema: $schema,
				objectId: $objectId,
				filters: []
			);
		} catch (Throwable $e) {
			$degraded[] = 'activity';
			$sources['activity'] = [];
			$this->logger->warning(
				'[ActivityFeedService] the Activity rows could not be read for the merged feed',
				['objectId' => $objectId, 'exception' => $e->getMessage()]
			);
		}

		$page = $this->merge->page(bySource: $sources, options: $options);
		$page['degraded'] = $degraded;

		return $page;
	}//end page()

	/**
	 * The object's own audit rows, bounded and in the merge's shape.
	 *
	 * READS ARE FETCHED, not filtered out here. The toggle belongs to the
	 * merge, which is the one place that decides what a page holds; dropping
	 * them at the query would make the toggle unable to bring them back
	 * without a second, differently shaped read.
	 *
	 * @param string              $objectId The object's uuid.
	 * @param int                 $bound    How many rows this source may contribute.
	 * @param array<string,mixed> $options  The caller's options, for the cursor.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 *
	 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md#requirement-the-activity-leaf-merges-an-objects-feed-from-five-sources
	 */
	private function auditRows(string $objectId, int $bound, array $options): array {
		$entries = $this->audit->findAll(
			limit: $bound,
			offset: 0,
			filters: ['objectUuid' => $objectId],
			sort: ['created' => 'DESC'],
		);

		$rows = [];
		foreach ($entries as $entry) {
			$created = $entry->getCreated();
			$timestamp = 0;
			if ($created instanceof \DateTimeInterface) {
				$timestamp = $created->getTimestamp();
			}


			$rows[] = [
				'id' => (string)$entry->getUuid(),
				'action' => (string)$entry->getAction(),
				'timestamp' => $timestamp,
				'actor' => (string)($entry->getUserName() ?? $entry->getUser() ?? ''),
				'summary' => $this->summaryOf(action: (string)$entry->getAction(), changed: $entry->getChanged()),
				// The trail has no page of its own to link to; the row is the
				// record. An empty url is the honest answer, and the surface
				// renders no link rather than a link to here.
				'url' => '',
			];
		}

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
