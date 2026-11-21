<?php

/**
 * BpmnReading
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

/**
 * How the importer read one BPMN construct: its kind, the verdict, and what
 * the author should do about it.
 *
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnReading {

	/**
	 * Constructor.
	 *
	 * @param string $kind    The element kind.
	 * @param string $verdict One of {@see BpmnMappingReport::VERDICTS}.
	 * @param string $action  What the author should do about it, '' for nothing.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $verdict,
		public readonly string $action = '',
	) {
	}//end __construct()
}//end class
