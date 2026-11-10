<?php

/**
 * OpenRegister SharedMasterDataRefusal
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
 * A write refused because the resource is shared master data another organisation holds.
 *
 * The operation object of AuditTrailMapper::recordSharedMasterDataRefusal().
 */
final class SharedMasterDataRefusal {

	/**
	 * Constructor.
	 *
	 * @param string                         $holderOrganisation UUID of the organisation that holds the shared resource.
	 * @param string                         $sourceOrganisation UUID of the organisation that attempted the write.
	 * @param string                         $resourceType       Either `register` or `schema`.
	 * @param SharedMasterDataRefusalOptions $options            The redacted context, the target and the actor, when known.
	 */
	public function __construct(
		public readonly string $holderOrganisation,
		public readonly string $sourceOrganisation,
		public readonly string $resourceType,
		public readonly SharedMasterDataRefusalOptions $options = new SharedMasterDataRefusalOptions(),
	) {
	}//end __construct()
}//end class
