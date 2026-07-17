<?php

/**
 * HandlesExceptionsTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Trait;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared exception-handling helper for controllers.
 *
 * SEC-CTRL-7: prevents pervasive exception-message disclosure on HTTP 500
 * responses. Internal exception messages (stack-trace adjacent, SQL fragments,
 * file paths) must never be echoed to the client. This trait logs the real
 * exception server-side and returns a generic envelope to the caller.
 *
 * Use ONLY for genuine 500 (internal error) paths. 4xx validation responses
 * that carry an intentional, user-facing message must keep that message.
 *
 * @category Controller
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */
trait HandlesExceptionsTrait
{
    /**
     * Log an exception and return a generic 500 JSON response.
     *
     * The real message/trace is written to the server log (when a logger is
     * available on the consuming controller) and never exposed to the client.
     *
     * @param Throwable $e       The caught exception.
     * @param string    $context Optional short context label for the log line.
     *
     * @return JSONResponse A generic internal-server-error response.
     */
    protected function errorResponse(Throwable $e, string $context=''): JSONResponse
    {
        // Log server-side when the consuming controller exposes a logger.
        if (property_exists($this, 'logger') === true && $this->logger instanceof LoggerInterface) {
            if ($context !== '') {
                $contextPrefix = $context.': ';
            } else {
                $contextPrefix = '';
            }

            $this->logger->error(
                message: '['.static::class.'] '.$contextPrefix.$e->getMessage(),
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'exception' => $e,
                ]
            );
        }

        return new JSONResponse(
            data: ['error' => 'Internal server error'],
            statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }//end errorResponse()
}//end trait
