<?php

/**
 * Everything the import could not take at face value, named by element id.
 *
 * 🔴 THE REQUIREMENT'S OWN SENTENCE IS THE POINT: "An import that loses
 * something and says nothing is the failure mode this requirement exists to
 * prevent." A BPMN file arrives from Camunda Modeler carrying constructs this
 * engine has no equivalent for. There are exactly two honest options — refuse
 * the file, or import less than it says AND say so, element by element — and
 * the one that is neither is the one that happens by default: import what you
 * understand and drop the rest, leaving an author with a flow that looks
 * complete and is not.
 *
 * 🔑 EVERY ENTRY CARRIES AN ACTION SENTENCE, not a verdict alone. "refused:
 * compensation" tells an author their file was wrong. "the engine does not
 * undo completed steps" tells them what to do instead, which is the difference
 * between a report somebody acts on and one they forward to support.
 *
 * 🔴 AND `strict` TURNS EVERY REFUSAL INTO A FAILED IMPORT WITH NO FLOW. The
 * lenient default exists because a half-imported flow an author can finish is
 * usually worth more than a rejection — but only when the losses are visible.
 * Strict is for the caller who would rather have nothing than something they
 * have to audit.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

use JsonSerializable;

/**
 * The lossy-mapping report an import returns beside the flow.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class BpmnMappingReport implements JsonSerializable {

	/**
	 * The construct has a faithful equivalent.
	 *
	 * @var string
	 */
	public const MAPPED = 'mapped';

	/**
	 * The construct was imported with reduced semantics.
	 *
	 * @var string
	 */
	public const APPROXIMATED = 'approximated';

	/**
	 * The construct has no honest mapping and was dropped.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * The three verdicts, and there is no fourth.
	 *
	 * A closed set on purpose: "handled in exactly one of three declared ways"
	 * is the requirement, and a fourth verdict invented at a call site is how
	 * a fourth way of losing something appears.
	 *
	 * @var array<int, string>
	 */
	public const VERDICTS = [self::MAPPED, self::APPROXIMATED, self::REFUSED];

	/**
	 * The entries, in the order the file presented them.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $entries = [];

	/**
	 * Record one construct's reading.
	 *
	 * 🔴 AN ENTRY WITHOUT AN ELEMENT ID IS REFUSED BY THIS METHOD. A report
	 * saying "an unsupported construct was dropped" without saying WHICH one
	 * is a report an author cannot act on, and it reads as though the importer
	 * is unsure rather than the file being unusual.
	 *
	 * @param string $elementId The BPMN element id.
	 * @param string $kind      The element kind.
	 * @param string $verdict   One of {@see self::VERDICTS}.
	 * @param string $action    What the author should do about it.
	 *
	 * @return bool True when the entry was recorded.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function record(string $elementId, string $kind, string $verdict, string $action = ''): bool {
		if (trim($elementId) === '' || in_array($verdict, self::VERDICTS, true) === false) {
			return false;
		}

		$this->entries[] = [
			'elementId' => $elementId,
			'kind' => $kind,
			'verdict' => $verdict,
			'action' => $action,
		];

		return true;
	}//end record()

	/**
	 * Every entry, in file order.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function entries(): array {
		return $this->entries;
	}//end entries()

	/**
	 * The entries carrying one verdict.
	 *
	 * @param string $verdict The verdict.
	 *
	 * @return array<int, array<string, string>> The entries.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function withVerdict(string $verdict): array {
		return array_values(
			array_filter($this->entries, static fn (array $e): bool => ($e['verdict'] === $verdict))
		);
	}//end withVerdict()

	/**
	 * Whether anything was lost, at any level.
	 *
	 * An APPROXIMATION counts as a loss. It is the verdict most likely to be
	 * read as "fine": the construct did import, and only the sentence beside
	 * it says the semantics are narrower than the file's.
	 *
	 * @return bool True when something was approximated or refused.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function lostSomething(): bool {
		return ($this->withVerdict(verdict: self::APPROXIMATED) !== []
			|| $this->withVerdict(verdict: self::REFUSED) !== []);
	}//end lostSomething()

	/**
	 * Whether a `strict` import must fail on this report.
	 *
	 * Strict fails on a REFUSAL, not on an approximation: an approximation is
	 * a construct that imported, with its narrowing stated, and failing the
	 * whole file for one would make strict unusable on the files people
	 * actually have.
	 *
	 * @return bool True when strict must refuse the import.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function failsStrict(): bool {
		return ($this->withVerdict(verdict: self::REFUSED) !== []);
	}//end failsStrict()

	/**
	 * The counts a caller renders at the top of the report.
	 *
	 * @return array<string, int> Verdict to count.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function summary(): array {
		$summary = [];
		foreach (self::VERDICTS as $verdict) {
			$summary[$verdict] = count($this->withVerdict(verdict: $verdict));
		}

		return $summary;
	}//end summary()

	/**
	 * The report as the endpoint returns it.
	 *
	 * @return array<string, mixed> The serialised report.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'summary' => $this->summary(),
			'lostSomething' => $this->lostSomething(),
			'entries' => $this->entries,
		];
	}//end jsonSerialize()
}//end class
