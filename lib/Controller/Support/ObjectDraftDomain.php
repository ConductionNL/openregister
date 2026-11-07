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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\DraftActivationInterface;

/**
 * Services of the object draft API.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
 */
final class ObjectDraftDomain {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface  $registers  Resolves the register.
	 * @param SchemaMapperInterface    $schemas    Resolves the schema.
	 * @param ObjectOperationsInterface   $objects    Resolves the record through the RBAC boundary.
	 * @param DraftActivationInterface $activation Makes the draft definitive.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-drafts-owner-must-be-able-to-make-it-definitive-through-the-object-api
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registers,
		public readonly SchemaMapperInterface $schemas,
		public readonly ObjectOperationsInterface $objects,
		public readonly DraftActivationInterface $activation,
	) {
	}//end __construct()
}//end class
