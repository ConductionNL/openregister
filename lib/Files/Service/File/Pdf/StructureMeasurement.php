<?php

/**
 * StructureMeasurement
 *
 * The structure-tree counts measured around one PDF redaction run, and the
 * conservative attestation rule that turns them into a
 * `StructurePreservation` result block.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Pdf;

use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;

/**
 * Structure counts of one redaction run.
 *
 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-002
 */
final class StructureMeasurement {
	/**
	 * Constructor.
	 *
	 * @param bool|null $rawOption The RAW caller-supplied tri-state option (distinct from
	 *                             the resolved option: needed to tell "explicit false" apart
	 *                             from "auto resolved to false because untagged", which report
	 *                             different `lossReasons`, design.md D3/REQ-ORTPR-005).
	 * @param int $tagCountBefore `/StructElem` count of the input.
	 * @param int $tagCountAfter `/StructElem` count of the output.
	 * @param bool $structureIntactAfter Whether the output is still detected as tagged.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-002
	 */
	public function __construct(
		public readonly ?bool $rawOption,
		public readonly int $tagCountBefore,
		public readonly int $tagCountAfter,
		public readonly bool $structureIntactAfter,
	) {

	}//end __construct()

	/**
	 * Resolve the tri-state option to an effective boolean (design.md D3 / REQ-ORTPR-004).
	 *
	 * Null is auto: preserve iff the input is tagged.
	 *
	 * @return bool True when preservation is in effect for this run.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-004
	 */
	public function requested(): bool {
		if ($this->rawOption === null) {
			return ($this->tagCountBefore > 0);
		}

		return $this->rawOption;

	}//end requested()

	/**
	 * Apply the conservative `preserved` attestation rule and build the
	 * `structurePreservation` result block (design.md D1 / D2, REQ-ORTPR-002).
	 *
	 * `preserved: true` is asserted ONLY when preservation was requested AND
	 * the input was tagged AND `tagCountAfter === tagCountBefore` AND the
	 * structure tree is still detected on the output AND no structured
	 * content stream was modified by the replacement. SAPP has zero
	 * marked-content (`/MCID`) awareness, so ANY content-stream mutation on
	 * a tagged document means the tag→content correspondence can no longer
	 * be guaranteed: the degradation is reported explicitly rather than
	 * presenting a pass-through as a faithful preservation.
	 *
	 * @param int $streamsModified Count of content streams the replacement touched
	 *                             (SAPP's `replace_text_in_document` stats).
	 *
	 * @return StructurePreservation The result block for this run.
	 *
	 * @spec openspec/changes/tag-preserving-redaction/specs/tag-preserving-redaction/spec.md#REQ-ORTPR-002
	 */
	public function attest(int $streamsModified): StructurePreservation {
		if ($this->rawOption === false) {
			// Explicit opt-out: no assertion of loss either, per D3; counts
			// are still measured for the truthful report.
			return $this->result(requested: false, lossReasons: []);
		}

		if ($this->tagCountBefore === 0) {
			// Preservation requested (auto or explicit true) but not
			// applicable: the input was not tagged. The resolved option is
			// false for auto+untagged and true for explicit true
			// (REQ-ORTPR-004); either way the loss is reported as "not
			// applicable", not a failure.
			return $this->result(
				requested: $this->requested(),
				lossReasons: [StructurePreservation::LOSS_REASON_INPUT_NOT_TAGGED]
			);
		}

		$lossReasons = [];
		if ($this->tagCountAfter !== $this->tagCountBefore || $this->structureIntactAfter === false) {
			$lossReasons[] = StructurePreservation::LOSS_REASON_STRUCTTREEROOT_DROPPED;
		} elseif ($streamsModified > 0) {
			$lossReasons[] = StructurePreservation::LOSS_REASON_MARKED_CONTENT_BROKEN;
		}

		return new StructurePreservation(
			requested: true,
			preserved: (count($lossReasons) === 0),
			tagCountBefore: $this->tagCountBefore,
			tagCountAfter: $this->tagCountAfter,
			lossReasons: $lossReasons
		);

	}//end attest()

	/**
	 * A not-preserved result block with these counts.
	 *
	 * @param bool $requested The resolved option.
	 * @param array<int, string> $lossReasons Machine-readable loss reasons.
	 *
	 * @return StructurePreservation The result block.
	 */
	private function result(bool $requested, array $lossReasons): StructurePreservation {
		return new StructurePreservation(
			requested: $requested,
			preserved: false,
			tagCountBefore: $this->tagCountBefore,
			tagCountAfter: $this->tagCountAfter,
			lossReasons: $lossReasons
		);

	}//end result()
}//end class
