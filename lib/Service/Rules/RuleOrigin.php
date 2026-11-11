<?php

/**
 * OpenRegister RuleOrigin
 *
 * Where a rule comes from: its kind, the schema it acts on, its key and the annotation path it was read from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * The facts a rule descriptor's id is derived from (kind, schema, key), with
 * the annotation path the rule was read from.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleOrigin {

	/**
	 * Constructor.
	 *
	 * @param string $kind       One of the RuleVocabulary KIND_ constants.
	 * @param string $schemaSlug The slug of the schema the rule acts on.
	 * @param string $key        The rule's own key within its kind.
	 * @param string $source     The annotation path the rule was read from.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $schemaSlug,
		public readonly string $key,
		public readonly string $source,
	) {
	}//end __construct()
}//end class
