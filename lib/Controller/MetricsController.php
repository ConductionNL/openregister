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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
     * @param string          $appName        The application name
     * @param IRequest        $request        The HTTP request
     * @param IDBConnection   $db             Database connection
     * @param RegisterMapper  $registerMapper Register mapper
     * @param SchemaMapper    $schemaMapper   Schema mapper
     * @param IAppManager     $appManager     App manager
     * @param LoggerInterface $logger         Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private IDBConnection $db,
        private RegisterMapper $registerMapper,
        private SchemaMapper $schemaMapper,
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
            $register = $this->sanitizeLabel(value: $row['register_name'] ?? 'unknown');
            $schema   = $this->sanitizeLabel(value: $row['schema_name'] ?? 'unknown');
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

        return implode("\n", $lines)."\n";
    }//end collectMetrics()

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
