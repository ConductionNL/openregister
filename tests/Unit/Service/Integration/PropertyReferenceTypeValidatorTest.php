<?php

/**
 * Unit tests for PropertyReferenceTypeValidator.
 *
 * Covers:
 *  - properties without `referenceType` pass through
 *  - `referenceType: null` passes through
 *  - non-string / empty / unregistered referenceType throws
 *  - registered referenceType is accepted
 *  - validateAll() walks every property in a map
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Integration\PropertyReferenceTypeValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tiny provider stub local to this test file (PSR-4 autoloading only
 * resolves one class per file by name).
 */
class _ValidatorStubProvider extends AbstractIntegrationProvider
{

    public function __construct(private string $id)
    {
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
        return 'magic-column';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    public function list(string $register, string $schema, string $objectId, array $filters = []): array
    {
        return [];
    }//end list()

}//end class

/**
 * Unit tests for PropertyReferenceTypeValidator.
 */
class PropertyReferenceTypeValidatorTest extends TestCase
{

    private IntegrationRegistry $registry;

    private PropertyReferenceTypeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry  = new IntegrationRegistry(new NullLogger());
        $this->registry->addProvider(new _ValidatorStubProvider('xwiki'));
        $this->registry->addProvider(new _ValidatorStubProvider('contacts'));
        $this->validator = new PropertyReferenceTypeValidator($this->registry);
    }//end setUp()

    public function testPropertyWithoutReferenceTypePassesThrough(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(['type' => 'string']);
    }//end testPropertyWithoutReferenceTypePassesThrough()

    public function testNullReferenceTypePassesThrough(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(['type' => 'string', 'referenceType' => null]);
    }//end testNullReferenceTypePassesThrough()

    public function testNonStringReferenceTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate(['type' => 'string', 'referenceType' => 42], 'assignedHandler');
    }//end testNonStringReferenceTypeIsRejected()

    public function testEmptyReferenceTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validate(['type' => 'string', 'referenceType' => '']);
    }//end testEmptyReferenceTypeIsRejected()

    public function testUnregisteredReferenceTypeIsRejected(): void
    {
        try {
            $this->validator->validate(
                ['type' => 'string', 'referenceType' => 'mail'],
                'assignedHandler'
            );
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("'mail'", $e->getMessage());
            $this->assertStringContainsString('Registered ids: contacts, xwiki', $e->getMessage());
        }
    }//end testUnregisteredReferenceTypeIsRejected()

    public function testRegisteredReferenceTypePasses(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(['type' => 'string', 'referenceType' => 'xwiki']);
    }//end testRegisteredReferenceTypePasses()

    public function testValidateAllWalksEveryProperty(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validateAll([
            'title'           => ['type' => 'string'],
            'assignedHandler' => ['type' => 'string', 'referenceType' => 'contacts'],
            'linkedPage'      => ['type' => 'string', 'referenceType' => 'xwiki'],
        ]);
    }//end testValidateAllWalksEveryProperty()

    public function testValidateAllStopsAtFirstInvalidProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator->validateAll([
            'title'         => ['type' => 'string'],
            'badReference'  => ['type' => 'string', 'referenceType' => 'mail'],
            'goodReference' => ['type' => 'string', 'referenceType' => 'contacts'],
        ]);
    }//end testValidateAllStopsAtFirstInvalidProperty()

    public function testEmptyRegistryReportsNoneInError(): void
    {
        $emptyRegistry  = new IntegrationRegistry(new NullLogger());
        $emptyValidator = new PropertyReferenceTypeValidator($emptyRegistry);

        try {
            $emptyValidator->validate(['referenceType' => 'xwiki']);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('(none)', $e->getMessage());
        }
    }//end testEmptyRegistryReportsNoneInError()

}//end class
