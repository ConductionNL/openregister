<?php

/**
 * A BPMN document that is not a BPMN document.
 *
 * 🔴 THIS IS A DIFFERENT ANSWER FROM `BpmnImportRefused`, and keeping the two
 * apart is the whole point of validating at the boundary. "Your file is
 * malformed, at this element, on this line" and "we cannot express this
 * construct" are two different things to tell an author, and before the schema
 * step there was only one of them: a broken file was walked into a flow with
 * missing nodes and read as a successful import.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a document does not validate against the vendored OMG BPMN 2.0 XSD.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
class BpmnSchemaInvalid extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $message  The first violation, as a sentence.
	 * @param int            $violationLine The line it sits on, or 0 when libxml gave none.
	 * @param string         $element  The element it names, or an empty string.
	 * @param Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message,
		private readonly int $violationLine = 0,
		private readonly string $element = '',
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 422, previous: $previous);
	}//end __construct()

	/**
	 * The line the first violation sits on.
	 *
	 * 🔑 NOT `getLine()`, and the property is not `$line` either: both are
	 * `Exception`'s own, final and non-readonly, and they answer about the PHP
	 * file that threw, which is the wrong document entirely.
	 *
	 * @return int The line, or 0.
	 */
	public function getViolationLine(): int {
		return $this->violationLine;
	}//end getViolationLine()

	/**
	 * The element the first violation names.
	 *
	 * @return string The element, or an empty string.
	 */
	public function getElement(): string {
		return $this->element;
	}//end getElement()
}//end class
