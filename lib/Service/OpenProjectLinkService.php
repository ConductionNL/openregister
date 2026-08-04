<?php

/**
 * OpenProjectLinkService — Tier-2 OpenProject (external / OpenConnector-
 * routed) integration service.
 *
 * Composes the {@see OpenProjectLinkMapper} (Tier-2 link table) with the
 * {@see OpenProjectProvider} + {@see ExternalIntegrationRouter} external
 * dispatch so the service exposes the Tier-2 surface:
 *
 *   - linkWorkPackage(uuid, registerId, schemaId, workPackageId)
 *       — link an existing OpenProject work package
 *   - createAndLinkWorkPackage(uuid, registerId, schemaId, projectId,
 *       subject, type) — create a new work package in OpenProject and
 *       link it
 *   - unlink(uuid, workPackageId)
 *       — remove a link (the work package itself stays in OpenProject)
 *   - getLinkedWorkPackages(uuid)
 *       — list linked work packages, refreshing the cached
 *       subject/type/status/priority/assignee/project/url columns when a
 *       row is older than 24h and the source is configured
 *   - getAvailableWorkPackages(?search)
 *       — picker source listing work packages reachable through the
 *       OpenConnector `openproject` source
 *
 * Unlike the NC-native Tier-2 services (Collectives / Photos / Deck …)
 * OpenProject lives outside Nextcloud: the only install dependency is
 * OpenConnector (which carries the `openproject` source + credentials,
 * AD-4 / AD-22). When the source is missing or the upstream OpenProject
 * is unreachable the {@see ExternalIntegrationRouter} raises a
 * {@see ProviderUnavailableException}; this service translates that into
 * a 503-with-cause Exception so the controller + UI degrade to a
 * "Configure" CTA rather than a broken tab (wave-5.2 4-state auth UX).
 * Stored link rows are always returned as-is for the linked list so
 * historical references survive an outage.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenProjectLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     OpenProjectProvider + ExternalIntegrationRouter + app manager +
 *     user session + logger. Each dependency is required for one of the
 *     Tier-2 flows (link, create, unlink, list, picker, cache refresh,
 *     graceful degradation).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Tier-2 service implements
 * link/createAndLink/unlink/list/picker/refresh/hydrateLink/fetchWorkPackage/normalise — all are
 * required faces of the OpenProject integration surface; splitting into separate classes would break
 * the single-service lazy-resolution contract.
 * @SuppressWarnings(PHPMD.LongVariable)             $openProjectLinkMapper follows the repo naming convention for
 * OR link-table mappers; $workPackageId is the exact term used by the OpenProject API and abbreviating
 * it would misalign with upstream documentation.
 */
class OpenProjectLinkService
{
    private const REQUIRED_APP = 'openconnector';

    private const STALE_AFTER = 86400;
    // 24 hours in seconds.

    /**
     * Constructor.
     *
     * @param OpenProjectLinkMapper     $openProjectLinkMapper Persistence for link rows.
     * @param OpenProjectProvider       $provider              Provider exposing the external dispatch.
     * @param ExternalIntegrationRouter $router                External-call router (OpenConnector).
     * @param IAppManager               $appManager            NC app manager.
     * @param IUserSession              $userSession           Active session.
     * @param LoggerInterface           $logger                Logger.
     */
    public function __construct(
        private readonly OpenProjectLinkMapper $openProjectLinkMapper,
        private readonly OpenProjectProvider $provider,
        private readonly ExternalIntegrationRouter $router,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether OpenConnector (which carries the OpenProject source) is
     * installed + enabled for the current user.
     *
     * @return bool
     */
    public function isOpenConnectorAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
    }//end isOpenConnectorAvailable()

    /**
     * Active session UID, or throw if no user is logged in.
     *
     * @return string The user id.
     *
     * @throws Exception When there is no active user.
     */
    private function requireUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        return $user->getUID();
    }//end requireUid()

    /**
     * Link an existing OpenProject work package to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception. Work-package
     * metadata is cached at link time, pulled fresh from OpenProject when
     * the source is configured.
     *
     * @param string $objectUuid    Parent OR object uuid.
     * @param int    $registerId    OR register id.
     * @param int    $schemaId      OR schema id.
     * @param int    $workPackageId OpenProject work-package id.
     *
     * @return OpenProjectLink The persisted link row.
     *
     * @throws Exception On missing user (401), duplicate (409),
     *                   OpenConnector/source unavailable (503).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function linkWorkPackage(string $objectUuid, int $registerId, int $schemaId, int $workPackageId): OpenProjectLink
    {
        $uid = $this->requireUid();

        if ($workPackageId === 0) {
            throw new Exception('workPackageId is required', 400);
        }

        $existing = $this->openProjectLinkMapper->findByObjectAndWorkPackage($objectUuid, $workPackageId);
        if ($existing !== null) {
            throw new Exception('Work package already linked to this object', 409);
        }

        // Best-effort metadata fetch — when the source is unconfigured /
        // down we still persist the link with the id so the row survives,
        // then refresh later (wave-5.2 graceful degradation).
        $info = $this->fetchWorkPackage(
            register: (string) $registerId,
            schema: (string) $schemaId,
            objectUuid: $objectUuid,
            workPackageId: $workPackageId
        );

        $link = $this->hydrateLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            workPackageId: $workPackageId,
            info: ($info ?? ['subject' => '#'.$workPackageId]),
            uid: $uid
        );

        return $this->openProjectLinkMapper->insert($link);
    }//end linkWorkPackage()

    /**
     * Create a new OpenProject work package and link it to an OR object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param string $projectId  Target OpenProject project id.
     * @param string $subject    New work-package subject.
     * @param string $type       Optional work-package type (id or label).
     *
     * @return OpenProjectLink The persisted link row.
     *
     * @throws Exception On missing user (401), empty subject (400),
     *                   missing project (400), source unavailable (503).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function createAndLinkWorkPackage(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $projectId,
        string $subject,
        string $type=''
    ): OpenProjectLink {
        $uid = $this->requireUid();

        $subject   = trim($subject);
        $projectId = trim($projectId);
        if ($subject === '') {
            throw new Exception('Subject is required', 400);
        }

        if ($projectId === '') {
            throw new Exception('Project id is required', 400);
        }

        $payload = ['subject' => $subject, 'project' => $projectId];
        if ($type !== '') {
            $payload['type'] = $type;
        }

        try {
            $created = $this->provider->create(
                register: (string) $registerId,
                schema: (string) $schemaId,
                objectId: $objectUuid,
                payload: $payload
            );
        } catch (ProviderUnavailableException $e) {
            throw $this->unavailable(exception: $e);
        } catch (Throwable $e) {
            $this->logger->warning('OpenProjectLinkService::createAndLinkWorkPackage failed: '.$e->getMessage());
            throw new Exception('Failed to create OpenProject work package', 500);
        }

        $workPackageId = (int) ($created['id'] ?? $created['reference'] ?? 0);
        if ($workPackageId === 0) {
            throw new Exception('OpenProject did not return a work-package id', 502);
        }

        $info = $this->normaliseRow(row: $created);

        $link = $this->hydrateLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            workPackageId: $workPackageId,
            info: $info,
            uid: $uid
        );

        return $this->openProjectLinkMapper->insert($link);
    }//end createAndLinkWorkPackage()

    /**
     * Build an OpenProjectLink from normalised work-package info.
     *
     * @param string              $objectUuid    Parent OR object uuid.
     * @param int                 $registerId    OR register id.
     * @param int                 $schemaId      OR schema id.
     * @param int                 $workPackageId OpenProject work-package id.
     * @param array<string,mixed> $info          Normalised work-package info.
     * @param string              $uid           The linking user id.
     *
     * @return OpenProjectLink
     */
    private function hydrateLink(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        int $workPackageId,
        array $info,
        string $uid
    ): OpenProjectLink {
        $link = new OpenProjectLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setWorkPackageId($workPackageId);
        $link->setSubject((string) ($info['subject'] ?? ('#'.$workPackageId)));
        $link->setType($this->stringOrNull(value: ($info['type'] ?? null)));
        $link->setStatus($this->stringOrNull(value: ($info['status'] ?? null)));
        $link->setPriority($this->stringOrNull(value: ($info['priority'] ?? null)));
        $link->setAssignee($this->stringOrNull(value: ($info['assignee'] ?? null)));
        $link->setProject($this->stringOrNull(value: ($info['project'] ?? null)));
        $link->setUrl($this->stringOrNull(value: ($info['url'] ?? null)));
        $link->setCachedAt(new DateTime());
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $link;
    }//end hydrateLink()

    /**
     * Unlink a work package from an object.
     *
     * Does NOT delete the work package itself — it stays in OpenProject.
     *
     * @param string $objectUuid    Parent OR object uuid.
     * @param int    $workPackageId OpenProject work-package id.
     *
     * @return void
     *
     * @throws Exception On missing user (401) or no matching link (404).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function unlink(string $objectUuid, int $workPackageId): void
    {
        $this->requireUid();

        $deleted = $this->openProjectLinkMapper->deleteByObjectAndWorkPackage($objectUuid, $workPackageId);
        if ($deleted === 0) {
            throw new Exception('OpenProject link not found', 404);
        }
    }//end unlink()

    /**
     * Return the linked work packages for an object, refreshing the
     * cached metadata columns when a row is older than 24h and the
     * OpenConnector source is reachable.
     *
     * @param string $objectUuid Parent OR object uuid.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getLinkedWorkPackages(string $objectUuid): array
    {
        $links     = $this->openProjectLinkMapper->findByObjectUuid($objectUuid);
        $available = $this->isOpenConnectorAvailable();

        $results = [];
        foreach ($links as $link) {
            if ($available === true && $this->isStale(link: $link) === true) {
                $link = $this->refreshLink(link: $link);
            }

            $results[] = $link->jsonSerialize();
        }

        return $results;
    }//end getLinkedWorkPackages()

    /**
     * Return the work packages reachable through the OpenConnector
     * `openproject` source (picker source), optionally filtered by a
     * search substring.
     *
     * @param string|null $search Optional subject-substring filter.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws Exception When the source is unconfigured / unreachable (503).
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
     */
    public function getAvailableWorkPackages(?string $search=null): array
    {
        if ($this->isOpenConnectorAvailable() === false) {
            throw new Exception('OpenConnector app is not available', 503);
        }

        $options = ['headers' => ['Accept' => 'application/json']];
        if ($search !== null && $search !== '') {
            $options['query'] = ['_search' => $search];
        }

        try {
            $response = $this->router->call(
                provider: $this->provider,
                method: 'GET',
                path: '',
                options: $options
            );
        } catch (ProviderUnavailableException $e) {
            throw $this->unavailable(exception: $e);
        } catch (Throwable $e) {
            $this->logger->warning('OpenProjectLinkService::getAvailableWorkPackages failed: '.$e->getMessage());
            throw new Exception('OpenProject is currently unavailable', 503);
        }

        return $this->normaliseList(response: $response);
    }//end getAvailableWorkPackages()

    /**
     * Whether a link row's cache is older than the stale window.
     *
     * @param OpenProjectLink $link The link row.
     *
     * @return bool
     */
    private function isStale(OpenProjectLink $link): bool
    {
        $cachedAt = ($link->getCachedAt() ?? $link->getLinkedAt());
        if ($cachedAt === null) {
            return true;
        }

        return (time() - $cachedAt->getTimestamp()) > self::STALE_AFTER;
    }//end isStale()

    /**
     * Refresh a link row's cached work-package metadata in place.
     *
     * Best-effort: when the work package can't be resolved (source down,
     * package deleted) the link is left untouched.
     *
     * @param OpenProjectLink $link The link row.
     *
     * @return OpenProjectLink The (possibly updated) link row.
     */
    private function refreshLink(OpenProjectLink $link): OpenProjectLink
    {
        $info = $this->fetchWorkPackage(
            register: (string) $link->getRegisterId(),
            schema: (string) ($link->getSchemaId() ?? 0),
            objectUuid: (string) $link->getObjectUuid(),
            workPackageId: (int) $link->getWorkPackageId()
        );
        if ($info === null) {
            return $link;
        }

        $link->setSubject((string) ($info['subject'] ?? $link->getSubject()));
        $link->setType($this->stringOrNull(value: ($info['type'] ?? null)));
        $link->setStatus($this->stringOrNull(value: ($info['status'] ?? null)));
        $link->setPriority($this->stringOrNull(value: ($info['priority'] ?? null)));
        $link->setAssignee($this->stringOrNull(value: ($info['assignee'] ?? null)));
        $link->setProject($this->stringOrNull(value: ($info['project'] ?? null)));
        $link->setUrl($this->stringOrNull(value: ($info['url'] ?? $link->getUrl())));
        $link->setCachedAt(new DateTime());

        try {
            return $this->openProjectLinkMapper->update($link);
        } catch (Throwable $e) {
            $this->logger->debug('OpenProjectLinkService::refreshLink update failed: '.$e->getMessage());
            return $link;
        }
    }//end refreshLink()

    /**
     * Fetch one work package from OpenProject (through the provider).
     *
     * Returns null when the source is unconfigured / unreachable or the
     * package cannot be resolved, so callers degrade gracefully.
     *
     * @param string $register      OR register id (context).
     * @param string $schema        OR schema id (context).
     * @param string $objectUuid    Parent OR object uuid (context).
     * @param int    $workPackageId OpenProject work-package id.
     *
     * @return array<string,mixed>|null
     */
    private function fetchWorkPackage(string $register, string $schema, string $objectUuid, int $workPackageId): ?array
    {
        if ($this->isOpenConnectorAvailable() === false) {
            return null;
        }

        try {
            $row = $this->provider->get(
                register: $register,
                schema: $schema,
                objectId: $objectUuid,
                entityId: (string) $workPackageId
            );
        } catch (Throwable $e) {
            $this->logger->debug('OpenProjectLinkService::fetchWorkPackage failed: '.$e->getMessage());
            return null;
        }

        return $this->normaliseRow(row: $row);
    }//end fetchWorkPackage()

    /**
     * Translate a ProviderUnavailableException into a 503 Exception that
     * the controller maps to the right "Configure" / "reconnect" UX.
     *
     * @param ProviderUnavailableException $exception The router failure.
     *
     * @return Exception
     */
    private function unavailable(ProviderUnavailableException $exception): Exception
    {
        $this->logger->info('OpenProjectLinkService: provider unavailable ('.$exception->getCause().'): '.$exception->getMessage());
        return new Exception($exception->getMessage(), 503);
    }//end unavailable()

    /**
     * Normalise a list response from the provider/router into picker rows.
     *
     * The OpenProjectProvider already flattens hAL envelopes for its own
     * `list()`; here we re-apply the same shaping to the raw router
     * envelope so the picker rows carry the flat fields.
     *
     * @param array<string,mixed> $response Decoded source response.
     *
     * @return array<int,array<string,mixed>>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) normaliseList() probes four distinct OpenProject
     * response envelope shapes (results/items/_embedded/elements) and handles the `_embedded.elements`
     * nesting variant; each probe is required to support the differing API versions returned by the
     * router.
     */
    private function normaliseList(array $response): array
    {
        $rows = [];
        foreach (['results', 'items', '_embedded', 'elements'] as $key) {
            if (isset($response[$key]) === true && is_array($response[$key]) === true) {
                $candidate = $response[$key];
                if ($key === '_embedded'
                    && isset($candidate['elements']) === true
                    && is_array($candidate['elements']) === true
                ) {
                    $candidate = $candidate['elements'];
                }

                $rows = $candidate;
                break;
            }
        }

        if ($rows === [] && array_is_list($response) === true) {
            $rows = $response;
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $out[] = $this->normaliseRow(row: $row);
            }
        }

        return $out;
    }//end normaliseList()

    /**
     * Shape one work-package row to the Tier-2 contract, flattening the
     * OpenProject hAL `_links` / `_embedded` envelopes onto top-level
     * keys (mirrors OpenProjectProvider::normalizeRow()).
     *
     * @param array<string,mixed> $row One source row.
     *
     * @return array<string,mixed>
     */
    private function normaliseRow(array $row): array
    {
        $id       = (int) ($row['id'] ?? $row['reference'] ?? 0);
        $subject  = (string) ($row['subject'] ?? $row['title'] ?? $row['name'] ?? ('#'.$id));
        $status   = $this->pickHalLabel(row: $row, field: 'status', linkKey: 'title', embedKey: 'name');
        $type     = $this->pickHalLabel(row: $row, field: 'type', linkKey: 'title', embedKey: 'name');
        $priority = $this->pickHalLabel(row: $row, field: 'priority', linkKey: 'title', embedKey: 'name');
        $assignee = $this->pickHalLabel(row: $row, field: 'assignee', linkKey: 'title', embedKey: 'name');
        $project  = $this->pickHalLabel(row: $row, field: 'project', linkKey: 'title', embedKey: 'name');
        $url      = (string) ($row['url'] ?? ($row['_links']['self']['href'] ?? ''));

        return [
            'id'            => $id,
            'workPackageId' => $id,
            'subject'       => $subject,
            'type'          => $type,
            'status'        => $status,
            'priority'      => $priority,
            'assignee'      => $assignee,
            'project'       => $project,
            'url'           => $url,
        ];
    }//end normaliseRow()

    /**
     * Pick a hAL label from a work-package row, preferring a top-level
     * field, then `_links.<field>.<linkKey>`, then
     * `_embedded.<field>.<embedKey>`.
     *
     * @param array<string,mixed> $row      Raw work-package row.
     * @param string              $field    Field name.
     * @param string              $linkKey  Label key under `_links.<field>`.
     * @param string              $embedKey Label key under `_embedded.<field>`.
     *
     * @return string Resolved label or empty string.
     */
    private function pickHalLabel(array $row, string $field, string $linkKey, string $embedKey): string
    {
        $top = ($row[$field] ?? null);
        if (is_string($top) === true && $top !== '') {
            return $top;
        }

        $link = ($row['_links'][$field][$linkKey] ?? null);
        if (is_string($link) === true && $link !== '') {
            return $link;
        }

        $embed = ($row['_embedded'][$field][$embedKey] ?? null);
        if (is_string($embed) === true && $embed !== '') {
            return $embed;
        }

        return '';
    }//end pickHalLabel()

    /**
     * Coerce a value to a non-empty string, or null when empty/absent.
     *
     * @param mixed $value The candidate value.
     *
     * @return string|null
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }//end stringOrNull()
}//end class
