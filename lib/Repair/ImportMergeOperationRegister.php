<?php

/**
 * ImportMergeOperationRegister — materialises the register merges are recorded in.
 *
 * `merge_operation_register.json` is a `type: core` descriptor declaring the
 * `merge-operation` register and its `mergeOperation` schema, and it shipped
 * with no Repair step to import it. Per ADR-005 Rule 1 that means it never
 * appeared on any instance: "shipping the JSON alone does nothing at runtime."
 *
 * 🔴 THIS ONE IS NOT COSMETIC. `MergeService::merge()` writes the audit row for
 * every merge straight into it:
 *
 *     $this->objectService->saveObject(
 *         object:  $mergeOperation,
 *         options: new ObjectSaveOptions(
 *             target: new ObjectFindTarget(
 *                 register: self::MERGE_REGISTER, // 'merge-operation'
 *                 schema: self::MERGE_SCHEMA      // 'mergeOperation'
 *             )
 *         )
 *     );
 *
 * and `reverseMerge` reads the `preMergeSnapshot` back out of that row to undo
 * a merge. A register that does not exist is a merge history that does not
 * exist, and reversibility that cannot be exercised.
 *
 * FOUND BY `occ openregister:descriptors:list`, which reported `merge-operation`
 * ABSENT alongside six others. The correlation across the app's fourteen
 * descriptors was exact: every one with a Repair step was present, every one
 * without was missing. This was the only non-mock descriptor in the second
 * group — the outlier that named the defect.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Imports the merge-operation register descriptor idempotently.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportMergeOperationRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/merge_operation_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = 'Merge-operation';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Merge-operation register imported (merge-operation + mergeOperation)';

	/**
	 * Configuration identity for this descriptor.
	 *
	 * Its own app id, so the `importFromApp` version gate moves independently of
	 * the other system registers — a bump here must not be masked by one of
	 * theirs, and vice versa.
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::MERGE_OPERATION;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/register-descriptor-admin/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister merge-operation register (merge audit + reversal snapshots)';
	}//end getName()
}//end class
