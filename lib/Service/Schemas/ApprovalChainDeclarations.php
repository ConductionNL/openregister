<?php

/**
 * OpenRegister ApprovalChainDeclarations
 *
 * Reads the approval chains a schema body declares, and whether any of their
 * positions declares a form.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The `x-openregister-approval-chains` declaration of a schema body, read as the body states it.
 *
 * Pure reading of the schema body's own vocabulary: no service, no lookup. The
 * schema API uses it to decide whether a body has approval-position forms for
 * the Processes check to judge, so that check is only built when there is work.
 *
 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
 */
final class ApprovalChainDeclarations {

	/**
	 * The schema configuration key that declares approval chains.
	 *
	 * @var string
	 */
	public const CHAINS_KEY = 'x-openregister-approval-chains';

	/**
	 * Whether a schema body declares any position form.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return bool True when at least one `approvers` entry carries `form`.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public static function declaresForms(array $data): bool {
		foreach (self::chainsOf(data: $data) as $chain) {
			foreach ((array)($chain['approvers'] ?? []) as $approver) {
				if (is_array($approver) === true && array_key_exists('form', $approver) === true) {
					return true;
				}
			}
		}

		return false;
	}//end declaresForms()

	/**
	 * The chains a schema body declares, by key.
	 *
	 * @param array<string, mixed> $data The schema body.
	 *
	 * @return array<int|string, array<string, mixed>> The chains that are maps.
	 *
	 * @spec openspec/changes/approval-position-task-form/specs/flow-approval-consolidation/spec.md#requirement-an-approval-position-may-declare-the-form-its-task-presents
	 */
	public static function chainsOf(array $data): array {
		$chains = (((array)($data['configuration'] ?? []))[self::CHAINS_KEY] ?? null);
		if (is_array($chains) === false) {
			return [];
		}

		return array_filter($chains, 'is_array');
	}//end chainsOf()
}//end class
