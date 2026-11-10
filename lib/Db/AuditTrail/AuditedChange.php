<?php

/**
 * OpenRegister AuditedChange
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One change to an object that the audit trail records: before, after, action and cascade context.
 *
 * The operation object of AuditTrailMapper::buildEntry() and recordChange().
 */
final class AuditedChange {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity|null $old            The old state of the object.
	 * @param ObjectEntity|null $new            The new state of the object.
	 * @param string|null       $action         The action to record; null infers it from which states are present.
	 * @param array|null        $cascadeContext Referential-integrity cascade context to fold into `changed`.
	 */
	public function __construct(
		public readonly ?ObjectEntity $old = null,
		public readonly ?ObjectEntity $new = null,
		public readonly ?string $action = null,
		public readonly ?array $cascadeContext = null,
	) {
	}//end __construct()
}//end class
