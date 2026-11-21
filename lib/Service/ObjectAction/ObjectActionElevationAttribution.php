<?php

/**
 * ObjectActionElevationAttribution: the declared action's open elevation, as the audit trail stamps it on a row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Kernel\Contracts\Compliance\ElevationAttributionInterface;

/**
 * Answers the audit trail's elevation question from the request-scoped frame the action run opened.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final class ObjectActionElevationAttribution implements ElevationAttributionInterface {

	/**
	 * Who elevated the write a row records, when the open frame used its register, schema and verb.
	 *
	 * @param AuditTrail $row The audit row being written.
	 *
	 * @return array{elevatedBy: string, triggeredBy: string|null, runAs: string}|null The attribution, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionElevationScope is the request-scoped frame the run opened.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
	 */
	public function attributionFor(AuditTrail $row): ?array {
		return ObjectActionElevationScope::attributionFor(
			registerId: $row->getRegister(),
			schemaId: $row->getSchema(),
			verb: (string)$row->getAction()
		);
	}//end attributionFor()
}//end class
