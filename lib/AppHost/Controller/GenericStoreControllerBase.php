<?php

/**
 * OpenRegister AppHost — Generic Store Controller Base
 *
 * Abstract base for the ADR-080 store plane's DISCOVERY surface. A leaf app
 * subclasses this, supplies its StoreDescriptor, and registers a `search`
 * route; the base owns authentication, the outcome envelope and slug
 * validation so no app re-implements them.
 *
 * INSTALL is deliberately absent. Cloning an application template, enabling a
 * connector adapter and instantiating an agent template are different
 * operations with different authorization — each app declares its own install
 * action and calls {@see GenericStoreService::resolve()} for the payload. An
 * app that finds itself re-implementing search, the SSRF guard or the outcome
 * mapping is violating ADR-080 Decision 3.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abstract base for a leaf app's store discovery endpoints.
 *
 * @psalm-suppress UnusedClass Subclassed by leaf apps (ADR-080 adoption waves).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
abstract class GenericStoreControllerBase extends Controller
{
    /**
     * Kebab-case slug pattern shared by store item slugs.
     */
    protected const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

    /**
     * Constructor.
     *
     * @param string             $appName      The leaf app id.
     * @param IRequest           $request      The current HTTP request.
     * @param IUserSession       $userSession  Current Nextcloud user session.
     * @param GenericStoreService $storeService Engine-owned store client.
     * @param LoggerInterface    $logger       PSR logger.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        protected readonly IUserSession $userSession,
        protected readonly GenericStoreService $storeService,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * The calling app's store parameters. Implemented by the subclass.
     *
     * @return StoreDescriptor
     */
    abstract protected function descriptor(): StoreDescriptor;

    /**
     * Search this app's remote store.
     *
     * Login-required (in-body guard, so an anonymous caller gets an explicit
     * 401 rather than a redirect). Returns normalised cards or a generic
     * outcome — NEVER the registry URL or token, which stay server-side.
     *
     * @return JSONResponse 200 with `{outcome, cards}`; 401 for anonymous.
     *
     * @spec openspec/specs/apphost-store-plane/spec.md
     */
    #[NoAdminRequired]
    public function search(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return $this->storeError(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        $query = $this->request->getParam('q');
        if (is_string($query) === false) {
            $query = null;
        }

        $kind = $this->request->getParam('kind');
        if (is_string($kind) === false) {
            $kind = null;
        }

        try {
            $result = $this->storeService->search(
                descriptor: $this->descriptor(),
                query: $query,
                kind: $kind
            );
        } catch (Throwable $e) {
            // Detail to the log, generic outcome to the browser: a registry's
            // internals are not the caller's business.
            $this->logger->error($this->appName.' store: search failed: '.$e->getMessage());
            return new JSONResponse(
                data: ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []],
                statusCode: Http::STATUS_OK
            );
        }

        return new JSONResponse(
            data: ['outcome' => $result['outcome'], 'cards' => $result['cards']],
            statusCode: Http::STATUS_OK
        );

    }//end search()

    /**
     * Resolve a remote item's full payload for an install action.
     *
     * Helper for subclasses' own install methods — NOT a route. Validates the
     * slug shape before any remote call so a malformed slug never reaches the
     * registry.
     *
     * @param string $slug The remote item slug.
     *
     * @return array<string, mixed>|null The remote object, or null when invalid / unresolved.
     */
    protected function resolveForInstall(string $slug): ?array
    {
        if (preg_match(static::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        try {
            return $this->storeService->resolve(descriptor: $this->descriptor(), slug: $slug);
        } catch (Throwable $e) {
            $this->logger->error($this->appName.' store: resolve failed for '.$slug.': '.$e->getMessage());
            return null;
        }

    }//end resolveForInstall()

    /**
     * Build a uniform error JSONResponse.
     *
     * @param string      $code   The error code.
     * @param int         $status The HTTP status code.
     * @param string|null $detail Optional detail message.
     *
     * @return JSONResponse
     */
    protected function storeError(string $code, int $status, ?string $detail=null): JSONResponse
    {
        $body = ['error' => $code];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JSONResponse(data: $body, statusCode: $status);

    }//end storeError()
}//end class
