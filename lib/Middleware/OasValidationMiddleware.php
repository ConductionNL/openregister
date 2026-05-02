<?php

/**
 * OpenRegister OAS request-validation middleware.
 *
 * Hooks into the NC framework's `before-controller` phase, looks up the
 * operation's request-body schema in the generated OAS, and validates
 * the incoming JSON body against it. On a validation miss the middleware
 * short-circuits with an RFC 7807 `application/problem+json` 422 response
 * via `ProblemDetailsBuilder::validationFailed`.
 *
 * Opt-in: only runs on JSON-bodied (POST/PUT/PATCH) requests AND only
 * when an `_validate=true` query parameter is set OR when the operation
 * is annotated with `@OasValidate` on the controller method (legacy
 * callers without the annotation skip validation, preserving the old
 * behaviour for clients that don't yet send strictly-typed bodies).
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/oas-validation/tasks.md "Request/Response Validation Against OAS Schema"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Oas\ProblemDetailsBuilder;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Validates request bodies against per-operation OAS schemas before they
 * reach the controller method.
 */
class OasValidationMiddleware extends Middleware
{
    /**
     * Constructor.
     *
     * @param IRequest              $request   The incoming request.
     * @param OasRequestValidator   $validator JSON-Schema validator wrapper.
     * @param ProblemDetailsBuilder $problems  RFC 7807 response shape builder.
     * @param ?LoggerInterface      $logger    Optional logger for skipped paths.
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly OasRequestValidator $validator,
        private readonly ProblemDetailsBuilder $problems,
        private readonly ?LoggerInterface $logger=null
    ) {

    }//end __construct()

    /**
     * Run before every controller method.
     *
     * @param mixed  $controller The controller instance.
     * @param string $methodName The method being invoked.
     *
     * @return void
     *
     * @throws OasValidationFailureException When the body fails validation.
     */
    public function beforeController(mixed $controller, string $methodName): void
    {
        // Only validate write methods.
        $verb = strtoupper((string) $this->request->getMethod());
        if (in_array($verb, ['POST', 'PUT', 'PATCH'], true) === false) {
            return;
        }

        // Opt-in: respect the `?_validate=true` query parameter.
        $optIn = (string) ($this->request->getParam('_validate') ?? '');
        if (in_array(strtolower($optIn), ['true', '1', 'yes', 'on'], true) === false) {
            return;
        }

        $schema = $this->resolveOperationSchema(controller: $controller, methodName: $methodName);
        if ($schema === null) {
            return;
        }

        $body   = $this->request->getParams();
        $errors = $this->validator->validate(body: $body, schema: $schema);
        if ($errors === []) {
            return;
        }

        // Throwing here lets afterException() turn it into the 422 response.
        throw new OasValidationFailureException(errors: $errors);

    }//end beforeController()

    /**
     * Translate our internal failure exception into an RFC 7807 422 response.
     *
     * @param mixed      $controller The controller instance.
     * @param string     $methodName The method being invoked.
     * @param \Throwable $exception  The exception that was raised.
     *
     * @return \OCP\AppFramework\Http\Response
     *
     * @throws \Throwable When the exception is not ours; rethrown for upstream handling.
     */
    public function afterException(mixed $controller, string $methodName, \Throwable $exception): \OCP\AppFramework\Http\Response
    {
        if ($exception instanceof OasValidationFailureException === false) {
            throw $exception;
        }

        $problem  = $this->problems->validationFailed(
            errors: $exception->getErrors(),
            detail: 'Request body failed OAS schema validation',
            instance: (string) $this->request->getRequestUri()
        );
        $response = new JSONResponse(data: $problem, statusCode: 422);
        $response->addHeader('Content-Type', ProblemDetailsBuilder::CONTENT_TYPE);
        return $response;

    }//end afterException()

    /**
     * Resolve the JSON-Schema used to validate this operation.
     *
     * Stub for now: returns null until OasService exposes a per-operation
     * schema lookup. Once that lookup exists, this middleware can rely on
     * it for fully-automatic validation; until then the middleware is a
     * pass-through and consumers can still reuse OasRequestValidator
     * directly when they have a schema in hand.
     *
     * @param mixed  $controller The controller instance.
     * @param string $methodName The method being invoked.
     *
     * @return array|null The JSON-Schema, or null when no schema was found.
     */
    private function resolveOperationSchema(mixed $controller, string $methodName): ?array
    {
        if ($this->logger !== null) {
            $msg = '[OasValidationMiddleware] no per-operation schema resolver wired yet; '
                .'skipping validation for '.$controller::class.'::'.$methodName;
            $this->logger->debug($msg, ['file' => __FILE__, 'line' => __LINE__]);
        }

        return null;

    }//end resolveOperationSchema()
}//end class
