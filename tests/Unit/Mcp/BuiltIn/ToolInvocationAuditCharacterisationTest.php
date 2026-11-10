<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Mcp\BuiltIn\ToolAuditTarget;
use OCA\OpenRegister\Mcp\BuiltIn\AuditedInvocation;
use OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what ToolInvocationAudit records for a succeeding invocation, a
 * failing invocation and a failing audit write.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationAudit
 */
class ToolInvocationAuditCharacterisationTest extends TestCase
{

    /**
     * A succeeding invocation writes its summary and result id, and returns the result.
     *
     * @return void
     */
    public function testSuccessWritesSummaryAndResultId(): void
    {
        $mapper = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->expects($this->once())->method('recordToolInvocation')->with(
            new ToolInvocation('app.tool', hash('sha256', '{"a":1}'), ['isError' => false, 'n' => 1], new AuditedObject(4, 7, null, 'obj-1'))
        )->willReturn($this->createMock(AuditTrail::class));

        $runner = new ToolInvocationAudit($mapper, $this->createMock(LoggerInterface::class), 'Src');
        $result = $runner->run(
            new ToolAuditTarget('app.tool', 4, 7),
            new AuditedInvocation(
                ['a' => 1],
                static fn (): array => ['id' => 'obj-1'],
                static fn (array $r): array => ['isError' => false, 'n' => count($r)]
            )
        );

        $this->assertSame(['id' => 'obj-1'], $result);
    }//end testSuccessWritesSummaryAndResultId()

    /**
     * A failing invocation writes the error summary without an object id and rethrows.
     *
     * @return void
     */
    public function testFailureWritesErrorSummaryAndRethrows(): void
    {
        $error  = new RuntimeException('boom');
        $mapper = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->expects($this->once())->method('recordToolInvocation')->with(
            new ToolInvocation(
                'app.tool',
                hash('sha256', '[]'),
                ['isError' => true, 'errorClass' => RuntimeException::class, 'message' => 'boom'],
                new AuditedObject(null, null, null, null)
            )
        )->willReturn($this->createMock(AuditTrail::class));

        $runner = new ToolInvocationAudit($mapper, $this->createMock(LoggerInterface::class), 'Src');

        try {
            $runner->run(
                new ToolAuditTarget('app.tool'),
                new AuditedInvocation(
                    [],
                    static function () use ($error): array {
                        throw $error;
                    },
                    static fn (array $r): array => []
                )
            );
            $this->fail('The invocation error must be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
    }//end testFailureWritesErrorSummaryAndRethrows()

    /**
     * A failing audit write is logged with the source and never masks the result.
     *
     * @return void
     */
    public function testFailedWriteIsLoggedAndResultStillReturned(): void
    {
        $mapper = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->method('recordToolInvocation')->willThrowException(new RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            '[Src] Failed to write invocation audit record',
            ['toolId' => 'app.tool', 'error' => 'db down']
        );

        $runner = new ToolInvocationAudit($mapper, $logger, 'Src');
        $result = $runner->run(
            new ToolAuditTarget('app.tool'),
            new AuditedInvocation(
                [],
                static fn (): array => ['ok' => true],
                static fn (array $r): array => ['isError' => false]
            )
        );

        $this->assertSame(['ok' => true], $result);
    }//end testFailedWriteIsLoggedAndResultStillReturned()
}//end class
