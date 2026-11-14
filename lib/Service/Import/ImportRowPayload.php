<?php

/**
 * The shapes a decided import row's payload and refusal take.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\ImportPreviewRow;
use Symfony\Component\Uid\Uuid;

/**
 * Pure functions over decided rows: the reason a mapping refusal carries,
 * the id a payload's write targets, and which batch row a refused object is.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportRowPayload {

	/**
	 * A refusal with its reason, in the shape decideRow returns.
	 *
	 * @param string $reason Why the row is refused.
	 * @param array<string, mixed> $payload The object as far as it got.
	 *
	 * @return array{decision: string, reason: string, targetUuid: null, candidates: array<int, string>, payload: array<string, mixed>} The refusal.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function refusal(string $reason, array $payload = []): array {
		return [
			'decision' => ImportPreviewRow::DECISION_REFUSE,
			'reason' => $reason,
			'targetUuid' => null,
			'candidates' => [],
			'payload' => $payload,
		];
	}//end refusal()

	/**
	 * The reason a row the column mapping refused carries, naming each column.
	 *
	 * @param array<int, array{source: string, message: string}> $errors The mapping errors.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function mappingRefusal(array $errors): string {
		$messages = [];
		foreach ($errors as $error) {
			$messages[] = 'source column "'.$error['source'].'": '.$error['message'];
		}

		return 'The column mapping refused this row. '.implode('; ', $messages);
	}//end mappingRefusal()

	/**
	 * The object carrying the id its write will target.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param array{decision: string, reason: string|null, targetUuid: string|null} $decision The policy's decision.
	 *
	 * @return array<string, mixed> The object, with `@self.id` set for an update or a create.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function withTargetId(array $object, array $decision): array {
		if ($decision['decision'] === ImportPreviewRow::DECISION_UPDATE) {
			$object['@self']['id'] = $decision['targetUuid'];
		}

		if ($decision['decision'] === ImportPreviewRow::DECISION_CREATE) {
			// A CREATE ROW GETS ITS UUID HERE, NOT AT WRITE TIME.
			//
			// writeBatch() hands the batch to saveObjects() and, on any throw,
			// walks the whole batch row by row on the stated assumption that a
			// throw means nothing was written. That does not hold underneath:
			// MagicBulkHandler::bulkUpsert() commits each CHUNK in its own
			// transaction and rolls back only the failing one, so when chunk 3
			// fails, chunks 1-2 are already durable. The row-by-row retry then
			// re-sent them - and a CREATE payload carried no id, so saveObject()
			// minted a NEW uuid and the committed rows were duplicated, then
			// reported as succeeded because for those rows the retry did work.
			//
			// UPDATE rows were never affected: their targetUuid above makes the
			// retry idempotent by uuid. This gives CREATE rows the same property
			// rather than inventing a second mechanism. `new ObjectEntity()`
			// honours a supplied uuid (SaveObject::createObject), so the retry
			// upserts the row it already wrote instead of adding a twin.
			//
			// It also closes the re-run half: findPendingWrites() still sees an
			// unstamped row after a failed commit, and a later commit() used to
			// duplicate it again. With the uuid on the stored payload, the
			// re-run targets the same object.
			$object['@self']['id'] = Uuid::v4()->toRfc4122();
		}

		return $object;
	}//end withTargetId()

	/**
	 * The payload of every batch row, in batch order.
	 *
	 * @param array<int, ImportPreviewRow> $batch The rows.
	 *
	 * @return array<int, array<string, mixed>> The payloads.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function payloadsOf(array $batch): array {
		$objects = [];
		foreach ($batch as $row) {
			$objects[] = ($row->getPayload() ?? []);
		}

		return $objects;
	}//end payloadsOf()

	/**
	 * The batch row a refused object came from. Every written payload carries its id.
	 *
	 * @param array<int, ImportPreviewRow> $batch  The rows sent.
	 * @param array<string, mixed>         $object The refused object.
	 *
	 * @return int|null The row's key, or null.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function batchKeyOf(array $batch, array $object): ?int {
		$id = self::objectId(object: $object);
		foreach ($batch as $key => $row) {
			if ($id !== null && self::objectId(object: ($row->getPayload() ?? [])) === $id) {
				return $key;
			}
		}

		return null;
	}//end batchKeyOf()

	/**
	 * The id a payload names, as a string, or null when it names none.
	 *
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return string|null The id.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-an-import-leaves-a-durable-record-with-an-outcome-per-row-req-rcn-005
	 */
	public static function idOf(array $object): ?string {
		$id = self::objectId(object: $object);
		if (is_string($id) === false || $id === '') {
			return null;
		}

		return $id;
	}//end idOf()

	/**
	 * The id an object names, in any of the shapes a payload carries it.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return mixed The id, or null.
	 */
	private static function objectId(array $object): mixed {
		return ($object['id'] ?? $object['@self']['id'] ?? $object['uuid'] ?? null);
	}//end objectId()
}//end class
