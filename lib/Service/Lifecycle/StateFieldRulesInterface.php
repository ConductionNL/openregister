<?php

/**
 * StateFieldRulesInterface: the resolved state-field rules as the state-field rule listener reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The hidden, read-only and required fields of one lifecycle state, typed for
 * the listener that refuses writes against them.
 *
 * A value, not a service: the resolver returns it and nothing is bound.
 */
interface StateFieldRulesInterface {

	/**
	 * Properties kept out of the rendered object.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function getHidden(): array;

	/**
	 * Properties rendered but refused on change.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function getReadOnly(): array;

	/**
	 * Properties that may not be left empty.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function getRequired(): array;

	/**
	 * The refusal sentence the author declared for a property, when there is one.
	 *
	 * @param string $property The property name.
	 *
	 * @return string|null The declared message, or null.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	public function messageFor(string $property): ?string;
}//end interface
