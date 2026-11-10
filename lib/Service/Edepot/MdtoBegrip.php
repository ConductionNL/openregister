<?php

/**
 * OpenRegister MDTO Begrip
 *
 * One MDTO term: the value of a `begripGegevens` element.
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
 * One MDTO term (`begripGegevens`): its label, the begrippenlijst it comes
 * from (the XSD makes both mandatory) and an optional code.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */
final class MdtoBegrip {

	/**
	 * Name the term.
	 *
	 * @param string      $label The begripLabel.
	 * @param string      $list  The begrippenlijst name.
	 * @param string|null $code  The begripCode, omitted when null or empty.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function __construct(
		public readonly string $label,
		public readonly string $list,
		public readonly ?string $code=null,
	) {
	}//end __construct()

	/**
	 * The term, or null when the object supplies no label for it.
	 *
	 * An optional begrip element is emitted when there is a term and omitted
	 * entirely otherwise, never written as a placeholder.
	 *
	 * @param string|null $label The begripLabel, or null when there is none.
	 * @param string      $list  The begrippenlijst name.
	 * @param string|null $code  The begripCode, omitted when null or empty.
	 *
	 * @return self|null The term, or null.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-emit-mdto-aggregatieniveau-beperkinggebruik-and-dekkingintijd-from-their-declared-sources
	 */
	public static function optional(?string $label, string $list, ?string $code=null): ?self {
		if ($label === null) {
			return null;
		}

		return new self(label: $label, list: $list, code: $code);
	}//end optional()
}//end class
