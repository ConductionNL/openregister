<?php
/**
 * SchemaAccessChangeLogInterface: where Core records a change to a schema's authorization.
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

use OCA\OpenRegister\Service\AuthorizationChange;

/**
 * Records a schema's authorization change after Core has written it.
 *
 * Core tells; the authorization audit (Compliance) records and is bound to this contract.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
interface SchemaAccessChangeLogInterface {

	/**
	 * The event type every authorization-change log line carries, so a reader can filter on it.
	 *
	 * @var string
	 */
	public const EVENT_TYPE = 'openregister_authorization';

	/**
	 * Record a change to a schema's authorization.
	 *
	 * @param AuthorizationChange $change The schema and its old and new authorization.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function recordSchemaChange(AuthorizationChange $change): void;
}//end interface
