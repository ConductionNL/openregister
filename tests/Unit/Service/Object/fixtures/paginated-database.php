<?php

/**
 * Recorded calls and responses of QueryHandler::searchObjectsPaginatedDatabase(), one entry per case in QueryHandlerPaginatedDatabaseTest::cases().
 *
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

return array (
  'defaults' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'page given' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 10,
            '_offset' => 20,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 3,
      'pages' => 1,
      'limit' => 10,
      'offset' => 20,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'page below one' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 10,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 10,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'offset given' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 10,
            '_offset' => 25,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 3,
      'pages' => 1,
      'limit' => 10,
      'offset' => 25,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'page and offset given' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 10,
            '_offset' => 5,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 4,
      'pages' => 1,
      'limit' => 10,
      'offset' => 5,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'limit zero' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 0,
            '_offset' => 25,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 7,
      'page' => 1,
      'pages' => 0,
      'limit' => 0,
      'offset' => 25,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'negative limit' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 0,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 0,
      'limit' => 0,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'control keys removed' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 5,
            'name' => 'x',
            '_offset' => 5,
          ),
          1 => 
          array (
            'name' => 'x',
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
            1 => 'owner',
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
      2 => 
      array (
        0 => 'getFacetableFields',
        1 => 
        array (
          0 => 
          array (
            'name' => 'x',
          ),
          1 => 100,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 2,
      'pages' => 1,
      'limit' => 5,
      'offset' => 5,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'facetable',
          3 => 'total',
        ),
      ),
      'facetable' => 
      array (
        'name' => 'string',
      ),
    ),
  ),
  'multitenancy asks the org' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => 'org-1',
          3 => true,
          4 => true,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'rbac false and ids and uses' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => false,
          4 => false,
          5 => 
          array (
            0 => 'a',
            1 => 'b',
          ),
          6 => 'u-1',
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'history narrows to nothing' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'narrow',
        1 => 
        array (
          0 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'history' => 
        array (
          'wasEver' => 
          array (
            'status' => 'open',
          ),
          'changedBetween' => 
          array (
          ),
          'unparsed' => 
          array (
          ),
        ),
        'metrics' => 
        array (
          0 => 'history',
          1 => 'search',
          2 => 'lightweightFiles',
          3 => 'translations',
          4 => 'writeOnlyRedaction',
          5 => 'total',
        ),
      ),
    ),
  ),
  'history narrows to some' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'narrow',
        1 => 
        array (
          0 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => 
          array (
            0 => 'id-1',
          ),
          6 => NULL,
        ),
      ),
      2 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      4 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'history' => 
        array (
          'wasEver' => 
          array (
            'status' => 'open',
          ),
          'changedBetween' => 
          array (
          ),
          'unparsed' => 
          array (
            0 => '_changed_between[x]',
          ),
        ),
        'metrics' => 
        array (
          0 => 'history',
          1 => 'search',
          2 => 'lightweightFiles',
          3 => 'translations',
          4 => 'writeOnlyRedaction',
          5 => 'total',
        ),
      ),
    ),
  ),
  'history unparsed only' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'history' => 
        array (
          'wasEver' => 
          array (
          ),
          'changedBetween' => 
          array (
          ),
          'unparsed' => 
          array (
            0 => '_was_ever[]',
          ),
        ),
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'content search' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_content_search' => 'true',
            '_search' => 'tax',
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_content_search' => 'true',
            '_search' => 'tax',
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'augmentWithChunkMatches',
        1 => 
        array (
          0 => 
          array (
            '_content_search' => 'true',
            '_search' => 'tax',
          ),
          1 => 
          array (
          ),
          2 => 2,
          3 => 20,
          4 => true,
          5 => false,
          6 => 0,
          7 => NULL,
        ),
      ),
      2 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'chunk-hit',
            ),
          ),
        ),
      ),
      3 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'chunk-hit',
            ),
          ),
        ),
      ),
      4 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'chunk-hit',
            ),
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'id' => 'chunk-hit',
        ),
      ),
      'total' => 99,
      'page' => 1,
      'pages' => 5,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'content_search',
          2 => 'lightweightFiles',
          3 => 'translations',
          4 => 'writeOnlyRedaction',
          5 => 'total',
        ),
      ),
    ),
  ),
  'cheap path with rows' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'r1',
            ),
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'r1',
            ),
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'r1',
            ),
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'id' => 'r1',
        ),
      ),
      'total' => 41,
      'page' => 1,
      'pages' => 3,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'render for fields' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_fields' => 
            array (
              0 => 'name',
            ),
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_fields' => 
            array (
              0 => 'name',
            ),
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
            0 => 
            array (
              'id' => 'r1',
            ),
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => 
          array (
            0 => 'name',
          ),
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'render for filter and unset' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_filter' => 
            array (
              'a' => 1,
            ),
            '_unset' => 
            array (
              0 => 'b',
            ),
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_filter' => 
            array (
              'a' => 1,
            ),
            '_unset' => 
            array (
              0 => 'b',
            ),
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
          ),
          2 => 
          array (
            'a' => 1,
          ),
          3 => NULL,
          4 => 
          array (
            0 => 'b',
          ),
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'render for extend string' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
            0 => 'owner',
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'only schema extends stay cheap' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'render for array property authz' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'empty authz stays cheap' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'render for entity property authz' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'render for a lens' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'non-array results skip rendering' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_fields' => 
            array (
              0 => 'a',
            ),
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_fields' => 
            array (
              0 => 'a',
            ),
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 'nope',
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'total',
        ),
      ),
    ),
  ),
  'registers and schemas extended' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
            0 => '_registers',
            1 => '@self.schemas',
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'registers' => 
        array (
          3 => 
          array (
            'id' => 3,
          ),
        ),
        'schemas' => 
        array (
          1 => 
          array (
            'id' => 1,
          ),
        ),
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'extend singular forms' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'registers' => 
        array (
          3 => 
          array (
            'id' => 3,
          ),
        ),
        'schemas' => 
        array (
          1 => 
          array (
            'id' => 1,
          ),
        ),
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'extend asked but nothing loaded' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'renderEntities',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 
          array (
            0 => '_registers',
            1 => '_schemas',
          ),
          2 => NULL,
          3 => NULL,
          4 => NULL,
          5 => true,
          6 => false,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
        0 => 
        array (
          'rendered' => true,
        ),
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'render',
          2 => 'total',
        ),
      ),
    ),
  ),
  'ignored filters without mistakes' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'ignoredFilters' => 
        array (
          0 => 'colour',
        ),
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'ignored filters with mistakes' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'ignoredFilters' => 
        array (
          0 => 'limit',
          1 => 'colour',
          2 => 'sort',
        ),
        'hint' => 'Query returned 0 results because limit, sort was treated as a property filter. Did you mean _limit, _sort? Control params require underscore prefix.',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'mapper metrics and source' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'index',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'db_search',
          2 => 'db_count',
          3 => 'lightweightFiles',
          4 => 'translations',
          5 => 'writeOnlyRedaction',
          6 => 'total',
        ),
      ),
    ),
  ),
  'mapper metrics partial' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'db_search',
          2 => 'db_count',
          3 => 'lightweightFiles',
          4 => 'translations',
          5 => 'writeOnlyRedaction',
          6 => 'total',
        ),
      ),
    ),
  ),
  'recent lens report' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_recentLens' => 
            array (
              'answered' => false,
              'reason' => 'no audit',
            ),
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_recentLens' => 
            array (
              'answered' => false,
              'reason' => 'no audit',
            ),
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'lenses' => 
        array (
          'recent' => 
          array (
            'answered' => false,
            'reason' => 'no audit',
          ),
        ),
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'recent lens not an array' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_recentLens' => 'yes',
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_recentLens' => 'yes',
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
  'facets' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_facets' => 
            array (
              'status' => 
              array (
                'type' => 'terms',
              ),
            ),
            '_limit' => 3,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_facets' => 
            array (
              'status' => 
              array (
                'type' => 'terms',
              ),
            ),
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
      4 => 
      array (
        0 => 'getFacetsForObjects',
        1 => 
        array (
          0 => 
          array (
            '_facets' => 
            array (
              'status' => 
              array (
                'type' => 'terms',
              ),
            ),
          ),
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 3,
      'offset' => 0,
      'facets' => 
      array (
        'status' => 
        array (
        ),
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'facets',
          5 => 'total',
        ),
      ),
    ),
  ),
  'facets with breakdown' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_facets' => 'x',
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
            '_facets' => 'x',
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
      4 => 
      array (
        0 => 'getFacetsForObjects',
        1 => 
        array (
          0 => 
          array (
            '_facets' => 'x',
          ),
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
        'f' => 1,
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'facets',
          5 => 'facets_breakdown',
          6 => 'total',
        ),
      ),
    ),
  ),
  'facetable true' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
      4 => 
      array (
        0 => 'getFacetableFields',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 100,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'facetable',
          5 => 'total',
        ),
      ),
      'facetable' => 
      array (
        'name' => 'string',
      ),
    ),
  ),
  'facetable string' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
      4 => 
      array (
        0 => 'getFacetableFields',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => 100,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'facetable',
          5 => 'total',
        ),
      ),
      'facetable' => 
      array (
        'name' => 'string',
      ),
    ),
  ),
  'facetable other string' => 
  array (
    'calls' => 
    array (
      0 => 
      array (
        0 => 'searchObjectsPaginated',
        1 => 
        array (
          0 => 
          array (
            '_limit' => 20,
            '_offset' => 0,
          ),
          1 => 
          array (
          ),
          2 => NULL,
          3 => true,
          4 => false,
          5 => NULL,
          6 => NULL,
        ),
      ),
      1 => 
      array (
        0 => 'attachLightweightFilesToRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      2 => 
      array (
        0 => 'resolveTranslationsForRows',
        1 => 
        array (
          0 => 
          array (
          ),
        ),
      ),
      3 => 
      array (
        0 => 'redactWriteOnlyFromRows',
        1 => 
        array (
          0 => 
          array (
          ),
          1 => true,
        ),
      ),
    ),
    'response' => 
    array (
      'results' => 
      array (
      ),
      'total' => 0,
      'page' => 1,
      'pages' => 1,
      'limit' => 20,
      'offset' => 0,
      'facets' => 
      array (
      ),
      '@self' => 
      array (
        'source' => 'database',
        'metrics' => 
        array (
          0 => 'search',
          1 => 'lightweightFiles',
          2 => 'translations',
          3 => 'writeOnlyRedaction',
          4 => 'total',
        ),
      ),
    ),
  ),
);
