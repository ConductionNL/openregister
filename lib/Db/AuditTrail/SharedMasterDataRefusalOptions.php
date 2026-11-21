<?php

/**
 * OpenRegister SharedMasterDataRefusalOptions
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
 * The optional parts of a shared-master-data refusal: context, target and actor.
 */
final class SharedMasterDataRefusalOptions {

	/**
	 * Constructor.
	 *
	 * @param array         $context Already-redacted refusal context.
	 * @param AuditedObject $target  The register, schema and object uuid the write targeted, when known.
	 * @param NamedActor    $actor   Acting user id and pseudonymous reference; no id is a system context.
	 */
	public function __construct(
		public readonly array $context = [],
		public readonly AuditedObject $target = new AuditedObject(),
		public readonly NamedActor $actor = new NamedActor(),
	) {
	}//end __construct()
}//end class
