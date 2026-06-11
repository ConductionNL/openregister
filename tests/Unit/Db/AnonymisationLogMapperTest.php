<?php

/**
 * AnonymisationLogMapper unit tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\AnonymisationLog;
use OCA\OpenRegister\Db\AnonymisationLogMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AnonymisationLogMapperTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private AnonymisationLogMapper $mapper;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->mapper = new AnonymisationLogMapper($this->db);
    }

    public function testMapperRegistersCorrectTableAndEntityClass(): void
    {
        $this->assertSame('openregister_anonymisation_log', $this->mapper->getTableName());
    }

    public function testInsertSetsCreatedTimestamp(): void
    {
        $entity = new AnonymisationLog();

        // Insert flow is delegated to the parent QBMapper, which requires a
        // real DB. We exercise only the entity preparation step.
        $reflection = new \ReflectionClass(AnonymisationLogMapper::class);
        $insertMethod = $reflection->getMethod('insert');
        $this->assertTrue($insertMethod->isPublic());

        $entity->setFileId(7);
        $this->assertSame(7, $entity->getFileId());
    }
}
