<?php

/**
 * RecentLensOrder: the `_recent` lens's order and moment, for the paths that
 * assemble rows outside one SQL statement.
 *
 * The single-schema path orders a `_recent` page in SQL
 * (`MagicSearchHandler::applyRecencyOrder()`) and stamps `@self.viewedAt`
 * there. The cross-table paths merge rows from several tables, or from a
 * lookup by id, so they apply the same rule here: an explicit `_order` wins,
 * otherwise the read history decides, newest first.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Order, page and stamp a `_recent` result set assembled in PHP.
 *
 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
 */
final class RecentLensOrder {

	/**
	 * The read history a query carries, or null when the lens is not on.
	 *
	 * `_recentViews` is set only by SearchQueryHandler, which strips any copy
	 * a caller sent.
	 *
	 * @param array $query The search query.
	 *
	 * @return array<string, string>|null Object uuid => ISO 8601 last read, newest first.
	 *
	 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public static function views(array $query): ?array {
		$views = ($query['_recentViews'] ?? null);
		if (is_array($views) === false || $views === []) {
			return null;
		}

		return $views;
	}//end views()

	/**
	 * Whether the history decides the order: the lens is on and no `_order`.
	 *
	 * The same precedence as MagicSearchHandler::applyResultOrder(). A search
	 * term does not displace the history.
	 *
	 * @param array $query The search query.
	 *
	 * @return bool True when the page is ordered by the history.
	 *
	 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public static function ordersPage(array $query): bool {
		return self::views(query: $query) !== null && empty($query['_order']) === true;
	}//end ordersPage()

	/**
	 * Sort objects by the history: newest read first, unplaced last by uuid.
	 *
	 * @param array<int, mixed>     $objects The objects.
	 * @param array<string, string> $views   Object uuid => last read, newest first.
	 *
	 * @return array<int, mixed> The objects in history order.
	 *
	 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public static function sort(array $objects, array $views): array {
		$position = array_flip(array_map('strval', array_keys($views)));
		$unplaced = count($position);

		usort(
			$objects,
			static function ($left, $right) use ($position, $unplaced): int {
				$leftUuid = self::uuidOf(object: $left);
				$rightUuid = self::uuidOf(object: $right);

				return [($position[$leftUuid] ?? $unplaced), $leftUuid] <=> [($position[$rightUuid] ?? $unplaced), $rightUuid];
			}
		);

		return $objects;
	}//end sort()

	/**
	 * Set `@self.viewedAt` on every object the history knows.
	 *
	 * @param array<int, mixed>     $objects The objects.
	 * @param array<string, string> $views   Object uuid => ISO 8601 last read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/recently-opened-means-opened/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public static function stamp(array $objects, array $views): void {
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$moment = ($views[self::uuidOf(object: $object)] ?? null);
			if (is_string($moment) === true) {
				$object->setViewedAt($moment);
			}
		}

	}//end stamp()

	/**
	 * The uuid of one result, or '' when it has none.
	 *
	 * @param mixed $object An ObjectEntity.
	 *
	 * @return string The uuid.
	 */
	private static function uuidOf(mixed $object): string {
		if ($object instanceof ObjectEntity) {
			return (string) $object->getUuid();
		}

		return '';
	}//end uuidOf()
}//end class
