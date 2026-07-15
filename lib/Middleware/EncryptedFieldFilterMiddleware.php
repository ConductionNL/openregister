<?php

/**
 * Encrypted Field Filter Middleware
 *
 * Maps an {@see EncryptedFieldFilterException} thrown anywhere in a request
 * (raised when a caller filters/searches/facets on a field flagged
 * `x-openregister-encrypted`) onto a clear HTTP 400 response, rather than
 * letting the request fall through to a default 500 or — worse — letting the
 * underlying query silently return zero rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Exception\EncryptedFieldFilterException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Middleware translating encrypted-field filter attempts into 400 responses.
 *
 * @package OCA\OpenRegister\Middleware
 */
class EncryptedFieldFilterMiddleware extends Middleware
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The app logger.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Map EncryptedFieldFilterException onto a 400 Bad Request response.
     *
     * @param Controller $controller The controller that was executing.
     * @param string     $methodName The controller method that was executing.
     * @param Exception  $exception  The exception that was thrown.
     *
     * @return Response The 400 response for an encrypted-field filter attempt.
     *
     * @throws Exception Rethrows every exception that is not an EncryptedFieldFilterException.
     *
     * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
     */
    public function afterException(Controller $controller, string $methodName, Exception $exception): Response
    {
        if ($exception instanceof EncryptedFieldFilterException === false) {
            throw $exception;
        }

        $this->logger->info(
            sprintf(
                '[EncryptedFieldFilterMiddleware] rejected filter on encrypted property "%s" in %s::%s',
                $exception->getProperty(),
                $controller::class,
                $methodName
            ),
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return new JSONResponse(
            data: [
                'error'    => 'encrypted-field-not-filterable',
                'property' => $exception->getProperty(),
                'message'  => $exception->getMessage(),
            ],
            statusCode: 400
        );
    }//end afterException()
}//end class
