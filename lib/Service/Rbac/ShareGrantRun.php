<?php

/**
 * One read of a caller's principal shares into a grant map.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\Share\IManager;

/**
 * The share manager and the caller, the share type being read, and the grant
 * map built so far.
 *
 * Built and consumed by ObjectGrantResolver only; it never leaves that class.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class ShareGrantRun {

	/**
	 * The share type being read, one of ObjectGrantResolver::PRINCIPAL_SHARE_TYPES.
	 *
	 * @var integer
	 */
	public int $shareType = 0;

	/**
	 * Object uuid to the widest permission bitmask granted on it.
	 *
	 * @var array<string, int>
	 */
	public array $granted = [];

	/**
	 * Start the run.
	 *
	 * @param IManager $manager Core share manager.
	 * @param string   $userId  The caller.
	 */
	public function __construct(
		public readonly IManager $manager,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
