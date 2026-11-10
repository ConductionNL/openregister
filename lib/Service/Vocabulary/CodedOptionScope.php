<?php

/**
 * OpenRegister CodedOptionScope
 *
 * What an option list or tree is read in: the label language, the context
 * value in play and the instant validity windows are judged at.
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
 * The options of {@see CodedOptionsBuilder::options()} and {@see CodedOptionsBuilder::tree()}.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */
final class CodedOptionScope {

	/**
	 * Constructor.
	 *
	 * @param string                 $language The BCP-47 tag to read labels in.
	 * @param string|null            $context  The context value in play, when the subset is bound; a tree ignores it.
	 * @param DateTimeInterface|null $asOf     The instant to judge windows at; now when null.
	 */
	public function __construct(
		public readonly string $language = 'nl',
		public readonly ?string $context = null,
		public readonly ?DateTimeInterface $asOf = null,
	) {
	}//end __construct()
}//end class
