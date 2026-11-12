<?php

/**
 * ImportDismissedPairRegister — materialises the register dismissals are recorded in.
 *
 * `dismissed_pair_register.json` is a `type: core` descriptor declaring the
 * `dismissed-pair` register and its `dismissedPair` schema. Per ADR-005 Rule 1
 * shipping the JSON alone does nothing at runtime: without a Repair step the
 * register never appears on any instance.
 *
 * 🔴 THIS ONE IS NOT COSMETIC, and its failure mode is the quiet kind.
 * `DuplicateDetectionService` asks this register which pairs a person has
 * already ruled out, and a register that does not exist answers "none". The
 * scorer then keeps offering every dismissed pair, the dismissal button
 * appears to do nothing, and nothing anywhere reports an error — which is
 * precisely the failure the capability exists to prevent, reintroduced one
 * layer down.
 *
 * Modelled on {@see ImportMergeOperationRegister}, which records the same
 * lesson: every descriptor with a Repair step was present on the instance and
 * every descriptor without one was ABSENT, and the correlation was exact.
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
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Imports the dismissed-pair register descriptor idempotently.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportDismissedPairRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/dismissed_pair_register.json';

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
	protected const LABEL = 'Dismissed-pair';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Dismissed-pair register imported (dismissed-pair + dismissedPair)';

	/**
	 * Configuration identity for this descriptor.
	 *
	 * Its own app id, so the `importFromApp` version gate moves independently of
	 * the other system registers — a bump here must not be masked by one of
	 * theirs, and vice versa.
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::DISMISSED_PAIR;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function getName(): string {
		return 'Import OpenRegister dismissed-pair register (pairs reviewed as not duplicates)';
	}//end getName()
}//end class
