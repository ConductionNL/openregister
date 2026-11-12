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
 *   - createAndLinkDraft(target, draft) — create a new work package in
 *       OpenProject and link it
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
use OCA\OpenRegister\Service\Integration\UpstreamRequest;
use Exception;
use OCA\OpenRegister\Db\OpenProjectLink;
use OCA\OpenRegister\Db\OpenProjectLinkMapperInterface;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\Providers\OpenProjectProvider;
use OCA\OpenRegister\Support\FleetAppId;
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
class OpenProjectLinkService implements OpenProjectLinkServiceInterface {
	// The connector answers to `integriq` on development and `openconnector`
	// on beta/main; FleetAppId holds both spellings, so this names the app
	// rather than one spelling of it. Never compare it to an id directly —
	// go through FleetAppId, or the comparison is false on half the fleet.
	private const REQUIRED_APP = 'integriq';

	private const STALE_AFTER = 86400;
	// 24 hours in seconds.

	/**
	 * Constructor.
	 *
	 * @param OpenProjectLinkMapperInterface $openProjectLinkMapper Persistence for link rows.
	 * @param OpenProjectProvider $provider Provider exposing the external dispatch.
	 * @param ExternalIntegrationRouterInterface $router External-call router (OpenConnector).
	 * @param LinkServiceRuntime $runtime Whether the app is enabled, the current user, and the logger.
	 */
	public function __construct(
		private readonly OpenProjectLinkMapperInterface $openProjectLinkMapper,
		private readonly OpenProjectProvider $provider,
		private readonly ExternalIntegrationRouterInterface $router,
		private readonly LinkServiceRuntime $runtime,
	) {
	}//end __construct()

	/**
	 * Whether OpenConnector (which carries the OpenProject source) is
	 * installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isOpenConnectorAvailable(): bool {
		return FleetAppId::isEnabledForUser($this->runtime->appManager(), self::REQUIRED_APP);
	}//end isOpenConnectorAvailable()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		$user = $this->runtime->userSession()->getUser();
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
	 * @param LinkTarget $target The object to link to.
	 * @param int $workPackageId OpenProject work-package id.
	 *
	 * @return OpenProjectLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), duplicate (409),
	 *                   OpenConnector/source unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkWorkPackage(LinkTarget $target, int $workPackageId): OpenProjectLink {
		$this->requireUid();

		if ($workPackageId === 0) {
			throw new Exception('workPackageId is required', 400);
		}

		$this->assertNotLinked(objectUuid: $target->objectUuid, workPackageId: $workPackageId);

		// Best-effort metadata fetch — when the source is unconfigured /
		// down we still persist the link with the id so the row survives,
		// then refresh later (wave-5.2 graceful degradation).
		$info = $this->fetchWorkPackage(
			ref: new ObjectRef(register: (string)$target->registerId, schema: (string)$target->schemaId(), id: $target->objectUuid),
			workPackageId: $workPackageId
		);

		$link = $this->hydrateLink(workPackageId: $workPackageId, info: ($info ?? ['subject' => '#' . $workPackageId]));

		return $this->insertLink(target: $target, link: $link);
	}//end linkWorkPackage()

	/**
	 * Refuse a second link between the same object and work package.
	 *
	 * @param string $objectUuid    Parent OR object uuid.
	 * @param int    $workPackageId OpenProject work-package id.
	 *
	 * @return void
	 *
	 * @throws Exception When the link already exists (409).
	 */
	private function assertNotLinked(string $objectUuid, int $workPackageId): void {
		if ($this->openProjectLinkMapper->findByObjectAndWorkPackage($objectUuid, $workPackageId) !== null) {
			throw new Exception('Work package already linked to this object', 409);
		}
	}//end assertNotLinked()

	/**
	 * Stamp a link row with its object and the session user, and persist it.
	 *
	 * Callers have already required a session user (401) before any other work.
	 *
	 * @param LinkTarget      $target The object to link to.
	 * @param OpenProjectLink $link   The hydrated link row.
	 *
	 * @return OpenProjectLink The persisted link row.
	 */
	private function insertLink(LinkTarget $target, OpenProjectLink $link): OpenProjectLink {
		$target->stamp(link: $link, linkedBy: $this->requireUid());

		return $this->openProjectLinkMapper->insert($link);
	}//end insertLink()

	/**
	 * Create a new OpenProject work package and link it to an OR object.
	 *
	 * @param LinkTarget       $target The object to link to.
	 * @param WorkPackageDraft $draft  The project, subject and type of the new work package.
	 *
	 * @return OpenProjectLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty subject (400),
	 *                   missing project (400), source unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkDraft(LinkTarget $target, WorkPackageDraft $draft): OpenProjectLink {
		$this->requireUid();

		$created = $this->createWorkPackage(target: $target, payload: $this->createPayload(draft: $draft));
		$link = $this->hydrateLink(workPackageId: $this->createdId(created: $created), info: $this->normaliseRow(row: $created));

		return $this->insertLink(target: $target, link: $link);
	}//end createAndLinkDraft()

	/**
	 * The create payload: trimmed subject and project, and the type when given.
	 *
	 * @param WorkPackageDraft $draft The project, subject and type of the new work package.
	 *
	 * @return array<string,string>
	 *
	 * @throws Exception On an empty subject or project (400).
	 */
	private function createPayload(WorkPackageDraft $draft): array {
		$payload = ['subject' => trim($draft->subject), 'project' => trim($draft->projectId)];
		if ($payload['subject'] === '') {
			throw new Exception('Subject is required', 400);
		}

		if ($payload['project'] === '') {
			throw new Exception('Project id is required', 400);
		}

		if ($draft->type !== '') {
			$payload['type'] = $draft->type;
		}

		return $payload;
	}//end createPayload()

	/**
	 * Create the work package in OpenProject through the provider.
	 *
	 * @param LinkTarget           $target  The object the work package is created for.
	 * @param array<string,string> $payload The create payload.
	 *
	 * @return array<string,mixed> The created row.
	 *
	 * @throws Exception On source unavailable (503) or create failure (500).
	 */
	private function createWorkPackage(LinkTarget $target, array $payload): array {
		try {
			return $this->provider->create(
				ref: new ObjectRef(register: (string)$target->registerId, schema: (string)$target->schemaId(), id: $target->objectUuid),
				payload: $payload
			);
		} catch (ProviderUnavailableException $e) {
			throw $this->unavailable(exception: $e);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('OpenProjectLinkService::createAndLinkDraft failed: ' . $e->getMessage());
			throw new Exception('Failed to create OpenProject work package', 500);
		}
	}//end createWorkPackage()

	/**
	 * The id of a created work package.
	 *
	 * @param array<string,mixed> $created The created row.
	 *
	 * @return int
	 *
	 * @throws Exception When OpenProject returned no id (502).
	 */
	private function createdId(array $created): int {
		$workPackageId = (int)($created['id'] ?? $created['reference'] ?? 0);
		if ($workPackageId === 0) {
			throw new Exception('OpenProject did not return a work-package id', 502);
		}

		return $workPackageId;
	}//end createdId()

	/**
	 * Build an OpenProjectLink from normalised work-package info.
	 *
	 * @param int $workPackageId OpenProject work-package id.
	 * @param array<string,mixed> $info Normalised work-package info.
	 *
	 * @return OpenProjectLink
	 */
	private function hydrateLink(int $workPackageId, array $info): OpenProjectLink {
		$link = new OpenProjectLink();
		$link->setWorkPackageId($workPackageId);
		$link->setSubject('#' . $workPackageId);
		$this->applyInfo(link: $link, info: $info);

		return $link;
	}//end hydrateLink()

	/**
	 * Copy normalised work-package info onto a link row's cached columns and stamp the cache time.
	 *
	 * The link's own subject and url stay when the info has none.
	 *
	 * @param OpenProjectLink     $link The link row.
	 * @param array<string,mixed> $info Normalised work-package info.
	 *
	 * @return void
	 */
	private function applyInfo(OpenProjectLink $link, array $info): void {
		$link->setSubject((string)($info['subject'] ?? $link->getSubject()));
		$link->setType($this->stringOrNull(value: ($info['type'] ?? null)));
		$link->setStatus($this->stringOrNull(value: ($info['status'] ?? null)));
		$link->setPriority($this->stringOrNull(value: ($info['priority'] ?? null)));
		$link->setAssignee($this->stringOrNull(value: ($info['assignee'] ?? null)));
		$link->setProject($this->stringOrNull(value: ($info['project'] ?? null)));
		$link->setUrl($this->stringOrNull(value: ($info['url'] ?? $link->getUrl())));
		$link->setCachedAt(new DateTime());
	}//end applyInfo()

	/**
	 * Unlink a work package from an object.
	 *
	 * Does NOT delete the work package itself — it stays in OpenProject.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $workPackageId OpenProject work-package id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlink(string $objectUuid, int $workPackageId): void {
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
	public function getLinkedWorkPackages(string $objectUuid): array {
		$links = $this->openProjectLinkMapper->findByObjectUuid($objectUuid);
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
	public function getAvailableWorkPackages(?string $search = null): array {
		if ($this->isOpenConnectorAvailable() === false) {
			throw new Exception('OpenConnector app is not available', 503);
		}

		return $this->normaliseList(response: $this->callPicker(options: $this->pickerOptions(search: $search)));
	}//end getAvailableWorkPackages()

	/**
	 * The router options of a picker call: JSON, and the search when given.
	 *
	 * @param string|null $search Optional subject-substring filter.
	 *
	 * @return array<string,mixed>
	 */
	private function pickerOptions(?string $search): array {
		$options = ['headers' => ['Accept' => 'application/json']];
		if ($search !== null && $search !== '') {
			$options['query'] = ['_search' => $search];
		}

		return $options;
	}//end pickerOptions()

	/**
	 * List the work packages through the external-call router.
	 *
	 * @param array<string,mixed> $options The router options.
	 *
	 * @return array<string,mixed> The decoded source response.
	 *
	 * @throws Exception When the source is unconfigured / unreachable (503).
	 */
	private function callPicker(array $options): array {
		try {
			return $this->router->call(provider: $this->provider, request: new UpstreamRequest(method: 'GET', path: '', options: $options));
		} catch (ProviderUnavailableException $e) {
			throw $this->unavailable(exception: $e);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('OpenProjectLinkService::getAvailableWorkPackages failed: ' . $e->getMessage());
			throw new Exception('OpenProject is currently unavailable', 503);
		}
	}//end callPicker()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param OpenProjectLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(OpenProjectLink $link): bool {
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
	private function refreshLink(OpenProjectLink $link): OpenProjectLink {
		$info = $this->fetchWorkPackage(
			ref: new ObjectRef(register: (string)$link->getRegisterId(), schema: (string)($link->getSchemaId() ?? 0), id: (string)$link->getObjectUuid()),
			workPackageId: (int)$link->getWorkPackageId()
		);
		if ($info === null) {
			return $link;
		}

		$this->applyInfo(link: $link, info: $info);

		return $this->saveRefreshed(link: $link);
	}//end refreshLink()

	/**
	 * Persist a refreshed link row; a failed update keeps the in-memory row.
	 *
	 * @param OpenProjectLink $link The refreshed link row.
	 *
	 * @return OpenProjectLink The stored row, or the in-memory one when the update fails.
	 */
	private function saveRefreshed(OpenProjectLink $link): OpenProjectLink {
		try {
			return $this->openProjectLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('OpenProjectLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end saveRefreshed()

	/**
	 * Fetch one work package from OpenProject (through the provider).
	 *
	 * Returns null when the source is unconfigured / unreachable or the
	 * package cannot be resolved, so callers degrade gracefully.
	 *
	 * @param ObjectRef $ref           The OR register, schema and object uuid (context).
	 * @param int       $workPackageId OpenProject work-package id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchWorkPackage(ObjectRef $ref, int $workPackageId): ?array {
		if ($this->isOpenConnectorAvailable() === false) {
			return null;
		}

		try {
			$row = $this->provider->get(
				ref: new ObjectRef(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				entityId: (string)$workPackageId
			);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('OpenProjectLinkService::fetchWorkPackage failed: ' . $e->getMessage());
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
	private function unavailable(ProviderUnavailableException $exception): Exception {
		$this->runtime->logger()->info('OpenProjectLinkService: provider unavailable (' . $exception->getCause() . '): ' . $exception->getMessage());
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
	 */
	private function normaliseList(array $response): array {
		$out = [];
		foreach ($this->listRows(response: $response) as $row) {
			if (is_array($row) === true) {
				$out[] = $this->normaliseRow(row: $row);
			}
		}

		return $out;
	}//end normaliseList()

	/**
	 * The raw rows of a list response: the first array envelope among
	 * results/items/_embedded/elements (`_embedded.elements` when nested),
	 * else the response itself when it is a plain list.
	 *
	 * @param array<string,mixed> $response Decoded source response.
	 *
	 * @return array
	 */
	private function listRows(array $response): array {
		foreach (['results', 'items', '_embedded', 'elements'] as $key) {
			$candidate = ($response[$key] ?? null);
			if (is_array($candidate) === true) {
				return $this->unwrapEmbedded(key: $key, candidate: $candidate);
			}
		}

		$rows = [];
		if (array_is_list($response) === true) {
			$rows = $response;
		}

		return $rows;
	}//end listRows()

	/**
	 * The `elements` of an `_embedded` envelope when it nests them; else the envelope.
	 *
	 * @param string $key       The envelope key.
	 * @param array  $candidate The envelope.
	 *
	 * @return array
	 */
	private function unwrapEmbedded(string $key, array $candidate): array {
		if ($key === '_embedded' && isset($candidate['elements']) === true && is_array($candidate['elements']) === true) {
			return $candidate['elements'];
		}

		return $candidate;
	}//end unwrapEmbedded()

	/**
	 * Shape one work-package row to the Tier-2 contract, flattening the
	 * OpenProject hAL `_links` / `_embedded` envelopes onto top-level
	 * keys (mirrors OpenProjectProvider::normalizeRow()).
	 *
	 * @param array<string,mixed> $row One source row.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseRow(array $row): array {
		$id = (int)($row['id'] ?? $row['reference'] ?? 0);
		$out = [
			'id' => $id,
			'workPackageId' => $id,
			'subject' => (string)($row['subject'] ?? $row['title'] ?? $row['name'] ?? ('#' . $id)),
		];
		foreach (['type', 'status', 'priority', 'assignee', 'project'] as $field) {
			$out[$field] = $this->pickHalLabel(row: $row, field: $field);
		}

		$out['url'] = (string)($row['url'] ?? ($row['_links']['self']['href'] ?? ''));
		return $out;
	}//end normaliseRow()

	/**
	 * Pick a hAL label from a work-package row, preferring a top-level
	 * field, then `_links.<field>.title`, then `_embedded.<field>.name`.
	 *
	 * @param array<string,mixed> $row   Raw work-package row.
	 * @param string              $field Field name.
	 *
	 * @return string Resolved label or empty string.
	 */
	private function pickHalLabel(array $row, string $field): string {
		$labels = array_values(
			array_filter(
				[($row[$field] ?? null), ($row['_links'][$field]['title'] ?? null), ($row['_embedded'][$field]['name'] ?? null)],
				static fn (mixed $label): bool => is_string($label) === true && $label !== ''
			)
		);

		return (string)($labels[0] ?? '');
	}//end pickHalLabel()

	/**
	 * Coerce a value to a non-empty trimmed string, or null when empty/absent.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return null;
	}//end stringOrNull()
}//end class
