<?php

/**
 * Write-through of business state to the register object.
 *
 * The register is the record; the engine is not. The status a plan reaches
 * and the result a case finishes in are written onto the anchoring object
 * through the ORDINARY object-write path, so `x-openregister-lifecycle`
 * governs them, readOnly enforcement applies and `object.updated` fires as
 * for any other write. One-directional: nothing here ever reads an object
 * write back as a plan-item transition.
 *
 * The field mapping comes from the plan's settings (`writeThrough`); a plan
 * without one mirrors nothing, deliberately, rather than guessing a field.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Mirrors status and result onto the anchoring object.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
 */
class CaseBusinessStateWriter {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The ordinary object write path.
	 */
	public function __construct(
		private readonly ObjectService $objects,
	) {

	}//end __construct()

	/**
	 * A milestone was reached: mirror it as the object's status.
	 *
	 * @param CaseItem $milestone The completed milestone.
	 *
	 * @return boolean True when a write happened; false when the plan maps no status field.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function mirrorStatus(CaseItem $milestone): bool {
		$mapping = $this->mapping(settings: ($milestone->getPlanSettings() ?? []));
		$statusField = trim((string)($mapping['statusField'] ?? ''));
		if ($statusField === '') {
			return false;
		}

		$data = [$statusField => (string)($milestone->getName() ?? $milestone->getItemKey())];
		$atField = trim((string)($mapping['statusAtField'] ?? ''));
		if ($atField !== '') {
			$data[$atField] = (new DateTime())->format('c');
		}

		$this->patch(item: $milestone, data: $data);

		return true;
	}//end mirrorStatus()

	/**
	 * The case finished: mirror its result.
	 *
	 * @param CaseItem $anyRow Any row of the plan (for the anchor and the settings).
	 * @param string $result The result, already checked against the allowed set.
	 *
	 * @return boolean True when a write happened; false when the plan maps no result field.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function mirrorResult(CaseItem $anyRow, string $result): bool {
		$mapping = $this->mapping(settings: ($anyRow->getPlanSettings() ?? []));
		$resultField = trim((string)($mapping['resultField'] ?? ''));
		if ($resultField === '') {
			return false;
		}

		$data = [$resultField => $result];
		$atField = trim((string)($mapping['resultAtField'] ?? ''));
		if ($atField !== '') {
			$data[$atField] = (new DateTime())->format('c');
		}

		$this->patch(item: $anyRow, data: $data);

		return true;
	}//end mirrorResult()

	/**
	 * The `writeThrough` mapping of a plan's settings.
	 *
	 * @param array<string, mixed> $settings The plan settings.
	 *
	 * @return array<string, mixed> The mapping, or [] when none.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	private function mapping(array $settings): array {
		$mapping = ($settings['writeThrough'] ?? null);
		if (is_array($mapping) === false) {
			return [];
		}

		return $mapping;
	}//end mapping()

	/**
	 * One partial write through the ordinary path.
	 *
	 * @param CaseItem $item The row naming the anchor.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	private function patch(CaseItem $item, array $data): void {
		$this->objects->patchObject(
			objectId: (string)$item->getObjectUuid(),
			data: $data,
			register: $item->getRegisterId(),
			schema: $item->getSchemaId(),
			_rbac: false
		);
	}//end patch()
}//end class
