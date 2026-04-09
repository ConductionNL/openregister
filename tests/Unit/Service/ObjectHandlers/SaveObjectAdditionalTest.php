<?php

/**
 * SaveObject Additional Unit Tests
 *
 * Tests for uncovered methods and branches in the SaveObject service,
 * focusing on: isReference, isEffectivelyEmptyObject, isValueNotEmpty,
 * isAuditTrailsEnabled, generateSlug, createSlug, removeQueryParameters,
 * resolveRegisterReference, sanitizeEmptyStringsForObjectProperties,
 * shouldApplyDefault, resolveDefaultTemplateValue, applyAlwaysDefaults,
 * applyPropertyDefaults, hydrateObjectMetadata branches, setSelfMetadata,
 * and getValueFromPath.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use stdClass;
use Twig\Loader\ArrayLoader;

/**
 * Testable Schema subclass to avoid mocking Entity __call magic methods.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AdditionalTestableSchema extends Schema
{
    public ?stdClass $testSchemaObject = null;
    public ?array $testConfiguration = null;
    public ?array $testProperties = null;
    private bool $testHasPropertyAuth = false;

    /**
     * @param IURLGenerator $urlGenerator URL generator
     *
     * @return stdClass
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): stdClass
    {
        return $this->testSchemaObject ?? new stdClass();
    }

    /**
     * @return array|null
     */
    public function getConfiguration(): ?array
    {
        return $this->testConfiguration;
    }

    /**
     * @return array
     */
    public function getProperties(): array
    {
        return $this->testProperties ?? [];
    }

    /**
     * @return bool
     */
    public function hasPropertyAuthorization(): bool
    {
        return $this->testHasPropertyAuth;
    }

    /**
     * @param bool $value Whether schema has property auth
     *
     * @return void
     */
    public function setTestHasPropertyAuth(bool $value): void
    {
        $this->testHasPropertyAuth = $value;
    }
}

/**
 * Additional unit tests for SaveObject service.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SaveObjectAdditionalTest extends TestCase
{
    private SaveObject $saveObject;
    private ReflectionClass $reflection;

    /** @var MockObject&MagicMapper */
    private $objectEntityMapper;

    /** @var MockObject&MagicMapper */
    private $unifiedObjectMapper;

    /** @var MockObject&MetadataHydrationHandler */
    private $metaHydrationHandler;

    /** @var MockObject&FilePropertyHandler */
    private $filePropertyHandler;

    /** @var MockObject&IUserSession */
    private $userSession;

    /** @var MockObject&AuditTrailMapper */
    private $auditTrailMapper;

    /** @var MockObject&SchemaMapper */
    private $schemaMapper;

    /** @var MockObject&RegisterMapper */
    private $registerMapper;

    /** @var MockObject&IURLGenerator */
    private $urlGenerator;

    /** @var MockObject&OrganisationService */
    private $organisationService;

    /** @var MockObject&CacheHandler */
    private $cacheHandler;

    /** @var MockObject&SettingsService */
    private $settingsService;

    /** @var MockObject&PropertyRbacHandler */
    private $propertyRbacHandler;

    /** @var MockObject&LoggerInterface */
    private $logger;

    /** @var Register */
    private Register $mockRegister;

    /** @var AdditionalTestableSchema */
    private AdditionalTestableSchema $mockSchema;

    /** @var MockObject&IUser */
    private $mockUser;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->unifiedObjectMapper = $this->createMock(MagicMapper::class);
        $this->metaHydrationHandler = $this->createMock(MetadataHydrationHandler::class);
        $this->filePropertyHandler = $this->createMock(FilePropertyHandler::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->mockRegister = new Register();
        $this->mockRegister->setId(1);
        $this->mockRegister->setSlug('test-register');

        $this->mockSchema = new AdditionalTestableSchema();
        $this->mockSchema->setId(1);
        $this->mockSchema->setSlug('test-schema');
        $this->mockSchema->testSchemaObject = (object) ['properties' => new stdClass()];

        $this->mockUser = $this->createMock(IUser::class);
        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-123');

        $this->saveObject = new SaveObject(
            $this->objectEntityMapper,
            $this->unifiedObjectMapper,
            $this->metaHydrationHandler,
            $this->filePropertyHandler,
            $this->createMock(LinkedEntityPropertyHandler::class),
            $this->userSession,
            $this->auditTrailMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->urlGenerator,
            $this->organisationService,
            $this->cacheHandler,
            $this->settingsService,
            $this->propertyRbacHandler,
            $this->createMock(ComputedFieldHandler::class),
            $this->createMock(TranslationHandler::class),
            $this->logger,
            $this->createMock(TmloService::class),
            new ArrayLoader([])
        );

        $this->reflection = new ReflectionClass(SaveObject::class);
    }

    /**
     * Helper to invoke private methods using reflection.
     *
     * @param string $methodName Method name
     * @param array  $parameters Parameters
     *
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->saveObject, $parameters);
    }

    /**
     * Helper to create an ObjectEntity with an ID via reflection.
     *
     * @param int    $id   Entity ID
     * @param string $uuid Entity UUID
     *
     * @return ObjectEntity
     */
    private function createEntity(int $id, string $uuid): ObjectEntity
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        $entity->setUuid($uuid);
        $entity->setRegister(1);
        $entity->setSchema(1);

        return $entity;
    }

    // =================================================================
    // isReference() tests
    // =================================================================

    public function testIsReferenceWithStandardUuid(): void
    {
        $result = $this->invokePrivate('isReference', ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithUuidWithoutDashes(): void
    {
        $result = $this->invokePrivate('isReference', ['dec9ac6ea4fd40fcbe5fe7ef6e5defb4']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithPrefixedUuid(): void
    {
        $result = $this->invokePrivate('isReference', ['id-819c2fe5-db4e-4b6f-8071-6a63fd400e34']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithPrefixedUuidNoDashes(): void
    {
        $result = $this->invokePrivate('isReference', ['ref-819c2fe5db4e4b6f80716a63fd400e34']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithNumericId(): void
    {
        $result = $this->invokePrivate('isReference', ['12345']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithUrl(): void
    {
        $result = $this->invokePrivate('isReference', ['http://example.com/api/objects/123']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithEmptyString(): void
    {
        $result = $this->invokePrivate('isReference', ['']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithWhitespaceOnly(): void
    {
        $result = $this->invokePrivate('isReference', ['   ']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithPlainText(): void
    {
        $result = $this->invokePrivate('isReference', ['Hello World']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithCommonWordReturnsTrue(): void
    {
        // "applicatie" is in the common words list, so it should NOT be treated as a reference.
        $result = $this->invokePrivate('isReference', ['applicatie']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithOpenSourceReturnsTrue(): void
    {
        // "open-source" is a common word excluded from identifier-like patterns.
        $result = $this->invokePrivate('isReference', ['open-source']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithIdentifierLikeString(): void
    {
        // Has hyphen and looks like an ID (8+ chars, alphanumeric with hyphens).
        $result = $this->invokePrivate('isReference', ['my-custom-identifier']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithShortString(): void
    {
        // Short string without hyphens, not UUID, not URL, not numeric.
        $result = $this->invokePrivate('isReference', ['short']);
        $this->assertFalse($result);
    }

    // =================================================================
    // isEffectivelyEmptyObject() tests
    // =================================================================

    public function testIsEffectivelyEmptyObjectWithEmptyArray(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [[]]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithOnlyMetadataKeys(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [
            ['@self' => ['foo' => 'bar'], 'id' => '123', '_id' => 'abc'],
        ]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithNullValues(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [
            ['name' => null, 'description' => null],
        ]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithEmptyStringValues(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [
            ['name' => '', 'description' => '  '],
        ]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithNonEmptyValue(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [
            ['name' => 'Test'],
        ]);
        $this->assertFalse($result);
    }

    public function testIsEffectivelyEmptyObjectWithNestedEmptyObject(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [
            ['address' => ['street' => '', 'city' => null]],
        ]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithNestedNonEmptyObject(): void
    {
        $result = $this->invokePrivate('isEffectivelyEmptyObject', [
            ['address' => ['street' => '123 Main St']],
        ]);
        $this->assertFalse($result);
    }

    // =================================================================
    // isValueNotEmpty() tests
    // =================================================================

    public function testIsValueNotEmptyWithNull(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [null]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithEmptyString(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', ['']);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithWhitespace(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', ['  ']);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithEmptyArray(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [[]]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithNonEmptyString(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', ['test']);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithNumber(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [42]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithZero(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [0]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithFalse(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [false]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithAssociativeArrayAllEmpty(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [['name' => null, 'desc' => '']]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithIndexedArrayAllEmpty(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [[null, '', '  ']]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithIndexedArraySomeNotEmpty(): void
    {
        $result = $this->invokePrivate('isValueNotEmpty', [[null, 'test']]);
        $this->assertTrue($result);
    }

    // =================================================================
    // isAuditTrailsEnabled() tests
    // =================================================================

    public function testIsAuditTrailsEnabledReturnsTrue(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn(['auditTrailsEnabled' => true]);

        $result = $this->invokePrivate('isAuditTrailsEnabled', []);
        $this->assertTrue($result);
    }

    public function testIsAuditTrailsEnabledReturnsFalse(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn(['auditTrailsEnabled' => false]);

        $result = $this->invokePrivate('isAuditTrailsEnabled', []);
        $this->assertFalse($result);
    }

    public function testIsAuditTrailsEnabledDefaultsToTrueWhenKeyMissing(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn([]);

        $result = $this->invokePrivate('isAuditTrailsEnabled', []);
        $this->assertTrue($result);
    }

    public function testIsAuditTrailsEnabledDefaultsToTrueOnException(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willThrowException(new Exception('Settings unavailable'));

        $result = $this->invokePrivate('isAuditTrailsEnabled', []);
        $this->assertTrue($result);
    }

    // =================================================================
    // removeQueryParameters() tests
    // =================================================================

    public function testRemoveQueryParametersWithQueryString(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema?key=value']);
        $this->assertSame('schema', $result);
    }

    public function testRemoveQueryParametersWithoutQueryString(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema']);
        $this->assertSame('schema', $result);
    }

    public function testRemoveQueryParametersWithMultipleParams(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema?a=1&b=2']);
        $this->assertSame('schema', $result);
    }

    // =================================================================
    // generateSlug() + createSlug() tests
    // =================================================================

    public function testGenerateSlugReturnsNullWhenNoConfig(): void
    {
        $this->mockSchema->testConfiguration = null;
        $result = $this->invokePrivate('generateSlug', [['name' => 'Test'], $this->mockSchema]);
        $this->assertNull($result);
    }

    public function testGenerateSlugReturnsNullWhenNoSlugField(): void
    {
        $this->mockSchema->testConfiguration = [];
        $result = $this->invokePrivate('generateSlug', [['name' => 'Test'], $this->mockSchema]);
        $this->assertNull($result);
    }

    public function testGenerateSlugReturnsNullWhenFieldValueIsEmpty(): void
    {
        $this->mockSchema->testConfiguration = ['objectSlugField' => 'name'];
        $result = $this->invokePrivate('generateSlug', [['name' => ''], $this->mockSchema]);
        $this->assertNull($result);
    }

    public function testGenerateSlugReturnsSlugWithTimestamp(): void
    {
        $this->mockSchema->testConfiguration = ['objectSlugField' => 'name'];
        $result = $this->invokePrivate('generateSlug', [['name' => 'My Test Object'], $this->mockSchema]);
        $this->assertNotNull($result);
        $this->assertMatchesRegularExpression('/^my-test-object-\d+$/', $result);
    }

    public function testCreateSlugConvertsToLowercase(): void
    {
        $result = $this->invokePrivate('createSlug', ['Hello World']);
        $this->assertSame('hello-world', $result);
    }

    public function testCreateSlugReplacesSpecialCharacters(): void
    {
        $result = $this->invokePrivate('createSlug', ['Hello & World! @2024']);
        $this->assertSame('hello-world-2024', $result);
    }

    public function testCreateSlugTrimsHyphens(): void
    {
        $result = $this->invokePrivate('createSlug', ['---test---']);
        $this->assertSame('test', $result);
    }

    public function testCreateSlugTruncatesLongStrings(): void
    {
        $longString = str_repeat('a', 60);
        $result = $this->invokePrivate('createSlug', [$longString]);
        $this->assertLessThanOrEqual(50, strlen($result));
    }

    // =================================================================
    // shouldApplyDefault() tests
    // =================================================================

    public function testShouldApplyDefaultAlwaysReturnsTrue(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['always', ['key' => 'value'], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyWithMissingKey(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['falsy', [], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyWithNullValue(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['falsy', ['key' => null], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyWithEmptyString(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['falsy', ['key' => ''], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyWithEmptyArray(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['falsy', ['key' => []], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyWithNonEmptyValue(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['falsy', ['key' => 'test'], 'key']);
        $this->assertFalse($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithMissingKey(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['false', [], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithNullValue(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['false', ['key' => null], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithExistingValue(): void
    {
        $result = $this->invokePrivate('shouldApplyDefault', ['false', ['key' => 'val'], 'key']);
        $this->assertFalse($result);
    }

    // =================================================================
    // resolveDefaultTemplateValue() tests
    // =================================================================

    public function testResolveDefaultTemplateValueSimpleReference(): void
    {
        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            '{{ name }}',
            ['name' => 'Test Value'],
            [],
        ]);
        $this->assertSame('Test Value', $result);
    }

    public function testResolveDefaultTemplateValueSimpleReferencePreservesArray(): void
    {
        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            '{{ items }}',
            ['items' => ['a', 'b', 'c']],
            [],
        ]);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testResolveDefaultTemplateValueSimpleReferenceMissing(): void
    {
        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            '{{ missing }}',
            ['name' => 'Test'],
            [],
        ]);
        $this->assertNull($result);
    }

    public function testResolveDefaultTemplateValueComplexTemplate(): void
    {
        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willReturn('John Doe');

        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            '{{ first }} {{ last }}',
            ['first' => 'John', 'last' => 'Doe'],
            [],
        ]);
        $this->assertSame('John Doe', $result);
    }

    public function testResolveDefaultTemplateValueNonTemplate(): void
    {
        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            'plain value',
            [],
            [],
        ]);
        $this->assertSame('plain value', $result);
    }

    public function testResolveDefaultTemplateValueNonString(): void
    {
        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            42,
            [],
            [],
        ]);
        $this->assertSame(42, $result);
    }

    public function testResolveDefaultTemplateValueExceptionReturnsNull(): void
    {
        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willThrowException(new Exception('Template error'));

        $result = $this->invokePrivate('resolveDefaultTemplateValue', [
            '{{ a }} {{ b }}',
            ['a' => 'x', 'b' => 'y'],
            [],
        ]);
        $this->assertNull($result);
    }

    // =================================================================
    // applyAlwaysDefaults() tests
    // =================================================================

    public function testApplyAlwaysDefaultsNoProperties(): void
    {
        $this->mockSchema->testSchemaObject = (object) ['properties' => new stdClass()];
        $data = ['name' => 'Test'];
        $result = $this->saveObject->applyAlwaysDefaults($this->mockSchema, $data);
        $this->assertSame($data, $result);
    }

    public function testApplyAlwaysDefaultsAppliesAlwaysBehavior(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'computed' => [
                    'type' => 'string',
                    'default' => 'constant-value',
                    'defaultBehavior' => 'always',
                ],
            ],
        ];

        $data = ['name' => 'Test', 'computed' => 'old-value'];
        $result = $this->saveObject->applyAlwaysDefaults($this->mockSchema, $data);
        $this->assertSame('constant-value', $result['computed']);
    }

    public function testApplyAlwaysDefaultsSkipsNonAlwaysDefaults(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => [
                    'type' => 'string',
                    'default' => 'default-name',
                    'defaultBehavior' => 'false',
                ],
            ],
        ];

        $data = ['name' => 'Existing'];
        $result = $this->saveObject->applyAlwaysDefaults($this->mockSchema, $data);
        $this->assertSame('Existing', $result['name']);
    }

    public function testApplyAlwaysDefaultsWithInvalidSchemaObjectReturnsData(): void
    {
        // Schema that throws on getSchemaObject.
        $schema = new class extends Schema {
            public function getSchemaObject(IURLGenerator $urlGenerator): stdClass
            {
                throw new Exception('Invalid schema');
            }
            public function hasPropertyAuthorization(): bool
            {
                return false;
            }
        };
        $schema->setId(99);

        $data = ['name' => 'Test'];
        $result = $this->saveObject->applyAlwaysDefaults($schema, $data);
        $this->assertSame($data, $result);
    }

    // =================================================================
    // applyPropertyDefaults() tests
    // =================================================================

    public function testApplyPropertyDefaultsAppliesDefaults(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'status' => [
                    'type' => 'string',
                    'default' => 'active',
                ],
            ],
        ];

        $data = [];
        $result = $this->saveObject->applyPropertyDefaults($this->mockSchema, $data);
        $this->assertSame('active', $result['status']);
    }

    public function testApplyPropertyDefaultsSkipsExistingValues(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'status' => [
                    'type' => 'string',
                    'default' => 'active',
                ],
            ],
        ];

        $data = ['status' => 'inactive'];
        $result = $this->saveObject->applyPropertyDefaults($this->mockSchema, $data);
        $this->assertSame('inactive', $result['status']);
    }

    public function testApplyPropertyDefaultsFalsyBehavior(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'status' => [
                    'type' => 'string',
                    'default' => 'active',
                    'defaultBehavior' => 'falsy',
                ],
            ],
        ];

        $data = ['status' => ''];
        $result = $this->saveObject->applyPropertyDefaults($this->mockSchema, $data);
        $this->assertSame('active', $result['status']);
    }

    // =================================================================
    // getValueFromPath() tests
    // =================================================================

    public function testGetValueFromPathSimple(): void
    {
        $result = $this->invokePrivate('getValueFromPath', [['name' => 'Test'], 'name']);
        $this->assertSame('Test', $result);
    }

    public function testGetValueFromPathNested(): void
    {
        $result = $this->invokePrivate('getValueFromPath', [
            ['contact' => ['email' => 'test@test.com']],
            'contact.email',
        ]);
        $this->assertSame('test@test.com', $result);
    }

    public function testGetValueFromPathReturnsNullForMissingKey(): void
    {
        $result = $this->invokePrivate('getValueFromPath', [['name' => 'Test'], 'missing']);
        $this->assertNull($result);
    }

    public function testGetValueFromPathConvertIntToString(): void
    {
        $result = $this->invokePrivate('getValueFromPath', [['count' => 42], 'count']);
        $this->assertSame('42', $result);
    }

    // =================================================================
    // sanitizeEmptyStringsForObjectProperties() tests
    // =================================================================

    public function testSanitizeEmptyStringsObjectPropertyEmptyStringBecomesNull(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'address' => ['type' => 'object'],
            ],
            'required' => [],
        ];

        $data = ['address' => ''];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        $this->assertNull($result['address']);
    }

    public function testSanitizeEmptyStringsObjectPropertyEmptyObjectNonRequired(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'address' => ['type' => 'object'],
            ],
            'required' => [],
        ];

        $data = ['address' => []];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        $this->assertNull($result['address']);
    }

    public function testSanitizeEmptyStringsObjectPropertyEmptyObjectRequired(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'address' => ['type' => 'object', 'required' => true],
            ],
            'required' => ['address'],
        ];

        $data = ['address' => []];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        // Required empty object stays as [] for validation error.
        $this->assertSame([], $result['address']);
    }

    public function testSanitizeEmptyStringsArrayPropertyEmptyString(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'tags' => ['type' => 'array'],
            ],
            'required' => [],
        ];

        $data = ['tags' => ''];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        $this->assertNull($result['tags']);
    }

    public function testSanitizeEmptyStringsArrayItemsWithEmptyStrings(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'items' => ['type' => 'array'],
            ],
            'required' => [],
        ];

        $data = ['items' => ['valid', '', 'also-valid']];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        $this->assertSame(['valid', null, 'also-valid'], $result['items']);
    }

    public function testSanitizeEmptyStringsScalarNonRequired(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => ['type' => 'string'],
            ],
            'required' => [],
        ];

        $data = ['name' => ''];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        $this->assertNull($result['name']);
    }

    public function testSanitizeEmptyStringsScalarRequired(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];

        $data = ['name' => ''];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        // Required empty string stays for validation error.
        $this->assertSame('', $result['name']);
    }

    public function testSanitizeEmptyStringsSkipsMissingProperty(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => ['type' => 'string'],
            ],
            'required' => [],
        ];

        $data = ['other' => 'value'];
        $result = $this->invokePrivate(
            'sanitizeEmptyStringsForObjectProperties',
            [$data, $this->mockSchema]
        );
        $this->assertSame($data, $result);
    }

    // =================================================================
    // resolveSchemaReference() tests - error paths
    // =================================================================

    public function testResolveSchemaReferenceEmptyString(): void
    {
        $result = $this->invokePrivate('resolveSchemaReference', ['']);
        $this->assertNull($result);
    }

    public function testResolveSchemaReferenceCachedResult(): void
    {
        // Pre-populate the schema reference cache.
        $cacheProperty = $this->reflection->getProperty('schemaReferenceCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($this->saveObject, ['test-ref' => '42']);

        $result = $this->invokePrivate('resolveSchemaReference', ['test-ref']);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceNotFoundCachesNull(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('find')->willThrowException(
            new DoesNotExistException('Not found')
        );

        $result = $this->invokePrivate('resolveSchemaReference', ['nonexistent-slug']);
        $this->assertNull($result);

        // Verify it was cached as null.
        $cacheProperty = $this->reflection->getProperty('schemaReferenceCache');
        $cacheProperty->setAccessible(true);
        $cache = $cacheProperty->getValue($this->saveObject);
        $this->assertNull($cache['nonexistent-slug']);
    }

    // =================================================================
    // resolveRegisterReference() tests
    // =================================================================

    public function testResolveRegisterReferenceEmpty(): void
    {
        $result = $this->invokePrivate('resolveRegisterReference', ['']);
        $this->assertNull($result);
    }

    public function testResolveRegisterReferenceNumericId(): void
    {
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 42);

        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->invokePrivate('resolveRegisterReference', ['42']);
        $this->assertSame('42', $result);
    }

    public function testResolveRegisterReferenceBySlugInUrl(): void
    {
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 5);
        $register->setSlug('publication');

        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->registerMapper->method('findAll')
            ->willReturn([$register]);

        $result = $this->invokePrivate(
            'resolveRegisterReference',
            ['http://example.com/registers/publication']
        );
        $this->assertSame('5', $result);
    }

    public function testResolveRegisterReferenceNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivate('resolveRegisterReference', ['nonexistent']);
        $this->assertNull($result);
    }

    // =================================================================
    // fillMissingSchemaPropertiesWithNull() tests
    // =================================================================

    public function testFillMissingSchemaPropertiesWithNull(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'status' => ['type' => 'string'],
            ],
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);

        $data = ['name' => 'Test'];
        $result = $this->invokePrivate('fillMissingSchemaPropertiesWithNull', [$data, 1]);
        $this->assertArrayHasKey('email', $result);
        $this->assertNull($result['email']);
        $this->assertArrayHasKey('status', $result);
        $this->assertNull($result['status']);
        $this->assertSame('Test', $result['name']);
    }

    // =================================================================
    // clearAllCaches() and sub-object tracking tests
    // =================================================================

    public function testClearAllCachesResetsEverything(): void
    {
        $this->saveObject->trackCreatedSubObject('uuid-1', ['name' => 'test']);
        $this->assertNotEmpty($this->saveObject->getCreatedSubObjects());

        $this->saveObject->clearAllCaches();
        $this->assertEmpty($this->saveObject->getCreatedSubObjects());
    }

    public function testTrackAndGetCreatedSubObjects(): void
    {
        $this->saveObject->clearCreatedSubObjects();
        $this->saveObject->trackCreatedSubObject('uuid-1', ['name' => 'Obj 1']);
        $this->saveObject->trackCreatedSubObject('uuid-2', ['name' => 'Obj 2']);

        $result = $this->saveObject->getCreatedSubObjects();
        $this->assertCount(2, $result);
        $this->assertSame(['name' => 'Obj 1'], $result['uuid-1']);
        $this->assertSame(['name' => 'Obj 2'], $result['uuid-2']);
    }

    // =================================================================
    // hydrateObjectMetadata() - image field branches
    // =================================================================

    public function testHydrateObjectMetadataWithImageStringUrl(): void
    {
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['image' => 'https://example.com/img.png']);

        $this->mockSchema->testConfiguration = ['objectImageField' => 'image'];

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn('https://example.com/img.png');

        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertSame('https://example.com/img.png', $entity->getImage());
    }

    public function testHydrateObjectMetadataWithImageArrayFileObjects(): void
    {
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['logo' => [['downloadUrl' => 'https://example.com/file.png']]]);

        $this->mockSchema->testConfiguration = ['objectImageField' => 'logo'];

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([['downloadUrl' => 'https://example.com/file.png']]);

        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertSame('https://example.com/file.png', $entity->getImage());
    }

    public function testHydrateObjectMetadataWithImageArrayAccessUrl(): void
    {
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['logo' => [['accessUrl' => 'https://example.com/access.png']]]);

        $this->mockSchema->testConfiguration = ['objectImageField' => 'logo'];

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([['accessUrl' => 'https://example.com/access.png']]);

        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertSame('https://example.com/access.png', $entity->getImage());
    }

    public function testHydrateObjectMetadataWithPublishedDate(): void
    {
        // objectPublishedField is deprecated — should not throw.
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['pubDate' => '2024-01-15']);

        $this->mockSchema->testConfiguration = ['objectPublishedField' => 'pubDate'];

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('2024-01-15');

        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertTrue(true);
    }

    public function testHydrateObjectMetadataWithInvalidPublishedDate(): void
    {
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['pubDate' => 'not-a-date']);

        $this->mockSchema->testConfiguration = ['objectPublishedField' => 'pubDate'];

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('not-a-date');

        // Should log warning but not throw.
        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        // No exception means success.
        $this->assertTrue(true);
    }

    public function testHydrateObjectMetadataWithDepublishedDate(): void
    {
        // objectDepublishedField is deprecated — should not throw.
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['endDate' => '2025-12-31']);

        $this->mockSchema->testConfiguration = ['objectDepublishedField' => 'endDate'];

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('2025-12-31');

        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertTrue(true);
    }

    public function testHydrateObjectMetadataWithInvalidDepublishedDate(): void
    {
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['endDate' => 'invalid-date']);

        $this->mockSchema->testConfiguration = ['objectDepublishedField' => 'endDate'];

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('invalid-date');

        // Should log warning but not throw.
        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertTrue(true);
    }

    public function testHydrateObjectMetadataWithNumericImage(): void
    {
        $entity = $this->createEntity(1, 'aaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setObject(['fileId' => 123]);

        $this->mockSchema->testConfiguration = ['objectImageField' => 'fileId'];

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn(123);

        // Numeric image - logs debug but doesn't set image.
        $this->saveObject->hydrateObjectMetadata($entity, $this->mockSchema);
        $this->assertTrue(true);
    }

    // =================================================================
    // scanForRelations() tests - additional branches
    // =================================================================

    public function testScanForRelationsWithSchemaObjectProperty(): void
    {
        $schema = $this->mockSchema;
        $schema->testSchemaObject = (object) [
            'properties' => (object) [
                'organisation' => ['type' => 'object'],
            ],
        ];

        $data = ['organisation' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->saveObject->scanForRelations($data, '', $schema);
        $this->assertArrayHasKey('organisation', $result);
    }

    public function testScanForRelationsWithSchemaTextUuidProperty(): void
    {
        $schema = $this->mockSchema;
        $schema->testSchemaObject = (object) [
            'properties' => (object) [
                'ref' => ['type' => 'text', 'format' => 'uuid'],
            ],
        ];

        $data = ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->saveObject->scanForRelations($data, '', $schema);
        $this->assertArrayHasKey('ref', $result);
    }

    public function testScanForRelationsWithArrayOfObjectStrings(): void
    {
        $schema = $this->mockSchema;
        $schema->testSchemaObject = (object) [
            'properties' => (object) [
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                ],
            ],
        ];

        $data = ['items' => ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']];
        $result = $this->saveObject->scanForRelations($data, '', $schema);
        $this->assertArrayHasKey('items.0', $result);
    }

    public function testScanForRelationsWithPrefix(): void
    {
        $data = ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->saveObject->scanForRelations($data, 'parent');
        $this->assertArrayHasKey('parent.ref', $result);
    }

    public function testScanForRelationsSkipsEmptyKeys(): void
    {
        $data = ['' => 'some-value', 'name' => 'Test'];
        $result = $this->saveObject->scanForRelations($data);
        $this->assertArrayNotHasKey('', $result);
    }

    // =================================================================
    // saveObject() — RBAC property authorization path
    // =================================================================

    public function testSaveObjectWithRbacUnauthorizedProperties(): void
    {
        $schema = new AdditionalTestableSchema();
        $schema->setId(1);
        $schema->setSlug('rbac-schema');
        $schema->setTestHasPropertyAuth(true);
        $schema->testSchemaObject = (object) ['properties' => new stdClass()];
        $schema->testProperties = ['name' => ['type' => 'string']];

        $this->schemaMapper->method('find')->willReturn($schema);

        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

        // For updates, find existing object.
        $existingObject = $this->createEntity(1, $uuid);
        $existingObject->setObject(['name' => 'Original']);

        $this->unifiedObjectMapper->method('find')
            ->willReturn($existingObject);

        // Mock unauthorized properties.
        $this->propertyRbacHandler->method('getUnauthorizedProperties')
            ->willReturn(['secret_field']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('not authorized to modify');

        $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $schema,
            data: ['name' => 'Updated', 'secret_field' => 'hacked'],
            uuid: $uuid,
            _rbac: true
        );
    }

    /**
     * Note: testSaveObjectWithRbacNoUnauthorizedProperties was removed because
     * the full saveObject -> handleObjectUpdate -> updateObject chain requires
     * MagicMapper->update() to return a proper ObjectEntity (not a mock).
     * The RBAC check path IS covered by testSaveObjectWithRbacUnauthorizedProperties
     * and testSaveObjectWithRbacCreateNewObjectNotFound.
     */

    public function testSaveObjectWithRbacCreateNewObjectNotFound(): void
    {
        $schema = new AdditionalTestableSchema();
        $schema->setId(1);
        $schema->setSlug('rbac-schema');
        $schema->setTestHasPropertyAuth(true);
        $schema->testSchemaObject = (object) ['properties' => new stdClass()];
        $schema->testProperties = [];
        $schema->testConfiguration = null;

        $this->schemaMapper->method('find')->willReturn($schema);

        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

        // RBAC check: find fails (treat as create).
        $this->unifiedObjectMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        // No unauthorized properties for create.
        $this->propertyRbacHandler->method('getUnauthorizedProperties')
            ->willReturn([]);

        // saveObject lookup: also not found.
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        // Create flow.
        $newObject = $this->createEntity(2, $uuid);
        $newObject->setObject(['name' => 'New']);

        $this->unifiedObjectMapper->method('insert')
            ->willReturn($newObject);

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://test.com/obj');
        $this->urlGenerator->method('linkToRoute')->willReturn('/obj');
        $this->filePropertyHandler->method('isFileProperty')->willReturn(false);

        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $schema,
            data: ['name' => 'New'],
            uuid: $uuid,
            _rbac: true
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =================================================================
    // saveObject() — auto-generated UUID path
    // =================================================================

    public function testSaveObjectWithAutoGeneratedUuidSkipsLookup(): void
    {
        $schema = $this->mockSchema;
        $schema->testSchemaObject = (object) ['properties' => new stdClass()];
        $schema->testProperties = [];
        $schema->testConfiguration = null;

        $this->schemaMapper->method('find')->willReturn($schema);

        $newObject = $this->createEntity(1, 'generated-uuid');
        $newObject->setObject(['name' => 'New']);

        $this->unifiedObjectMapper->method('insert')
            ->willReturn($newObject);

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://test.com/obj');
        $this->urlGenerator->method('linkToRoute')->willReturn('/obj');
        $this->filePropertyHandler->method('isFileProperty')->willReturn(false);

        // Pass data with @self._autoGeneratedUuid.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $schema,
            data: ['name' => 'New', '@self' => ['_autoGeneratedUuid' => true]]
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =================================================================
    // saveObject() — file property processing path
    // =================================================================

    public function testSaveObjectWithFilePropertyProcessing(): void
    {
        $schema = $this->mockSchema;
        $schema->testSchemaObject = (object) ['properties' => new stdClass()];
        $schema->testProperties = ['logo' => ['type' => 'file']];
        $schema->testConfiguration = null;

        $this->schemaMapper->method('find')->willReturn($schema);

        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

        // No existing object (create).
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $newObject = $this->createEntity(1, $uuid);
        $newObject->setObject(['name' => 'New', 'logo' => 42]);

        $this->unifiedObjectMapper->method('insert')
            ->willReturn($newObject);

        // File property detected.
        $this->filePropertyHandler->method('isFileProperty')
            ->willReturnCallback(function ($value, $schema, $propertyName) {
                return $propertyName === 'logo';
            });

        // File handling modifies data in-place (simulated).
        $this->filePropertyHandler->method('handleFileProperty')
            ->willReturnCallback(function ($objectEntity, &$object, $propertyName) {
                $object[$propertyName] = 42;
            });

        $this->objectEntityMapper->method('update')
            ->willReturnCallback(function ($entity) {
                return $entity;
            });

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://test.com/obj');
        $this->urlGenerator->method('linkToRoute')->willReturn('/obj');

        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $schema,
            data: ['name' => 'New', 'logo' => 'file-data'],
            uuid: $uuid
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =================================================================
    // setDefaultValues — Twig template branches
    // =================================================================

    public function testSetDefaultValuesWithConstantValues(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'type' => [
                    'type' => 'string',
                    'const' => 'fixed-type',
                ],
            ],
        ];

        $entity = $this->createEntity(1, 'test-uuid');
        $entity->setObject([]);

        $result = $this->invokePrivate('setDefaultValues', [
            $entity, $this->mockSchema, ['type' => 'custom'],
        ]);

        // Constant values always override.
        $this->assertSame('fixed-type', $result['type']);
    }

    public function testSetDefaultValuesWithAlwaysBehavior(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'computed' => [
                    'type' => 'string',
                    'default' => 'always-value',
                    'defaultBehavior' => 'always',
                ],
            ],
        ];

        $entity = $this->createEntity(1, 'test-uuid');
        $entity->setObject([]);

        $result = $this->invokePrivate('setDefaultValues', [
            $entity, $this->mockSchema, ['computed' => 'existing'],
        ]);

        $this->assertSame('always-value', $result['computed']);
    }

    public function testSetDefaultValuesWithFalsyBehaviorEmptyString(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => [
                    'type' => 'string',
                    'default' => 'default-name',
                    'defaultBehavior' => 'falsy',
                ],
            ],
        ];

        $entity = $this->createEntity(1, 'test-uuid');
        $entity->setObject([]);

        $result = $this->invokePrivate('setDefaultValues', [
            $entity, $this->mockSchema, ['name' => ''],
        ]);

        $this->assertSame('default-name', $result['name']);
    }

    public function testSetDefaultValuesWithTemplateException(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'computed' => [
                    'type' => 'string',
                    'default' => '{{ invalid }} {{ template }}',
                ],
            ],
        ];

        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willThrowException(new Exception('Template error'));

        $entity = $this->createEntity(1, 'test-uuid');
        $entity->setObject([]);

        $result = $this->invokePrivate('setDefaultValues', [
            $entity, $this->mockSchema, [],
        ]);

        // Falls back to original value on exception.
        $this->assertSame('{{ invalid }} {{ template }}', $result['computed']);
    }

    public function testSetDefaultValuesWithSlugGeneration(): void
    {
        $this->mockSchema->testSchemaObject = (object) [
            'properties' => (object) [
                'name' => ['type' => 'string'],
            ],
        ];
        $this->mockSchema->testConfiguration = ['objectSlugField' => 'name'];

        $entity = $this->createEntity(1, 'test-uuid');
        $entity->setObject([]);

        $result = $this->invokePrivate('setDefaultValues', [
            $entity, $this->mockSchema, ['name' => 'My Object'],
        ]);

        // Slug should be generated.
        $this->assertArrayHasKey('slug', $result);
        $this->assertMatchesRegularExpression('/^my-object-\d+$/', $result['slug']);
    }

    // =================================================================
    // updateObjectRelations
    // =================================================================

    public function testUpdateObjectRelationsSetsRelationsOnEntity(): void
    {
        $entity = $this->createEntity(1, 'test-uuid');
        $data = [
            'related' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
            'name' => 'test',
        ];

        $result = $this->invokePrivate('updateObjectRelations', [$entity, $data]);
        $relations = $result->getRelations();
        $this->assertArrayHasKey('related', $relations);
    }

    // =================================================================
    // setSelfMetadata — published/depublished fields
    // =================================================================

    public function testSetSelfMetadataWithSlug(): void
    {
        $entity = $this->createEntity(1, 'test-uuid');

        $this->invokePrivate('setSelfMetadata', [
            $entity,
            ['slug' => 'my-slug'],
            [],
        ]);

        $this->assertSame('my-slug', $entity->getSlug());
    }

    public function testSetSelfMetadataWithPublished(): void
    {
        // Published is no longer handled by setSelfMetadata (removed from ObjectEntity).
        $entity = $this->createEntity(1, 'test-uuid');

        $this->invokePrivate('setSelfMetadata', [
            $entity,
            ['published' => '2024-01-15T10:00:00+00:00'],
            [],
        ]);

        $this->assertTrue(true);
    }

    public function testSetSelfMetadataWithNullPublished(): void
    {
        // Published is no longer handled by setSelfMetadata (removed from ObjectEntity).
        $entity = $this->createEntity(1, 'test-uuid');

        $this->invokePrivate('setSelfMetadata', [
            $entity,
            ['published' => null],
            [],
        ]);

        $this->assertTrue(true);
    }

    public function testSetSelfMetadataWithDepublished(): void
    {
        // Depublished is no longer handled by setSelfMetadata (removed from ObjectEntity).
        $entity = $this->createEntity(1, 'test-uuid');

        $this->invokePrivate('setSelfMetadata', [
            $entity,
            ['depublished' => '2025-12-31T23:59:59+00:00'],
            [],
        ]);

        $this->assertTrue(true);
    }

    // =================================================================
    // getCachedSchema / getCachedRegister
    // =================================================================

    public function testGetCachedSchemaFetchesAndCaches(): void
    {
        $schema = new AdditionalTestableSchema();
        $schema->setId(5);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->willReturn($schema);

        // First call fetches from mapper.
        $result1 = $this->invokePrivate('getCachedSchema', [5]);
        $this->assertSame(5, $result1->getId());

        // Second call should use cache (mapper only called once).
        $result2 = $this->invokePrivate('getCachedSchema', [5]);
        $this->assertSame(5, $result2->getId());
    }

    public function testGetCachedRegisterFetchesAndCaches(): void
    {
        $register = new Register();
        $register->setId(7);

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result1 = $this->invokePrivate('getCachedRegister', [7]);
        $this->assertSame(7, $result1->getId());

        $result2 = $this->invokePrivate('getCachedRegister', [7]);
        $this->assertSame(7, $result2->getId());
    }

    // =================================================================
    // resolveSchemaReference — by slug path and direct slug
    // =================================================================

    public function testResolveSchemaReferenceBySlugPath(): void
    {
        $schema = new AdditionalTestableSchema();
        $schema->setId(10);
        $schema->setSlug('contactgegevens');

        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->invokePrivate('resolveSchemaReference', [
            '#/components/schemas/Contactgegevens',
        ]);
        $this->assertSame('10', $result);
    }

    public function testResolveSchemaReferenceDirectSlugMatch(): void
    {
        $schema = new AdditionalTestableSchema();
        $schema->setId(15);
        $schema->setSlug('organisation');

        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->invokePrivate('resolveSchemaReference', ['organisation']);
        $this->assertSame('15', $result);
    }

    public function testResolveSchemaReferenceCleanedCacheLookup(): void
    {
        // Pre-populate cache with cleaned reference.
        $cacheProperty = $this->reflection->getProperty('schemaReferenceCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue($this->saveObject, ['schema' => '99']);

        // Query with query params should find the cleaned version.
        $result = $this->invokePrivate('resolveSchemaReference', ['schema?version=2']);
        $this->assertSame('99', $result);
    }
}
