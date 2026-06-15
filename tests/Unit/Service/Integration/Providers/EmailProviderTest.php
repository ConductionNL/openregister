<?php

/**
 * Unit tests for EmailProvider.
 *
 * Covers the provider contract (id, label, icon, group, storage,
 * requiredApp, isEnabled, health) and the delegation paths
 * (list, create, delete) that wrap EmailService + EmailLinkService.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-email/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test methods; arrange/act/assert structure makes intent self-documenting.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\Integration\Providers\EmailProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * EmailProvider unit tests.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class EmailProviderTest extends TestCase
{

    /**
     * Email service mock.
     *
     * @var EmailService&MockObject
     */
    private EmailService&MockObject $emailService;

    /**
     * Email link service mock.
     *
     * @var EmailLinkService&MockObject
     */
    private EmailLinkService&MockObject $emailLinkService;

    /**
     * App manager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Localisation mock.
     *
     * @var IL10N&MockObject
     */
    private IL10N&MockObject $l10n;

    /**
     * Provider under test.
     *
     * @var EmailProvider
     */
    private EmailProvider $provider;

    /**
     * Set up mocks and create the provider under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->emailService     = $this->createMock(EmailService::class);
        $this->emailLinkService = $this->createMock(EmailLinkService::class);
        $this->appManager       = $this->createMock(IAppManager::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

        $this->provider = new EmailProvider(
            emailService: $this->emailService,
            emailLinkService: $this->emailLinkService,
            appManager: $this->appManager,
            l10n: $this->l10n,
        );
    }//end setUp()

    public function testGetIdReturnsEmail(): void
    {
        $this->assertSame('email', $this->provider->getId());
    }//end testGetIdReturnsEmail()

    public function testGetLabelReturnsEmails(): void
    {
        $this->assertSame('Emails', $this->provider->getLabel());
    }//end testGetLabelReturnsEmails()

    public function testGetIconReturnsEmail(): void
    {
        $this->assertSame('Email', $this->provider->getIcon());
    }//end testGetIconReturnsEmail()

    public function testGetGroupReturnsComms(): void
    {
        $this->assertSame('comms', $this->provider->getGroup());
    }//end testGetGroupReturnsComms()

    public function testGetRequiredAppReturnsMail(): void
    {
        $this->assertSame('mail', $this->provider->getRequiredApp());
    }//end testGetRequiredAppReturnsMail()

    public function testGetStorageStrategyReturnsLinkTable(): void
    {
        $this->assertSame('link-table', $this->provider->getStorageStrategy());
    }//end testGetStorageStrategyReturnsLinkTable()

    public function testIsEnabledReturnsTrueWhenMailAvailable(): void
    {
        $this->emailLinkService->method('isMailAvailable')->willReturn(true);

        $this->assertTrue($this->provider->isEnabled());
    }//end testIsEnabledReturnsTrueWhenMailAvailable()

    public function testIsEnabledReturnsFalseWhenMailUnavailable(): void
    {
        $this->emailLinkService->method('isMailAvailable')->willReturn(false);

        $this->assertFalse($this->provider->isEnabled());
    }//end testIsEnabledReturnsFalseWhenMailUnavailable()

    public function testHealthReturnsOkWhenMailAvailable(): void
    {
        $this->emailLinkService->method('isMailAvailable')->willReturn(true);

        $health = $this->provider->health();

        $this->assertSame('ok', $health['status']);
        $this->assertSame('configured', $health['authStatus']);
        $this->assertNull($health['message']);
    }//end testHealthReturnsOkWhenMailAvailable()

    public function testHealthReturnsUnavailableWhenMailMissing(): void
    {
        $this->emailLinkService->method('isMailAvailable')->willReturn(false);

        $health = $this->provider->health();

        $this->assertSame('unavailable', $health['status']);
        $this->assertIsString($health['message']);
        $this->assertNotEmpty($health['message']);
    }//end testHealthReturnsUnavailableWhenMailMissing()

    public function testListDelegatesToEmailServiceAndReturnsPaginatedEnvelope(): void
    {
        $this->emailService->method('getEmailsForObject')
            ->with(objectUuid: 'obj-uuid', limit: null, offset: null)
            ->willReturn(['results' => [['id' => 1, 'subject' => 'Hello']], 'total' => 1]);

        $result = $this->provider->list('reg', 'schema', 'obj-uuid');

        $this->assertSame([['id' => 1, 'subject' => 'Hello']], $result['items']);
        $this->assertSame(1, $result['total']);
        $this->assertNull($result['nextCursor']);
    }//end testListDelegatesToEmailServiceAndReturnsPaginatedEnvelope()

    public function testListHonoursPaginationFilters(): void
    {
        $this->emailService->expects($this->once())
            ->method('getEmailsForObject')
            ->with(objectUuid: 'obj-uuid', limit: 10, offset: 10)
            ->willReturn(['results' => [['id' => 2]], 'total' => 25]);

        $result = $this->provider->list('reg', 'schema', 'obj-uuid', ['_limit' => 10, '_page' => 1]);

        $this->assertSame([['id' => 2]], $result['items']);
        $this->assertSame(25, $result['total']);
        // Page 1 with limit 10 gives offset 10; 10 + 1 = 11 < 25, so next page exists.
        $this->assertSame('2', $result['nextCursor']);
    }//end testListHonoursPaginationFilters()

    public function testListReturnsEmptyEnvelopeOnException(): void
    {
        $this->emailService->method('getEmailsForObject')
            ->willThrowException(new \RuntimeException('Mail down'));

        $result = $this->provider->list('reg', 'schema', 'obj-uuid');

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertNull($result['nextCursor']);
    }//end testListReturnsEmptyEnvelopeOnException()

    public function testListSetsNoNextCursorOnLastPage(): void
    {
        $this->emailService->method('getEmailsForObject')
            ->willReturn(['results' => [['id' => 3], ['id' => 4], ['id' => 5]], 'total' => 3]);

        // Limit 5 page 0 — all 3 rows fit: offset 0, 0 + 3 = 3 = total → no cursor.
        $result = $this->provider->list('r', 's', 'obj', ['_limit' => 5, '_page' => 0]);

        $this->assertNull($result['nextCursor']);
    }//end testListSetsNoNextCursorOnLastPage()

    public function testCreateDelegatesToEmailLinkService(): void
    {
        $link = $this->createMock(EmailLink::class);
        $link->method('jsonSerialize')->willReturn(
            ['id' => 99, 'objectUuid' => 'obj-uuid', 'mailAccountId' => 5, 'mailMessageId' => 'msg-1']
        );

        $this->emailLinkService->expects($this->once())
            ->method('linkEmail')
            ->with(
                objectUuid: 'obj-uuid',
                registerId: 1,
                schemaId: 2,
                mailAccountId: 5,
                messageId: 'msg-1',
                messageUid: 'uid-1',
            )
            ->willReturn($link);

        $payload = [
            'registerId'    => 1,
            'schemaId'      => 2,
            'mailAccountId' => 5,
            'messageId'     => 'msg-1',
            'messageUid'    => 'uid-1',
        ];

        $result = $this->provider->create('1', '2', 'obj-uuid', $payload);

        $this->assertSame(99, $result['id']);
    }//end testCreateDelegatesToEmailLinkService()

    public function testCreateFallsThroughToLegacyMailMessageIdField(): void
    {
        $link = $this->createMock(EmailLink::class);
        $link->method('jsonSerialize')->willReturn(['id' => 7]);

        $this->emailLinkService->expects($this->once())
            ->method('linkEmail')
            ->with(
                objectUuid: 'obj-uuid',
                registerId: 1,
                schemaId: 2,
                mailAccountId: 3,
                messageId: 'legacy-msg',
                messageUid: '',
            )
            ->willReturn($link);

        $payload = [
            'registerId'    => 1,
            'schemaId'      => 2,
            'mailAccountId' => 3,
            'mailMessageId' => 'legacy-msg',
        ];

        $result = $this->provider->create('1', '2', 'obj-uuid', $payload);

        $this->assertSame(7, $result['id']);
    }//end testCreateFallsThroughToLegacyMailMessageIdField()

    public function testDeleteDelegatesToEmailLinkService(): void
    {
        $this->emailLinkService->expects($this->once())
            ->method('unlinkEmail')
            ->with(objectUuid: 'obj-uuid', linkId: 42);

        $this->provider->delete('r', 's', 'obj-uuid', '42');
    }//end testDeleteDelegatesToEmailLinkService()
}//end class
