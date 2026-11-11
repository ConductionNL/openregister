<?php

/**
 * Who acts on a delegation grant, and whether they are an administrator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Delegation;

/**
 * The person answering or withdrawing a grant: their uid, and whether they are an administrator.
 *
 * A fact about the caller, decided at the HTTP boundary. Whether that caller
 * may answer or withdraw a given grant is decided by DelegationConsentService,
 * never here.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class DelegationCaller {

	/**
	 * The caller's uid.
	 *
	 * @var string
	 */
	public readonly string $uid;

	/**
	 * Whether the caller is an administrator.
	 *
	 * @var boolean
	 */
	public readonly bool $isAdmin;

	/**
	 * Name the caller.
	 *
	 * @param string  $uid     The caller's uid.
	 * @param boolean $isAdmin Whether the caller is an administrator.
	 */
	public function __construct(string $uid, bool $isAdmin) {
		$this->uid     = $uid;
		$this->isAdmin = $isAdmin;
	}//end __construct()
}//end class
