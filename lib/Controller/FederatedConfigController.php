<?php

/**
 * The federated configuration sharing API.
 *
 * The surface a client (or the builder UI) uses to share configuration over
 * GitHub: list the shareable types every app has contributed, package a
 * selection into a portable bundle, and install a bundle — the last governed by
 * the organisation's source allowlist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;
use UnexpectedValueException;

/**
 * REST surface for sharing, bundling and installing configuration.
 */
class FederatedConfigController extends Controller
{

    /**
     * The app its user preferences live under.
     */
    private const APP_ID = 'openregister';

    /**
     * User-preference key holding the chosen store GitHub credential (written by
     * the nc-vue Configuration store settings pane via /api/preferences).
     */
    private const CREDENTIAL_PREF = 'pref_federated-config-credential';

    /**
     * Constructor.
     *
     * @param string                 $appName     The app id.
     * @param IRequest               $request     The request.
     * @param FederatedConfigService $service     The federation engine.
     * @param FederatedConfigAccess  $access      Per-org publish/install gating.
     * @param IUserSession           $userSession The current user.
     * @param IConfig                $config      Reads the user's chosen store credential.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FederatedConfigService $service,
        private readonly FederatedConfigAccess $access,
        private readonly IUserSession $userSession,
        private readonly IConfig $config
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * The shareable configuration types every app has contributed.
     *
     * @return JSONResponse `{types: [{id, name, topic}]}`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function types(): JSONResponse
    {
        return new JSONResponse(['types' => $this->service->types()]);

    }//end types()

    /**
     * Package a selection of a type's configuration into a portable bundle.
     *
     * @return JSONResponse The bundle, or a 4xx.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function bundle(): JSONResponse
    {
        $type = trim((string) $this->request->getParam('type', ''));
        if ($type === '') {
            return new JSONResponse(['error' => 'A bundle needs a type.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $bundle = $this->service->bundle(typeId: $type, selection: (array) $this->request->getParam('selection', []));
        } catch (UnexpectedValueException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($bundle);

    }//end bundle()

    /**
     * Install a bundle, subject to the organisation's source allowlist.
     *
     * @return JSONResponse The install result, 404 unknown type, or 403 when the
     *                      source is not allowlisted.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function install(): JSONResponse
    {
        if ($this->access->canInstall(user: $this->userSession->getUser()) === false) {
            return new JSONResponse(['error' => 'You are not allowed to install shared configuration.'], Http::STATUS_FORBIDDEN);
        }

        $type = trim((string) $this->request->getParam('type', ''));
        if ($type === '') {
            return new JSONResponse(['error' => 'An install needs a type.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->service->install(
                typeId: $type,
                bundle: (array) $this->request->getParam('bundle', []),
                source: trim((string) $this->request->getParam('source', ''))
            );
        } catch (UnexpectedValueException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            // A blocked source, an untrusted key, or a bad signature is a 403;
            // anything else a 500.
            $status  = Http::STATUS_INTERNAL_SERVER_ERROR;
            $message = $e->getMessage();
            if (str_contains($message, 'allowlist') === true
                || str_contains($message, 'trusted') === true
                || str_contains($message, 'signature') === true
            ) {
                $status = Http::STATUS_FORBIDDEN;
            }

            return new JSONResponse(['error' => $message], $status);
        }//end try

        return new JSONResponse($result);

    }//end install()

    /**
     * Publish a selection to a GitHub repository, using the user's chosen store
     * credential and signing the bundle.
     *
     * The credential is NOT taken from the request — it is the one the user
     * selected in the Configuration store settings pane — so a caller can never
     * publish with a credential they did not choose.
     *
     * @return JSONResponse `{published, path, status}`, or a 4xx.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function publish(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($this->access->canPublish(user: $user) === false) {
            return new JSONResponse(['error' => 'You are not allowed to publish configuration.'], Http::STATUS_FORBIDDEN);
        }

        $type = trim((string) $this->request->getParam('type', ''));
        $repo = trim((string) $this->request->getParam('repo', ''));
        $path = trim((string) $this->request->getParam('path', ''));
        if ($type === '' || $repo === '' || $path === '') {
            return new JSONResponse(['error' => 'A publish needs a type, repo and path.'], Http::STATUS_BAD_REQUEST);
        }

        $credentialId = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
        if ($credentialId === '') {
            return new JSONResponse(
                ['error' => 'No store credential selected. Choose a GitHub credential in the Configuration store settings.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $branch = trim((string) $this->request->getParam('branch', ''));
        if ($branch === '') {
            $branch = 'main';
        }

        try {
            $result = $this->service->publish(
                typeId: $type,
                selection: (array) $this->request->getParam('selection', []),
                repo: $repo,
                path: $path,
                credentialId: $credentialId,
                branch: $branch
            );
        } catch (UnexpectedValueException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($result);

    }//end publish()

    /**
     * Discover published bundles across GitHub by a type's discovery topic.
     *
     * @return JSONResponse `{results: [{repo, name, description, url, stars, updated, topics}]}`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function discover(): JSONResponse
    {
        $topic = trim((string) $this->request->getParam('topic', ''));
        if ($topic === '') {
            return new JSONResponse(['error' => 'Discovery needs a topic.'], Http::STATUS_BAD_REQUEST);
        }

        // An authenticated search (higher rate limit) uses the user's chosen
        // store credential when they have one; otherwise it is anonymous.
        $credentialId = null;
        $user         = $this->userSession->getUser();
        if ($user !== null) {
            $stored = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
            if ($stored !== '') {
                $credentialId = $stored;
            }
        }

        return new JSONResponse(['results' => $this->service->discover(topic: $topic, credentialId: $credentialId)]);

    }//end discover()

    /**
     * Fetch a published bundle file from a GitHub repo (the bridge from discover
     * to install).
     *
     * @return JSONResponse The decoded bundle, or a 4xx.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function fetch(): JSONResponse
    {
        $repo = trim((string) $this->request->getParam('repo', ''));
        $path = trim((string) $this->request->getParam('path', ''));
        if ($repo === '' || $path === '') {
            return new JSONResponse(['error' => 'A fetch needs a repo and a path.'], Http::STATUS_BAD_REQUEST);
        }

        $credentialId = null;
        $user         = $this->userSession->getUser();
        if ($user !== null) {
            $stored = $this->config->getUserValue($user->getUID(), self::APP_ID, self::CREDENTIAL_PREF, '');
            if ($stored !== '') {
                $credentialId = $stored;
            }
        }

        try {
            $bundle = $this->service->fetchBundle(repo: $repo, path: $path, credentialId: $credentialId);
        } catch (Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['bundle' => $bundle]);

    }//end fetch()

    /**
     * This instance's signing public key, for other orgs to add to their trust list.
     *
     * @return JSONResponse `{publicKey}`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function publicKey(): JSONResponse
    {
        return new JSONResponse(['publicKey' => $this->service->publicKey()]);

    }//end publicKey()
}//end class
