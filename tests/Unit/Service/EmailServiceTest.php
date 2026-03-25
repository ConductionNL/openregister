<?php

declare(strict_types=1);

namespace Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\EmailService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for EmailService.
 */
class EmailServiceTest extends TestCase
{
    private EmailLinkMapper&MockObject $emailLinkMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private LoggerInterface&MockObject $logger;
    private EmailService $service;

    protected function setUp(): void
    {
        $this->emailLinkMapper = $this->createMock(EmailLinkMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new EmailService(
            $this->emailLinkMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->logger
        );
    }

    private function createEmailLink(
        int $id,
        int $accountId,
        int $messageId,
        string $objectUuid,
        int $registerId,
        ?int $schemaId = null,
        ?string $linkedBy = null
    ): EmailLink {
        $link = new EmailLink();
        $link->setId($id);
        $link->setMailAccountId($accountId);
        $link->setMailMessageId($messageId);
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setLinkedBy($linkedBy);
        $link->setLinkedAt(new DateTime('2026-03-20T14:30:00+00:00'));
        return $link;
    }

    private function createRegisterMock(int $id, string $title): Register&MockObject
    {
        $register = $this->createMock(Register::class);
        $register->method('getTitle')->willReturn($title);
        return $register;
    }

    private function createSchemaMock(int $id, string $title): Schema&MockObject
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getTitle')->willReturn($title);
        return $schema;
    }

    public function testFindByMessageIdReturnsLinkedObjects(): void
    {
        $link1 = $this->createEmailLink(1, 1, 42, 'abc-123', 1, 3, 'admin');
        $link2 = $this->createEmailLink(2, 1, 42, 'def-456', 1, 3, 'admin');

        $this->emailLinkMapper->expects($this->once())
            ->method('findByAccountAndMessage')
            ->with(1, 42)
            ->willReturn([$link1, $link2]);

        $this->registerMapper->method('find')
            ->with(1)
            ->willReturn($this->createRegisterMock(1, 'Vergunningen'));

        $this->schemaMapper->method('find')
            ->with(3)
            ->willReturn($this->createSchemaMock(3, 'Omgevingsvergunning'));

        $result = $this->service->findByMessageId(1, 42);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['results']);
        $this->assertSame('abc-123', $result['results'][0]['objectUuid']);
        $this->assertSame('Vergunningen', $result['results'][0]['registerTitle']);
        $this->assertSame('Omgevingsvergunning', $result['results'][0]['schemaTitle']);
    }

    public function testFindByMessageIdReturnsEmptyWhenNoLinks(): void
    {
        $this->emailLinkMapper->expects($this->once())
            ->method('findByAccountAndMessage')
            ->with(1, 99)
            ->willReturn([]);

        $result = $this->service->findByMessageId(1, 99);

        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['results']);
    }

    public function testFindObjectsBySenderReturnsGroupedResults(): void
    {
        $this->emailLinkMapper->expects($this->once())
            ->method('findBySender')
            ->with('burger@test.local')
            ->willReturn([
                ['object_uuid' => 'abc-123', 'register_id' => '1', 'schema_id' => '3', 'linked_email_count' => '2'],
                ['object_uuid' => 'ghi-789', 'register_id' => '2', 'schema_id' => '5', 'linked_email_count' => '1'],
            ]);

        $this->registerMapper->method('find')
            ->willReturnCallback(function (int $id) {
                return match ($id) {
                    1 => $this->createRegisterMock(1, 'Vergunningen'),
                    2 => $this->createRegisterMock(2, 'Meldingen'),
                    default => throw new \Exception('Not found'),
                };
            });

        $this->schemaMapper->method('find')
            ->willReturnCallback(function (int $id) {
                return match ($id) {
                    3 => $this->createSchemaMock(3, 'Omgevingsvergunning'),
                    5 => $this->createSchemaMock(5, 'Melding'),
                    default => throw new \Exception('Not found'),
                };
            });

        $result = $this->service->findObjectsBySender('burger@test.local');

        $this->assertSame(2, $result['total']);
        $this->assertSame('abc-123', $result['results'][0]['objectUuid']);
        $this->assertSame(2, $result['results'][0]['linkedEmailCount']);
        $this->assertSame('ghi-789', $result['results'][1]['objectUuid']);
    }

    public function testFindObjectsBySenderReturnsEmptyForUnknownSender(): void
    {
        $this->emailLinkMapper->expects($this->once())
            ->method('findBySender')
            ->with('unknown@example.com')
            ->willReturn([]);

        $result = $this->service->findObjectsBySender('unknown@example.com');

        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['results']);
    }

    public function testQuickLinkCreatesNewLink(): void
    {
        $this->emailLinkMapper->expects($this->once())
            ->method('findExistingLink')
            ->with(1, 42, 'abc-123')
            ->willReturn(null);

        $this->emailLinkMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (EmailLink $link) {
                $link->setId(1);
                return $link;
            });

        $this->registerMapper->method('find')
            ->willReturn($this->createRegisterMock(1, 'Vergunningen'));
        $this->schemaMapper->method('find')
            ->willReturn($this->createSchemaMock(3, 'Omgevingsvergunning'));

        $result = $this->service->quickLink([
            'mailAccountId' => 1,
            'mailMessageId' => 42,
            'objectUuid' => 'abc-123',
            'registerId' => 1,
            'schemaId' => 3,
            'linkedBy' => 'admin',
        ]);

        $this->assertSame(1, $result['linkId']);
        $this->assertSame('abc-123', $result['objectUuid']);
        $this->assertSame('Vergunningen', $result['registerTitle']);
    }

    public function testQuickLinkThrowsOnDuplicate(): void
    {
        $existing = $this->createEmailLink(1, 1, 42, 'abc-123', 1, 3);

        $this->emailLinkMapper->expects($this->once())
            ->method('findExistingLink')
            ->with(1, 42, 'abc-123')
            ->willReturn($existing);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);

        $this->service->quickLink([
            'mailAccountId' => 1,
            'mailMessageId' => 42,
            'objectUuid' => 'abc-123',
            'registerId' => 1,
        ]);
    }

    public function testDeleteLinkCallsMapper(): void
    {
        $link = $this->createEmailLink(7, 1, 42, 'abc-123', 1);

        $this->emailLinkMapper->expects($this->once())
            ->method('findById')
            ->with(7)
            ->willReturn($link);

        $this->emailLinkMapper->expects($this->once())
            ->method('delete')
            ->with($link);

        $this->service->deleteLink(7);
    }
}
