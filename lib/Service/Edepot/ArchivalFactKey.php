<?php

/**
 * OpenRegister Archival Fact Key
 *
 * The name of one archival fact in its two spellings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * One archival fact as the two blocks name it: the abstract English key the
 * `retention` block and the schema annotation use, and TMLO's own Dutch key.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
 */
final class ArchivalFactKey {

	/**
	 * Constructor.
	 *
	 * @param string $abstractKey The English key, used on the retention block and the annotation.
	 * @param string $tmloKey     The Dutch key on the TMLO block.
	 */
	public function __construct(
		public readonly string $abstractKey,
		public readonly string $tmloKey,
	) {
	}//end __construct()
}//end class
