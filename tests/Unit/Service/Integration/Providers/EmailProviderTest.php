<?php

/**
 * EmailProviderTest
 *
 * Unit tests for EmailProvider — coverage for the IntegrationProvider contract
 * and delegation to EmailService.
 *
 * @category Test
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

use OCA\OpenRegister\Service\EmailService;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\Providers\EmailProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EmailProvider.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @spec openspec/changes/integration-email/tasks.md#task-3
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
     * Subject under test.
     *
     * @var EmailProvider
     */
    private EmailProvider $provider;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->emailService = $this->createMock(EmailService::class);
        $this->provider     = new EmailProvider(emailService: $this->emailService);
    }//end setUp()

    /**
     * Provider implements IntegrationProvider interface.
     *
     * @return void
     */
    public function testImplementsIntegrationProviderInterface(): void
    {
        $this->assertInstanceOf(IntegrationProvider::class, $this->provider);
    }//end testImplementsIntegrationProviderInterface()

    /**
     * Provider metadata returns expected constants.
     *
     * @return void
     */
    public function testProviderMetadataConstants(): void
    {
        $this->assertSame('email', $this->provider->getId());
        $this->assertSame('Emails', $this->provider->getLabel());
        $this->assertSame('Email', $this->provider->getIcon());
        $this->assertSame('comms', $this->provider->getGroup());
        $this->assertSame('mail', $this->provider->getRequiredApp());
        $this->assertSame('link-table', $this->provider->getStorageStrategy());
        $this->assertNull($this->provider->requiresPermission());
    }//end testProviderMetadataConstants()

    /**
     * getLinkedItems delegates to EmailService when Mail is available.
     *
     * @return void
     */
    public function testGetLinkedItemsDelegatesToEmailService(): void
    {
        $expected = [
            'results' => [
                ['id' => 1, 'subject' => 'Hello world', 'sender' => 'alice@example.com'],
            ],
            'total'   => 1,
        ];

        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->emailService->method('getEmailsForObject')
            ->with('obj-uuid-1', 10, 0)
            ->willReturn($expected);

        $result = $this->provider->getLinkedItems(
            objectUuid: 'obj-uuid-1',
            limit: 10,
            offset: 0
        );

        $this->assertSame($expected, $result);
    }//end testGetLinkedItemsDelegatesToEmailService()

    /**
     * getLinkedItems returns empty result when Mail app is unavailable.
     *
     * @return void
     */
    public function testGetLinkedItemsReturnsEmptyWhenMailUnavailable(): void
    {
        $this->emailService->method('isMailAvailable')->willReturn(false);
        $this->emailService->expects($this->never())->method('getEmailsForObject');

        $result = $this->provider->getLinkedItems(objectUuid: 'obj-uuid-1');

        $this->assertSame(['results' => [], 'total' => 0], $result);
    }//end testGetLinkedItemsReturnsEmptyWhenMailUnavailable()

    /**
     * getLinkedItems passes null limit/offset to EmailService unchanged.
     *
     * @return void
     */
    public function testGetLinkedItemsPassesNullPaginationToService(): void
    {
        $this->emailService->method('isMailAvailable')->willReturn(true);
        $this->emailService->expects($this->once())
            ->method('getEmailsForObject')
            ->with('obj-uuid-2', null, null)
            ->willReturn(['results' => [], 'total' => 0]);

        $result = $this->provider->getLinkedItems(objectUuid: 'obj-uuid-2');

        $this->assertSame(['results' => [], 'total' => 0], $result);
    }//end testGetLinkedItemsPassesNullPaginationToService()

    /**
     * Provider present in registry when Mail is installed (scenario from spec).
     *
     * Verifies that getRequiredApp returns 'mail' so IntegrationRegistry can
     * filter based on NC app availability.
     *
     * @return void
     */
    public function testProviderIsFilteredByRequiredApp(): void
    {
        $requiredApp = $this->provider->getRequiredApp();
        $this->assertSame('mail', $requiredApp, 'Registry must filter provider by mail app availability');
    }//end testProviderIsFilteredByRequiredApp()

    /**
     * Permission inheritance — requiresPermission returns null (ADR-005 RBAC-inherit).
     *
     * @return void
     */
    public function testPermissionInheritanceReturnsNull(): void
    {
        $this->assertNull(
            $this->provider->requiresPermission(),
            'Email integration inherits from object RBAC + Mail account ownership'
        );
    }//end testPermissionInheritanceReturnsNull()
}//end class
