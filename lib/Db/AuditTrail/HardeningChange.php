<?php

/**
 * OpenRegister HardeningChange
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * One change asked of a security control, accepted or refused.
 *
 * The operation object of AuditTrailMapper::recordHardeningChange().
 */
final class HardeningChange {

	/**
	 * Constructor.
	 *
	 * @param string           $control The control identifier.
	 * @param int|string|array $before  The value before the change.
	 * @param int|string|array $after   The value asked for.
	 * @param string           $refusal The refusal, when the change was not made.
	 */
	public function __construct(
		public readonly string $control,
		public readonly int|string|array $before,
		public readonly int|string|array $after,
		public readonly string $refusal = '',
	) {
	}//end __construct()
}//end class
