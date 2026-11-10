<?php

/**
 * Writes a whole page of items through ONE `saveObjects()` call.
 *
 * Every row's uuid is decided CLIENT-SIDE before the call — generated for a
 * create, taken from the item's match value for an upsert — because the bulk
 * result comes back categorised (`saved` / `updated` / `unchanged`), not in input
 * order. Owning the uuid is what lets each output item name the row it produced
 * without guessing at a correlation, and it is what a downstream contract-commit
 * step reads as the target id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-object-write-bulk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCP\IL10N;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Service\Object\BulkSaveOptions;
use OCA\OpenRegister\Service\Object\BulkBatchDefaults;

/**
 * The bulk write path of an object-write step.
 */
final class ObjectWriteBulk {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects  Performs the bulk save.
	 * @param ObjectWritePayload        $payloads Builds each row's payload.
	 * @param IL10N                     $l10n     Translations.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectWritePayload $payloads,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Write the whole page of items.
	 *
	 * The cap is enforced against the page size BEFORE anything is written. The
	 * per-item path stops at the cap mid-page, which is recoverable because each
	 * write already happened or did not; one bulk call has no such midpoint, so
	 * the only honest cap is a refusal up front.
	 *
	 * The report is placed after output processing; see
	 * {@see ObjectWritePlan::report()}.
	 *
	 * @param array           $items The input items.
	 * @param ObjectWritePlan $plan  The step's resolved plan (create or upsert, one uuid pair for an upsert).
	 *
	 * @return array<int, array{json: array, binary: array, fromItemIndex: int}> The arguments of one output item per input item.
	 *
	 * @throws RuntimeException When the page exceeds the cap, a match value cannot
	 *                          be resolved, or the bulk save rejected rows.
	 *
	 * @spec openspec/specs/flow-object-write-bulk/spec.md
	 */
	public function write(array $items, ObjectWritePlan $plan): array {
		$this->guardPageCap(items: $items, plan: $plan);

		$page = $this->planRows(items: $items, plan: $plan);
		if ($page->rows === []) {
			return array_map(static fn (int|string $index): array => self::unwritten(page: $page, index: $index), array_keys($items));
		}

		$this->save(page: $page);

		$out = [];
		foreach (array_keys($items) as $index) {
			$out[] = $this->outputItem(page: $page, index: $index);
		}

		$plan->report(report: $this->savedCounts(result: $page->result));
		return $out;
	}//end write()

	/**
	 * Refuse a page larger than the cap.
	 *
	 * @param array           $items The input items.
	 * @param ObjectWritePlan $plan  The step's resolved plan.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the page exceeds the cap.
	 */
	private function guardPageCap(array $items, ObjectWritePlan $plan): void {
		if (count($items) <= $plan->cap) {
			return;
		}

		throw new RuntimeException(
			$this->l10n->t(
				'This bulk write of %1$s items exceeds the step\'s write cap of %2$s; nothing was written.',
				[(string)count($items), (string)$plan->cap]
			)
		);
	}//end guardPageCap()

	/**
	 * Decide which items are written and under which ids.
	 *
	 * @param array           $items The input items.
	 * @param ObjectWritePlan $plan  The step's resolved plan.
	 *
	 * @return ObjectWriteBulkPage The page.
	 *
	 * @spec openspec/specs/flow-object-write-skip-when/spec.md
	 */
	private function planRows(array $items, ObjectWritePlan $plan): ObjectWriteBulkPage {
		$page = new ObjectWriteBulkPage(items: $items, plan: $plan);
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			if ($this->payloads->template->isSkipped(path: $plan->skipWhen, json: $json) === false) {
				$payload = $this->payloads->build(plan: $plan, json: $json);
				$payload['id'] = $this->rowId(plan: $plan, json: $json);
				$page->rows[$index] = $payload;
			}
		}

		return $page;
	}//end planRows()

	/**
	 * Save the page and take in what the save returned.
	 *
	 * Validation stays ON to keep parity with the single-object path; per-object
	 * lifecycle events stay OFF because dispatching thousands of them is precisely
	 * the cost bulk mode exists to shed. The palette help says so in as many words.
	 *
	 * @param ObjectWriteBulkPage $page The page.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the bulk save rejected rows.
	 */
	private function save(ObjectWriteBulkPage $page): void {
		$page->result = $this->objects->saveObjects(
			objects: array_values($page->rows),
			options: new BulkSaveOptions(
				defaults: new BulkBatchDefaults(register: $page->plan->register, schema: $page->plan->schema),
				validation: true,
				events: false
			)
		);

		$this->guardFailures(result: $page->result);
		$page->written = $this->indexResults(result: $page->result);
	}//end save()

	/**
	 * The uuid one bulk row is written under.
	 *
	 * A create generates one — the same v4 form `saveObjects()` itself would
	 * assign — so the node knows every row's identity without re-reading. An
	 * upsert resolves the single uuid match pair against the item; a value that
	 * does not resolve fails the item rather than widening into a create, because
	 * a match key is never omitted.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param array           $json The item's record.
	 *
	 * @return string The row uuid.
	 *
	 * @throws RuntimeException When an upsert's match value cannot be resolved.
	 *
	 * @spec openspec/specs/flow-object-write-bulk/spec.md
	 */
	private function rowId(ObjectWritePlan $plan, array $json): string {
		if ($plan->operation === ObjectWriteNode::OP_CREATE) {
			return Uuid::v4()->toRfc4122();
		}

		$id = $this->upsertId(pair: ($plan->pairs[0] ?? []), json: $json);
		if ($id === '') {
			throw new RuntimeException(
				$this->l10n->t(
					'The match value for "%s" could not be resolved from the item; a match key is never omitted.',
					[(string)($plan->pairs[0]['property'] ?? 'uuid')]
				)
			);
		}

		return $id;
	}//end rowId()

	/**
	 * The trimmed value an upsert's uuid pair resolves to, or empty.
	 *
	 * @param array $pair The match pair.
	 * @param array $json The item's record.
	 *
	 * @return string The value.
	 */
	private function upsertId(array $pair, array $json): string {
		$resolved = $this->payloads->template->resolve(value: ($pair['value'] ?? null), json: $json);
		if ($resolved['resolved'] === true && is_scalar($resolved['value']) === true) {
			return trim((string)$resolved['value']);
		}

		return '';
	}//end upsertId()

	/**
	 * Refuse to report a bulk save that rejected rows as a success.
	 *
	 * `saveObjects()` never throws for a rejected row — refusals land in the
	 * result's `invalid` and `errors` buckets while the accepted rows are already
	 * written. Folding that into per-item output would be exactly the hollow
	 * success the node's third rule forbids, so the step fails loudly, naming the
	 * count, the first reason, and the fact that the accepted writes were not
	 * rolled back.
	 *
	 * `invalid` and `errors` mirror each other for safeguard rejections; the larger
	 * is taken so a bucket populated by only one path still counts.
	 *
	 * @param array $result The bulk save result.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When any row was rejected.
	 *
	 * @spec openspec/specs/flow-object-write-bulk/spec.md
	 */
	private function guardFailures(array $result): void {
		$invalid = (array)($result['invalid'] ?? []);
		$errors = (array)($result['errors'] ?? []);
		$failures = max(count($invalid), count($errors));
		if ($failures === 0) {
			return;
		}

		throw new RuntimeException(
			$this->l10n->t(
				'The bulk write rejected %1$s of its rows (first reason: %2$s); accepted rows were written and were not rolled back.',
				[(string)$failures, $this->firstReason(entries: array_merge($invalid, $errors))]
			)
		);
	}//end guardFailures()

	/**
	 * The first failure entry that says why.
	 *
	 * @param array $entries The failure entries.
	 *
	 * @return string The reason, or empty.
	 */
	private function firstReason(array $entries): string {
		foreach ($entries as $entry) {
			if (is_array($entry) === true && trim((string)($entry['error'] ?? '')) !== '') {
				return trim((string)$entry['error']);
			}
		}

		return '';
	}//end firstReason()

	/**
	 * Index the bulk result's serialised rows by uuid.
	 *
	 * @param array $result The bulk save result.
	 *
	 * @return array<string, array<string, mixed>> Serialised rows keyed by uuid.
	 */
	private function indexResults(array $result): array {
		$rows = [];
		foreach (['saved', 'updated', 'unchanged'] as $bucket) {
			$rows = array_merge($rows, array_values((array)($result[$bucket] ?? [])));
		}

		$written = [];
		foreach (array_filter($rows, 'is_array') as $row) {
			$written[self::rowUuid(row: $row)] = $row;
		}

		unset($written['']);
		return $written;
	}//end indexResults()

	/**
	 * The uuid a serialised row carries, or empty.
	 *
	 * @param array $row The serialised row.
	 *
	 * @return string The uuid.
	 */
	private static function rowUuid(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return (string)($self['uuid'] ?? $self['id'] ?? $row['uuid'] ?? $row['id'] ?? '');
	}//end rowUuid()

	/**
	 * The output item of one input item.
	 *
	 * @param ObjectWriteBulkPage $page  The saved page.
	 * @param int|string          $index The item's index.
	 *
	 * @return array{json: array, binary: array, fromItemIndex: int} The output item's arguments.
	 */
	private function outputItem(ObjectWriteBulkPage $page, int|string $index): array {
		if (array_key_exists($index, $page->rows) === false) {
			return self::unwritten(page: $page, index: $index);
		}

		$item = $page->items[$index];
		return [
			'json' => $page->plan->shaped(
				incoming: (array)($item[FlowItems::JSON] ?? []),
				written: $this->writtenRow(page: $page, index: $index)
			),
			'binary' => (array)($item[FlowItems::BINARY] ?? []),
			'fromItemIndex' => (int)$index,
		];
	}//end outputItem()

	/**
	 * The record one written row reports.
	 *
	 * The bulk result carries the serialised row when the id came back; the sent
	 * payload is the fallback so the output never goes dark.
	 *
	 * @param ObjectWriteBulkPage $page  The saved page.
	 * @param int|string          $index The item's index.
	 *
	 * @return array The record.
	 */
	private function writtenRow(ObjectWriteBulkPage $page, int|string $index): array {
		$sent = $page->rows[$index];
		$id = (string)$sent['id'];
		$json = ($page->written[$id] ?? $sent);
		unset($json['@self'], $json['id']);
		$json['uuid'] = $id;
		$json['register'] = $page->plan->registerLabel();
		$json['schema'] = $page->plan->schemaLabel();
		return $json;
	}//end writtenRow()

	/**
	 * An item exactly as it arrived.
	 *
	 * @param ObjectWriteBulkPage $page  The page.
	 * @param int|string          $index The item's index.
	 *
	 * @return array{json: array, binary: array, fromItemIndex: int} The output item's arguments.
	 */
	private static function unwritten(ObjectWriteBulkPage $page, int|string $index): array {
		$item = $page->items[$index];
		return [
			'json' => (array)($item[FlowItems::JSON] ?? []),
			'binary' => (array)($item[FlowItems::BINARY] ?? []),
			'fromItemIndex' => (int)$index,
		];
	}//end unwritten()

	/**
	 * The saved / updated / unchanged tally of a bulk save.
	 *
	 * @param array $result The bulk save result.
	 *
	 * @return array<string, array{count: int}> The report.
	 */
	private function savedCounts(array $result): array {
		return [
			'created' => ['count' => count((array)($result['saved'] ?? []))],
			'updated' => ['count' => count((array)($result['updated'] ?? []))],
			'unchanged' => ['count' => count((array)($result['unchanged'] ?? []))],
		];
	}//end savedCounts()
}//end class
