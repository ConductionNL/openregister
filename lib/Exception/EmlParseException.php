<?php

/**
 * EmlParseException
 *
 * Thrown by EmlParser when an EML file cannot be parsed due to irrecoverable
 * malformation. The exception message identifies the parse-failure point but
 * MUST NOT contain PII (email addresses, display names, subject content,
 * body content, or attachment filenames) per ADR-005.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Exception for irrecoverable EML parse failures.
 *
 * ParseEmlStructured() throws this on malformed input. ExtractEml() catches
 * it, logs the sanitised message (no PII), and returns null to match the
 * existing TextExtractionService failure pattern.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
 */
class EmlParseException extends Exception
{
    /**
     * Constructor.
     *
     * @param string         $message  Sanitised parse failure description (no PII).
     * @param int            $code     Error code (default 0).
     * @param Throwable|null $previous Underlying cause.
     *
     * @spec openspec/changes/text-extraction-eml/tasks.md#task-2.1
     */
    public function __construct(
        string $message,
        int $code=0,
        ?Throwable $previous=null
    ) {
        parent::__construct(message: $message, code: $code, previous: $previous);
    }//end __construct()
}//end class
