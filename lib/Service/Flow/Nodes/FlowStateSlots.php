<?php

/**
 * The slot pool a flow-state step claims from and releases to.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use DateTimeInterface;

/**
 * The `claim` and `release` operations of `FlowStateNode`.
 *
 * A capacity cap is a map of named slots where a free one must be taken by
 * exactly one holder. This class owns that map's shape — every slot from 1 to
 * capacity present, a free one `null`, a held one a `{holder, since, …}`
 * record — and the lease that lets an abandoned slot be taken again.
 *
 * It is safe only because a scheduled flow never overlaps itself; see
 * `FlowStateNode` for why, and for what to use when a cross-flow lock is meant.
 *
 * @spec exclude The flow-state slot contract has no canonical spec; see
 *  FlowStateSlots::leaseExpired() for the gap.
 */
class FlowStateSlots {

	/**
	 * Take a free slot, or report that none was free.
	 *
	 * Emits `claimed` and `slot` onto the item so a router can branch on them.
	 * A caller that ignores `claimed` and proceeds anyway has overrun its own
	 * cap — which is why this reports rather than throws: the flow author
	 * decides whether "no capacity" means wait, stop or escalate.
	 *
	 * WHAT A SLOT HOLDS, AND WHY IT IS A RECORD
	 * -----------------------------------------
	 * An occupied slot holds `{holder, since, …}` rather than a bare holder
	 * string, and every slot from 1 to `capacity` is present — a free one as
	 * `null`. Both are so `GET /api/flow/{flowId}/state` is answerable on its
	 * own: "what is running right now, and since when" needs a timestamp, and
	 * "slot 3 of 10 is free" needs slot 3 to EXIST. The previous shape stored a
	 * scalar and omitted unclaimed slots entirely, so a reader could not tell an
	 * empty slot from one outside the cap, and had to be told the capacity
	 * separately — which is the cap living in two places again.
	 *
	 * The table is persisted even when nothing was claimed: it may have just
	 * been materialised to full capacity for the first time, and a dashboard
	 * reading "0 of 10 free" is more useful than one reading nothing at all.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The item's json.
	 *
	 * @spec exclude The flow-state slot contract has no canonical spec.
	 */
	public function claim(FlowStateStep $step, array $json): array {
		$stored = (array)$step->state->get($step->slotsKey(), []);
		$slots = $this->slotTable(stored: $stored, capacity: (int)$step->config['capacity']);
		$free = $this->firstFreeSlot(slots: $slots, step: $step);
		if ($free !== null) {
			$slots[(string)$free] = $this->heldRecord(step: $step, json: $json);
		}

		$step->state->set($step->slotsKey(), $slots);

		$json['claimed'] = ($free !== null);
		$json['slot'] = $free;

		return $json;
	}//end claim()

	/**
	 * The lowest-numbered slot a claim may take, or null when none is free.
	 *
	 * A slot is free when it holds nothing, OR when whoever holds it has held
	 * it for longer than the configured lease. Slots are numbered from 1 so the
	 * value reads naturally in a dashboard ("slot 3 of 10").
	 *
	 * 🔑 WITHOUT A LEASE A SLOT IS LOST, NOT BORROWED. `claim` and `release`
	 * are separate steps, and everything that can happen between them happens:
	 * a node with `onError: stop`, a refusal, a crashed worker, a run that
	 * outlives its deadline. Any of those ends the run holding a slot that
	 * nothing will ever give back — and unlike the per-issue lock beside it,
	 * which `hydra-lock-reaper` ages out, nothing reaps a slot.
	 *
	 * ⚠️ THE SYMPTOM IS NOT AN ERROR, IT IS SILENCE. Once the pool is full of
	 * dead holders every claim routes to whatever the caller's "at capacity"
	 * branch is, which is a NORMAL outcome — the flow reports `completed`, the
	 * queue stops moving, and the pipeline looks busy. Measured on hydra's
	 * sequencer: one run stopped at `lock-issue` with `onError: stop` and its
	 * slot was still held hours later; three of those and that repository can
	 * never be built again.
	 *
	 * The lease is opt-in (`leaseSeconds`) and absent by default, because a
	 * caller that genuinely wants a slot held until it is released — a
	 * long-running import, a manual gate — must keep that behaviour. Where it
	 * is set, an expired holder is treated as gone rather than deleted: the
	 * claim simply overwrites the entry, so a slot recovers on the next attempt
	 * to use it and no reaper has to run at all.
	 *
	 * @param array         $slots The normalised slot table.
	 * @param FlowStateStep $step  The state handle and configuration.
	 *
	 * @return int|null The free slot's number.
	 */
	private function firstFreeSlot(array $slots, FlowStateStep $step): ?int {
		$capacity = (int)$step->config['capacity'];
		$leaseSeconds = (int)($step->config['leaseSeconds'] ?? 0);

		for ($n = 1; $n <= $capacity; $n++) {
			$entry = ($slots[(string)$n] ?? null);
			if ($entry === null || $this->leaseExpired(entry: $entry, leaseSeconds: $leaseSeconds) === true) {
				return $n;
			}
		}

		return null;
	}//end firstFreeSlot()

	/**
	 * The record a claim writes into the slot it takes.
	 *
	 * `record` names item fields to copy onto the slot, so a caller decides what
	 * "what is running" means for its own flow (hydra: stage, repo, issue).
	 * A named field the item does not carry is recorded as null rather than
	 * skipped, so the shape is the same for every slot.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The slot record.
	 */
	private function heldRecord(FlowStateStep $step, array $json): array {
		$held = [
			'holder' => null,
			'since' => (new DateTime())->format(DateTimeInterface::ATOM),
		];

		$holder = $step->holderOf(json: $json);
		if ($holder !== '') {
			$held['holder'] = $holder;
		}

		return ($held + $this->recordedFields(record: (array)($step->config['record'] ?? []), json: $json));
	}//end heldRecord()

	/**
	 * The item fields `record` names, keyed by field.
	 *
	 * `holder` and `since` are the claim's own and never copied over; a blank
	 * name is skipped.
	 *
	 * @param array $record The configured field names.
	 * @param array $json   The item's json.
	 *
	 * @return array<string, mixed> The recorded fields.
	 */
	private function recordedFields(array $record, array $json): array {
		$fields = [];
		foreach ($record as $field) {
			$field = (string)$field;
			if ($field === '' || $field === 'holder' || $field === 'since') {
				continue;
			}

			$fields[$field] = ($json[$field] ?? null);
		}

		return $fields;
	}//end recordedFields()

	/**
	 * The slot table as stored, normalised to every slot from 1 to capacity.
	 *
	 * Free slots are `null` and present. Values written by an older revision —
	 * a bare holder string, or `true` — are carried forward into the record
	 * shape rather than dropped, so a flow that was mid-run when this changed
	 * does not lose track of what it was holding.
	 *
	 * @param array $stored The stored table.
	 * @param int $capacity The configured capacity.
	 *
	 * @return array<string, array|null> The normalised table.
	 */
	private function slotTable(array $stored, int $capacity): array {
		$table = [];
		for ($n = 1; $n <= $capacity; $n++) {
			$table[(string)$n] = $this->slotRecordOrFree(value: ($stored[(string)$n] ?? null));
		}

		return $this->withSlotsBeyondCapacity(table: $table, stored: $stored);
	}//end slotTable()

	/**
	 * Keep every occupied slot the capacity no longer covers.
	 *
	 * A slot beyond the current capacity is still OCCUPIED by somebody, and
	 * dropping it would let the flow exceed a cap the operator has just
	 * lowered. Kept until it is released.
	 *
	 * @param array $table  The table normalised up to capacity.
	 * @param array $stored The stored table.
	 *
	 * @return array<string, array|null> The table with the occupied extra slots.
	 */
	private function withSlotsBeyondCapacity(array $table, array $stored): array {
		foreach ($stored as $slot => $value) {
			if (array_key_exists((string)$slot, $table) === false && $value !== null) {
				$table[(string)$slot] = $this->slotRecord(value: $value);
			}
		}

		return $table;
	}//end withSlotsBeyondCapacity()

	/**
	 * One stored slot value as a record, or null when the slot is free.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array|null The record.
	 */
	private function slotRecordOrFree(mixed $value): ?array {
		if ($value === null) {
			return null;
		}

		return $this->slotRecord(value: $value);
	}//end slotRecordOrFree()

	/**
	 * One stored slot value as a record.
	 *
	 * An array is already in shape. A scalar is what an older revision wrote —
	 * the holder string, or `true` when the claim named no holder — and becomes
	 * a record with a null `since`, because that moment was never recorded.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array The record.
	 */
	private function slotRecord(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		$holder = null;
		if ($value !== true) {
			$holder = (string)$value;
		}

		return ['holder' => $holder, 'since' => null];
	}//end slotRecord()

	/**
	 * Whether a held slot's lease has run out.
	 *
	 * Answers FALSE whenever it cannot be sure, and that direction is chosen
	 * deliberately: treating a live holder as expired would hand its slot to a
	 * second run and destroy the mutual exclusion the pool exists for, whereas
	 * treating an expired holder as live only delays a recovery. So a missing
	 * lease, an absent `since`, or a timestamp that will not parse all keep the
	 * slot held.
	 *
	 * @param mixed $entry        The stored slot value.
	 * @param int   $leaseSeconds The lease, or 0 for none.
	 *
	 * @return bool True when the holder may be displaced.
	 *
	 * @spec exclude The slot/lease contract has no canonical spec.
	 *  openspec/specs/flow-state/spec.md does not exist and never has; the tag
	 *  pointed at a file rather than at a requirement. flow-engine's spec is
	 *  the nearest home and it covers resume and suspend-on-signal, NOT slot
	 *  leases, so citing it here would be a reference that reads as coverage
	 *  and is not. Excluded with the gap named until the flow-state slot
	 *  contract is written down.
	 */
	private function leaseExpired(mixed $entry, int $leaseSeconds): bool {
		if ($leaseSeconds <= 0) {
			return false;
		}

		$taken = strtotime($this->sinceOf(entry: $entry));
		if ($taken === false) {
			return false;
		}

		return ((time() - $taken) > $leaseSeconds);

	}//end leaseExpired()

	/**
	 * When a held slot was taken, as stored, or an empty string.
	 *
	 * A slot value that is not a record, and a `since` that is absent or not a
	 * string, each answer an empty string — which does not parse, and so keeps
	 * the slot held exactly as a timestamp that will not parse does.
	 *
	 * @param mixed $entry The stored slot value.
	 *
	 * @return string The stored `since`, or an empty string.
	 */
	private function sinceOf(mixed $entry): string {
		if (is_array($entry) === false) {
			return '';
		}

		$since = ($entry['since'] ?? null);
		if (is_string($since) === false) {
			return '';
		}

		return $since;
	}//end sinceOf()

	/**
	 * Give a held slot back.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return array The item's json.
	 *
	 * @spec exclude The flow-state slot contract has no canonical spec.
	 */
	public function release(FlowStateStep $step, array $json): array {
		$slots = (array)$step->state->get($step->slotsKey(), []);

		$slot = $this->slotToRelease(step: $step, json: $json);
		if ($slot !== null) {
			$slots[$slot] = null;
			$step->state->set($step->slotsKey(), $slots);
		}

		$json['released'] = ($slot !== null);

		return $json;
	}//end release()

	/**
	 * Which slot an item gives back, or null when it holds none.
	 *
	 * By slot number when the caller knows it, otherwise by holder — a stage
	 * that crashed and is being cleaned up knows who it was, not which slot it
	 * got.
	 *
	 * @param FlowStateStep $step The state handle and configuration.
	 * @param array         $json The item's json.
	 *
	 * @return string|null The slot's key.
	 */
	private function slotToRelease(FlowStateStep $step, array $json): ?string {
		$slots = (array)$step->state->get($step->slotsKey(), []);

		$slot = ($json[(string)($step->config['slot'] ?? 'slot')] ?? null);
		if ($slot !== null && array_key_exists((string)$slot, $slots) === true) {
			return (string)$slot;
		}

		return $this->slotHeldBy(slots: $slots, holder: $step->holderOf(json: $json));
	}//end slotToRelease()

	/**
	 * The first slot the holder holds, or null.
	 *
	 * @param array  $slots  The stored slot table.
	 * @param string $holder The holder, or an empty string for none.
	 *
	 * @return string|null The slot's key.
	 */
	private function slotHeldBy(array $slots, string $holder): ?string {
		if ($holder === '') {
			return null;
		}

		foreach ($slots as $key => $value) {
			if ($this->holderOfSlot(value: $value) === $holder) {
				return (string)$key;
			}
		}

		return null;
	}//end slotHeldBy()

	/**
	 * Who holds a stored slot, or null when nobody named does.
	 *
	 * This reads INTO the slot record rather than comparing the slot's value,
	 * which is what a bare `array_search($holder, $slots)` did — correct only
	 * while a slot held the holder string itself. Now a slot holds
	 * `{holder, since, …}`, so a value comparison would never match and a
	 * crashed stage's slot would leak forever.
	 *
	 * @param mixed $value The stored slot value.
	 *
	 * @return string|null The holder.
	 */
	private function holderOfSlot(mixed $value): ?string {
		$held = $value;
		if (is_array($value) === true) {
			$held = ($value['holder'] ?? null);
		}

		if ($held === null || $held === true) {
			return null;
		}

		return (string)$held;
	}//end holderOfSlot()
}//end class
