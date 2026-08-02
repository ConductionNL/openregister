<?php

/**
 * OpenRegister UnknownMetadataFieldMiddleware
 *
 * Translates an unresolvable `@self` metadata filter into a 400 response.
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
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use Exception;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;

/**
 * Middleware translating unknown `@self` metadata filters into 400 responses.
 *
 * Without this the field name reached the database driver and came back as an
 * opaque HTTP 500, which reads like a server fault rather than a correctable
 * query mistake.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
 */
class UnknownMetadataFieldMiddleware extends Middleware
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
     * Map UnknownMetadataFieldException onto a 400 Bad Request response.
     *
     * @param Controller $controller The controller that was executing.
     * @param string     $methodName The controller method that was executing.
     * @param Exception  $exception  The exception that was thrown.
     *
     * @return Response The 400 response naming the unknown metadata field.
     *
     * @throws Exception Rethrows every exception that is not an UnknownMetadataFieldException.
     *
     * @spec openspec/specs/zoeken-filteren/spec.md#requirement-self-metadata-filters-support-comparison-operators
     */
    public function afterException(Controller $controller, string $methodName, Exception $exception): Response
    {
        if ($exception instanceof UnknownMetadataFieldException === false) {
            throw $exception;
        }

        $this->logger->info(
            sprintf(
                '[UnknownMetadataFieldMiddleware] rejected unknown @self field "%s" in %s::%s',
                $exception->getField(),
                $controller::class,
                $methodName
            ),
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return new JSONResponse(
            data: [
                'error'   => 'unknown-metadata-field',
                'field'   => $exception->getField(),
                'message' => $exception->getMessage(),
            ],
            statusCode: 400
        );
    }//end afterException()
}//end class
