<?php

/**
 * ObjectIntegrationsController — object-scoped sub-resource dispatch
 * through the pluggable integration registry.
 *
 * Endpoints:
 *   GET    /api/objects/{register}/{schema}/{id}/integrations/{integrationId}
 *   GET    /api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}
 *   POST   /api/objects/{register}/{schema}/{id}/integrations/{integrationId}
 *   PUT    /api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}
 *   DELETE /api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}
 *
 * The controller is intentionally thin: it resolves the integration
 * by id, forwards the call to the matching `IntegrationProvider`
 * method, and translates exceptions into HTTP shapes:
 *
 *   - `NotImplementedException`           -> 501 (per AD-22 / QueryTimeContract)
 *   - `ProviderUnavailableException`      -> 503 with `details.cause` payload
 *   - unknown integration id               -> 404
 *   - any other Throwable                  -> 500 with a generic message
 *
 * Additive endpoint — existing object sub-resource routes
 * (`/api/objects/{...}/files`, `/api/objects/{...}/notes`, ...) stay
 * exactly as they are. Tasks 18-22 of the umbrella eventually
 * consolidate the legacy routes onto this dispatch path.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\QueryTimeContract;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Object-scoped integration sub-resource controller.
 */
class ObjectIntegrationsController extends Controller
{

    /**
     * Constructor.
     *
     * @param string              $appName  App name (injected by NC).
     * @param IRequest            $request  Current request.
     * @param IntegrationRegistry $registry Integration registry.
     * @param LoggerInterface     $logger   Logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private IntegrationRegistry $registry,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * GET /api/objects/{register}/{schema}/{id}/integrations/{integrationId}
     *
     * Lists every linked thing the named integration exposes for the
     * given object.
     *
     * @param string $register      Register slug or numeric id.
     * @param string $schema        Schema slug or numeric id.
     * @param string $id            Object uuid.
     * @param string $integrationId Integration id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function index(string $register, string $schema, string $id, string $integrationId): JSONResponse
    {
        return $this->dispatch(
            $integrationId,
            fn ($provider) => ['items' => $provider->list($register, $schema, $id, $this->collectFilters())]
        );
    }//end index()

    /**
     * GET /api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}
     *
     * Fetches one linked thing by id.
     *
     * @param string $register      Register slug or numeric id.
     * @param string $schema        Schema slug or numeric id.
     * @param string $id            Object uuid.
     * @param string $integrationId Integration id.
     * @param string $entityId      Linked-thing id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function show(string $register, string $schema, string $id, string $integrationId, string $entityId): JSONResponse
    {
        return $this->dispatch(
            $integrationId,
            fn ($provider) => $provider->get($register, $schema, $id, $entityId)
        );
    }//end show()

    /**
     * POST /api/objects/{register}/{schema}/{id}/integrations/{integrationId}
     *
     * Creates a new linked thing for the object.
     *
     * @param string $register      Register slug or numeric id.
     * @param string $schema        Schema slug or numeric id.
     * @param string $id            Object uuid.
     * @param string $integrationId Integration id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function create(string $register, string $schema, string $id, string $integrationId): JSONResponse
    {
        $payload = $this->collectPayload();
        return $this->dispatch(
            $integrationId,
            fn ($provider) => $provider->create($register, $schema, $id, $payload),
            Http::STATUS_CREATED
        );
    }//end create()

    /**
     * PUT /api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}
     *
     * Updates a linked thing.
     *
     * @param string $register      Register slug or numeric id.
     * @param string $schema        Schema slug or numeric id.
     * @param string $id            Object uuid.
     * @param string $integrationId Integration id.
     * @param string $entityId      Linked-thing id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function update(string $register, string $schema, string $id, string $integrationId, string $entityId): JSONResponse
    {
        $payload = $this->collectPayload();
        return $this->dispatch(
            $integrationId,
            fn ($provider) => $provider->update($register, $schema, $id, $entityId, $payload)
        );
    }//end update()

    /**
     * DELETE /api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}
     *
     * Deletes a linked thing.
     *
     * @param string $register      Register slug or numeric id.
     * @param string $schema        Schema slug or numeric id.
     * @param string $id            Object uuid.
     * @param string $integrationId Integration id.
     * @param string $entityId      Linked-thing id.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function destroy(string $register, string $schema, string $id, string $integrationId, string $entityId): JSONResponse
    {
        try {
            $provider = $this->resolveProvider($integrationId);
            $provider->delete($register, $schema, $id, $entityId);
            return new JSONResponse(null, Http::STATUS_NO_CONTENT);
        } catch (NotImplementedException $e) {
            return $this->respondNotImplemented($e, $integrationId);
        } catch (ProviderUnavailableException $e) {
            return $this->respondUnavailable($e);
        } catch (\Throwable $e) {
            return $this->respondInternalError($e, $integrationId);
        }
    }//end destroy()

    /**
     * Shared dispatch helper for the non-DELETE methods.
     *
     * @param string   $integrationId Integration id.
     * @param callable $callback      Function that receives the resolved
     *                                provider and returns the response body.
     * @param int      $okStatus      HTTP status on success (200 / 201).
     *
     * @return JSONResponse
     */
    private function dispatch(string $integrationId, callable $callback, int $okStatus = Http::STATUS_OK): JSONResponse
    {
        try {
            $provider = $this->resolveProvider($integrationId);
            $body     = $callback($provider);
            return new JSONResponse($body, $okStatus);
        } catch (NotImplementedException $e) {
            return $this->respondNotImplemented($e, $integrationId);
        } catch (ProviderUnavailableException $e) {
            return $this->respondUnavailable($e);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->respondInternalError($e, $integrationId);
        }
    }//end dispatch()

    /**
     * Resolve the named integration or throw an HttpException-ish
     * 404 response via NotImplementedException-shaped fallback.
     *
     * @param string $integrationId Integration id.
     *
     * @return \OCA\OpenRegister\Service\Integration\IntegrationProvider
     *
     * @throws NotImplementedException When the id is not registered —
     *                                 chosen for symmetry with the
     *                                 query-time contract.
     */
    private function resolveProvider(string $integrationId)
    {
        $provider = $this->registry->get($integrationId);
        if ($provider === null) {
            $registered = $this->registry->listIds();
            sort($registered);
            $hint = ($registered === []) ? '(no providers registered)' : implode(', ', $registered);
            throw new NotImplementedException(
                sprintf("Integration '%s' is not registered. Registered: %s", $integrationId, $hint)
            );
        }

        return $provider;
    }//end resolveProvider()

    /**
     * Translate `NotImplementedException` to the 501 envelope shape
     * documented in QueryTimeContract::buildHttpBody().
     *
     * @param NotImplementedException $exception  Exception.
     * @param string                  $integrationId Integration id.
     *
     * @return JSONResponse
     */
    private function respondNotImplemented(NotImplementedException $exception, string $integrationId): JSONResponse
    {
        $registered = $this->registry->get($integrationId);
        if ($registered === null) {
            // "Integration not registered" semantically belongs to 404
            // rather than 501. The QueryTimeContract envelope is only
            // the right shape when the integration EXISTS but doesn't
            // support the requested operation.
            return new JSONResponse(
                ['message' => $exception->getMessage()],
                Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(
            QueryTimeContract::buildHttpBody($exception, $integrationId),
            QueryTimeContract::HTTP_NOT_IMPLEMENTED
        );
    }//end respondNotImplemented()

    /**
     * Translate `ProviderUnavailableException` to a 503 envelope
     * carrying the documented cause payload (AD-23).
     *
     * @param ProviderUnavailableException $exception Exception.
     *
     * @return JSONResponse
     */
    private function respondUnavailable(ProviderUnavailableException $exception): JSONResponse
    {
        return new JSONResponse(
            [
                'message' => $exception->getMessage(),
                'code'    => Http::STATUS_SERVICE_UNAVAILABLE,
                'details' => $exception->getDetails(),
            ],
            Http::STATUS_SERVICE_UNAVAILABLE
        );
    }//end respondUnavailable()

    /**
     * Translate any other throwable to a generic 500.
     *
     * Per ADR-005 the real exception is logged server-side; the
     * client gets a static message.
     *
     * @param \Throwable $exception     Exception.
     * @param string     $integrationId Integration id.
     *
     * @return JSONResponse
     */
    private function respondInternalError(\Throwable $exception, string $integrationId): JSONResponse
    {
        $this->logger->error(
            sprintf('[ObjectIntegrationsController] dispatch failed for %s', $integrationId),
            ['exception' => $exception]
        );
        return new JSONResponse(
            ['message' => 'Integration call failed'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }//end respondInternalError()

    /**
     * Collect filter / pagination params from the request.
     *
     * @return array<string,mixed>
     */
    private function collectFilters(): array
    {
        $filters = [];
        foreach ($this->request->getParams() as $key => $value) {
            if (in_array($key, ['register', 'schema', 'id', 'integrationId', 'entityId'], true) === true) {
                continue;
            }
            $filters[$key] = $value;
        }

        return $filters;
    }//end collectFilters()

    /**
     * Collect the JSON body from the current POST/PUT request.
     *
     * @return array<string,mixed>
     */
    private function collectPayload(): array
    {
        $raw = $this->request->getParams();
        unset($raw['register'], $raw['schema'], $raw['id'], $raw['integrationId'], $raw['entityId']);
        return $raw;
    }//end collectPayload()

}//end class
