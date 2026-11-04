<?php

/**
 * OpenRegister object draft domain
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\DraftActivation;

/**
 * Services of the object draft API.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class ObjectDraftDomain {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapper  $registers  Resolves the register.
	 * @param SchemaMapper    $schemas    Resolves the schema.
	 * @param ObjectService   $objects    Resolves the record through the RBAC boundary.
	 * @param DraftActivation $activation Makes the draft definitive.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function __construct(
		public readonly RegisterMapper $registers,
		public readonly SchemaMapper $schemas,
		public readonly ObjectService $objects,
		public readonly DraftActivation $activation,
	) {
	}//end __construct()
}//end class
