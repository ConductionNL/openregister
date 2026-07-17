<?php

/**
 * SaveObject field-level-object-encryption integration tests.
 *
 * Exercises the real `prepareObjectData()` private method (via reflection,
 * mirroring the established pattern in SaveObjectRefactoredMethodsTest) to
 * prove that a property flagged `x-openregister-encrypted: true` is enveloped
 * before persistence — the encrypt-on-save half of the feature's round trip.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/field-level-object-encryption/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\FieldEncryptionHandler;
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
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Twig\Loader\ArrayLoader;
use stdClass;

/**
 * Testable Schema subclass exposing a controllable getSchemaObject().
 *
 * Properties are set via the real `setProperties()` so that both `getProperties()`
 * and `hasEncryptedProperties()` (which reads the underlying `$properties` field
 * directly) see the same data.
 */
class EncryptionTestableSchema extends Schema
{
    public ?stdClass $testSchemaObject = null;

    public function getSchemaObject(\OCP\IURLGenerator $urlGenerator): stdClass
    {
        return $this->testSchemaObject ?? new stdClass();
    }//end getSchemaObject()

    public function getConfiguration(): ?array
    {
        return null;
    }//end getConfiguration()

    public function hasPropertyAuthorization(): bool
    {
        return false;
    }//end hasPropertyAuthorization()
}

class SaveObjectFieldEncryptionTest extends TestCase
{
    private SaveObject $saveObject;

    private ReflectionClass $reflection;

    /** @var ComputedFieldHandler&MockObject */
    private $computedFieldHandler;

    private EncryptionTestableSchema $schema;

    /** @var ICrypto&MockObject */
    private $crypto;

    protected function setUp(): void
    {
        parent::setUp();

        $objectEntityMapper  = $this->createMock(MagicMapper::class);
        $unifiedObjectMapper = $this->createMock(MagicMapper::class);
        $metaHydrationHandler = $this->createMock(MetadataHydrationHandler::class);
        $filePropertyHandler = $this->createMock(FilePropertyHandler::class);
        $userSession         = $this->createMock(\OCP\IUserSession::class);
        $auditTrailMapper    = $this->createMock(AuditTrailMapper::class);
        $schemaMapper        = $this->createMock(SchemaMapper::class);
        $registerMapper      = $this->createMock(RegisterMapper::class);
        $urlGenerator        = $this->createMock(\OCP\IURLGenerator::class);
        $organisationService = $this->createMock(OrganisationService::class);
        $cacheHandler        = $this->createMock(CacheHandler::class);
        $settingsService     = $this->createMock(SettingsService::class);
        $propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);
        $logger              = $this->createMock(LoggerInterface::class);
        $this->computedFieldHandler = $this->createMock(ComputedFieldHandler::class);
        $this->computedFieldHandler->method('hasComputedProperties')->willReturn(false);

        $arrayLoader = new ArrayLoader([]);

        $this->schema = new EncryptionTestableSchema();
        $this->schema->setId(1);
        $this->schema->setSlug('test-schema');
        $this->schema->testSchemaObject = (object) ['properties' => []];

        $this->crypto = $this->createMock(ICrypto::class);
        $this->crypto->method('encrypt')->willReturnCallback(
            fn (string $plain): string => 'CIPHER('.$plain.')'
        );
        $fieldEncryptionHandler = new FieldEncryptionHandler($this->crypto, $logger);

        $this->saveObject = new SaveObject(
            objectEntityMapper: $objectEntityMapper,
            unifiedObjectMapper: $unifiedObjectMapper,
            metaHydrationHandler: $metaHydrationHandler,
            filePropertyHandler: $filePropertyHandler,
            linkedEntityHandler: $this->createMock(LinkedEntityPropertyHandler::class),
            userSession: $userSession,
            auditTrailMapper: $auditTrailMapper,
            schemaMapper: $schemaMapper,
            registerMapper: $registerMapper,
            urlGenerator: $urlGenerator,
            organisationService: $organisationService,
            cacheHandler: $cacheHandler,
            settingsService: $settingsService,
            propertyRbacHandler: $propertyRbacHandler,
            computedFieldHandler: $this->computedFieldHandler,
            translationHandler: $this->createMock(TranslationHandler::class),
            translationProjectionService: $this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
            translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
            logger: $logger,
            tmloService: $this->createMock(TmloService::class),
            folderManagementHandler: $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
            arrayLoader: $arrayLoader,
            fieldEncryptionHandler: $fieldEncryptionHandler,
        );

        $this->reflection = new ReflectionClass(SaveObject::class);
    }

    private function invokePrepareObjectData(Schema $schema, array $data): array
    {
        $entity = new ObjectEntity();
        $entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $method = $this->reflection->getMethod('prepareObjectData');
        $method->setAccessible(true);

        return $method->invokeArgs($this->saveObject, [$entity, $schema, $data]);
    }

    public function testFlaggedPropertyIsEncryptedBeforePersistence(): void
    {
        $this->schema->setProperties([
            'name' => ['type' => 'string'],
            'bsn'  => ['type' => 'string', 'x-openregister-encrypted' => true],
        ]);

        $result = $this->invokePrepareObjectData($this->schema, [
            'name' => 'Jan Jansen',
            'bsn'  => '123456789',
        ]);

        $this->assertSame('Jan Jansen', $result['name'], 'Unflagged field must remain plaintext');
        $this->assertStringStartsWith(
            FieldEncryptionHandler::ENVELOPE_PREFIX,
            $result['bsn'],
            'Flagged field must be persisted as an encryption envelope, never plaintext'
        );
        // Note: the ICrypto test double is an identity-style fake (CIPHER(x)) so we
        // cannot assert ciphertext excludes the plaintext here — that property belongs
        // to the real ICrypto and is out of scope for a unit test with a mocked crypto
        // boundary. The envelope-prefix assertion above is what proves encrypt-on-save
        // ran through the real handler wiring.
        $this->assertNotSame('123456789', $result['bsn'], 'Value must have passed through the encryption envelope');
    }

    public function testUnflaggedSchemaLeavesDataUntouched(): void
    {
        $this->schema->setProperties(['name' => ['type' => 'string']]);

        $result = $this->invokePrepareObjectData($this->schema, ['name' => 'unchanged']);

        $this->assertSame('unchanged', $result['name']);
    }

    public function testResavingAnAlreadyEncryptedValueDoesNotDoubleEncrypt(): void
    {
        $this->schema->setProperties([
            'bsn' => ['type' => 'string', 'x-openregister-encrypted' => true],
        ]);

        $first  = $this->invokePrepareObjectData($this->schema, ['bsn' => '123456789']);
        $second = $this->invokePrepareObjectData($this->schema, ['bsn' => $first['bsn']]);

        $this->assertSame($first['bsn'], $second['bsn'], 'Re-saving an envelope must be idempotent, not double-wrap it');
    }
}
