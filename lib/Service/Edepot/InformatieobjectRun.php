<?php

/**
 * OpenRegister Informatieobject Run
 *
 * One MDTO informatieobject document being written: its element, the object
 * it describes and that object's core archival facts.
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
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DOMElement;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The run of {@see MdtoXmlGenerator::generate()}: the steps that fill the
 * informatieobject take it instead of the element, the object and the facts.
 *
 * A run object (ADR-011 Rule 3): it never leaves MdtoXmlGenerator.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-generate-mdto-compliant-xml-metadata-per-object
 */
final class InformatieobjectRun {

	/**
	 * Constructor.
	 *
	 * @param DOMElement           $root   The informatieobject element.
	 * @param ObjectEntity         $object The object it describes.
	 * @param array<string, mixed> $facts  The object's core archival facts ({@see MdtoSourceReader::coreFacts()}).
	 */
	public function __construct(
		public readonly DOMElement $root,
		public readonly ObjectEntity $object,
		public readonly array $facts,
	) {
	}//end __construct()
}//end class
