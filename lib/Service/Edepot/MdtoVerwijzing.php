<?php

/**
 * OpenRegister MDTO Verwijzing
 *
 * One MDTO reference: the value of a `verwijzingGegevens` element.
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
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

/**
 * One MDTO reference (`verwijzingGegevens`): the name of the referenced
 * object and, optionally, its key and the source within which that key is
 * unique.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */
final class MdtoVerwijzing {

	/**
	 * Name the reference.
	 *
	 * @param string      $naam    The name of the referenced object.
	 * @param string|null $kenmerk The reference key, omitted with its bron when null or empty.
	 * @param string|null $bron    The source within which the key is unique.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function __construct(
		public readonly string $naam,
		public readonly ?string $kenmerk=null,
		public readonly ?string $bron=null,
	) {
	}//end __construct()
}//end class
