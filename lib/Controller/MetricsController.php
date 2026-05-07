<?php

/**
 * OpenRegister Metrics Controller
 *
 * Exposes application metrics in Prometheus text exposition format.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Controller for exposing Prometheus metrics.
 *
 * @psalm-suppress UnusedClass
 */
class MetricsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName    The application name
     * @param IRequest        $request    The HTTP request
     * @param IDBConnection   $db         Database connection
     * @param IAppManager     $appManager App manager
     * @param LoggerInterface $logger     Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return Prometheus metrics in text exposition format.
     *
     * @NoCSRFRequired
     *
     * @return TextPlainResponse Prometheus-formatted metrics
     */
    public function index(): TextPlainResponse
    {
        $metrics  = $this->collectMetrics();
        $response = new TextPlainResponse($metrics);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;
    }//end index()

    /**
     * Collect all metrics and format as Prometheus text.
     *
     * @return string Prometheus exposition format text
     */
    private function collectMetrics(): string
    {
        $lines = [];

        // App info gauge.
        $version    = $this->getAppVersion();
        $phpVersion = PHP_VERSION;

        $lines[] = '# HELP openregister_info Application information';
        $lines[] = '# TYPE openregister_info gauge';
        $lines[] = 'openregister_info{version="'.$version.'",php_version="'.$phpVersion.'"} 1';
        $lines[] = '';

        // App up gauge.
        $lines[] = '# HELP openregister_up Whether the application is healthy';
        $lines[] = '# TYPE openregister_up gauge';
        $lines[] = 'openregister_up 1';
        $lines[] = '';

        // Registers total.
        $registersTotal = $this->countTable(table: 'openregister_registers');
        $lines[]        = '# HELP openregister_registers_total Total number of registers';
        $lines[]        = '# TYPE openregister_registers_total gauge';
        $lines[]        = 'openregister_registers_total '.$registersTotal;
        $lines[]        = '';

        // Schemas total.
        $schemasTotal = $this->countTable(table: 'openregister_schemas');
        $lines[]      = '# HELP openregister_schemas_total Total number of schemas';
        $lines[]      = '# TYPE openregister_schemas_total gauge';
        $lines[]      = 'openregister_schemas_total '.$schemasTotal;
        $lines[]      = '';

        // Objects total (by register and schema).
        $lines[]      = '# HELP openregister_objects_total Total objects by register and schema';
        $lines[]      = '# TYPE openregister_objects_total gauge';
        $objectCounts = $this->getObjectCountsByRegisterAndSchema();
        foreach ($objectCounts as $row) {
            $register = $this->sanitizeLabel(value: $row['register_name']);
            $schema   = $this->sanitizeLabel(value: $row['schema_name']);
            $count    = (int) $row['object_count'];
            $lines[]  = 'openregister_objects_total{register="'.$register.'",schema="'.$schema.'"} '.$count;
        }

        $lines[] = '';

        // Search requests total (from metrics table if it exists).
        $searchCount = $this->countMetricsByType(typePrefix: 'search_');
        $lines[]     = '# HELP openregister_search_requests_total Total search requests';
        $lines[]     = '# TYPE openregister_search_requests_total counter';
        $lines[]     = 'openregister_search_requests_total '.$searchCount;
        $lines[]     = '';

        // CRUD operation counters sourced from the audit-trail ledger.
        // Lifetime counters reset only on audit-trail truncation, so they
        // satisfy Prometheus counter monotonicity for typical operations.
        $crudCounts = $this->getCrudCountsByAction();
        $lines[]    = '# HELP openregister_objects_created_total Total object create operations recorded in the audit trail';
        $lines[]    = '# TYPE openregister_objects_created_total counter';
        $lines[]    = 'openregister_objects_created_total '.($crudCounts['create'] ?? 0);
        $lines[]    = '';
        $lines[]    = '# HELP openregister_objects_updated_total Total object update operations recorded in the audit trail';
        $lines[]    = '# TYPE openregister_objects_updated_total counter';
        $lines[]    = 'openregister_objects_updated_total '.($crudCounts['update'] ?? 0);
        $lines[]    = '';
        $lines[]    = '# HELP openregister_objects_deleted_total Total object delete operations recorded in the audit trail';
        $lines[]    = '# TYPE openregister_objects_deleted_total counter';
        $lines[]    = 'openregister_objects_deleted_total '.($crudCounts['delete'] ?? 0);
        $lines[]    = '';
        $lines[]    = '# HELP openregister_objects_read_total Total object read operations recorded in the audit trail';
        $lines[]    = '# TYPE openregister_objects_read_total counter';
        $lines[]    = 'openregister_objects_read_total '.($crudCounts['read'] ?? 0);
        $lines[]    = '';

        // Webhook delivery counters from the webhook log table.
        $webhookCounts = $this->getWebhookCountsByStatus();
        $lines[]       = '# HELP openregister_webhook_deliveries_total Total webhook delivery attempts grouped by status';
        $lines[]       = '# TYPE openregister_webhook_deliveries_total counter';
        foreach ($webhookCounts as $status => $count) {
            $statusLabel = $this->sanitizeLabel(value: (string) $status);
            $lines[]     = 'openregister_webhook_deliveries_total{status="'.$statusLabel.'"} '.$count;
        }

        $lines[] = '';

        return implode("\n", $lines)."\n";
    }//end collectMetrics()

    /**
     * Aggregate audit-trail rows by action.
     *
     * Returns a map keyed by action ('create', 'update', 'delete', 'read',
     * etc.) where each value is the lifetime count for that action. Used
     * as the data source for `openregister_objects_{action}_total`
     * counters. Failures (table missing, permission denied) collapse to
     * an empty array — the counter then emits 0.
     *
     * @return array<string, int> Map action => count
     */
    private function getCrudCountsByAction(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('action')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('openregister_audit_trails')
                ->groupBy('action');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            $out = [];
            foreach ($rows as $row) {
                $action       = (string) ($row['action'] ?? '');
                $out[$action] = (int) ($row['cnt'] ?? 0);
            }

            return $out;
        } catch (\Exception $e) {
            $this->logger->warning(
                '[MetricsController] Failed to aggregate audit trails by action',
                ['error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getCrudCountsByAction()

    /**
     * Aggregate webhook delivery log rows by status.
     *
     * The webhook log stores delivery outcome as a boolean `success`
     * column; this method projects that into the conventional Prometheus
     * status label vocabulary ('success' / 'failure'). Used as the data
     * source for the labelled
     * `openregister_webhook_deliveries_total{status}` counter so
     * operators can alert on rising failure rates without label
     * cardinality explosions. Failures (table missing, permission
     * denied) collapse to an empty array.
     *
     * @return array<string, int> Map status => count
     */
    private function getWebhookCountsByStatus(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('success')
                ->selectAlias($qb->func()->count('*'), 'cnt')
                ->from('openregister_webhook_logs')
                ->groupBy('success');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            $out = [];
            foreach ($rows as $row) {
                $isSuccess = (bool) ($row['success'] ?? false);
                $status    = 'failure';
                if ($isSuccess === true) {
                    $status = 'success';
                }

                $out[$status] = (int) ($row['cnt'] ?? 0);
            }

            return $out;
        } catch (\Exception $e) {
            return [];
        }//end try
    }//end getWebhookCountsByStatus()

    /**
     * Count rows in a database table.
     *
     * @param string $table The table name (without prefix)
     *
     * @return int Row count
     */
    private function countTable(string $table): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))
                ->from($table);
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to count table '.$table, ['error' => $e->getMessage()]);
            return 0;
        }
    }//end countTable()

    /**
     * Get object counts grouped by register and schema.
     *
     * @return array<array{register_name: string, schema_name: string, object_count: string}> Grouped counts
     */
    private function getObjectCountsByRegisterAndSchema(): array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('r.title AS register_name', 's.title AS schema_name')
                ->selectAlias($qb->func()->count('o.id'), 'object_count')
                ->from('openregister_objects', 'o')
                ->leftJoin('o', 'openregister_registers', 'r', $qb->expr()->eq('o.register', 'r.id'))
                ->leftJoin('o', 'openregister_schemas', 's', $qb->expr()->eq('o.schema', 's.id'))
                ->groupBy('o.register', 'o.schema', 'r.title', 's.title');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            return $rows;
        } catch (\Exception $e) {
            $this->logger->warning('[MetricsController] Failed to get object counts', ['error' => $e->getMessage()]);
            return [];
        }
    }//end getObjectCountsByRegisterAndSchema()

    /**
     * Count metrics entries by type prefix.
     *
     * @param string $typePrefix The metric type prefix to match
     *
     * @return int Count of matching metrics
     */
    private function countMetricsByType(string $typePrefix): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))
                ->from('openregister_metrics')
                ->where($qb->expr()->like('metric_type', $qb->createNamedParameter($typePrefix.'%')));

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            return (int) ($row['cnt'] ?? 0);
        } catch (\Exception $e) {
            // Table may not exist yet.
            return 0;
        }
    }//end countMetricsByType()

    /**
     * Get the app version from the app manager.
     *
     * @return string The app version
     */
    private function getAppVersion(): string
    {
        try {
            return $this->appManager->getAppVersion('openregister');
        } catch (\Exception $e) {
            return 'unknown';
        }
    }//end getAppVersion()

    /**
     * Sanitize a label value for Prometheus format.
     *
     * @param string $value The label value
     *
     * @return string Sanitized label value
     */
    private function sanitizeLabel(string $value): string
    {
        // Escape backslashes, double quotes, and newlines.
        return str_replace(
            ['\\', '"', "\n"],
            ['\\\\', '\\"', '\\n'],
            $value
        );
    }//end sanitizeLabel()
}//end class
