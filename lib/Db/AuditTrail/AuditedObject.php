<?php

/**
 * OpenRegister AuditedObject
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
 * What one audit row is about: the register, schema and object it touched,
 * each only when known.
 *
 * Pure data: the audit trail mapper writes it onto the row
 * ({@see \OCA\OpenRegister\Db\AuditTrailMapper::stampTarget()}).
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final readonly class AuditedObject {

	/**
	 * Constructor.
	 *
	 * @param int|null    $register   Register id, when known.
	 * @param int|null    $schema     Schema id, when known.
	 * @param int|null    $object     Object id, when known.
	 * @param string|null $objectUuid Object uuid, when known.
	 */
	public function __construct(
		public ?int $register = null,
		public ?int $schema = null,
		public ?int $object = null,
		public ?string $objectUuid = null,
	) {
	}//end __construct()
}//end class
