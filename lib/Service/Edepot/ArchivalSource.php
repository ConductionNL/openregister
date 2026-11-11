<?php

/**
 * OpenRegister Archival Source
 *
 * An object with its schema's evaluated archival annotation: every layer a declared fact is read from.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The layers an archival fact is declared in: the object's `retention` and
 * `tmlo` blocks, and the evaluated `x-openregister-archival` annotation its
 * schema supplies as the default.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
 */
final class ArchivalSource {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $object     The source object.
	 * @param array<string, mixed> $annotation The object's evaluated archival annotation, empty when it has none.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $annotation = [],
	) {
	}//end __construct()
}//end class
