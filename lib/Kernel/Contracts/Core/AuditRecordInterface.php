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

/**
 * An audit trail row, as the deletion services (Core) read it: who acted.
 *
 * Implemented by the audit trail entity (Compliance), so Core reads a
 * destruction record without naming the entity. The column accessor is served
 * by Nextcloud's Entity::__call, so it is listed as a tag here.
 *
 * @method string|null getUser()
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
interface AuditRecordInterface {
}//end interface
