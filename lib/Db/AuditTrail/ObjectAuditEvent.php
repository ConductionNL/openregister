<?php

/**
 * OpenRegister ObjectAuditEvent
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
 * A non-CRUD action on one object, recorded with an explicit actor when the caller has one.
 *
 * The operation object of AuditTrailMapper::recordObjectEvent().
 */
final class ObjectAuditEvent {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity            $object  The object the entry relates to.
	 * @param string                  $action  The action, e.g. `archival.destroyed`.
	 * @param array                   $context Additional context data.
	 * @param ObjectAuditEventOptions $options The explicit actor and calling address, when given.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $action,
		public readonly array $context = [],
		public readonly ObjectAuditEventOptions $options = new ObjectAuditEventOptions(),
	) {
	}//end __construct()
}//end class
