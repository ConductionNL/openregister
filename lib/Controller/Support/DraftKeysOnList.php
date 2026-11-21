<?php

/**
 * OpenRegister DraftKeysOnList
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftService;
use Throwable;

/**
 * Adds the keys of the drafts a caller may see to each row of a list, in `@self.drafts`.
 *
 * Drafts live in their own table, so a list never returns one as an object;
 * this only annotates the rows the list already returned. It never adds,
 * removes or reorders a row. The drafts of the whole page are read at once;
 * a row's live object is only loaded when one of its drafts is not the
 * caller's own, because only then does write access decide.
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-never-appear-as-objects-in-lists-or-search
 */
final class DraftKeysOnList {

	/**
	 * The query parameter that asks for the annotation.
	 *
	 * @var string
	 */
	public const PARAM = '_drafts';

	/**
	 * Whether the request parameters ask for draft keys.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-never-appear-as-objects-in-lists-or-search
	 */
	public static function asked(array $params): bool {
		return filter_var(($params[self::PARAM] ?? false), FILTER_VALIDATE_BOOLEAN) === true;
	}//end asked()

	/**
	 * The rows with `@self.drafts` set to the visible draft keys (empty when none).
	 *
	 * @param array<int|string, mixed> $results The list's rows, as rendered.
	 * @param DraftService             $drafts  The drafts.
	 * @param Schema                   $schema  The listed schema.
	 * @param string|null              $caller  The calling user.
	 * @param callable                 $load    fn (string $uuid): ?ObjectEntity, the live object under the caller's access.
	 *
	 * @return array<int|string, mixed> The same rows in the same order.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-drafts-never-appear-as-objects-in-lists-or-search
	 */
	public static function annotate(array $results, DraftService $drafts, Schema $schema, ?string $caller, callable $load): array {
		$uuids = [];
		foreach ($results as $row) {
			$uuid = self::uuidOf(row: $row);
			if ($uuid !== null) {
				$uuids[] = $uuid;
			}
		}

		$byObject = [];
		if ($caller !== null) {
			$byObject = $drafts->draftsOf(objectUuids: $uuids);
		}

		foreach ($results as $index => $row) {
			$uuid = self::uuidOf(row: $row);
			if ($uuid === null) {
				continue;
			}

			$keys = self::visibleKeys(
				own: ($byObject[$uuid] ?? []),
				drafts: $drafts,
				schema: $schema,
				caller: $caller,
				object: fn (): ?ObjectEntity => self::loaded(load: $load, uuid: $uuid)
			);

			$self = ($row['@self'] ?? []);
			if (is_array($self) === false) {
				$self = [];
			}

			$self['drafts']         = $keys;
			$results[$index]['@self'] = $self;
		}

		return $results;
	}//end annotate()

	/**
	 * The keys of the drafts of one object the caller may see.
	 *
	 * @param array<int, DraftVersion> $own    The object's drafts.
	 * @param DraftService             $drafts The drafts.
	 * @param Schema                   $schema The schema.
	 * @param string|null              $caller The caller.
	 * @param callable                 $object fn (): ?ObjectEntity.
	 *
	 * @return array<int, string>
	 */
	private static function visibleKeys(array $own, DraftService $drafts, Schema $schema, ?string $caller, callable $object): array {
		if ($own === [] || $caller === null) {
			return [];
		}

		$live = null;
		foreach ($own as $draft) {
			if ($draft->getCreatedBy() !== $caller) {
				$live = $object();
				break;
			}
		}

		return array_map(
			static fn (DraftVersion $draft): string => (string)$draft->getDraftKey(),
			$drafts->visibleAmong(drafts: $own, object: $live, schema: $schema, caller: $caller)
		);
	}//end visibleKeys()

	/**
	 * The live object, or null when it cannot be read.
	 *
	 * @param callable $load The loader.
	 * @param string   $uuid The uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private static function loaded(callable $load, string $uuid): ?ObjectEntity {
		try {
			$object = $load($uuid);
		} catch (Throwable) {
			return null;
		}

		if ($object instanceof ObjectEntity) {
			return $object;
		}

		return null;
	}//end loaded()

	/**
	 * A rendered row's uuid, or null when it is not an object row.
	 *
	 * @param mixed $row The row.
	 *
	 * @return string|null
	 */
	private static function uuidOf(mixed $row): ?string {
		if (is_array($row) === false) {
			return null;
		}

		$uuid = ($row['@self']['id'] ?? $row['@self']['uuid'] ?? $row['id'] ?? $row['uuid'] ?? null);
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		return $uuid;
	}//end uuidOf()
}//end class
