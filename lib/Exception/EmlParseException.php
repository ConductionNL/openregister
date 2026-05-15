<?php

/**
 * OpenRegister EmlParseException.
 *
 * Thrown by `EmlParser::parse()` on irrecoverable malformed input.
 * The structured-parse path MUST throw (not return null or a partial
 * `EmlStructure`) so downstream consumers — notably DocuDesk's
 * `eml-pdf-assembly` — can drive their fallback paths via exception
 * propagation. The exception's message MUST NOT contain PII per
 * ADR-005; only structural failure information.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md
 *       "Malformed input MUST NOT throw from `extractEml`; `parseEmlStructured` MUST throw a typed exception"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Irrecoverable EML parse failure.
 */
class EmlParseException extends Exception
{
}//end class
