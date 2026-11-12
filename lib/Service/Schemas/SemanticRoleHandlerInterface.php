<?php

/**
 * SemanticRoleHandlerInterface: what callers outside the Service component ask of SemanticRoleHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Semantic property roles and help texts, as the schemas controller and the
 * assignee-follow listener use them. Only the methods those callers call.
 */
interface SemanticRoleHandlerInterface {

	/**
	 * The property name holding each declared role.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 *
	 * @return array<string,string> The property name holding each role.
	 */
	public function roles(array $properties): array;

	/**
	 * The help text of each property, in the language asked for.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 * @param string              $language   The BCP-47 tag asked for.
	 *
	 * @return array<string,string> The resolved help text, keyed by property name.
	 */
	public function helpTexts(array $properties, string $language): array;

	/**
	 * The refusals the role and help declarations earn.
	 *
	 * @param array<string,mixed> $properties The schema's property map.
	 *
	 * @return array<int,string> The refusals, empty when the declarations are valid.
	 */
	public function violations(array $properties): array;
}//end interface
