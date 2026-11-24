<?php
/**
 * DeclaredGroupRightsInterface: the rights a configuration document gives its declared groups.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reads, from a configuration document, the rights each shipped register and schema
 * gives the groups the app declares (Core).
 *
 * The configuration import (Configuration) stores the result under PREFIX . appId,
 * so the reconciler can restore those rights later.
 */
interface DeclaredGroupRightsInterface {

	/**
	 * App-config key prefix under which each app's declared group rights are stored.
	 *
	 * @var string
	 */
	public const PREFIX = 'declared_group_rights_';

	/**
	 * The declared-group rights of every schema and register in a configuration document.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return array{schemas: array<string, array<string, list<mixed>>>, registers: array<string, array<string, list<mixed>>>}
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-a-product-owned-groups-rights-are-restored-to-what-the-product-declares-req-rah-003
	 */
	public function fromDocument(array $document): array;
}//end interface
