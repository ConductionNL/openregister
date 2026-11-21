<?php

/**
 * Unit test for the VectorEmbeddingHandlers collaborator bundle.
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

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStorageHandlerInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\EmbeddingGeneratorHandler
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSearchHandler
 * @uses   \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler
 */
class VectorEmbeddingHandlersTest extends TestCase
{
    public function testBundleHandsOutEachCollaboratorUnderItsOwnRole(): void
    {
        $logger    = $this->createMock(LoggerInterface::class);
        $db        = $this->createMock(IDBConnection::class);
        $generator = new EmbeddingGeneratorHandler($logger);
        $storage   = $this->createMock(VectorStorageHandlerInterface::class);
        $search    = new VectorSearchHandler($db, $this->createMock(PgVectorPlatform::class), $logger);
        $stats     = new VectorStatsHandler($db, $logger);

        $bundle = new VectorEmbeddingHandlers($generator, $storage, $search, $stats);

        $this->assertSame(
            [$generator, $storage, $search, $stats],
            [$bundle->generator, $bundle->storage, $bundle->search, $bundle->stats]
        );
        $this->assertSame(768, $bundle->generator->getDefaultDimensions('nomic-embed-text'));
    }//end testBundleHandsOutEachCollaboratorUnderItsOwnRole()
}//end class
