<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Mcp\BuiltIn;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditedObject;
use OCA\OpenRegister\Db\AuditTrail\ToolInvocation;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationAudit;
use OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An app provider's invocation is recorded as the built-in providers record
 * theirs: the key-sorted argument digest, a summary, and nothing raw.
 *
 * @covers \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationRecorder
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses \OCA\OpenRegister\Db\AuditTrail\ToolInvocation
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationDigest
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationAudit
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\ToolAuditTarget
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\AuditedInvocation
 * @uses \OCA\OpenRegister\Mcp\BuiltIn\ToolInvocationOutcome
 */
class ToolInvocationRecorderTest extends TestCase
{

    /**
     * A success is recorded with the digest of the sorted arguments and the result's id.
     *
     * @return void
     */
    public function testASuccessIsRecordedByDigestAndId(): void
    {
        $mapper = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->expects($this->once())->method('recordToolInvocation')->with(
            new ToolInvocation(
                'shillinq.vat_return.submit',
                hash('sha256', '{"administrationId":"adm-1","returnId":"ret-7"}'),
                ['isError' => false, 'id' => 'ret-7'],
                new AuditedObject(objectUuid: 'ret-7')
            )
        )->willReturn(new AuditTrail());

        (self::recorder($mapper, $this->createMock(LoggerInterface::class)))->record(
            'shillinq.vat_return.submit',
            ['returnId' => 'ret-7', 'administrationId' => 'adm-1'],
            ['id' => 'ret-7', 'statusCode' => 'submitted']
        );
    }//end testASuccessIsRecordedByDigestAndId()

    /**
     * A refusal is recorded by its code only, never its message or the arguments.
     *
     * @return void
     */
    public function testARefusalIsRecordedByItsCode(): void
    {
        $recorded = [];
        $mapper   = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->method('recordToolInvocation')->willReturnCallback(
            static function (ToolInvocation $invocation) use (&$recorded): AuditTrail {
                $recorded[] = $invocation;
                return new AuditTrail();
            }
        );

        (self::recorder($mapper, $this->createMock(LoggerInterface::class)))->record(
            'shillinq.dunning.pause',
            ['details' => 'customer says the amount is wrong'],
            ['isError' => true, 'error' => 'not_found', 'message' => 'Administration not found']
        );

        $this->assertCount(1, $recorded);
        $this->assertSame(['isError' => true, 'error' => 'not_found'], $recorded[0]->resultSummary);
        $this->assertNull($recorded[0]->about->objectUuid);
        $this->assertStringNotContainsString('customer', $recorded[0]->paramsDigest);
        $this->assertSame(64, strlen($recorded[0]->paramsDigest));
    }//end testARefusalIsRecordedByItsCode()

    /**
     * A failed write is logged and never thrown to the caller.
     *
     * @return void
     */
    public function testAFailedWriteIsLoggedNotThrown(): void
    {
        $mapper = $this->createMock(AuditTrailMapperInterface::class);
        $mapper->method('recordToolInvocation')->willThrowException(new RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            '[ToolInvocationRecorder] Failed to write invocation audit record',
            ['toolId' => 'shillinq.invoice.issue', 'error' => 'db down']
        );

        (self::recorder($mapper, $logger))->record('shillinq.invoice.issue', [], ['invoice' => []]);
    }//end testAFailedWriteIsLoggedNotThrown()

    /**
     * The recorder over the built-in providers' audit writer.
     *
     * @param AuditTrailMapperInterface $mapper The audit-trail mapper double.
     * @param LoggerInterface           $logger The logger double.
     *
     * @return ToolInvocationRecorder
     */
    private static function recorder(AuditTrailMapperInterface $mapper, LoggerInterface $logger): ToolInvocationRecorder
    {
        return new ToolInvocationRecorder(new ToolInvocationAudit($mapper, $logger));
    }//end recorder()
}//end class
