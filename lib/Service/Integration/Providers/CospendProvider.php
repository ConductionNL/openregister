<?php

/**
 * CospendProvider — exposes NC Cospend (Costs) projects + bills linked to
 * an OpenRegister object.
 *
 * Tier-2 (this file) reads the dedicated `openregister_cospend_links`
 * table via {@see CospendLinkMapper}. Pre-Tier-2 the provider matched a
 * `[or:{objectUuid}]` marker embedded in a project's `name` field; that
 * convention is retained as a backwards-compat fallback (two-type shape:
 * a project row followed by its bill rows) for projects that pre-date the
 * link table. Mapper lookups for the legacy fallback resolve NC Cospend's
 * `ProjectMapper` / `BillMapper` lazily through the server container so
 * the file loads even when the Cospend app is not installed (AD-23:
 * graceful degradation).
 *
 * Storage strategy is `link-table` — Tier-2 link rows live in OR; the
 * upstream `cospend_projects` / `cospend_bills` tables are only read for
 * the legacy marker fallback.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Db\CospendLink;
use OCA\OpenRegister\Db\CospendLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Cospend (NC Cospend) integration provider.
 *
 * Always-on metadata: id='cospend', group='workflow',
 * requiredApp='cospend', storage='link-table'.
 */
class CospendProvider extends AbstractIntegrationProvider {

	use NcAppLinkTableProvider;

	/**
	 * NC app id required for this integration.
	 *
	 * @var string
	 */
	private const REQUIRED_APP = 'cospend';

	/**
	 * Marker prefix used by the legacy Tier-1 link convention.
	 *
	 * @var string
	 */
	private const MARKER_PREFIX = '[or:';

	/**
	 * Maximum number of bills to surface per linked project (legacy
	 * fallback path).
	 *
	 * @var int
	 */
	private const BILLS_PER_PROJECT = 25;

	/**
	 * Optional server container override for the legacy fallback mapper
	 * lookups. Tests inject a mock; production uses `\OCP\Server`.
	 *
	 * @var ContainerInterface|null
	 */
	private ?ContainerInterface $container;

	/**
	 * NC DB connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param LeafProviderPlatform       $platform          The DB connection, app manager and localisation.
	 * @param CospendLinkMapperInterface $cospendLinkMapper Tier-2 link table mapper.
	 * @param ContainerInterface|null    $container         Optional server-container override (tests).
	 *
	 * @return void
	 */
	public function __construct(
		LeafProviderPlatform $platform,
		private CospendLinkMapperInterface $cospendLinkMapper,
		?ContainerInterface $container = null,
	) {
		$this->db = $platform->db;
		$this->appManager = $platform->appManager;
		$this->l10n = $platform->l10n;
		$this->container = $container;
	}//end __construct()

	public function getId(): string {
		return 'cospend';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Costs');
	}//end getLabel()

	public function getIcon(): string {
		return 'CurrencyEur';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	/**
	 * The link mapper this provider reads first.
	 *
	 * @return CospendLinkMapperInterface
	 */
	protected function linkMapper(): CospendLinkMapperInterface {
		return $this->cospendLinkMapper;
	}//end linkMapper()

	/**
	 * Convert a CospendLink row into the registry leaf-row shape.
	 *
	 * Mirrors the wave-2.3 project/bill row contract so the bespoke
	 * CnCospendTab renders link-table rows identically to legacy rows.
	 *
	 * @param CospendLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-cospend/spec.md
	 */
	protected function rowFromLink(CospendLink $link): array {
		return [
			'type' => (string)$link->getEntryType(),
			'id' => $this->entryIdOf(link: $link),
			'entryId' => $link->getId(),
			'title' => (string)$link->getName(),
			'url' => '/index.php/apps/cospend/p/' . (string)$link->getProjectId(),
			'amount' => $link->getAmount(),
			'currency' => $link->getCurrency(),
			'data' => $link->jsonSerialize(),
		];
	}//end rowFromLink()

	/**
	 * The row id of a link: the project id, or `project/bill` for a bill.
	 *
	 * @param CospendLink $link Link row.
	 *
	 * @return string
	 */
	private function entryIdOf(CospendLink $link): string {
		$projectId = (string)$link->getProjectId();
		if ((string)$link->getEntryType() === 'bill' && $link->getBillId() !== null) {
			return $projectId . '/' . $link->getBillId();
		}

		return $projectId;
	}//end entryIdOf()

	/**
	 * Legacy fallback: the projects whose `name` carries the `[or:{uuid}]`
	 * marker, each followed by its bills (the wave-2.3 two-type shape).
	 *
	 * @param string $objectId Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function unlinkedRows(string $objectId): array {
		$projectMapper = $this->serviceOrNull(serviceName: 'OCA\\Cospend\\Db\\ProjectMapper');
		if ($projectMapper === null) {
			return [];
		}

		$projects = $this->findProjectsByMarker(projectMapper: $projectMapper, objectId: $objectId);
		if (count($projects) === 0) {
			return [];
		}

		return $this->withBills(projects: $projects, billMapper: $this->serviceOrNull(serviceName: 'OCA\\Cospend\\Db\\BillMapper'));
	}//end unlinkedRows()

	/**
	 * Each project row followed by its bill rows.
	 *
	 * @param array<int,array<string,mixed>> $projects   Project leaf rows.
	 * @param object|null                    $billMapper NC Cospend BillMapper, if it resolved.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function withBills(array $projects, ?object $billMapper): array {
		$rows = [];
		foreach ($projects as $project) {
			$rows = array_merge($rows, [$project], $this->collectBills(billMapper: $billMapper, project: $project));
		}

		return $rows;
	}//end withBills()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
	 *              the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->isEnabled(), missing: 'NC Cospend app is not installed');
	}//end health()

	/**
	 * Resolve a service from the container (legacy fallback).
	 *
	 * @param string $serviceName Fully qualified class name to resolve.
	 *
	 * @return object Resolved service instance.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the
	 * NC-canonical service-locator entry point for late-bound classes.
	 */
	private function lookup(string $serviceName): object {
		if ($this->container !== null) {
			$resolved = $this->container->get($serviceName);
			if (is_object($resolved) === false) {
				throw new RuntimeException(sprintf('Container returned non-object for %s', $serviceName));
			}

			return $resolved;
		}

		// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- Last-resort fallback for a provider constructed without a container; the injected one is tried first, just above.
		return Server::get($serviceName);
	}//end lookup()

	/**
	 * A service from the container, or null when it does not resolve.
	 *
	 * @param string $serviceName Fully qualified class name to resolve.
	 *
	 * @return object|null
	 */
	private function serviceOrNull(string $serviceName): ?object {
		try {
			return $this->lookup(serviceName: $serviceName);
		} catch (Throwable $e) {
			return null;
		}
	}//end serviceOrNull()

	/**
	 * The NC Cospend projects whose `name` contains the OR object marker, as leaf rows.
	 *
	 * @param object $projectMapper NC Cospend ProjectMapper instance.
	 * @param string $objectId      Owning object uuid.
	 *
	 * @return array<int,array<string,mixed>> Project leaf rows; none when the query fails.
	 */
	private function findProjectsByMarker(object $projectMapper, string $objectId): array {
		try {
			$rows = $this->fetchRows(qb: $this->projectQuery(projectMapper: $projectMapper, marker: self::MARKER_PREFIX . $objectId . ']'));
		} catch (Throwable $e) {
			return [];
		}

		return array_map(fn (array $row): array => $this->normaliseProject(project: (object)$row, objectId: $objectId), $rows);
	}//end findProjectsByMarker()

	/**
	 * The query for the projects whose name contains the marker, newest first.
	 *
	 * @param object $projectMapper NC Cospend ProjectMapper instance.
	 * @param string $marker        The `[or:{uuid}]` marker.
	 *
	 * @return IQueryBuilder
	 */
	private function projectQuery(object $projectMapper, string $marker): IQueryBuilder {
		$tableName = $projectMapper->getTableName();
		$qb = $this->db->getQueryBuilder();
		$like = '%' . $this->db->escapeLikeParameter($marker) . '%';

		return $qb->select('*')
			->from($tableName)
			->where($qb->expr()->iLike('name', $qb->createNamedParameter($like)))
			->orderBy('last_changed', 'DESC');
	}//end projectQuery()

	/**
	 * Every row a query returns, cursor closed.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchRows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end fetchRows()

	/**
	 * Normalise a raw cospend_projects row into a registry leaf row.
	 *
	 * @param object $project  Raw project row.
	 * @param string $objectId Owning object uuid (used to strip the marker).
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseProject(object $project, string $objectId): array {
		$data = $this->projectData(project: $project, objectId: $objectId);

		return [
			'type' => 'project',
			'id' => $data['id'],
			'title' => $data['name'],
			'description' => $data['userId'],
			'url' => '/index.php/apps/cospend/p/' . $data['id'],
			'lastUpdated' => $data['lastChanged'],
			'data' => $data,
		];
	}//end normaliseProject()

	/**
	 * The `data` block of a project row: id, marker-free name, owner, currency, last change.
	 *
	 * @param object $project  Raw project row.
	 * @param string $objectId Owning object uuid (used to strip the marker).
	 *
	 * @return array{id: string, name: string, userId: string, currencyName: string, lastChanged: int|null}
	 */
	private function projectData(object $project, string $objectId): array {
		$marker = self::MARKER_PREFIX . $objectId . ']';

		return [
			'id' => (string)($project->id ?? ''),
			'name' => trim(str_replace($marker, '', (string)($project->name ?? ''))),
			'userId' => (string)($project->{'user_id'} ?? ''),
			'currencyName' => (string)($project->{'currency_name'} ?? ''),
			'lastChanged' => $this->intOrNull(value: $project->{'last_changed'} ?? null),
		];
	}//end projectData()

	/**
	 * The bill rows of one linked project (legacy fallback); none without a bill mapper or when the query fails.
	 *
	 * @param object|null         $billMapper NC Cospend BillMapper, if it resolved.
	 * @param array<string,mixed> $project    The project leaf row.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collectBills(?object $billMapper, array $project): array {
		if ($billMapper === null) {
			return [];
		}

		try {
			$rows = $this->fetchRows(qb: $this->billQuery(billMapper: $billMapper, projectId: (string)$project['data']['id']));
		} catch (Throwable $e) {
			return [];
		}

		return array_map(fn (array $row): array => $this->normaliseBill(bill: (object)$row, project: $project), $rows);
	}//end collectBills()

	/**
	 * The query for a project's newest non-deleted bills.
	 *
	 * @param object $billMapper NC Cospend BillMapper instance.
	 * @param string $projectId  Cospend project id.
	 *
	 * @return IQueryBuilder
	 */
	private function billQuery(object $billMapper, string $projectId): IQueryBuilder {
		$tableName = $billMapper->getTableName();
		$qb = $this->db->getQueryBuilder();

		return $qb->select('*')
			->from($tableName)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0)))
			->orderBy('timestamp', 'DESC')
			->setMaxResults(self::BILLS_PER_PROJECT);
	}//end billQuery()

	/**
	 * Normalise a raw cospend_bills row into a leaf row.
	 *
	 * @param object              $bill    Raw bill row.
	 * @param array<string,mixed> $project The project leaf row the bill belongs to.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseBill(object $bill, array $project): array {
		$data = $this->billData(bill: $bill, project: $project);

		return [
			'type' => 'bill',
			'id' => $data['projectId'] . '/' . $data['id'],
			'title' => $this->billTitle(what: $data['what'], projectName: (string)$project['title']),
			'description' => $this->isoTimestamp(timestamp: $data['timestamp']),
			'url' => '/index.php/apps/cospend/p/' . $data['projectId'],
			'lastUpdated' => $data['timestamp'],
			'data' => $data,
		];
	}//end normaliseBill()

	/**
	 * The `data` block of a bill row; project id and currency come from the project.
	 *
	 * @param object              $bill    Raw bill row.
	 * @param array<string,mixed> $project The project leaf row.
	 *
	 * @return array{id: string, projectId: string, what: string, amount: float, payerId: int|null, timestamp: int|null, currencyName: string}
	 */
	private function billData(object $bill, array $project): array {
		return [
			'id' => (string)($bill->id ?? ''),
			'projectId' => (string)$project['data']['id'],
			'what' => (string)($bill->what ?? ''),
			'amount' => (float)($bill->amount ?? 0.0),
			'payerId' => $this->intOrNull(value: $bill->{'payer_id'} ?? null),
			'timestamp' => $this->intOrNull(value: $bill->timestamp ?? null),
			'currencyName' => (string)($project['data']['currencyName'] ?? ''),
		];
	}//end billData()

	/**
	 * A bill's title: what it was for, else the project name.
	 *
	 * @param string $what        The bill's `what`.
	 * @param string $projectName The project name.
	 *
	 * @return string
	 */
	private function billTitle(string $what, string $projectName): string {
		if ($what !== '') {
			return $what;
		}

		return $projectName;
	}//end billTitle()

	/**
	 * An ISO-8601 UTC timestamp, or '' when there is none.
	 *
	 * @param int|null $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	private function isoTimestamp(?int $timestamp): string {
		if ($timestamp === null) {
			return '';
		}

		return gmdate('c', $timestamp);
	}//end isoTimestamp()

	/**
	 * A column value as an int, or null when it is absent.
	 *
	 * @param mixed $value Raw column value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()
}//end class
