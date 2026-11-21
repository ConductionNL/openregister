<?php

/**
 * OpenRegister PermissionVerb
 *
 * One custom permission verb an app declares on PermissionsDeclaringEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

/**
 * A verb, the app that owns it, what it means and where it may be granted.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionVerb {

	/**
	 * Describe the verb.
	 *
	 * @param string             $verb        The verb, matching {@see PermissionsDeclaringEvent::VERB_PATTERN}.
	 * @param string             $app         The app id that owns it.
	 * @param string             $description One sentence a person can read.
	 * @param array<int, string> $levels      The scope levels it may be granted at.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly string $verb,
		public readonly string $app,
		public readonly string $description,
		public readonly array $levels = PermissionsDeclaringEvent::DEFAULT_LEVELS,
	) {
	}//end __construct()
}//end class
