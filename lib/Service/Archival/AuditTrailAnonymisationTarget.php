<?php

/**
 * The audit trail as a store an anonymisation must reach.
 *
 * Design D-5: the trail keeps the fact and loses the values. The values of
 * the anonymised properties are taken out of every stored diff of the record
 * (by {@see AuditDiffRedactionTarget}), an entry recording the act is added in
 * their place, and the chain is re-sealed over the result so it verifies
 * afterwards and the removal is itself on it.
 *
 * ADR-003 Rule 4's order is kept: the chain is verified before anything is
 * rewritten, and that verdict is written into the entry, because a re-seal
 * makes a chain that was already broken verify too, and the one moment its
 * true state can still be recorded is before the rewrite.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail\AuditDiffStoreInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\AuditResealInterface;
use RuntimeException;
use Symfony\Component\Uid\UuidV4;

/**
 * Redacts one record's stored diffs, records the act and re-seals the chain.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
 */
final class AuditTrailAnonymisationTarget implements AnonymisationTarget {

	/**
	 * The action of the entry that records the act.
	 *
	 * @var string
	 */
	public const ACTION = 'archival.anonymise';

	/**
	 * The diffs that change, by audit row id; filled by prepare().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $redacted = [];

	/**
	 * The chain verdict taken before anything is rewritten; filled by prepare().
	 *
	 * @var array{valid: bool, brokenAt: int|null}|null
	 */
	private ?array $verdict = null;

	/**
	 * Constructor.
	 *
	 * @param AuditDiffStoreInterface  $store     The record's stored diffs.
	 * @param AuditResealInterface     $chain     The hash chain.
	 * @param AnonymisationAuthority   $authority Who runs the act and under which decision.
	 * @param int|null                 $objectId  The record's database id, put on the entry when known.
	 * @param AuditDiffRedactionTarget $redaction How a diff loses its values.
	 */
	public function __construct(
		private readonly AuditDiffStoreInterface $store,
		private readonly AuditResealInterface $chain,
		private readonly AnonymisationAuthority $authority,
		private readonly ?int $objectId = null,
		private readonly AuditDiffRedactionTarget $redaction = new AuditDiffRedactionTarget(),
	) {
	}//end __construct()

	/**
	 * What this target is.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function name(): string {
		return 'the audit trail';
	}//end name()

	/**
	 * Read the diffs, redact them in memory, check the redaction and take the chain's verdict.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a redacted diff would still hold a removed value.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function prepare(AnonymisationPlan $plan): void {
		$this->redacted = [];
		$afterwards     = [];
		$removed        = array_values(array_intersect_key($plan->before, array_flip($plan->changedProperties)));
		foreach ($this->store->diffsOfObject(objectUuid: $plan->objectUuid) as $id => $changed) {
			$redacted = $this->redaction->redact(changed: $changed, changedProperties: $plan->changedProperties);
			$afterwards[(int)$id] = $redacted;
			if ($redacted !== $changed) {
				$this->redacted[(int)$id] = $redacted;
				$removed = array_merge($removed, $this->formerValues(changed: $changed, plan: $plan));
			}
		}

		$this->assertClean(plan: $plan, diffs: $afterwards, removed: $removed);
		$this->verdict = $this->verdictFrom(firstId: $this->firstRedactedId());
	}//end prepare()

	/**
	 * Every value an anonymised property held in one stored diff, on either side.
	 *
	 * The record's current value is not the only one to look for: a name that
	 * was corrected years ago is still a name, and it is in the trail exactly
	 * because it once was the value.
	 *
	 * @param array<string, mixed> $changed The stored diff, before redaction.
	 * @param AnonymisationPlan    $plan    The plan.
	 *
	 * @return list<mixed> The values.
	 */
	private function formerValues(array $changed, AnonymisationPlan $plan): array {
		$values = [];
		foreach (array_intersect_key($changed, array_flip($plan->changedProperties)) as $entry) {
			if (is_array($entry) === false) {
				$values[] = $entry;
				continue;
			}

			array_push($values, ...array_values($entry));
		}

		return $values;
	}//end formerValues()

	/**
	 * Refuse when a stored diff of the record would still hold a value the anonymisation removes.
	 *
	 * A value the anonymised record itself still carries (in a kept property)
	 * is not counted: the trail holding it re-identifies nobody the record does
	 * not. The message counts the values and never quotes them, because the
	 * refusal is logged and the values are what is being removed.
	 *
	 * @param AnonymisationPlan                $plan    The plan.
	 * @param array<int, array<string, mixed>> $diffs   Every diff of the record as it will be stored, by row id.
	 * @param list<mixed>                      $removed Every value the anonymised properties held.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a diff would still hold a removed value.
	 */
	private function assertClean(AnonymisationPlan $plan, array $diffs, array $removed): void {
		$removed = array_values(array_unique(array_filter($removed, fn (mixed $value): bool => $this->isTraceable(value: $value))));
		$allowed = $this->redaction->leftovers(redacted: $plan->after, removed: $removed);

		foreach ($diffs as $id => $diff) {
			$left = array_diff($this->redaction->leftovers(redacted: $diff, removed: $removed), $allowed);
			if ($left !== []) {
				throw new RuntimeException(
					sprintf('audit row %d would still hold %d removed value(s) after redaction, under a property the profile does not name', $id, count($left))
				);
			}
		}
	}//end assertClean()

	/**
	 * Whether a removed value is worth searching the trail for.
	 *
	 * Text of three characters or more. A number, a boolean or a one-letter
	 * value turns up inside every timestamp and id of a diff, so searching for
	 * it would refuse every anonymisation for a coincidence, and on its own it
	 * names nobody. The redaction itself still takes every value of a named
	 * property out, whatever its type; this is only the self-check.
	 *
	 * @param mixed $value A removed value.
	 *
	 * @return bool True when the value is searched for.
	 */
	private function isTraceable(mixed $value): bool {
		return is_string($value) === true && mb_strlen(trim($value)) >= 3;
	}//end isTraceable()

	/**
	 * The first audit row the act rewrites, or null when it rewrites none.
	 *
	 * @return int|null The row id.
	 */
	private function firstRedactedId(): ?int {
		if ($this->redacted === []) {
			return null;
		}

		return min(array_keys($this->redacted));
	}//end firstRedactedId()

	/**
	 * The chain's verdict from the first rewritten row on, before the rewrite.
	 *
	 * @param int|null $firstId The first row to be rewritten, or null when none is.
	 *
	 * @return array{valid: bool, brokenAt: int|null}|null The verdict, or null when nothing is rewritten.
	 */
	private function verdictFrom(?int $firstId): ?array {
		if ($firstId === null) {
			return null;
		}

		$report = $this->chain->verifyChain(from: $firstId);

		return ['valid' => $report['valid'], 'brokenAt' => $report['brokenAt']];
	}//end verdictFrom()

	/**
	 * Rewrite the diffs, record the act and re-seal the chain over both.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return void
	 *
	 * @throws \Throwable When a write or the re-seal fails; the run then reports the act unfinished.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function apply(AnonymisationPlan $plan): void {
		foreach ($this->redacted as $id => $diff) {
			$this->store->rewriteDiff(id: $id, changed: $diff);
		}

		$entry = $this->store->recordAct(entry: $this->actEntry(plan: $plan));

		$this->chain->resealFrom(fromId: ($this->firstRedactedId() ?? (int)$entry->getId()));
	}//end apply()

	/**
	 * The entry that records the act: what it covered, who ran it, under which decision.
	 *
	 * @param AnonymisationPlan $plan The plan.
	 *
	 * @return AuditTrail The entry, not yet inserted.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/gdpr-data-subject-rights/spec.md#requirement-the-trail-keeps-the-act-and-loses-the-values-req-aao-006
	 */
	public function actEntry(AnonymisationPlan $plan): AuditTrail {
		$changed = $this->redaction->entryFor(plan: $plan);
		$changed['anonymisation']['decision']         = $this->authority->decision;
		$changed['anonymisation']['redactedEntries']  = array_keys($this->redacted);
		$changed['anonymisation']['chainBeforeRedaction'] = $this->verdict;

		$entry = new AuditTrail();
		$entry->setUuid((string)new UuidV4());
		$entry->setAction(self::ACTION);
		$entry->setObjectUuid($plan->objectUuid);
		if ($this->objectId !== null) {
			$entry->setObject($this->objectId);
		}

		$entry->setUser($this->authority->actorId);
		$entry->setUserName($this->authority->actorName);
		$entry->setChanged($changed);
		$entry->setCreated(new DateTime());

		return $entry;
	}//end actEntry()
}//end class
