<?php

/**
 * OpenRegister provenance options
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The cascade a provenance report reads, the denials it names and whether they decide yet.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ProvenanceOptions {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $blocks   Keys: object, schema, register, roleDefinitions.
	 * @param array<string, array|null> $denials  The denial per action, where there is one.
	 * @param bool                      $enforced Whether a denial changes an answer yet.
	 */
	public function __construct(
		public readonly array $blocks = [],
		public readonly array $denials = [],
		public readonly bool $enforced = true,
	) {
	}//end __construct()
}//end class
