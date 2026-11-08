<?php

/**
 * The effective access set of one object, now and at a past moment.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The effective access set of one object, now and at a past moment.
 *
 * Bound to ObjectAccessReport in ContractBindings. Every method is described on ObjectAccessReport.
 */
interface ObjectAccessReportInterface {

	/**
	 * Who holds which right on one object, and which rule put them there.
	 *
	 * @param ObjectEntity $object      The object.
	 * @param mixed        $registerRef The register it belongs to, as the request named it.
	 * @param mixed        $schemaRef   The schema it belongs to, as the request named it.
	 *
	 * @return array<string, mixed> The access set.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function setFor(ObjectEntity $object, mixed $registerRef, mixed $schemaRef): array;

	/**
	 * How that set changed, and what it was at a named moment.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string|null  $moment An ISO-8601 moment to report the set as of.
	 *
	 * @return array<string, mixed> The history.
	 *
	 * @throws \Throwable When the trail cannot be read.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function historyFor(ObjectEntity $object, ?string $moment): array;
}//end interface
