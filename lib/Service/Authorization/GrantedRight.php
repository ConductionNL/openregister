<?php

/**
 * OpenRegister GrantedRight
 *
 * One right a write would give: an action on a schema, in a register when the
 * write names one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

use OCA\OpenRegister\Db\Schema;

/**
 * An action on a schema that a grant gives.
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */
final class GrantedRight {

	/**
	 * Build the right.
	 *
	 * @param Schema      $schema   The schema the action is given on, as stored.
	 * @param string      $action   The action given.
	 * @param string|null $register The register, when the write names one.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $action,
		public readonly ?string $register=null,
	) {
	}//end __construct()

	/**
	 * The right as the refusal names it.
	 *
	 * @return array{register: string|null, schema: string, action: string} The named right.
	 *
	 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
	 */
	public function named(): array {
		$schema = $this->schema->getSlug();
		if ($schema === null || $schema === '') {
			$schema = (string)$this->schema->getId();
		}

		return ['register' => $this->register, 'schema' => $schema, 'action' => $this->action];
	}//end named()
}//end class
