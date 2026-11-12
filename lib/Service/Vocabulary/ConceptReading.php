<?php

/**
 * OpenRegister ConceptReading
 *
 * The two things every concept is judged in when an option list or tree is
 * built: the language its label is read in and the instant its validity
 * window is judged at. They always travel together, so they travel as one.
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

use DateTimeInterface;

/**
 * The language and instant a concept is read in.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final readonly class ConceptReading {

	/**
	 * Constructor.
	 *
	 * @param string            $language The BCP-47 tag to read labels in.
	 * @param DateTimeInterface $instant  The instant to judge each window at.
	 */
	public function __construct(
		public string $language,
		public DateTimeInterface $instant,
	) {

	}//end __construct()
}//end class
