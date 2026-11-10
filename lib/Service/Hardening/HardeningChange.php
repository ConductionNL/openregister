<?php

/**
 * One administered hardening change, as the audit trail records it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

/**
 * The control a change was asked for, with its value before and the value asked for.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class HardeningChange {

	/**
	 * Constructor.
	 *
	 * @param string           $control The control identifier.
	 * @param int|string|array $before  The value before.
	 * @param int|string|array $after   The value asked for.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $control,
		public readonly int|string|array $before,
		public readonly int|string|array $after,
	) {

	}//end __construct()
}//end class
