<?php

/**
 * OpenRegister organisation membership change
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * One join or leave request on an organisation: which of the two, the
 * organisation, and the user it is for once the request body is read.
 *
 * Built by OrganisationController::join() and ::leave() and never handed
 * outside that class.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md
 */
final class MembershipChangeRun {

	/**
	 * A join.
	 *
	 * @var string
	 */
	public const JOIN = 'join';

	/**
	 * A leave.
	 *
	 * @var string
	 */
	public const LEAVE = 'leave';

	/**
	 * The user the change is for, from the request body; null for the caller.
	 *
	 * Set once the request body has been read.
	 *
	 * @var mixed
	 */
	public mixed $userId = null;

	/**
	 * Constructor.
	 *
	 * @param string $operation self::JOIN or self::LEAVE.
	 * @param string $uuid      The organisation UUID.
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md
	 */
	public function __construct(
		public readonly string $operation,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
