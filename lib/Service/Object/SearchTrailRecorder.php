<?php

/**
 * OpenRegister SearchTrailRecorder
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/search-trail-recording/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Psr\Log\LoggerInterface;

/**
 * Records one paginated search in the search trail, when the recording mode asks for it.
 *
 * Resolves the effective mode via SearchQueryHandler (memoized per request):
 * 'none' records nothing; '_search' records only when a non-empty `_search` term
 * is present; 'all' records every paginated search. The entry is buffered and
 * persisted after the response (deferred flush in SearchQueryHandler), so
 * recording adds no write latency to the search.
 */
final class SearchTrailRecorder
{
    /**
     * Constructor.
     *
     * @param SearchQueryHandler $searchQueryHandler Decides the mode and buffers the entry.
     * @param LoggerInterface    $logger             Logs a failed recording.
     * @param float              $startTime          The microtime(true) captured at search entry.
     *
     * @spec openspec/specs/search-trail-recording/spec.md
     */
    public function __construct(
        private readonly SearchQueryHandler $searchQueryHandler,
        private readonly LoggerInterface $logger,
        private readonly float $startTime,
    ) {
    }//end __construct()

    /**
     * Record the search. Never throws: a recording failure must not affect the search response.
     *
     * @param array $query  The post-view-merge search query.
     * @param array $result The paginated search result (results, total, @self).
     *
     * @return void
     *
     * @spec openspec/specs/search-trail-recording/spec.md
     */
    public function record(array $query, array $result): void
    {
        try {
            if ($this->isRecorded(query: $query) === true) {
                $this->write(query: $query, result: $result);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[ObjectService] recordSearchTrail failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }
    }//end record()

    /**
     * Whether the recording mode covers this query.
     *
     * @param array $query The search query.
     *
     * @return bool True when the search is recorded.
     *
     * @spec openspec/specs/search-trail-recording/spec.md
     */
    private function isRecorded(array $query): bool
    {
        $mode = $this->searchQueryHandler->getEffectiveRecordingMode();
        if ($mode === 'none') {
            return false;
        }

        return $mode !== '_search' || trim((string) ($query['_search'] ?? '')) !== '';
    }//end isRecorded()

    /**
     * Hand the entry to the search query handler.
     *
     * @param array $query  The search query.
     * @param array $result The paginated search result.
     *
     * @return void
     *
     * @spec openspec/specs/search-trail-recording/spec.md
     */
    private function write(array $query, array $result): void
    {
        $executionType = 'database';
        if (($result['@self']['source'] ?? 'database') === 'index') {
            $executionType = 'index';
        }

        $this->searchQueryHandler->logSearchTrail(
            $query,
            count($result['results'] ?? []),
            (int) ($result['total'] ?? 0),
            (float) ((microtime(true) - $this->startTime) * 1000),
            $executionType
        );
    }//end write()
}//end class
