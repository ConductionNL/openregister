<?php

/**
 * CospendLinkService — Tier-2 cospend (NC Cospend / Costs) integration
 * service.
 *
 * Composes the {@see CospendLinkMapper} with NC Cospend's
 * `OCA\Cospend\Service\ProjectService` plus direct `cospend_projects` /
 * `cospend_bills` queries (the wave-2.3 pattern) to expose the Tier-2
 * surface:
 *
 *   - linkProject(uuid, registerId, schemaId, projectId)
 *       — link an existing project
 *   - linkBill(uuid, registerId, schemaId, projectId, billId)
 *       — link a specific bill under a project
 *   - createAndLinkProject(uuid, registerId, schemaId, name, currency)
 *       — create a new Cospend project and link it
 *   - unlink(uuid, entryId)
 *       — remove a link row (the project/bill itself stays in Cospend)
 *   - getLinkedEntries(uuid)
 *       — list linked entries, refreshing cached amount/currency when the
 *       row is older than 24h
 *   - getAvailableProjects(?search)
 *       — picker source listing the current user's projects
 *
 * NC Cospend's `ProjectService` + the `cospend_projects` / `cospend_bills`
 * tables are resolved lazily through the server container behind a
 * `class_exists` + `Throwable` guard so this service loads even when the
 * Cospend app is not installed (ADR-019 AD-23 graceful degradation): when
 * Cospend is missing or a call throws, the stored link row is returned
 * as-is so historical references survive.
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\CospendEntryKey;
use OCA\OpenRegister\Db\CospendLink;
use OCA\OpenRegister\Db\CospendLinkMapperInterface;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CospendLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     NC Cospend ProjectService (late-bound) + DB connection + user
 *     session + app manager + container + logger. Each dependency is
 *     required for one of the Tier-2 flows (link, create, unlink, list,
 *     picker, cache refresh, graceful degradation).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CospendLinkService implements CospendLinkServiceInterface {
	private const REQUIRED_APP = 'cospend';

	private const PROJECT_SERVICE = 'OCA\\Cospend\\Service\\ProjectService';

	private const PROJECTS_TABLE = 'cospend_projects';

	private const BILLS_TABLE = 'cospend_bills';

	private const ENTRY_PROJECT = 'project';

	private const ENTRY_BILL = 'bill';

	private const STALE_AFTER = 86400;
	// 24 hours in seconds.

	/**
	 * Constructor.
	 *
	 * @param CospendLinkMapperInterface $cospendLinkMapper Persistence for link rows.
	 * @param ContainerInterface $container Container for late-bound Cospend classes.
	 * @param IAppManager $appManager NC app manager.
	 * @param IUserSession $userSession Active session.
	 * @param IDBConnection $db DB connection for direct Cospend table queries.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly CospendLinkMapperInterface $cospendLinkMapper,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether NC Cospend is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isCospendAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
	}//end isCospendAvailable()

	/**
	 * Resolve NC Cospend's ProjectService lazily.
	 *
	 * Returns null when Cospend is absent or the class can't be resolved,
	 * so callers degrade gracefully (ADR-019 AD-23).
	 *
	 * @return object|null The service instance or null.
	 */
	private function getProjectService(): ?object {
		if ($this->isCospendAvailable() === false || class_exists(self::PROJECT_SERVICE) === false) {
			return null;
		}

		try {
			return $this->container->get(self::PROJECT_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug('CospendLinkService: ProjectService unavailable: ' . $e->getMessage());
			return null;
		}
	}//end getProjectService()

	/**
	 * Active session UID, or throw if no user is logged in.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception When there is no active user.
	 */
	private function requireUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in', 401);
		}

		return $user->getUID();
	}//end requireUid()

	/**
	 * Link an existing NC Cospend project to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception. Project name +
	 * currency are cached at link time.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $projectId NC Cospend project id.
	 *
	 * @return CospendLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing project (404),
	 *                   duplicate (409), Cospend unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link contract is owned by the integration-cospend capability.
	 */
	public function linkProject(LinkTarget $target, string $projectId): CospendLink {
		$uid = $this->requireUid();

		if ($this->isCospendAvailable() === false) {
			throw new Exception('NC Cospend is not available', 503);
		}

		if (trim($projectId) === '') {
			throw new Exception('Project id is required', 400);
		}

		$existing = $this->cospendLinkMapper->findDuplicate(
			objectUuid: $target->objectUuid,
			entry: new CospendEntryKey(entryType: self::ENTRY_PROJECT, projectId: $projectId)
		);
		if ($existing !== null) {
			throw new Exception('Project already linked to this object', 409);
		}

		$project = $this->fetchProject(projectId: $projectId);
		if ($project === null) {
			throw new Exception('Cospend project not found', 404);
		}

		$link = $this->hydrateLink(
			target: $target,
			opts: [
				'entryType' => self::ENTRY_PROJECT,
				'projectId' => $projectId,
				'billId' => null,
				'name' => (string)($project['name'] ?? $projectId),
				'amount' => null,
				'currency' => ($project['currency'] ?? null),
				'uid' => $uid,
			]
		);

		return $this->cospendLinkMapper->insert($link);
	}//end linkProject()

	/**
	 * Link a specific NC Cospend bill (under a project) to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $projectId NC Cospend project id.
	 * @param int $billId NC Cospend bill id.
	 *
	 * @return CospendLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), missing bill (404),
	 *                   duplicate (409), Cospend unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link contract is owned by the integration-cospend capability.
	 */
	public function linkBill(LinkTarget $target, string $projectId, int $billId): CospendLink {
		$uid = $this->requireUid();
		$this->requireBillRequest(projectId: $projectId, billId: $billId);

		$existing = $this->cospendLinkMapper->findDuplicate(
			objectUuid: $target->objectUuid,
			entry: (new CospendEntryKey(entryType: self::ENTRY_BILL, projectId: $projectId))->withBill(billId: $billId)
		);
		if ($existing !== null) {
			throw new Exception('Bill already linked to this object', 409);
		}

		$bill = $this->fetchBill(projectId: $projectId, billId: $billId);
		if ($bill === null) {
			throw new Exception('Cospend bill not found', 404);
		}

		$project = $this->fetchProject(projectId: $projectId);
		$currency = $project['currency'] ?? null;

		$link = $this->hydrateLink(
			target: $target,
			opts: [
				'entryType' => self::ENTRY_BILL,
				'projectId' => $projectId,
				'billId' => $billId,
				'name' => (string)($bill['what'] ?? ($project['name'] ?? $projectId)),
				'amount' => ($bill['amount'] ?? null),
				'currency' => $currency,
				'uid' => $uid,
			]
		);

		return $this->cospendLinkMapper->insert($link);
	}//end linkBill()

	/**
	 * Refuse a bill link while Cospend is unavailable or without a project and bill id.
	 *
	 * @param string $projectId Cospend project id.
	 * @param int    $billId    Cospend bill id.
	 *
	 * @return void
	 *
	 * @throws Exception When Cospend is unavailable (503) or an id is missing (400).
	 */
	private function requireBillRequest(string $projectId, int $billId): void {
		if ($this->isCospendAvailable() === false) {
			throw new Exception('NC Cospend is not available', 503);
		}

		if (trim($projectId) === '' || $billId === 0) {
			throw new Exception('Project id and bill id are required', 400);
		}
	}//end requireBillRequest()

	/**
	 * Create a new NC Cospend project and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $name New project name.
	 * @param string $currency Project currency name.
	 *
	 * @return CospendLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   Cospend unavailable (503), create failure (500).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link contract is owned by the integration-cospend capability.
	 */
	public function createAndLinkProject(LinkTarget $target, string $name, string $currency): CospendLink {
		$uid = $this->requireUid();

		$name = trim($name);
		if ($name === '') {
			throw new Exception('Project name is required', 400);
		}

		$projectService = $this->getProjectService();
		if ($projectService === null) {
			throw new Exception('NC Cospend is not available', 503);
		}

		$projectId = $this->createProject(projectService: $projectService, name: $name, uid: $uid);
		if ($projectId === null) {
			throw new Exception('Failed to create Cospend project', 500);
		}

		$currencyValue = $this->resolveCurrency(projectId: $projectId, currency: $currency);

		$link = $this->hydrateLink(
			target: $target,
			opts: [
				'entryType' => self::ENTRY_PROJECT,
				'projectId' => $projectId,
				'billId' => null,
				'name' => $name,
				'amount' => null,
				'currency' => $currencyValue,
				'uid' => $uid,
			]
		);

		return $this->cospendLinkMapper->insert($link);
	}//end createAndLinkProject()

	/**
	 * The currency for a new project link: the given one, else the project's own, else none.
	 *
	 * @param string $projectId The created project id.
	 * @param string $currency  The requested currency, possibly blank.
	 *
	 * @return string|null
	 */
	private function resolveCurrency(string $projectId, string $currency): ?string {
		$cleanCurrency = trim($currency);
		if ($cleanCurrency === '') {
			$project = $this->fetchProject(projectId: $projectId);
			$cleanCurrency = (string)($project['currency'] ?? '');
		}

		if ($cleanCurrency === '') {
			return null;
		}

		return $cleanCurrency;
	}//end resolveCurrency()

	/**
	 * Create a Cospend project via ProjectService.
	 *
	 * NC Cospend's ProjectService::createProject has shifted signature
	 * across versions; we call it positionally (name, id, contact_email,
	 * userId) and fall back to (name, id, userId). Returns the resulting
	 * project id, or null on failure.
	 *
	 * @param object $projectService NC Cospend ProjectService.
	 * @param string $name Project name.
	 * @param string $uid Owning user id.
	 *
	 * @return string|null The new project id, or null on failure.
	 */
	private function createProject(object $projectService, string $name, string $uid): ?string {
		$projectId = $this->candidateProjectId(name: $name);

		$outcome = $this->invokeCreateProject(
			projectService: $projectService,
			request: ['name' => $name, 'projectId' => $projectId, 'uid' => $uid]
		);
		if ($outcome === null) {
			return null;
		}

		return $this->createdProjectId(result: $outcome[0], fallback: $projectId);
	}//end createProject()

	/**
	 * A project id candidate: a slug of the name plus a short random suffix to avoid collisions.
	 *
	 * @param string $name The project name.
	 *
	 * @return string
	 */
	private function candidateProjectId(string $name): string {
		// Cospend project ids are slugs; derive a stable-ish candidate
		// from the name + a short random suffix to avoid collisions.
		$base = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name));
		$base = trim((string)$base, '-');
		if ($base === '') {
			$base = 'project';
		}

		return substr($base, 0, 40) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
	}//end candidateProjectId()

	/**
	 * Call ProjectService::createProject with the current signature, falling back to the older one.
	 *
	 * @param object                                         $projectService The NC Cospend ProjectService.
	 * @param array{name:string,projectId:string,uid:string} $request        The project to create.
	 *
	 * @return array{0:mixed}|null The call's result wrapped in a list, or null when both signatures fail.
	 */
	private function invokeCreateProject(object $projectService, array $request): ?array {
		try {
			return [$projectService->createProject($request['name'], $request['projectId'], null, $request['uid'])];
		} catch (Throwable $first) {
			try {
				return [$projectService->createProject($request['name'], $request['projectId'], $request['uid'])];
			} catch (Throwable $second) {
				$this->logger->warning('CospendLinkService::createProject failed: ' . $second->getMessage());
				return null;
			}
		}
	}//end invokeCreateProject()

	/**
	 * The id ProjectService::createProject returned (a string or an array carrying it), else the candidate.
	 *
	 * @param mixed  $result   What createProject returned.
	 * @param string $fallback The candidate project id.
	 *
	 * @return string
	 */
	private function createdProjectId(mixed $result, string $fallback): string {
		if (is_string($result) === true && $result !== '') {
			return $result;
		}

		if (is_array($result) === true && isset($result['id']) === true) {
			return (string)$result['id'];
		}

		return $fallback;
	}//end createdProjectId()

	/**
	 * Build a CospendLink from a normalised options map.
	 *
	 * Uses an options array instead of 10 individual parameters to keep the
	 * call sites readable and satisfy the PHPMD ExcessiveParameterList rule.
	 *
	 * Required keys: `entryType` (string), `projectId` (string),
	 * `uid` (string).
	 * Optional keys: `billId` (?int), `name` (string), `amount` (?float),
	 * `currency` (?string).
	 *
	 * @param LinkTarget          $target The object to link to.
	 * @param array<string,mixed> $opts   Options map — see keys above.
	 *
	 * @return CospendLink
	 */
	private function hydrateLink(LinkTarget $target, array $opts): CospendLink {
		$link = new CospendLink();
		$link->setEntryType((string)$opts['entryType']);
		$link->setProjectId((string)$opts['projectId']);
		$billId = null;
		if (isset($opts['billId']) === true) {
			$billId = (int)$opts['billId'];
		}

		$link->setBillId($billId);
		$link->setName((string)($opts['name'] ?? ''));

		$amount = null;
		if (isset($opts['amount']) === true) {
			$amount = (float)$opts['amount'];
		}

		$currency = null;
		if (isset($opts['currency']) === true) {
			$currency = (string)$opts['currency'];
		}

		$link->setAmount($amount);
		$link->setCurrency($currency);
		$target->stamp(link: $link, linkedBy: (string)$opts['uid']);

		return $link;
	}//end hydrateLink()

	/**
	 * Unlink a project / bill from an object.
	 *
	 * Does NOT delete the project/bill itself — it stays in NC Cospend.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $entryId Link row id.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink contract is owned by the integration-cospend capability.
	 */
	public function unlink(string $objectUuid, int $entryId): void {
		$this->requireUid();

		$deleted = $this->cospendLinkMapper->deleteByObjectAndId($objectUuid, $entryId);
		if ($deleted === 0) {
			throw new Exception('Cospend link not found', 404);
		}
	}//end unlink()

	/**
	 * Return the linked entries for an object, refreshing the cached
	 * amount/currency columns when a row is older than 24h.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-entries listing contract is owned
	 *              by the integration-cospend capability.
	 */
	public function getLinkedEntries(string $objectUuid): array {
		$links = $this->cospendLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isCospendAvailable();

		$results = [];
		foreach ($links as $link) {
			if ($available === true && $this->isStale(link: $link) === true) {
				$link = $this->refreshLink(link: $link);
			}

			$results[] = $this->withCospendUrl(row: $link->jsonSerialize());
		}

		return $results;
	}//end getLinkedEntries()

	/**
	 * Add the NC Cospend url to a link row that has a project.
	 *
	 * NC Cospend opens a project at /p/{projectId} and a specific bill at
	 * /p/{projectId}/b/{billId} (routes cospend.page.indexProject/indexBill).
	 *
	 * @param array<string,mixed> $row The serialised link row.
	 *
	 * @return array<string,mixed>
	 */
	private function withCospendUrl(array $row): array {
		$projectId = ($row['projectId'] ?? '');
		if ($projectId === '') {
			return $row;
		}

		$billId = ($row['billId'] ?? '');
		$billPath = '';
		if ($billId !== '') {
			$billPath = '/b/' . rawurlencode((string)$billId);
		}

		$row['url'] = '/apps/cospend/p/' . rawurlencode((string)$projectId) . $billPath;
		return $row;
	}//end withCospendUrl()

	/**
	 * Return the current user's NC Cospend projects (picker source).
	 *
	 * Optionally filtered by a name substring. Returns an empty array
	 * when Cospend is unavailable.
	 *
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-cospend capability.
	 */
	public function getAvailableProjects(?string $search = null): array {
		if ($this->isCospendAvailable() === false) {
			return [];
		}

		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return [];
		}

		return $this->queryProjects(uid: $uid, needle: $this->searchNeedle(search: $search ?? ''));
	}//end getAvailableProjects()

	/**
	 * The lower-cased name filter, or null for no filter.
	 *
	 * @param string $search The search text; '' for none.
	 *
	 * @return string|null
	 */
	private function searchNeedle(string $search): ?string {
		if ($search === '') {
			return null;
		}

		return mb_strtolower($search);
	}//end searchNeedle()

	/**
	 * Read the user's projects ordered by name; a failure is logged and yields none.
	 *
	 * @param string      $uid    The current user's id.
	 * @param string|null $needle Lower-cased name filter, or null.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function queryProjects(string $uid, ?string $needle): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from(self::PROJECTS_TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->orderBy('name', 'ASC');

			$result = $qb->executeQuery();
			$out = $this->collectProjects(result: $result, needle: $needle);
			$result->closeCursor();
			return $out;
		} catch (Throwable $e) {
			$this->logger->warning('CospendLinkService::getAvailableProjects failed: ' . $e->getMessage());
			return [];
		}//end try
	}//end queryProjects()

	/**
	 * Normalise the fetched project rows, keeping those whose name contains the needle.
	 *
	 * @param IResult     $result The project query result.
	 * @param string|null $needle Lower-cased name filter, or null.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collectProjects(IResult $result, ?string $needle): array {
		$out = [];
		$row = $result->fetch();
		while ($row !== false) {
			$project = $this->normaliseProjectRow(row: $row);
			$row = $result->fetch();
			if ($needle !== null && str_contains(mb_strtolower((string)$project['name']), $needle) === false) {
				continue;
			}

			$out[] = $project;
		}

		return $out;
	}//end collectProjects()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param CospendLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(CospendLink $link): bool {
		$linkedAt = $link->getLinkedAt();
		if ($linkedAt === null) {
			return true;
		}

		return (time() - $linkedAt->getTimestamp()) > self::STALE_AFTER;
	}//end isStale()

	/**
	 * Refresh a link row's cached name/amount/currency in place.
	 *
	 * Best-effort: when the project/bill can't be resolved the link is
	 * left untouched (it may have been deleted in NC Cospend).
	 *
	 * @param CospendLink $link The link row.
	 *
	 * @return CospendLink The (possibly updated) link row.
	 */
	private function refreshLink(CospendLink $link): CospendLink {
		$projectId = (string)$link->getProjectId();
		$project = $this->fetchProject(projectId: $projectId);
		if ($project === null) {
			return $link;
		}

		if ($link->getEntryType() === self::ENTRY_BILL && $link->getBillId() !== null) {
			$this->refreshBillFields(link: $link, projectId: $projectId);
		} else {
			$link->setName((string)($project['name'] ?? $link->getName()));
		}

		$link->setCurrency($project['currency'] ?? $link->getCurrency());
		$link->setLinkedAt(new DateTime());

		return $this->saveRefreshed(link: $link);
	}//end refreshLink()

	/**
	 * Copy the bill's current description and amount onto a bill link row, when the bill still exists.
	 *
	 * @param CospendLink $link      The bill link row.
	 * @param string      $projectId The bill's project id.
	 *
	 * @return void
	 */
	private function refreshBillFields(CospendLink $link, string $projectId): void {
		$bill = $this->fetchBill(projectId: $projectId, billId: (int)$link->getBillId());
		if ($bill !== null) {
			$link->setName((string)($bill['what'] ?? $link->getName()));
			$link->setAmount($bill['amount'] ?? $link->getAmount());
		}
	}//end refreshBillFields()

	/**
	 * Persist a refreshed link row; a failed save is logged and the in-memory row returned.
	 *
	 * @param CospendLink $link The refreshed link row.
	 *
	 * @return CospendLink
	 */
	private function saveRefreshed(CospendLink $link): CospendLink {
		try {
			return $this->cospendLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->logger->debug('CospendLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end saveRefreshed()

	/**
	 * Fetch a normalised Cospend project row by id.
	 *
	 * @param string $projectId The Cospend project id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchProject(string $projectId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from(self::PROJECTS_TABLE)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($projectId)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if ($row === false) {
				return null;
			}

			return $this->normaliseProjectRow(row: $row);
		} catch (Throwable $e) {
			$this->logger->debug('CospendLinkService::fetchProject failed: ' . $e->getMessage());
			return null;
		}//end try
	}//end fetchProject()

	/**
	 * Fetch a normalised Cospend bill row by project + bill id.
	 *
	 * @param string $projectId The Cospend project id.
	 * @param int $billId The Cospend bill id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchBill(string $projectId, int $billId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from(self::BILLS_TABLE)
				->where($qb->expr()->eq('id', $qb->createNamedParameter($billId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			if ($row === false) {
				return null;
			}

			$amount = null;
			if (isset($row['amount']) === true) {
				$amount = (float)$row['amount'];
			}

			return [
				'id' => (int)($row['id'] ?? 0),
				'projectId' => (string)($row['project_id'] ?? $projectId),
				'what' => (string)($row['what'] ?? ''),
				'amount' => $amount,
			];
		} catch (Throwable $e) {
			$this->logger->debug('CospendLinkService::fetchBill failed: ' . $e->getMessage());
			return null;
		}//end try
	}//end fetchBill()

	/**
	 * Normalise a raw cospend_projects row into the picker / cache shape.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseProjectRow(array $row): array {
		return [
			'id' => (string)($row['id'] ?? ''),
			'name' => (string)($row['name'] ?? ($row['id'] ?? '')),
			'currency' => (string)($row['currency_name'] ?? ''),
			'userId' => (string)($row['user_id'] ?? ''),
		];
	}//end normaliseProjectRow()
}//end class
