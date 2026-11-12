<?php

/**
 * OpenRegister CodedValueRules
 *
 * The part of a {@see CodedPropertyDeclaration} that says what a coded value
 * is written as and which concepts may be written at all: the storage form,
 * whether a deprecated concept is still accepted and whether only leaves are.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

/**
 * How a coded value is stored and which concepts it may hold.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final readonly class CodedValueRules {

	/**
	 * Constructor.
	 *
	 * @param string  $store           Whether values are stored as `uri` or as `notation`.
	 * @param boolean $allowDeprecated Whether a deprecated concept may still be written.
	 * @param boolean $leafOnly        Whether only leaf concepts may be written.
	 */
	public function __construct(
		public string $store = 'uri',
		public bool $allowDeprecated = false,
		public bool $leafOnly = false,
	) {

	}//end __construct()
}//end class
