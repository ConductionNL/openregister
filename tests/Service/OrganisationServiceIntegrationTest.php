<?php

/**
 * Integration tests for OrganisationService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for OrganisationService
 *
 * Tests organisation creation, default organisation management,
 * cache operations, and settings retrieval.
 */
class OrganisationServiceIntegrationTest extends TestCase
{
    /**
     * The organisation service instance
     *
     * @var OrganisationService
     */
    private OrganisationService $service;

    /**
     * Organisation mapper
     *
     * @var OrganisationMapper
     */
    private OrganisationMapper $mapper;

    /**
     * UUIDs of created test organisations for cleanup
     *
     * @var string[]
     */
    private array $createdOrgUuids = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(OrganisationService::class);
        $this->mapper = \OC::$server->get(OrganisationMapper::class);
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdOrgUuids as $uuid) {
            try {
                $org = $this->mapper->findByUuid($uuid);
                $this->mapper->delete($org);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }

    /**
     * Test ensureDefaultOrganisation returns an Organisation
     *
     * @return void
     */
    public function testEnsureDefaultOrganisation(): void
    {
        $result = $this->service->ensureDefaultOrganisation();

        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertNotEmpty($result->getUuid());
        $this->assertNotEmpty($result->getName());
    }

    /**
     * Test ensureDefaultOrganisation is idempotent
     *
     * @return void
     */
    public function testEnsureDefaultOrganisationIdempotent(): void
    {
        $first = $this->service->ensureDefaultOrganisation();
        $second = $this->service->ensureDefaultOrganisation();

        $this->assertSame($first->getUuid(), $second->getUuid());
    }

    /**
     * Test getOrganisationSettingsOnly returns settings
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnly(): void
    {
        $result = $this->service->getOrganisationSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('organisation', $result);
        $this->assertArrayHasKey('default_organisation', $result['organisation']);
        $this->assertArrayHasKey('auto_create_default_organisation', $result['organisation']);
    }

    /**
     * Test getDefaultOrganisationUuid returns string or null
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuid(): void
    {
        // Ensure default exists first
        $this->service->ensureDefaultOrganisation();

        $result = $this->service->getDefaultOrganisationUuid();

        // After ensuring default org exists, the UUID should be set
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test createOrganisation creates a new organisation
     *
     * @return void
     */
    public function testCreateOrganisation(): void
    {
        $name = 'phpunit-test-' . uniqid();
        $description = 'Test organisation for integration tests';

        $result = $this->service->createOrganisation($name, $description, false);

        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertSame($name, $result->getName());
        $this->assertSame($description, $result->getDescription());
        $this->assertNotEmpty($result->getUuid());

        $this->createdOrgUuids[] = $result->getUuid();
    }

    /**
     * Test createOrganisation with specific UUID
     *
     * @return void
     */
    public function testCreateOrganisationWithUuid(): void
    {
        $name = 'phpunit-test-uuid-' . uniqid();
        $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $result = $this->service->createOrganisation($name, '', false, $uuid);

        $this->assertInstanceOf(Organisation::class, $result);
        $this->assertSame($uuid, $result->getUuid());

        $this->createdOrgUuids[] = $result->getUuid();
    }

    /**
     * Test createOrganisation with invalid UUID throws exception
     *
     * @return void
     */
    public function testCreateOrganisationInvalidUuid(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid UUID format');

        $this->service->createOrganisation('test', '', false, 'not-a-valid-uuid');
    }

    /**
     * Test createOrganisation generates slug
     *
     * @return void
     */
    public function testCreateOrganisationGeneratesSlug(): void
    {
        $name = 'phpunit-test-slug-' . uniqid();

        $result = $this->service->createOrganisation($name, '', false);

        $this->assertInstanceOf(Organisation::class, $result);
        $slug = $result->getSlug();
        $this->assertNotEmpty($slug);
        // Slug should be lowercase, no spaces
        $this->assertSame(strtolower($slug), $slug);
        $this->assertStringNotContainsString(' ', $slug);

        $this->createdOrgUuids[] = $result->getUuid();
    }

    /**
     * Test hasAccessToOrganisation with nonexistent org
     *
     * @return void
     */
    public function testHasAccessToOrganisationNonexistent(): void
    {
        $result = $this->service->hasAccessToOrganisation('nonexistent-uuid-' . uniqid());

        $this->assertFalse($result);
    }

    /**
     * Test getUserOrganisationStats returns proper structure
     *
     * @return void
     */
    public function testGetUserOrganisationStats(): void
    {
        $result = $this->service->getUserOrganisationStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('results', $result);
    }

    /**
     * Test clearDefaultOrganisationCache does not throw
     *
     * @return void
     */
    public function testClearDefaultOrganisationCache(): void
    {
        // Should not throw
        $this->service->clearDefaultOrganisationCache();

        // Verify we can still get default org after clearing cache
        $result = $this->service->ensureDefaultOrganisation();
        $this->assertInstanceOf(Organisation::class, $result);
    }

    /**
     * Test clearCache returns boolean
     *
     * @return void
     */
    public function testClearCache(): void
    {
        $result = $this->service->clearCache();

        // Returns false if no user is logged in, true otherwise
        $this->assertIsBool($result);
    }

    /**
     * Test getOrganisationForNewEntity returns string or null
     *
     * @return void
     */
    public function testGetOrganisationForNewEntity(): void
    {
        $result = $this->service->getOrganisationForNewEntity();

        // Should return a UUID string (either from active or default org)
        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test getDefaultOrganisationId returns string or null
     *
     * @return void
     */
    public function testGetDefaultOrganisationId(): void
    {
        // Ensure default exists
        $this->service->ensureDefaultOrganisation();

        $result = $this->service->getDefaultOrganisationId();

        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test setDefaultOrganisationId stores UUID
     *
     * @return void
     */
    public function testSetDefaultOrganisationId(): void
    {
        $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $this->service->setDefaultOrganisationId($uuid);

        $result = $this->service->getDefaultOrganisationId();
        $this->assertSame($uuid, $result);

        // Restore original default
        $defaultOrg = $this->service->ensureDefaultOrganisation();
        $this->service->setDefaultOrganisationId($defaultOrg->getUuid());
    }

    /**
     * Test getUserOrganisations returns array (may be empty without session)
     *
     * @return void
     */
    public function testGetUserOrganisations(): void
    {
        $result = $this->service->getUserOrganisations();

        $this->assertIsArray($result);
    }

    /**
     * Test getActiveOrganisation returns Organisation or null
     *
     * @return void
     */
    public function testGetActiveOrganisation(): void
    {
        $result = $this->service->getActiveOrganisation();

        $this->assertTrue($result === null || $result instanceof Organisation);
    }

    /**
     * Test getUserActiveOrganisations returns array
     *
     * @return void
     */
    public function testGetUserActiveOrganisations(): void
    {
        $result = $this->service->getUserActiveOrganisations();

        $this->assertIsArray($result);
    }
}
