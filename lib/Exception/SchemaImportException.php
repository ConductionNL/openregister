<?php

/**
 * SchemaImportException.
 *
 * Thrown by the standards importers when an import cannot be performed:
 * an unknown type reference, an unsupported/undetectable dialect, or a
 * malformed source document. Carries an HTTP status hint (404 for unknown
 * references, 422 for undetectable input, 400 otherwise) so controllers can
 * map it to the correct response without re-classifying the message.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;
use Throwable;

/**
 * Signals a failed standards import, with an HTTP status hint.
 */
class SchemaImportException extends RuntimeException
{
    /**
     * Constructor.
     *
     * @param string         $message    The human-readable error.
     * @param int            $httpStatus The HTTP status the controller should return (default 400).
     * @param Throwable|null $previous   The previous throwable, if any.
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus=400,
        ?Throwable $previous=null
    ) {
        parent::__construct(message: $message, code: 0, previous: $previous);
    }//end __construct()

    /**
     * The HTTP status hint for this failure.
     *
     * @return int The HTTP status code.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }//end getHttpStatus()

    /**
     * Build a 404 "unknown reference" exception.
     *
     * @param string $reference The unresolved reference.
     * @param string $dialect   The dialect that was searched.
     *
     * @return self The exception.
     */
    public static function unknownReference(string $reference, string $dialect): self
    {
        return new self(
            sprintf('Unknown %s reference: %s', $dialect, $reference),
            404
        );
    }//end unknownReference()

    /**
     * Build a 422 "undetectable / unsupported dialect" exception.
     *
     * @param array<int, string> $supported The supported dialect keys.
     *
     * @return self The exception.
     */
    public static function undetectableDialect(array $supported): self
    {
        return new self(
            'Could not determine the schema dialect of the uploaded document. '
            .'Pass an explicit "dialect" parameter. Supported dialects: '
            .implode(', ', $supported).'.',
            422
        );
    }//end undetectableDialect()
}//end class
