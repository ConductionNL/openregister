<?php

/**
 * HardeningFloorsInterface: what the hardening floors endpoint asks of HardeningPolicy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * The floors in force, the declared floors, and each control's baseline and comparator. Only the methods HardeningController calls.
 */
interface HardeningFloorsInterface {

	/**
	 * Every floor in force, declared or baseline.
	 *
	 * @return array<string, int> Control identifier to floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function floors(): array;

	/**
	 * Every floor this instance declared, read from storage.
	 *
	 * A stored map that cannot be decoded resolves to no declarations at all.
	 *
	 * @return array<string, int> Control identifier to declared floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function declaredFloors(): array;

	/**
	 * The shipped baseline for one control.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return int The baseline, or 0 for a control with no declaration.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function baseline(string $control): int;

	/**
	 * How a control's value is compared with its floor.
	 *
	 * @param string $control The control identifier.
	 *
	 * @return string `atLeast` or `atMost`.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public static function comparator(string $control): string;
}//end interface
