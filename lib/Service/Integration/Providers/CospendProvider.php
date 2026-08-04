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
use OCA\OpenRegister\Db\CospendLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
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
class CospendProvider extends AbstractIntegrationProvider
{

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
     * Constructor.
     *
     * @param IDBConnection           $db                NC DB connection.
     * @param IAppManager             $appManager        NC app manager.
     * @param IL10N                   $l10n              Localisation.
     * @param CospendLinkMapper       $cospendLinkMapper Tier-2 link table mapper.
     * @param ContainerInterface|null $container         Optional server-container override (tests).
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
        private IL10N $l10n,
        private CospendLinkMapper $cospendLinkMapper,
        ?ContainerInterface $container=null,
    ) {
        $this->container = $container;
    }//end __construct()

    public function getId(): string
    {
        return 'cospend';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Costs');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'CurrencyEur';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List NC Cospend entries linked to an OR object.
     *
     * Reads the Tier-2 link table first; if no link rows exist it falls
     * back to the legacy `[or:{uuid}]` marker scan in
     * `cospend_projects.name`, surfacing each matched project plus its
     * bills (the wave-2.3 two-type shape).
     *
     * @param string              $register Register slug for the parent object.
     * @param string              $schema   Schema slug for the parent object.
     * @param string              $objectId UUID of the OR object whose rows we want.
     * @param array<string,mixed> $filters  Optional registry filters (unused).
     *
     * @return array<int,array<string,mixed>> List of registry leaf rows.
     *
     * @spec openspec/specs/integration-cospend/spec.md
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        // Tier-2 path: read from the link table.
        try {
            $linkRows = $this->cospendLinkMapper->findByObjectUuid($objectId);
        } catch (Throwable $e) {
            $linkRows = [];
        }

        if (count($linkRows) > 0) {
            return array_map(
                fn (CospendLink $link): array => $this->rowFromLink(link: $link),
                $linkRows
            );
        }

        // Backwards-compat fallback: scan the legacy `[or:{uuid}]` marker
        // in `cospend_projects.name`, surfacing projects + their bills.
        return $this->legacyMarkerList(objectId: $objectId);
    }//end list()

    /**
     * Convert a CospendLink row into the registry leaf-row shape.
     *
     * Mirrors the wave-2.3 project/bill row contract so the bespoke
     * CnCospendTab renders link-table rows identically to legacy rows.
     *
     * @param CospendLink $link Link row from the mapper.
     *
     * @return array<string,mixed>
     */
    private function rowFromLink(CospendLink $link): array
    {
        $projectId = (string) $link->getProjectId();
        $entryType = (string) $link->getEntryType();
        $billId    = $link->getBillId();

        $id = $projectId;
        if ($entryType === 'bill' && $billId !== null) {
            $id = $projectId.'/'.$billId;
        }

        return [
            'type'     => $entryType,
            'id'       => $id,
            'entryId'  => $link->getId(),
            'title'    => (string) $link->getName(),
            'url'      => '/index.php/apps/cospend/p/'.$projectId,
            'amount'   => $link->getAmount(),
            'currency' => $link->getCurrency(),
            'data'     => $link->jsonSerialize(),
        ];
    }//end rowFromLink()

    /**
     * Legacy marker-scan list (projects + bills).
     *
     * @param string $objectId Owning object uuid.
     *
     * @return array<int,array<string,mixed>>
     */
    private function legacyMarkerList(string $objectId): array
    {
        try {
            $projectMapper = $this->lookup(serviceName: 'OCA\\Cospend\\Db\\ProjectMapper');
        } catch (Throwable $e) {
            return [];
        }

        $projects = $this->findProjectsByMarker(projectMapper: $projectMapper, objectId: $objectId);
        if (count($projects) === 0) {
            return [];
        }

        $billMapper = null;
        try {
            $billMapper = $this->lookup(serviceName: 'OCA\\Cospend\\Db\\BillMapper');
        } catch (Throwable $e) {
            $billMapper = null;
        }

        $rows = [];
        foreach ($projects as $project) {
            $projectRow = $this->normaliseProject(project: $project, objectId: $objectId);
            $rows[]     = $projectRow;
            $rows       = array_merge(
                $rows,
                $this->collectBills(
                    billMapper: $billMapper,
                    projectId: (string) $projectRow['data']['id'],
                    projectName: (string) $projectRow['title'],
                    currencyName: (string) ($projectRow['data']['currencyName'] ?? '')
                )
            );
        }

        return $rows;
    }//end legacyMarkerList()

    /**
     * Provider health descriptor (enabled/disabled echo).
     *
     * @return array<string,mixed>
     *
     * @spec exclude Static enabled/disabled descriptor echoing isEnabled() — no standalone health behaviour;
     *              the health/OCS contract is owned by pluggable-integration-registry task-2.
     */
    public function health(): array
    {
        $status  = 'unavailable';
        $message = 'NC Cospend app is not installed';
        if ($this->isEnabled() === true) {
            $status  = 'ok';
            $message = null;
        }

        return [
            'status'     => $status,
            'authStatus' => 'configured',
            'message'    => $message,
        ];
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
    private function lookup(string $serviceName): object
    {
        if ($this->container !== null) {
            $resolved = $this->container->get($serviceName);
            if (is_object($resolved) === false) {
                throw new RuntimeException(sprintf('Container returned non-object for %s', $serviceName));
            }

            return $resolved;
        }

        return Server::get($serviceName);
    }//end lookup()

    /**
     * Find NC Cospend projects whose `name` contains the OR object marker.
     *
     * @param object $projectMapper NC Cospend ProjectMapper instance.
     * @param string $objectId      Owning object uuid.
     *
     * @return array<int,object> Matching project rows (loose-typed).
     */
    private function findProjectsByMarker(object $projectMapper, string $objectId): array
    {
        $marker = self::MARKER_PREFIX.$objectId.']';

        try {
            $tableName = $projectMapper->getTableName();
            $qb        = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName)
                ->where(
                    $qb->expr()->iLike(
                        'name',
                        $qb->createNamedParameter('%'.$this->db->escapeLikeParameter($marker).'%')
                    )
                )
                ->orderBy('last_changed', 'DESC');

            $result = $qb->executeQuery();
            $rows   = [];
            $row    = $result->fetch();
            while ($row !== false) {
                $rows[] = (object) $row;
                $row    = $result->fetch();
            }

            $result->closeCursor();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }//end try
    }//end findProjectsByMarker()

    /**
     * Normalise a raw cospend_projects row into a registry leaf row.
     *
     * @param object $project  Raw project row.
     * @param string $objectId Owning object uuid (used to strip the marker).
     *
     * @return array<string,mixed>
     */
    private function normaliseProject(object $project, string $objectId): array
    {
        $lastChangedColumn = 'last_changed';
        $lastChanged       = null;
        if (isset($project->{$lastChangedColumn}) === true) {
            $lastChanged = (int) $project->{$lastChangedColumn};
        }

        $userIdColumn   = 'user_id';
        $currencyColumn = 'currency_name';

        $marker  = self::MARKER_PREFIX.$objectId.']';
        $rawName = '';
        if (isset($project->name) === true) {
            $rawName = (string) $project->name;
        }

        $cleanName = trim(str_replace($marker, '', $rawName));

        $id = '';
        if (isset($project->id) === true) {
            $id = (string) $project->id;
        }

        $userId = '';
        if (isset($project->{$userIdColumn}) === true) {
            $userId = (string) $project->{$userIdColumn};
        }

        $currency = '';
        if (isset($project->{$currencyColumn}) === true) {
            $currency = (string) $project->{$currencyColumn};
        }

        return [
            'type'        => 'project',
            'id'          => $id,
            'title'       => $cleanName,
            'description' => $userId,
            'url'         => '/index.php/apps/cospend/p/'.$id,
            'lastUpdated' => $lastChanged,
            'data'        => [
                'id'           => $id,
                'name'         => $cleanName,
                'userId'       => $userId,
                'currencyName' => $currency,
                'lastChanged'  => $lastChanged,
            ],
        ];
    }//end normaliseProject()

    /**
     * Collect bill rows for a single linked project (legacy fallback).
     *
     * @param object|null $billMapper   BillMapper instance (or null).
     * @param string      $projectId    Cospend project id.
     * @param string      $projectName  Cleaned project name (label fallback).
     * @param string      $currencyName Currency name carried from the project.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectBills(
        ?object $billMapper,
        string $projectId,
        string $projectName,
        string $currencyName
    ): array {
        if ($billMapper === null) {
            return [];
        }

        try {
            $tableName = $billMapper->getTableName();
            $qb        = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName)
                ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId)))
                ->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0)))
                ->orderBy('timestamp', 'DESC')
                ->setMaxResults(self::BILLS_PER_PROJECT);

            $result = $qb->executeQuery();
            $rows   = [];
            $raw    = $result->fetch();
            while ($raw !== false) {
                $rows[] = $this->normaliseBill(
                    bill: (object) $raw,
                    projectId: $projectId,
                    projectName: $projectName,
                    currencyName: $currencyName
                );
                $raw    = $result->fetch();
            }

            $result->closeCursor();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }//end try
    }//end collectBills()

    /**
     * Normalise a raw cospend_bills row into a leaf row.
     *
     * @param object $bill         Raw bill row.
     * @param string $projectId    Cospend project id.
     * @param string $projectName  Cleaned project name (label fallback).
     * @param string $currencyName Currency name carried from the project.
     *
     * @return array<string,mixed>
     */
    private function normaliseBill(
        object $bill,
        string $projectId,
        string $projectName,
        string $currencyName
    ): array {
        $billId = '';
        if (isset($bill->id) === true) {
            $billId = (string) $bill->id;
        }

        $what = '';
        if (isset($bill->what) === true) {
            $what = (string) $bill->what;
        }

        $amount = 0.0;
        if (isset($bill->amount) === true) {
            $amount = (float) $bill->amount;
        }

        $tsCandidate = null;
        if (isset($bill->timestamp) === true) {
            $tsCandidate = (int) $bill->timestamp;
        }

        $payerColumn = 'payer_id';
        $payerId     = null;
        if (isset($bill->{$payerColumn}) === true) {
            $payerId = (int) $bill->{$payerColumn};
        }

        $isoTimestamp = '';
        if ($tsCandidate !== null) {
            $isoTimestamp = gmdate('c', $tsCandidate);
        }

        $title = $projectName;
        if ($what !== '') {
            $title = $what;
        }

        return [
            'type'        => 'bill',
            'id'          => $projectId.'/'.$billId,
            'title'       => $title,
            'description' => $isoTimestamp,
            'url'         => '/index.php/apps/cospend/p/'.$projectId,
            'lastUpdated' => $tsCandidate,
            'data'        => [
                'id'           => $billId,
                'projectId'    => $projectId,
                'what'         => $what,
                'amount'       => $amount,
                'payerId'      => $payerId,
                'timestamp'    => $tsCandidate,
                'currencyName' => $currencyName,
            ],
        ];
    }//end normaliseBill()
}//end class
