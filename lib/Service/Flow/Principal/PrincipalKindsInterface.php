<?php

/**
 * PrincipalKindsInterface: which principal kinds answer to a bare name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

/**
 * Answers which registered principal kinds a stored, untyped name resolves under.
 *
 * Lets a caller outside the component (the performer typing repair step) ask
 * the question without building a {@see PrincipalReference} itself.
 */
interface PrincipalKindsInterface {

	/**
	 * Which registered kinds answer to this name, right now.
	 *
	 * @param string $name The stored string.
	 *
	 * @return array<int, string> The types that resolve it, in a stable order.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function kindsAnsweringTo(string $name): array;
}//end interface
