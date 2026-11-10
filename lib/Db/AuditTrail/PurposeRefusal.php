<?php

/**
 * OpenRegister PurposeRefusal
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
 * A read refused because no usable purpose (doelbinding) was named.
 *
 * The operation object of AuditTrailMapper::recordPurposeRefusal().
 */
final class PurposeRefusal {

	/**
	 * Constructor.
	 *
	 * @param string        $rule    Which refusal it was, one of PurposeRefusedException's RULE_ constants.
	 * @param string|null   $purpose The purpose the caller named, or null when none was.
	 * @param AuditedObject $target  The register and schema that were being read, when known.
	 */
	public function __construct(
		public readonly string $rule,
		public readonly ?string $purpose = null,
		public readonly AuditedObject $target = new AuditedObject(),
	) {
	}//end __construct()
}//end class
