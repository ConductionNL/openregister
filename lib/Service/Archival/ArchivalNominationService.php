<?php

/**
 * What happens to a record, decided when its business use ends.
 *
 * 🔴 NOTHING NOMINATED AN OBJECT WHEN IT CLOSED. `RetentionService::
 * applyArchivalMetadata()` runs once, at creation, and returns early the moment
 * `archiefnominatie` is set, so the archival future of a record was decided
 * before anybody knew how the case would end. A resultaat is what drives a
 * selectielijst row, and a resultaat does not exist at creation.
 *
 * 🔴 AND A NOMINATION COMPUTED ON DEMAND CANNOT BE ARGUED WITH LATER, because
 * the selectielijst will have changed. So it is derived when the object reaches
 * a terminal lifecycle state, WRITTEN on the object with the row and the rule
 * that produced it, and recomputed only by an explicit act that records who
 * asked and why.
 *
 * 🔴 AN OBJECT NOBODY CAN NOMINATE IS REPORTED, NOT SKIPPED. A record with no
 * selectielijst row and no schema default has no archival future at all, and
 * silence there is the failure mode that keeps personal data past its lawful
 * term. It gets a nomination block saying `unnominatable` and why, which reads
 * back on the object, plus a warning in the log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives, writes and recomputes an object's archival nomination.
 *
 * @psalm-suppress UnusedClass
 */
class ArchivalNominationService implements ArchivalNominationServiceInterface {

	/**
	 * The selectielijst row the schema's classification points at decided it.
	 */
	public const RULE_SELECTION_LIST = 'selection_list';

	/**
	 * The schema's own archive defaults decided it.
	 */
	public const RULE_SCHEMA_DEFAULT = 'schema_default';

	/**
	 * The schema's `bewaartermijnOverride`, a deliberate local decision.
	 */
	public const RULE_LOCAL_OVERRIDE = 'local_override';

	/**
	 * The schema's `x-openregister-archival` retention block decided it.
	 *
	 * A schema that declares retention the vocabulary way has said what happens
	 * to its rows when their term runs out, and that is a nomination whether or
	 * not anybody also filled in the `archive` column.
	 */
	public const RULE_ARCHIVAL_ANNOTATION = 'archival_annotation';

	/**
	 * Why a record on a schema that asks for no archiving is not nominated.
	 *
	 * Written out rather than left implicit, because "not applicable" and "we
	 * looked in one of the two places" read identically from the outside, and
	 * the second is the bug this reason exists to make visible.
	 */
	public const NOTHING_DECLARES_ARCHIVING = 'this schema declares neither an `archive` block with '
		. '`enabled: true` nor an `x-openregister-archival` retention block, so nothing says what '
		. 'happens to its records when their business use ends';

	/**
	 * A derivation the selectielijst contributed nothing to.
	 *
	 * @var array{appraisal: null, period: null, rule: null, selectionListRow: null, source: null}
	 */
	private const NOTHING_FOUND = [
		'appraisal' => null,
		'period' => null,
		'rule' => null,
		'selectionListRow' => null,
		'source' => null,
	];

	/**
	 * Which derived value is written to which retention key, in write order.
	 *
	 * @var array<string, string>
	 */
	private const DERIVED_RETENTION_KEYS = [
		'period' => 'bewaartermijn',
		'disposalDate' => 'archiefactiedatum',
		'classification' => 'classification',
		'source' => 'selectielijstBron',
		'selectionListRow' => 'selectielijstRow',
	];

	/**
	 * Constructor.
	 *
	 * @param RetentionServiceInterface            $retentionService Owns the selectielijst lookup and the date arithmetic.
	 * @param LoggerInterface             $logger           Where an unnominatable record is reported.
	 * @param LifecycleFinalStateResolver $finalStates      Answers whether a referenced state row is an end.
	 * @param ArchivalDeclarationReader   $declarations     Reads both places a schema can declare archiving.
	 */
	public function __construct(
		private readonly RetentionServiceInterface $retentionService,
		private readonly LoggerInterface $logger,
		private readonly LifecycleFinalStateResolver $finalStates,
		private readonly ArchivalDeclarationReader $declarations,
	) {
	}//end __construct()

	/**
	 * Is this lifecycle value one the schema declares as an end?
	 *
	 * The vocabulary is `x-openregister-lifecycle.final`, the same declaration
	 * the transition engine locks moves out of. Reading it here rather than
	 * keeping a second list is the point: a state that stops being an end stops
	 * nominating on the same day.
	 *
	 * 🔴 A LIST OF STATE STRINGS CANNOT DESCRIBE A LIFECYCLE WHOSE STATES ARE
	 * ROWS. When the lifecycle field is a `$ref`, the value reaching here is the
	 * uuid of a row every tenant creates for itself, so a list written in the
	 * schema names nothing and NOTHING IS EVER TERMINAL: no error, no log, no
	 * nomination, and a closed dossier with no archival future. So `final` also
	 * takes `{ from: <schema>, field: <property> }`, resolved against the
	 * referenced row; see {@see LifecycleFinalStateResolver}. A graph-mode
	 * annotation already says the same thing in `graph.schema` and
	 * `graph.finalField`, so it is read there rather than declared twice.
	 *
	 * @param Schema $schema The object's schema.
	 * @param string $state  The lifecycle value the object just reached.
	 *
	 * @return bool True when the schema calls this state an end.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	public function isTerminalState(Schema $schema, string $state): bool {
		if (trim($state) === '') {
			return false;
		}

		$final = $this->finalDeclaration(schema: $schema);
		if ($final === null) {
			return false;
		}

		if ($this->finalStates->isReferenceForm($final) === true) {
			return $this->finalStates->isFinalByReference(declaration: $final, state: $state);
		}

		return in_array($state, $final, true);
	}//end isTerminalState()

	/**
	 * The schema's `final` declaration: an explicit one, else the graph block's.
	 *
	 * @param Schema $schema The object's schema.
	 *
	 * @return array<mixed>|null The declaration, or null when the schema declares no end.
	 */
	private function finalDeclaration(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		$annotation = ($configuration['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false) {
			return null;
		}

		$final = ($annotation['final'] ?? $this->graphFinal(annotation: $annotation));
		if (is_array($final) === false) {
			return null;
		}

		return $final;
	}//end finalDeclaration()

	/**
	 * Derive this object's archival future and write it down.
	 *
	 * The object is mutated and returned; persisting it is the caller's job, so
	 * a nomination taken during a write joins that write rather than racing it.
	 *
	 * @param ObjectEntity $object  The record whose business use has ended.
	 * @param Schema       $schema  Its schema, holding the archive block.
	 * @param string       $trigger What caused this: `closure` or `recompute`.
	 * @param string|null  $actor   Who asked, for a recomputation.
	 * @param string|null  $reason  Why they asked, for a recomputation.
	 *
	 * @return array<string, mixed> The nomination block that was written.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function nominate(ObjectEntity $object, Schema $schema, string $trigger, ?string $actor = null, ?string $reason = null): array {
		$archive = $this->declarations->read(object: $object, schema: $schema);
		if ($archive === []) {
			return ['status' => self::STATUS_NOT_APPLICABLE, 'unnominatableReason' => self::NOTHING_DECLARES_ARCHIVING];
		}

		$derived = $this->derive(object: $object, schema: $schema, archive: $archive);
		$block = $this->openBlock(status: $derived['status'], trigger: $trigger, actor: $actor, reason: $reason);
		if ($derived['status'] === self::STATUS_UNNOMINATABLE) {
			return $this->recordUnnominatable(object: $object, block: $block, reason: $derived['reason']);
		}

		$block = $this->withRule(block: $block, derived: $derived);
		$this->write(object: $object, derived: $derived, block: $block);

		return $block;
	}//end nominate()

	/**
	 * Name the rule that decided, and the selectielijst row when one did.
	 *
	 * @param array<string, mixed> $block   The nomination block so far.
	 * @param array<string, mixed> $derived The derivation.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function withRule(array $block, array $derived): array {
		$block['rule'] = $derived['rule'];
		if ($derived['selectionListRow'] !== null) {
			$block['selectionListRow'] = $derived['selectionListRow'];
		}

		return $block;
	}//end withRule()

	/**
	 * The start of a nomination block: what, when, why and by whom.
	 *
	 * @param string      $status  The derived status.
	 * @param string      $trigger What caused this: `closure` or `recompute`.
	 * @param string|null $actor   Who asked, for a recomputation.
	 * @param string|null $reason  Why they asked, for a recomputation.
	 *
	 * @return array<string, mixed> The block so far.
	 */
	private function openBlock(string $status, string $trigger, ?string $actor, ?string $reason): array {
		$block = [
			'status' => $status,
			'at' => (new DateTimeImmutable())->format('c'),
			'trigger' => $trigger,
		];

		foreach (['actor' => $actor, 'reason' => $reason] as $key => $value) {
			if ($value !== null) {
				$block[$key] = $value;
			}
		}

		return $block;
	}//end openBlock()

	/**
	 * Report and record that a record which reached its end cannot be nominated.
	 *
	 * @param ObjectEntity         $object The record.
	 * @param array<string, mixed> $block  The nomination block so far.
	 * @param string               $reason Which source was missing.
	 *
	 * @return array<string, mixed> The nomination block that was written.
	 */
	private function recordUnnominatable(ObjectEntity $object, array $block, string $reason): array {
		$block['unnominatableReason'] = $reason;

		$this->logger->warning(
			'[ArchivalNominationService] ' . (string)$object->getUuid()
			. ' reached a terminal state and cannot be nominated: ' . $reason
		);

		$this->write(object: $object, derived: [], block: $block);

		return $block;
	}//end recordUnnominatable()

	/**
	 * Decide the appraisal, the retention period and where each came from.
	 *
	 * @param ObjectEntity         $object  The record.
	 * @param Schema               $schema  Its schema.
	 * @param array<string, mixed> $archive The schema's archive block.
	 *
	 * @return array<string, mixed> The derivation, or a status of unnominatable with a reason.
	 *
	 */
	private function derive(ObjectEntity $object, Schema $schema, array $archive): array {
		// The schema's category, unless this record names its own (DECISIONS
		// row 48). The same resolver decides it at creation, so a record is
		// created and nominated under one category.
		$classification = (new ClassificationOverride())->effective(
			archive: $archive,
			data: ($object->getObject() ?? [])
		);

		$found = $this->fromSelectionList(classification: $classification);
		if ($found['appraisal'] === null) {
			$found = $this->withSchemaDefault(found: $found, archive: $archive);
		}

		if ($found['appraisal'] === null) {
			return $this->unnominatable(reason: $this->missingSourceReason(classification: $classification));
		}

		$found = $this->withLocalOverride(found: $found, archive: $archive);

		return $this->dated(object: $object, schema: $schema, found: $found, classification: $classification);
	}//end derive()

	/**
	 * What the selectielijst row for this category decides, if there is one.
	 *
	 * @param string|null $classification The effective category.
	 *
	 * @return array<string, string|null> The appraisal, period, rule, row and source; all null without a row.
	 */
	private function fromSelectionList(?string $classification): array {
		$entry = $this->entryFor(classification: $classification);
		if ($entry === null) {
			return self::NOTHING_FOUND;
		}

		return [
			'appraisal' => ArchivalText::fromStringOrInt(value: ($entry['archiefnominatie'] ?? null)),
			'period' => ArchivalText::fromStringOrInt(value: ($entry['bewaartermijn'] ?? null)),
			'rule' => self::RULE_SELECTION_LIST,
			'selectionListRow' => (ArchivalText::fromStringOrInt(value: ($entry['categorie'] ?? null)) ?? $classification),
			'source' => ArchivalText::fromStringOrInt(value: ($entry['bron'] ?? null)),
		];
	}//end fromSelectionList()

	/**
	 * The selectielijst row for a category, or null when there is no category or no row.
	 *
	 * @param string|null $classification The effective category.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function entryFor(?string $classification): ?array {
		if ($classification === null) {
			return null;
		}

		return $this->retentionService->lookupSelectielijstEntry($classification);
	}//end entryFor()

	/**
	 * Take the appraisal and period from the schema's default, when the selectielijst gave no appraisal.
	 *
	 * The row and source a selectielijst row contributed are kept.
	 *
	 * @param array<string, string|null> $found   The derivation so far.
	 * @param array<string, mixed>       $archive The schema's archive block.
	 *
	 * @return array<string, string|null> The derivation.
	 */
	private function withSchemaDefault(array $found, array $archive): array {
		$found['appraisal'] = ArchivalText::fromStringOrInt(value: ($archive['defaultNominatie'] ?? null));
		$found['period'] = ArchivalText::fromStringOrInt(value: ($archive['defaultBewaartermijn'] ?? null));
		if ($found['appraisal'] !== null) {
			// A translated `x-openregister-archival` block says so itself,
			// so the nomination names the declaration it actually came from
			// rather than crediting an `archive` column nobody filled in.
			$found['rule'] = ($archive['defaultRule'] ?? self::RULE_SCHEMA_DEFAULT);
		}

		return $found;
	}//end withSchemaDefault()

	/**
	 * Let a local override take the period, and say so.
	 *
	 * @param array<string, string|null> $found   The derivation so far.
	 * @param array<string, mixed>       $archive The schema's archive block.
	 *
	 * @return array<string, string|null> The derivation.
	 */
	private function withLocalOverride(array $found, array $archive): array {
		$override = ArchivalText::fromStringOrInt(value: ($archive['bewaartermijnOverride'] ?? null));
		if ($override !== null) {
			$found['period'] = $override;
			$found['rule'] = self::RULE_LOCAL_OVERRIDE;
		}

		return $found;
	}//end withLocalOverride()

	/**
	 * Count the disposal date, and refuse a period that cannot be counted.
	 *
	 * A record kept forever has no disposal date to count, and demanding one
	 * would make every permanently preserved dossier unnominatable.
	 *
	 * @param ObjectEntity               $object         The record.
	 * @param Schema                     $schema         Its schema.
	 * @param array<string, string|null> $found          The derivation.
	 * @param string|null                $classification The effective category.
	 *
	 * @return array<string, mixed> The derivation, or a status of unnominatable with a reason.
	 */
	private function dated(ObjectEntity $object, Schema $schema, array $found, ?string $classification): array {
		$period = $found['period'];
		$disposalDate = null;
		if ($period !== null) {
			$disposalDate = $this->retentionService->calculateArchiveActionDate(object: $object, schema: $schema, retentionPeriod: $period);
		}

		if ($this->cannotBeCounted(found: $found, disposalDate: $disposalDate) === true) {
			return $this->unnominatable(reason: 'the retention period ' . $period . ' could not be counted from any date on this record');
		}

		return ['status' => self::STATUS_NOMINATED, 'disposalDate' => $disposalDate, 'classification' => $classification] + $found;
	}//end dated()

	/**
	 * Whether a period was declared but no disposal date could be counted for a record that is not kept forever.
	 *
	 * @param array<string, string|null> $found        The derivation.
	 * @param string|null                $disposalDate The counted date, or null.
	 *
	 * @return bool True when the record cannot be nominated for it.
	 */
	private function cannotBeCounted(array $found, ?string $disposalDate): bool {
		return $found['period'] !== null
			&& $disposalDate === null
			&& in_array($found['appraisal'], Appraisal::RETAIN_PERMANENTLY_ALIASES, true) === false;
	}//end cannotBeCounted()

	/**
	 * A derivation that could not nominate, and why.
	 *
	 * @param string $reason Which source was missing.
	 *
	 * @return array{status: string, reason: string} The derivation.
	 */
	private function unnominatable(string $reason): array {
		return ['status' => self::STATUS_UNNOMINATABLE, 'reason' => $reason];
	}//end unnominatable()

	/**
	 * The reference a graph-mode annotation already declares, read as `final`.
	 *
	 * A graph block names `schema` and `finalField`: the sibling schema its
	 * states live in, and the property on a state row that marks the last one.
	 * That is the reference form written in different words, so a graph-mode
	 * schema gets the same answer without declaring `final` twice. An explicit
	 * `final` still wins, because an author who wrote one meant it.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-lifecycle` block.
	 *
	 * @return array<string, mixed>|null The reference form, or null when the annotation is not graph mode.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/object-lifecycle/spec.md
	 */
	private function graphFinal(array $annotation): ?array {
		$graph = ($annotation['graph'] ?? null);
		if (is_array($graph) === false) {
			return null;
		}

		$from = ArchivalText::fromStringOrInt(value: ($graph['schema'] ?? null));
		$field = ArchivalText::fromStringOrInt(value: ($graph['finalField'] ?? null));
		if ($from === null || $field === null) {
			return null;
		}

		return ['from' => $from, 'field' => $field];
	}//end graphFinal()

	/**
	 * Say which source was missing, rather than that one was.
	 *
	 * Both places a default can live are named, because "the schema declares no
	 * default nomination" reads as a finished sentence and sent a reader to the
	 * `archive` block alone, which is exactly the half-look that made a schema
	 * declaring retention the vocabulary way unnominatable in silence.
	 *
	 * @param string|null $classification The classification the schema declared, if any.
	 *
	 * @return string The reason.
	 */
	private function missingSourceReason(?string $classification): string {
		$where = ', and neither the schema\'s `archive` block nor its '
			. '`x-openregister-archival` retention block names a default nomination';

		if ($classification !== null) {
			return 'no selectielijst row matches category ' . $classification . $where;
		}

		return 'the schema names no selectielijst category' . $where;
	}//end missingSourceReason()

	/**
	 * Write the derivation and the nomination block onto the record.
	 *
	 * The history is appended to rather than replaced: a recomputation that
	 * overwrote the nomination it replaced would take away the one thing that
	 * explains why a disposal date moved.
	 *
	 * @param ObjectEntity         $object  The record.
	 * @param array<string, mixed> $derived The derivation, empty when unnominatable.
	 * @param array<string, mixed> $block   The nomination block.
	 *
	 * @return void
	 */
	private function write(ObjectEntity $object, array $derived, array $block): void {
		$retention = ($object->getRetention() ?? []);
		if (is_array($retention) === false) {
			$retention = [];
		}

		if ($derived !== []) {
			$retention = $this->withDerivation(retention: $retention, derived: $derived);
		}

		$retention['nomination'] = $block;
		$object->setRetention($this->withHistory(retention: $retention, block: $block));
	}//end write()

	/**
	 * Append the nomination to the history rather than replacing what it replaced.
	 *
	 * @param array<string, mixed> $retention The retention block.
	 * @param array<string, mixed> $block     The nomination block.
	 *
	 * @return array<string, mixed> The retention block.
	 */
	private function withHistory(array $retention, array $block): array {
		$history = ($retention['nominationHistory'] ?? []);
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = $block;
		$retention['nominationHistory'] = $history;

		return $retention;
	}//end withHistory()

	/**
	 * Write the derived appraisal, state and whichever derived facts are known.
	 *
	 * @param array<string, mixed> $retention The stored retention block.
	 * @param array<string, mixed> $derived   The derivation.
	 *
	 * @return array<string, mixed> The retention block.
	 */
	private function withDerivation(array $retention, array $derived): array {
		$retention['archiefnominatie'] = $derived['appraisal'];
		$retention['archiefstatus'] = RecordState::SEMI_STATIC;

		foreach (self::DERIVED_RETENTION_KEYS as $from => $to) {
			if ($derived[$from] !== null) {
				$retention[$to] = $derived[$from];
			}
		}

		return $retention;
	}//end withDerivation()
}//end class
