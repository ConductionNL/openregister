<?php

/**
 * Behaviour tests for VectorStorageHandler::storeVector(): text sanitising, metadata and failure.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers;

use Exception;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorChunkPosition;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorPayload;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSubject;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lists the value objects as used, so the coverage of these tests is kept
 * under beStrictAboutCoverageMetadata.
 *
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandler
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorChunkPosition
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorPayload
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSubject
 */
class VectorStorageHandlerStoreBehaviourTest extends TestCase
{
    /** @var array<string, mixed> Named parameters by column, in insert order. */
    private array $params = [];

    /** @var list<array{0: string, 1: array}> Sidecar statements. */
    private array $sidecar = [];

    /** @var list<array{0: string, 1: string, 2: array}> Log calls. */
    private array $logs = [];

    private function handler(?int $dimension, ?Exception $insertFailure = null, ?Exception $sidecarFailure = null): VectorStorageHandler
    {
        $qb      = $this->createMock(IQueryBuilder::class);
        $counter = 0;
        $qb->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value) use (&$counter): string {
                $counter++;
                return ':p'.$counter.'='.var_export($value, true);
            }
        );
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnCallback(
            function (array $values) use ($qb): IQueryBuilder {
                $this->params = $values;
                return $qb;
            }
        );
        if ($insertFailure !== null) {
            $qb->method('executeStatement')->willThrowException($insertFailure);
        } else {
            $qb->method('executeStatement')->willReturn(1);
        }

        $qb->method('getLastInsertId')->willReturn(77);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $db->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use ($sidecarFailure): int {
                if ($sidecarFailure !== null) {
                    throw $sidecarFailure;
                }

                $this->sidecar[] = [$sql, $params];
                return 1;
            }
        );

        $pgVector = $this->createMock(PgVectorPlatform::class);
        $pgVector->method('getVectorColumnDimension')->willReturn($dimension);
        $pgVector->method('formatVector')->willReturnCallback(
            static fn(array $e): string => '['.implode(',', $e).']'
        );

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['debug', 'info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    unset($context['file'], $context['line']);
                    $this->logs[] = [$level, $message, $context];
                }
            );
        }

        return new VectorStorageHandler($db, $pgVector, $logger);
    }//end handler()

    private function vector(?string $text, array $metadata, array $embedding = [0.1, 0.2]): StoredVector
    {
        return new StoredVector(
            subject: new VectorSubject(entityType: 'object', entityId: 'obj-9'),
            payload: new VectorPayload(embedding: $embedding, model: 'nomic', dimensions: count($embedding)),
            position: new VectorChunkPosition(chunkIndex: 1, totalChunks: 2, chunkText: $text),
            metadata: $metadata
        );
    }//end vector()

    public function testChunkTextIsStrippedOfControlCharsInvalidBytesAndExtraWhitespace(): void
    {
        $id = $this->handler(dimension: null)->storeVector(
            $this->vector("\t Line\x01 one\x00\n\n  two\xC3\x28 three\x7F  ", ['k' => 'v'])
        );

        $this->assertSame(77, $id);
        $this->assertStringEndsWith("='Line one two?( three'", $this->params['chunk_text']);
        $this->assertStringEndsWith("='{\"k\":\"v\"}'", $this->params['metadata']);
        $this->assertStringEndsWith("='nomic'", $this->params['embedding_model']);
    }//end testChunkTextIsStrippedOfControlCharsInvalidBytesAndExtraWhitespace()

    public function testMissingTextAndEmptyMetadataAreStoredAsNull(): void
    {
        $this->handler(dimension: null)->storeVector($this->vector(null, []));

        $this->assertStringEndsWith('=NULL', $this->params['chunk_text']);
        $this->assertStringEndsWith('=NULL', $this->params['metadata']);
    }//end testMissingTextAndEmptyMetadataAreStoredAsNull()

    public function testInsertFailureIsLoggedWithEntityAndRethrownWithPrefix(): void
    {
        $handler = $this->handler(dimension: 2, insertFailure: new Exception('deadlock'));

        try {
            $handler->storeVector($this->vector('text', []));
            $this->fail('A failed insert must be rethrown.');
        } catch (Exception $e) {
            $this->assertSame('Vector storage failed: deadlock', $e->getMessage());
        }

        $this->assertSame([], $this->sidecar);
        $this->assertSame(
            ['error', '[VectorStorageHandler] Failed to store vector in database', ['error' => 'deadlock', 'entity_type' => 'object', 'entity_id' => 'obj-9']],
            end($this->logs)
        );
    }//end testInsertFailureIsLoggedWithEntityAndRethrownWithPrefix()

    public function testMatchingDimensionWritesSidecarRow(): void
    {
        $id = $this->handler(dimension: 2)->storeVector($this->vector('t', []));

        $this->assertSame(77, $id);
        $this->assertCount(1, $this->sidecar);
        $this->assertSame(['77', '[0.1,0.2]'], $this->sidecar[0][1]);
        $this->assertStringContainsString('ON CONFLICT (vector_id) DO UPDATE', $this->sidecar[0][0]);
    }//end testMatchingDimensionWritesSidecarRow()

    public function testSidecarFailureIsOnlyAWarningAndTheIdIsStillReturned(): void
    {
        $id = $this->handler(dimension: 2, sidecarFailure: new Exception('no pgvector'))
            ->storeVector($this->vector('t', []));

        $this->assertSame(77, $id);
        $levels = array_column($this->logs, 0);
        $this->assertContains('warning', $levels);
        $this->assertNotContains('error', $levels);
        $this->assertSame('info', end($levels));
    }//end testSidecarFailureIsOnlyAWarningAndTheIdIsStillReturned()
}//end class
