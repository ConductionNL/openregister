<?php

/**
 * OpenRegister removed draft
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A draft that was just removed, as read before the delete, with the register and schema it lived in.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
 */
final class RemovedDraft {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $draft    The removed draft, as read before the delete.
	 * @param ?Register    $register Its register, for the slug.
	 * @param ?Schema      $schema   Its schema, for the slug.
	 */
	public function __construct(
		public readonly ObjectEntity $draft,
		public readonly ?Register $register,
		public readonly ?Schema $schema,
	) {
	}//end __construct()
}//end class
