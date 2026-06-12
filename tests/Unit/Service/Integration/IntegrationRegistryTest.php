<?php

/**
 * Unit tests for IntegrationRegistry.
 *
 * Covers:
 *  - addProvider() accepts valid providers and indexes them by id
 *  - duplicate id is rejected; first registration wins (AD-13)
 *  - external storage without OpenConnector source is rejected (AD-4)
 *  - list / listIds / get / getEnabled return expected shapes
 *  - withProviders() replaces the entire registered set (test seam)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Minimal stub provider used across the test suite.
 *
 * Concrete tests override the metadata methods inline; the
 * AbstractIntegrationProvider base supplies the rest.
 */
class _DummyProvider extends AbstractIntegrationProvider
{

    public function __construct(
        private string $id,
        private string $storage = 'magic-column',
        private ?string $openConnectorSource = null,
        private bool $enabled = true,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return $this->id;
    }//end getId()

    public function getLabel(): string
    {
        return ucfirst($this->id);
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Cube';
    }//end getIcon()

    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return $this->storage;
    }//end getStorageStrategy()

    public function getOpenConnectorSource(): ?string
    {
        return $this->openConnectorSource;
    }//end getOpenConnectorSource()

    public function isEnabled(): bool
    {
        return $this->enabled;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * Unit tests for IntegrationRegistry.
 */
class IntegrationRegistryTest extends TestCase
{

    private IntegrationRegistry $registry;

    /**
     * Build a fresh registry with a NullLogger for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new IntegrationRegistry(new NullLogger());
    }//end setUp()

    public function testAddProviderRegistersAndExposesById(): void
    {
        $provider = new _DummyProvider('files');
        $accepted = $this->registry->addProvider($provider);

        $this->assertTrue($accepted, 'addProvider should accept a valid provider');
        $this->assertSame($provider, $this->registry->get('files'));
        $this->assertSame(['files'], $this->registry->listIds());
    }//end testAddProviderRegistersAndExposesById()

    public function testDuplicateIdKeepsFirstRegistration(): void
    {
        $first  = new _DummyProvider('files');
        $second = new _DummyProvider('files');

        $this->assertTrue($this->registry->addProvider($first));
        $this->assertFalse(
            $this->registry->addProvider($second),
            'Second registration with the same id must be rejected'
        );
        $this->assertSame($first, $this->registry->get('files'));
        $this->assertSame(['files'], $this->registry->listIds());
    }//end testDuplicateIdKeepsFirstRegistration()

    public function testExternalProviderWithoutSourceIsRejected(): void
    {
        $broken = new _DummyProvider(id: 'xwiki', storage: 'external', openConnectorSource: null);
        $this->assertFalse(
            $this->registry->addProvider($broken),
            'External provider without OpenConnector source must be rejected'
        );
        $this->assertNull($this->registry->get('xwiki'));
    }//end testExternalProviderWithoutSourceIsRejected()

    public function testExternalProviderWithSourceIsAccepted(): void
    {
        $wired = new _DummyProvider(id: 'xwiki', storage: 'external', openConnectorSource: 'xwiki');
        $this->assertTrue($this->registry->addProvider($wired));
        $this->assertSame($wired, $this->registry->get('xwiki'));
    }//end testExternalProviderWithSourceIsAccepted()

    public function testGetEnabledFiltersDisabledProviders(): void
    {
        $a = new _DummyProvider(id: 'files', enabled: true);
        $b = new _DummyProvider(id: 'deck', enabled: false);
        $this->registry->addProvider($a);
        $this->registry->addProvider($b);

        $enabled = $this->registry->getEnabled();
        $this->assertCount(1, $enabled);
        $this->assertSame($a, $enabled[0]);
    }//end testGetEnabledFiltersDisabledProviders()

    public function testWithProvidersReplacesEntireSet(): void
    {
        $this->registry->addProvider(new _DummyProvider('files'));
        $this->registry->withProviders([
            new _DummyProvider('notes'),
            new _DummyProvider('tasks'),
        ]);

        $this->assertNull($this->registry->get('files'));
        $this->assertSame(['notes', 'tasks'], $this->registry->listIds());
    }//end testWithProvidersReplacesEntireSet()

    public function testListReturnsAllProvidersRegardlessOfEnabled(): void
    {
        $a = new _DummyProvider(id: 'a', enabled: true);
        $b = new _DummyProvider(id: 'b', enabled: false);
        $this->registry->addProvider($a);
        $this->registry->addProvider($b);

        $all = $this->registry->list();
        $this->assertCount(2, $all);
    }//end testListReturnsAllProvidersRegardlessOfEnabled()

    public function testGetReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->registry->get('nonexistent'));
    }//end testGetReturnsNullForUnknownId()

}//end class
