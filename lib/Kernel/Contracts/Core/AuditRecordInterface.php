<?php
/**
 * AuditRecordInterface: what Core reads of an audit trail row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use DateTime;

/**
 * An audit trail row, as Core reads it: who acted, when, and what changed.
 *
 * Implemented by the audit trail entity (Compliance), so Core reads a
 * destruction record (and its tombstone), a write's intervening changes, or the writes a refused
 * save collided with, without naming the entity. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method string|null getAction()
 * @method string|null getUser()
 * @method string|null getUserName()
 * @method array|null getChanged()
 * @method DateTime|null getCreated()
 * @method string|null getObjectUuid()
 * @method int|null getRegister()
 * @method int|null getSchema()
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface AuditRecordInterface {
}//end interface
