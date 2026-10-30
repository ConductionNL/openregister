<?php

/**
 * Recorded statements and results of AggregationRunner::tryNativeAggregation(), one entry per case in AggregationRunnerNativeSqlTest::cases().
 *
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

return array (
  'pg count scalar' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 7,
    ),
  ),
  'pg count scalar no row' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 0,
    ),
  ),
  'pg sum scalar string' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT SUM(NULLIF("amount"::text, \'\')::numeric) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 12.5,
    ),
  ),
  'pg avg scalar null' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT AVG(NULLIF("amount"::text, \'\')::numeric) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => NULL,
    ),
  ),
  'pg max scalar float' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT MAX(NULLIF("amount"::text, \'\')::numeric) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 4.0,
    ),
  ),
  'mysql scalar refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'unknown platform refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'unsupported metric refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'value metric without field' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'unsupported operator refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'array property filter refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'conditional metric refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'dotted group field refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'empty table name refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'org none' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND 1 = 0',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 0,
    ),
  ),
  'org null only' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND "_organisation" IS NULL',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 1,
    ),
  ),
  'org in' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND "_organisation" IN (?, ?)',
        'bindings' => 
        array (
          0 => 'org-1',
          1 => 'org-2',
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 2,
    ),
  ),
  'org in or null' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND ("_organisation" IN (?) OR "_organisation" IS NULL)',
        'bindings' => 
        array (
          0 => 'org-1',
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 2,
    ),
  ),
  'org unknown mode refused' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
  'org waived by rbac' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 9,
    ),
  ),
  'org widened by rbac' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND ("_organisation" IN (?, ?) OR "_organisation" IS NULL)',
        'bindings' => 
        array (
          0 => 'org-1',
          1 => 'org-2',
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 9,
    ),
  ),
  'rbac off ignores rbac answers' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND "_organisation" IN (?, ?)',
        'bindings' => 
        array (
          0 => 'org-1',
          1 => 'org-2',
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 9,
    ),
  ),
  'row predicate' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND ("learner" = \'u1\')',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 1,
    ),
  ),
  'draft lens with owner' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND ((_status IS NULL OR _status <> \'draft\') OR _owner = ?)',
        'bindings' => 
        array (
          0 => 'alice',
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 1,
    ),
  ),
  'draft lens without status column' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb)',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 1,
    ),
  ),
  'filters of every shape' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND "status" = ? AND "kind" IN (?, ?) AND 1 = 0 AND "flag" = ? AND "code" IN (?, ?) AND 1 = 0 AND "not_code" NOT IN (?) AND 1 = 1 AND 1 = 0 AND "closed_at" IS NULL AND "opened_at" IS NOT NULL AND "amount" > ? AND "amount" >= ? AND "amount" < ? AND "amount" <= ? AND "amount" <> ? AND "owner_name" = ?',
        'bindings' => 
        array (
          0 => 'open',
          1 => 'a',
          2 => 'b',
          3 => 'true',
          4 => 'x',
          5 => 'y',
          6 => 'z',
          7 => '1',
          8 => '2',
          9 => '3',
          10 => '4',
          11 => '5',
          12 => '',
        ),
      ),
    ),
    'result' => 
    array (
      'value' => 4,
    ),
  ),
  'pg group single' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT "status" AS g0, COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') GROUP BY "status"',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => 'open',
          'value' => 3,
        ),
        1 => 
        array (
          'key' => NULL,
          'value' => 1,
        ),
      ),
    ),
  ),
  'pg group single sum' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT "status" AS g0, SUM(NULLIF("amount"::text, \'\')::numeric) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') GROUP BY "status"',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => 'open',
          'value' => 3.0,
        ),
        1 => 
        array (
          'key' => 'x',
          'value' => 2,
        ),
        2 => 
        array (
          'key' => 'y',
          'value' => NULL,
        ),
      ),
    ),
  ),
  'mysql group multi' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT `status` AS g0, `kind` AS g1, COUNT(*) AS agg FROM `oc_register_1_schema_case` WHERE (_deleted IS NULL OR _deleted = \'null\' OR _deleted = \'\') AND (_archived IS NULL OR _archived = \'null\' OR _archived = \'\') AND (_status IS NULL OR _status <> \'draft\') GROUP BY `status`, `kind`',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'keys' => 
          array (
            'status' => 'open',
            'kind' => 'a',
          ),
          'value' => 2,
        ),
      ),
    ),
  ),
  'sqlite group list' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT "status" AS g0, COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\' OR _deleted = \'\') AND (_archived IS NULL OR _archived = \'null\' OR _archived = \'\') AND (_status IS NULL OR _status <> \'draft\') GROUP BY "status"',
        'bindings' => 
        array (
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => 'open',
          'value' => 3,
        ),
        1 => 
        array (
          'key' => NULL,
          'value' => 1,
        ),
      ),
    ),
  ),
  'pg bucket cumulative' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT date_trunc(?, "created")::text AS bucket, COUNT(*) AS agg, SUM(COUNT(*)) OVER (ORDER BY bucket) AS cumulative_agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND "status" = ? AND "created" >= ? AND "created" < ? GROUP BY bucket ORDER BY bucket',
        'bindings' => 
        array (
          0 => 'day',
          1 => 'open',
          2 => '2026-01-01',
          3 => '2026-02-01',
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => '2026-01-01T00:00:00Z',
          'value' => 2,
          'cumulative' => 2,
        ),
        1 => 
        array (
          'key' => '2026-01-02T00:00:00Z',
          'value' => 3,
          'cumulative' => 5,
        ),
      ),
    ),
  ),
  'pg bucket plain sum' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT date_trunc(?, "created")::text AS bucket, SUM(NULLIF("amount"::text, \'\')::numeric) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\'::jsonb) AND (_archived IS NULL OR _archived = \'null\'::jsonb) AND (_status IS NULL OR _status <> \'draft\') AND "created" >= ? AND "created" < ? GROUP BY bucket ORDER BY bucket',
        'bindings' => 
        array (
          0 => 'month',
          1 => '2026-01-01',
          2 => '2026-02-01',
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => '2026-01-01T00:00:00Z',
          'value' => 2.5,
        ),
      ),
    ),
  ),
  'mysql bucket cumulative' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT DATE_FORMAT(`created`, \'%Y-%m-%dT00:00:00Z\') AS bucket, COUNT(*) AS agg FROM `oc_register_1_schema_case` WHERE (_deleted IS NULL OR _deleted = \'null\' OR _deleted = \'\') AND (_archived IS NULL OR _archived = \'null\' OR _archived = \'\') AND (_status IS NULL OR _status <> \'draft\') AND `created` >= ? AND `created` < ? GROUP BY bucket ORDER BY bucket',
        'bindings' => 
        array (
          0 => '2026-01-01',
          1 => '2026-01-03',
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => '2026-01-01T00:00:00Z',
          'value' => 2,
          'cumulative' => 2,
        ),
        1 => 
        array (
          'key' => '2026-01-02T00:00:00Z',
          'value' => 3,
          'cumulative' => 5,
        ),
      ),
    ),
  ),
  'sqlite bucket' => 
  array (
    'statements' => 
    array (
      0 => 
      array (
        'sql' => 'SELECT strftime(\'%Y-%m-%dT00:00:00Z\', "created", \'weekday 0\', \'-6 days\') AS bucket, COUNT(*) AS agg FROM "oc_register_1_schema_case" WHERE (_deleted IS NULL OR _deleted = \'null\' OR _deleted = \'\') AND (_archived IS NULL OR _archived = \'null\' OR _archived = \'\') AND (_status IS NULL OR _status <> \'draft\') AND "created" >= ? AND "created" < ? GROUP BY bucket ORDER BY bucket',
        'bindings' => 
        array (
          0 => '2026-01-01',
          1 => '2026-01-03',
        ),
      ),
    ),
    'result' => 
    array (
      'groups' => 
      array (
        0 => 
        array (
          'key' => '2026-01-01T00:00:00Z',
          'value' => 2,
        ),
      ),
    ),
  ),
  'query failure falls back' => 
  array (
    'statements' => 
    array (
    ),
    'result' => NULL,
  ),
);
