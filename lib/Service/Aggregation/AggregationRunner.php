<?php

/**
 * AggregationRunner — executes time-bucket and scalar aggregation queries.
 *
 * Dispatches to the native SQL path for PostgreSQL, MySQL, and SQLite, and
 * falls back to the PHP bucketInPhp() path for all other engines.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Runs aggregation queries against dynamic magic-table storage.
 *
 * Native paths are selected in priority order: PostgreSQL → MySQL → SQLite →
 * PHP fallback.  All native paths emit the same ISO-8601-UTC bucket key
 * format; coerceBucketKey() normalises any remaining variance.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class AggregationRunner
{

    /**
     * Maximum rows to fetch in the PHP fallback path.
     *
     * @var int
     */
    private const PHP_FALLBACK_ROW_CAP = 50000;

    /**
     * Magic table prefix (without the oc_ NC prefix).
     *
     * @var string
     */
    private const TABLE_PREFIX = 'openregister_table_';

    /**
     * Constructor.
     *
     * @param IDBConnection    $db     Database connection.
     * @param AggregationCache $cache  Aggregation result cache.
     * @param LoggerInterface  $logger Logger.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly AggregationCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Execute an ad-hoc aggregation with read-through caching.
     *
     * On cache hit: returns the stored envelope with cached=true without
     * touching the database.  On miss: runs the aggregation, stores the
     * result, and returns it with cached=false.
     *
     * @param string           $registerSlug Register slug.
     * @param string           $schemaSlug   Schema slug.
     * @param AggregationQuery $query        Aggregation parameters.
     *
     * @return array{groups?: list<array>, value?: int|float, backend: string, cached: bool}
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.3
     */
    public function runAdhoc(string $registerSlug, string $schemaSlug, AggregationQuery $query): array
    {
        // Cache read.
        $cached = $this->cache->getAdhoc(registerSlug: $registerSlug, schemaSlug: $schemaSlug, query: $query);
        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }

        // Cache miss — execute aggregation.
        $envelope           = $this->executeAggregationBySlug(schemaSlug: $schemaSlug, query: $query);
        $envelope['cached'] = false;

        // Write to cache (no-op if cache unavailable).
        $this->cache->setAdhoc(
            registerSlug: $registerSlug,
            schemaSlug: $schemaSlug,
            query: $query,
            envelope: $envelope
        );

        return $envelope;
    }//end runAdhoc()

    /**
     * Execute the aggregation by schema slug (native or PHP fallback).
     *
     * @param string           $schemaSlug Schema slug.
     * @param AggregationQuery $query      Aggregation parameters.
     *
     * @return array{groups?: list<array>, value?: int|float, backend: string}
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1
     */
    private function executeAggregationBySlug(string $schemaSlug, AggregationQuery $query): array
    {
        if ($query->getDateBucket() !== null) {
            $native = $this->tryNativeAggregation(schemaSlug: $schemaSlug, query: $query);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->bucketInPhp(schemaSlug: $schemaSlug, query: $query);
    }//end executeAggregationBySlug()

    /**
     * Attempt a native SQL time-bucket aggregation.
     *
     * Returns null when the database platform is not recognised, allowing
     * the caller to fall through to the PHP path.
     *
     * @param string           $schemaSlug Schema slug.
     * @param AggregationQuery $query      Aggregation parameters.
     *
     * @return array|null Result envelope or null if the platform is unsupported.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.2
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.3
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.4
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function tryNativeAggregation(string $schemaSlug, AggregationQuery $query): ?array
    {
        $platform  = $this->detectDatabasePlatform();
        $tableName = self::TABLE_PREFIX.$schemaSlug;
        $gap       = $query->getDateBucket();
        $field     = $query->getField() ?? '_created';
        $metricSql = $this->buildMetricSql(metric: $query->getMetric(), field: $field, platform: $platform);

        switch ($platform) {
            case 'postgres':
                $bucketExpr = $this->postgresBucketExpression(field: $field, gap: $gap ?? 'day');
                $quote      = '"';
                break;

            case 'mysql':
                $bucketExpr = $this->mysqlBucketExpression(field: $field, gap: $gap ?? 'day');
                $quote      = '`';
                break;

            case 'sqlite':
                $bucketExpr = $this->sqliteBucketExpression(field: $field, gap: $gap ?? 'day');
                $quote      = '"';
                break;

            default:
                return null;
        }//end switch

        $sql = sprintf(
            'SELECT %s AS bucket, %s AS agg FROM %s%s WHERE 1=1 GROUP BY bucket ORDER BY bucket',
            $bucketExpr,
            $metricSql,
            $quote.$tableName.$quote,
            ''
        );

        try {
            $stmt   = $this->db->prepare(sql: $sql);
            $result = $stmt->execute();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            $groups = [];
            foreach ($rows as $row) {
                $groups[] = [
                    'key'   => $this->coerceBucketKey(key: (string) $row['bucket']),
                    'value' => $this->coerceMetricValue(raw: $row['agg']),
                ];
            }

            return ['groups' => $groups, 'backend' => $platform];
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AggregationRunner] Native aggregation failed, falling back to PHP',
                context: ['platform' => $platform, 'error' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end tryNativeAggregation()

    /**
     * PHP fallback: fetch rows and bucket them in memory.
     *
     * Capped at PHP_FALLBACK_ROW_CAP rows per request.
     *
     * @param string           $schemaSlug Schema slug.
     * @param AggregationQuery $query      Aggregation parameters.
     *
     * @return array{groups: list<array>, backend: string}
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.1
     */
    public function bucketInPhp(string $schemaSlug, AggregationQuery $query): array
    {
        $tableName = self::TABLE_PREFIX.$schemaSlug;
        $gap       = $query->getDateBucket() ?? 'day';
        $field     = $query->getField() ?? '_created';

        $sql  = sprintf('SELECT %s FROM %s LIMIT %d', $field, $tableName, self::PHP_FALLBACK_ROW_CAP);
        $rows = [];
        try {
            $stmt   = $this->db->prepare(sql: $sql);
            $result = $stmt->execute();
            $rows   = $result->fetchAll();
            $result->closeCursor();
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AggregationRunner] PHP fallback fetch failed',
                context: ['error' => $e->getMessage()]
            );
        }

        $buckets = [];
        foreach ($rows as $row) {
            $rawValue = $row[$field] ?? null;
            if ($rawValue === null) {
                continue;
            }

            $bucketKey = $this->phpDateTrunc(value: (string) $rawValue, gap: $gap);
            if ($bucketKey === null) {
                continue;
            }

            if (isset($buckets[$bucketKey]) === false) {
                $buckets[$bucketKey] = 0;
            }

            $buckets[$bucketKey]++;
        }

        ksort($buckets);

        $groups = [];
        foreach ($buckets as $key => $count) {
            $groups[] = ['key' => $key, 'value' => $count];
        }

        return ['groups' => $groups, 'backend' => 'php-fallback'];
    }//end bucketInPhp()

    /**
     * Detect the active database platform short name.
     *
     * @return string One of 'postgres', 'mysql', 'sqlite', or 'unknown'.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.1
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.4
     */
    public function detectDatabasePlatform(): string
    {
        try {
            $platformClass = $this->db->getDatabasePlatform()::class;
        } catch (\Throwable) {
            return 'unknown';
        }

        if (stripos(haystack: $platformClass, needle: 'PostgreSQL') !== false) {
            return 'postgres';
        }

        if (stripos(haystack: $platformClass, needle: 'MySQL') !== false
            || stripos(haystack: $platformClass, needle: 'MariaDB') !== false
        ) {
            return 'mysql';
        }

        if (stripos(haystack: $platformClass, needle: 'SQLite') !== false) {
            return 'sqlite';
        }

        return 'unknown';
    }//end detectDatabasePlatform()

    /**
     * Build the Postgres date-trunc bucket expression.
     *
     * @param string $field Column name.
     * @param string $gap   Gap vocabulary value.
     *
     * @return string SQL fragment.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.2
     */
    private function postgresBucketExpression(string $field, string $gap): string
    {
        $sanitized = $this->sanitizeColumnName(name: $field);
        return sprintf("date_trunc('%s', %s)::text", $gap, $sanitized);
    }//end postgresBucketExpression()

    /**
     * Build the MySQL DATE_FORMAT bucket expression.
     *
     * Maps the gap vocabulary to MySQL DATE_FORMAT format strings.
     * Note: MySQL minute = %i (NOT %M which is full month name).
     * For week and quarter, extended expressions are emitted.
     *
     * @param string $field Column name.
     * @param string $gap   Gap vocabulary value.
     *
     * @return string SQL fragment.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.2
     */
    public static function mysqlBucketExpression(string $field, string $gap): string
    {
        $col = '`'.$field.'`';

        // Week: ISO-Monday via DAYOFWEEK shift.
        if ($gap === 'week') {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- SQL expression.
            return sprintf("DATE_FORMAT(%s - INTERVAL ((DAYOFWEEK(%s) + 5) %% 7) DAY, '%%Y-%%m-%%dT00:00:00Z') AS bucket", $col, $col);
        }

        // Quarter: CONCAT + QUARTER arithmetic.
        if ($gap === 'quarter') {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- SQL expression.
            return sprintf("CONCAT(YEAR(%s), '-', LPAD(((QUARTER(%s) - 1) * 3 + 1), 2, '0'), '-01T00:00:00Z') AS bucket", $col, $col);
        }

        $format = match ($gap) {
            'minute' => '%Y-%m-%dT%H:%i:00Z',
            'hour'   => '%Y-%m-%dT%H:00:00Z',
            'day'    => '%Y-%m-%dT00:00:00Z',
            'month'  => '%Y-%m-01T00:00:00Z',
            'year'   => '%Y-01-01T00:00:00Z',
            default  => '%Y-%m-%dT00:00:00Z',
        };

        return sprintf("DATE_FORMAT(%s, '%s') AS bucket", $col, $format);
    }//end mysqlBucketExpression()

    /**
     * Build the SQLite strftime bucket expression.
     *
     * Maps the gap vocabulary to SQLite strftime format strings.
     * Note: SQLite minute = %M (unlike MySQL's %i).
     * For week, the weekday modifier is used for ISO-Monday semantics.
     * For quarter, a CASE expression is used (strftime has no quarter).
     *
     * @param string $field Column name.
     * @param string $gap   Gap vocabulary value.
     *
     * @return string SQL fragment.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.2
     */
    public static function sqliteBucketExpression(string $field, string $gap): string
    {
        $col = '"'.$field.'"';

        // Week: ISO-Monday via weekday modifier.
        if ($gap === 'week') {
            // Back-shift to Monday: weekday 0 = Sunday; subtract 6 days to get prev Monday.
            // phpcs:ignore Generic.Files.LineLength.TooLong -- SQL expression.
            return sprintf("strftime('%%Y-%%m-%%dT00:00:00Z', %s, 'weekday 0', '-6 days') AS bucket", $col);
        }

        // Quarter: CASE expression (strftime has no %%q).
        if ($gap === 'quarter') {
            $q1  = "WHEN strftime('%%m', %s) IN ('01','02','03') THEN strftime('%%Y-01-01T00:00:00Z', %s)";
            $q2  = "WHEN strftime('%%m', %s) IN ('04','05','06') THEN strftime('%%Y-04-01T00:00:00Z', %s)";
            $q3  = "WHEN strftime('%%m', %s) IN ('07','08','09') THEN strftime('%%Y-07-01T00:00:00Z', %s)";
            $q4  = "ELSE strftime('%%Y-10-01T00:00:00Z', %s)";
            $fmt = "CASE $q1 $q2 $q3 $q4 END AS bucket";
            return sprintf($fmt, $col, $col, $col, $col, $col, $col, $col);
        }

        // SQLite minute placeholder is %M (NOT %i like MySQL).
        $format = match ($gap) {
            'minute' => '%Y-%m-%dT%H:%M:00Z',
            'hour'   => '%Y-%m-%dT%H:00:00Z',
            'day'    => '%Y-%m-%dT00:00:00Z',
            'month'  => '%Y-%m-01T00:00:00Z',
            'year'   => '%Y-01-01T00:00:00Z',
            default  => '%Y-%m-%dT00:00:00Z',
        };

        return sprintf("strftime('%s', %s) AS bucket", $format, $col);
    }//end sqliteBucketExpression()

    /**
     * Build the metric SQL expression.
     *
     * @param string $metric   Metric name ('count', 'sum', 'avg', 'min', 'max').
     * @param string $field    Aggregation field.
     * @param string $platform Database platform short name.
     *
     * @return string SQL fragment without alias.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1
     */
    private function buildMetricSql(string $metric, string $field, string $platform): string
    {
        $col = $this->quoteColumn(field: $field, platform: $platform);

        return match ($metric) {
            'count'  => 'COUNT(*)',
            'sum'    => sprintf('SUM(%s)', $col),
            'avg'    => sprintf('AVG(%s)', $col),
            'min'    => sprintf('MIN(%s)', $col),
            'max'    => sprintf('MAX(%s)', $col),
            default  => 'COUNT(*)',
        };
    }//end buildMetricSql()

    /**
     * Platform-aware column quoting.
     *
     * @param string $field    Column name.
     * @param string $platform Platform short name.
     *
     * @return string Quoted column reference.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.3
     */
    private function quoteColumn(string $field, string $platform): string
    {
        if ($platform === 'mysql') {
            return '`'.$field.'`';
        }

        return '"'.$field.'"';
    }//end quoteColumn()

    /**
     * Sanitize a column name for use in a Postgres double-quoted identifier.
     *
     * @param string $name Raw column name.
     *
     * @return string Double-quoted identifier.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.3
     */
    private function sanitizeColumnName(string $name): string
    {
        $safe = preg_replace(pattern: '/[^a-zA-Z0-9_]/', replacement: '', subject: $name);
        return '"'.($safe ?? $name).'"';
    }//end sanitizeColumnName()

    /**
     * Normalise a raw bucket key to Y-m-d\TH:i:s\Z format.
     *
     * MySQL/SQLite native paths emit this format directly, so this call is
     * typically a no-op on those keys.  Postgres text-casts may emit
     * 'Y-m-d H:i:s+TZ' which is re-formatted here.
     *
     * @param string $key Raw bucket key from the database.
     *
     * @return string ISO-8601-UTC bucket key.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.5
     */
    public function coerceBucketKey(string $key): string
    {
        $ts = strtotime(datetime: $key);
        if ($ts === false) {
            return $key;
        }

        return gmdate(format: 'Y-m-d\TH:i:s\Z', timestamp: $ts);
    }//end coerceBucketKey()

    /**
     * Coerce a raw metric value to int or float.
     *
     * @param mixed $raw Raw database value.
     *
     * @return int|float
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1
     */
    private function coerceMetricValue(mixed $raw): int|float
    {
        if (is_int(value: $raw) === true || is_float(value: $raw) === true) {
            return $raw;
        }

        $str = (string) $raw;
        if (str_contains(haystack: $str, needle: '.') === true) {
            return (float) $str;
        }

        return (int) $str;
    }//end coerceMetricValue()

    /**
     * PHP date_trunc polyfill: bucket a datetime string by gap.
     *
     * Returns null for unparseable values so the caller can skip them.
     *
     * @param string $value Raw datetime string.
     * @param string $gap   Gap vocabulary value.
     *
     * @return string|null ISO-8601-UTC bucket key or null on parse failure.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.1
     */
    private function phpDateTrunc(string $value, string $gap): ?string
    {
        $ts = strtotime(datetime: $value);
        if ($ts === false) {
            return null;
        }

        $dt = new \DateTimeImmutable(datetime: '@'.$ts);

        return match ($gap) {
            'minute'  => $dt->format(format: 'Y-m-d\TH:i:00\Z'),
            'hour'    => $dt->format(format: 'Y-m-d\TH:00:00\Z'),
            'day'     => $dt->format(format: 'Y-m-d\T00:00:00\Z'),
            'month'   => $dt->format(format: 'Y-m-01T00:00:00\Z'),
            'year'    => $dt->format(format: 'Y-01-01T00:00:00\Z'),
            'week'    => $this->phpWeekBucket(dt: $dt),
            'quarter' => $this->phpQuarterBucket(dt: $dt),
            default   => $dt->format(format: 'Y-m-d\T00:00:00\Z'),
        };
    }//end phpDateTrunc()

    /**
     * Compute the ISO-Monday week-start bucket key for a given datetime.
     *
     * @param \DateTimeImmutable $dt Datetime.
     *
     * @return string ISO-8601-UTC bucket key for the Monday of the ISO week.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.1
     */
    private function phpWeekBucket(\DateTimeImmutable $dt): string
    {
        $dayOfWeek = (int) $dt->format(format: 'N');
        $monday    = $dt->modify(modifier: sprintf('-%d days', $dayOfWeek - 1));
        return $monday->format(format: 'Y-m-d\T00:00:00\Z');
    }//end phpWeekBucket()

    /**
     * Compute the quarter-start bucket key for a given datetime.
     *
     * @param \DateTimeImmutable $dt Datetime.
     *
     * @return string ISO-8601-UTC bucket key for the first day of the quarter.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-1.1
     */
    private function phpQuarterBucket(\DateTimeImmutable $dt): string
    {
        $month        = (int) $dt->format(format: 'm');
        $quarterStart = match (true) {
            $month <= 3  => '01',
            $month <= 6  => '04',
            $month <= 9  => '07',
            default      => '10',
        };

        return $dt->format(format: 'Y').'-'.$quarterStart.'-01T00:00:00Z';
    }//end phpQuarterBucket()
}//end class
