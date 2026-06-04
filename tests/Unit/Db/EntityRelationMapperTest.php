<?php

/**
 * Unit tests for the EntityRelationMapper constructor and inherited surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lightweight construction-and-wiring test for `EntityRelationMapper`.
 *
 * Verifies the mapper instantiates with its DI dependencies (which now
 * include `AuditTrailMapper`, `IUserSession`, `IEventDispatcher`, and
 * `LoggerInterface` per the `entity-relation-grondslagen` change).
 * DB-heavy query behaviour is covered by integration tests; the audited
 * write path's logic is covered by `EntityRelationMapperUpdateDecisionMetadataTest`.
 */
class EntityRelationMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private IUserSession&MockObject $userSession;
    private IEventDispatcher&MockObject $eventDispatcher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testConstructsWithInjectedDependencies(): void
    {
        $mapper = new EntityRelationMapper(
            $this->db,
            $this->auditTrailMapper,
            $this->userSession,
            $this->eventDispatcher,
            $this->logger
        );

        $this->assertInstanceOf(EntityRelationMapper::class, $mapper);
    }
}
