<?php

/**
 * OpenRegister GrantHolder.
 *
 * A principal named in an object's grants report, and the verbs it holds there.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Who holds a grant row in {@see ObjectGrantsReport}, and with which verbs.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class GrantHolder {

	/**
	 * Constructor.
	 *
	 * @param string   $type        The principal type (`user`, `group`, `remote`, ...).
	 * @param string   $principalId The principal id.
	 * @param string[] $verbs       The verbs the principal holds.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $principalId,
		public readonly array $verbs,
	) {
	}//end __construct()
}//end class
