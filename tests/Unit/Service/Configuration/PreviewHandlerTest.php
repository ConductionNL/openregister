<?php

declare(strict_types=1);

/**
 * PreviewHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\FetchHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PreviewHandler
 *
 * Tests configuration preview and comparison logic.
 */
class PreviewHandlerTest extends TestCase
{

    /** @var PreviewHandler */
    private PreviewHandler $handler;

    /** @var RegisterMapper&MockObject */
    private $registerMapper;

    /** @var SchemaMapper&MockObject */
    private $schemaMapper;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var FetchHandler&MockObject */
    private $fetchHandler;


    protected function setUp(): void
    {
        parent::setUp();

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->fetchHandler   = $this->createMock(FetchHandler::class);

        $this->handler = new PreviewHandler(
            $this->registerMapper,
            $this->schemaMapper,
            $this->logger,
            $this->fetchHandler
        );
    }


    /**
     * Create a Configuration entity with given properties.
     *
     * @param int         $id           The configuration ID.
     * @param string      $title        The title.
     * @param string      $sourceUrl    The source URL.
     * @param string|null $localVersion The local version.
     *
     * @return Configuration
     */
    private function makeConfiguration(
        int $id = 1,
        string $title = 'Config',
        string $sourceUrl = 'https://example.com',
        ?string $localVersion = '1.0.0'
    ): Configuration {
        $config = new Configuration();
        $config->setId($id);
        $config->setTitle($title);
        $config->setSourceUrl($sourceUrl);
        $config->setLocalVersion($localVersion);
        return $config;
    }


    /**
     * Create a Register entity with given slug and version.
     *
     * @param int         $id      The register ID.
     * @param string|null $slug    The slug.
     * @param string|null $version The version.
     * @param string|null $title   The title.
     *
     * @return Register
     */
    private function makeRegister(
        int $id = 1,
        ?string $slug = 'test',
        ?string $version = '1.0.0',
        ?string $title = 'Test Register'
    ): Register {
        $register = new Register();
        $register->setId($id);
        $register->setSlug($slug);
        $register->setVersion($version);
        $register->setTitle($title);
        return $register;
    }


    /**
     * Create a Schema entity with given slug and version.
     *
     * @param int         $id      The schema ID.
     * @param string|null $slug    The slug.
     * @param string|null $version The version.
     * @param string|null $title   The title.
     *
     * @return Schema
     */
    private function makeSchema(
        int $id = 1,
        ?string $slug = 'test',
        ?string $version = '1.0.0',
        ?string $title = 'Test Schema'
    ): Schema {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setVersion($version);
        $schema->setTitle($title);
        return $schema;
    }


    // ──────────────────────────────────────────────────────────────
    // previewRegisterChange — create action
    // ──────────────────────────────────────────────────────────────

    /**
     * Test previewRegisterChange for a new register (create action).
     */
    public function testPreviewRegisterChangeCreateWhenNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $registerData = [
            'title'   => 'New Register',
            'version' => '1.0.0',
        ];
        $result = $this->handler->previewRegisterChange('new-register', $registerData);

        $this->assertSame('register', $result['type']);
        $this->assertSame('create', $result['action']);
        $this->assertSame('new-register', $result['slug']);
        $this->assertSame('New Register', $result['title']);
        $this->assertNull($result['current']);
        $this->assertSame($registerData, $result['proposed']);
        $this->assertSame([], $result['changes']);
    }


    /**
     * Test previewRegisterChange slug is lowercased.
     */
    public function testPreviewRegisterChangeSlugLowercased(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->previewRegisterChange('My-Register', ['title' => 'Test']);

        $this->assertSame('my-register', $result['slug']);
    }


    /**
     * Test previewRegisterChange uses slug as title fallback when title not provided.
     */
    public function testPreviewRegisterChangeTitleFallbackToSlug(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->previewRegisterChange('my-reg', []);

        $this->assertSame('my-reg', $result['title']);
    }


    /**
     * Test previewRegisterChange proposed data is preserved in result.
     */
    public function testPreviewRegisterChangeProposedDataPreserved(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $registerData = [
            'title'       => 'My Register',
            'version'     => '3.2.1',
            'description' => 'A test register',
            'customField' => 'customValue',
        ];

        $result = $this->handler->previewRegisterChange('test', $registerData);

        $this->assertSame($registerData, $result['proposed']);
    }


    /**
     * Test find is called with lowercased slug.
     */
    public function testPreviewRegisterChangeFindCalledWithLowercasedSlug(): void
    {
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with('upper-case')
            ->willThrowException(new Exception('Not found'));

        $this->handler->previewRegisterChange('UPPER-CASE', ['title' => 'Test']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewRegisterChange — update action (newer version)
    // ──────────────────────────────────────────────────────────────

    /**
     * Test previewRegisterChange for an existing register with newer remote version.
     */
    public function testPreviewRegisterChangeUpdateWithNewerVersion(): void
    {
        $existing = $this->makeRegister(1, 'my-register', '1.0.0', 'Old Title');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $registerData = [
            'title'   => 'Updated Title',
            'version' => '2.0.0',
        ];
        $result = $this->handler->previewRegisterChange('my-register', $registerData);

        $this->assertSame('update', $result['action']);
        $this->assertNotNull($result['current']);
        $this->assertSame('Updated Title', $result['title']);
        $this->assertIsArray($result['changes']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewRegisterChange — skip action (same or older version)
    // ──────────────────────────────────────────────────────────────

    /**
     * Test previewRegisterChange skips when remote version equals current.
     */
    public function testPreviewRegisterChangeSkipSameVersion(): void
    {
        $existing = $this->makeRegister(1, 'my-register', '1.0.0', 'Title');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $result = $this->handler->previewRegisterChange('my-register', [
            'title'   => 'Title',
            'version' => '1.0.0',
        ]);

        $this->assertSame('skip', $result['action']);
        $this->assertArrayHasKey('reason', $result);
        $this->assertStringContainsString('1.0.0', $result['reason']);
        $this->assertSame([], $result['changes']);
    }


    /**
     * Test previewRegisterChange skips when remote version is older.
     */
    public function testPreviewRegisterChangeSkipOlderVersion(): void
    {
        $existing = $this->makeRegister(1, 'my-register', '2.0.0', 'Title');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $result = $this->handler->previewRegisterChange('my-register', [
            'title'   => 'Title',
            'version' => '1.0.0',
        ]);

        $this->assertSame('skip', $result['action']);
        $this->assertArrayHasKey('reason', $result);
        $this->assertStringContainsString('not newer', $result['reason']);
    }


    /**
     * Test previewRegisterChange with null version on existing register defaults to 0.0.0.
     */
    public function testPreviewRegisterChangeNullVersionDefaults(): void
    {
        $existing = $this->makeRegister(1, 'my-register', null, 'Title');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        // No version in registerData => defaults to 0.0.0, same as current => skip.
        $result = $this->handler->previewRegisterChange('my-register', [
            'title' => 'Title',
        ]);

        $this->assertSame('skip', $result['action']);
    }


    /**
     * Test previewRegisterChange with null current version but newer proposed.
     */
    public function testPreviewRegisterChangeNullCurrentVersionNewerProposed(): void
    {
        $existing = $this->makeRegister(1, 'my-register', null, 'Title');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $result = $this->handler->previewRegisterChange('my-register', [
            'title'   => 'Title',
            'version' => '1.0.0',
        ]);

        // 1.0.0 > 0.0.0 => update.
        $this->assertSame('update', $result['action']);
    }


    /**
     * Test previewRegisterChange current data is set from existing register.
     */
    public function testPreviewRegisterChangeCurrentDataFromExisting(): void
    {
        $existing = $this->makeRegister(1, 'my-register', '1.0.0', 'Current Title');

        $this->registerMapper->method('find')
            ->willReturn($existing);

        $result = $this->handler->previewRegisterChange('my-register', [
            'title'   => 'New Title',
            'version' => '2.0.0',
        ]);

        $this->assertIsArray($result['current']);
        $this->assertSame('Current Title', $result['current']['title']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — returns JSONResponse on error
    // ──────────────────────────────────────────────────────────────

    /**
     * Test previewConfigurationChanges returns JSONResponse when fetch fails.
     */
    public function testPreviewConfigurationChangesReturnsJsonResponseOnFetchError(): void
    {
        $configuration = $this->makeConfiguration();
        $errorResponse = new JSONResponse(['error' => 'Failed to fetch'], 500);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn($errorResponse);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertInstanceOf(JSONResponse::class, $result);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — empty / no components
    // ──────────────────────────────────────────────────────────────

    /**
     * Test previewConfigurationChanges with empty remote data.
     */
    public function testPreviewConfigurationChangesEmptyComponents(): void
    {
        $configuration = $this->makeConfiguration(1, 'Test Config', 'https://example.com/config.json', '1.0.0');

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [],
                'version'    => '2.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertIsArray($result);
        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
        $this->assertSame([], $result['objects']);
        $this->assertSame([], $result['endpoints']);
        $this->assertSame([], $result['sources']);
        $this->assertSame([], $result['mappings']);
        $this->assertSame([], $result['jobs']);
        $this->assertSame([], $result['synchronizations']);
        $this->assertSame([], $result['rules']);
        $this->assertArrayHasKey('metadata', $result);
    }


    /**
     * Test previewConfigurationChanges with no components key at all.
     */
    public function testPreviewConfigurationChangesNoComponentsKey(): void
    {
        $configuration = $this->makeConfiguration();

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertIsArray($result);
        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
        $this->assertSame([], $result['objects']);
        $this->assertSame(0, $result['metadata']['totalChanges']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — metadata
    // ──────────────────────────────────────────────────────────────

    /**
     * Test metadata is populated correctly.
     */
    public function testPreviewConfigurationChangesMetadata(): void
    {
        $configuration = $this->makeConfiguration(42, 'My Config', 'https://example.com/api', '1.2.3');

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [],
                'version'    => '3.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $metadata = $result['metadata'];
        $this->assertSame(42, $metadata['configurationId']);
        $this->assertSame('My Config', $metadata['configurationTitle']);
        $this->assertSame('https://example.com/api', $metadata['sourceUrl']);
        $this->assertSame('3.0.0', $metadata['remoteVersion']);
        $this->assertSame('1.2.3', $metadata['localVersion']);
        $this->assertArrayHasKey('previewedAt', $metadata);
        $this->assertSame(0, $metadata['totalChanges']);
    }


    /**
     * Test metadata uses info.version fallback.
     */
    public function testPreviewConfigurationChangesMetadataInfoVersionFallback(): void
    {
        $configuration = $this->makeConfiguration();

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [],
                'info'       => ['version' => '4.5.6'],
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame('4.5.6', $result['metadata']['remoteVersion']);
    }


    /**
     * Test metadata remote version is null when neither version key exists.
     */
    public function testPreviewConfigurationChangesMetadataNoVersion(): void
    {
        $configuration = $this->makeConfiguration(1, 'Config', 'https://example.com', null);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [],
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertNull($result['metadata']['remoteVersion']);
    }


    /**
     * Test previewedAt is ISO 8601 format.
     */
    public function testPreviewConfigurationChangesPreviewedAtFormat(): void
    {
        $configuration = $this->makeConfiguration();

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [],
                'version'    => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $result['metadata']['previewedAt']);
        $this->assertNotFalse($parsed, 'previewedAt should be a valid ISO 8601 date');
    }


    /**
     * Test version key takes precedence over info.version.
     */
    public function testPreviewConfigurationChangesVersionPrecedence(): void
    {
        $configuration = $this->makeConfiguration();

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [],
                'version'    => '1.0.0',
                'info'       => ['version' => '9.9.9'],
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame('1.0.0', $result['metadata']['remoteVersion']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — with registers
    // ──────────────────────────────────────────────────────────────

    /**
     * Test processes registers correctly.
     */
    public function testPreviewConfigurationChangesWithRegisters(): void
    {
        $configuration = $this->makeConfiguration();

        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'registers' => [
                        'reg-one' => ['title' => 'Register One', 'version' => '1.0.0'],
                        'reg-two' => ['title' => 'Register Two', 'version' => '2.0.0'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertCount(2, $result['registers']);
        $this->assertSame('create', $result['registers'][0]['action']);
        $this->assertSame('reg-one', $result['registers'][0]['slug']);
        $this->assertSame('create', $result['registers'][1]['action']);
        $this->assertSame('reg-two', $result['registers'][1]['slug']);
        $this->assertSame(2, $result['metadata']['totalChanges']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — with schemas
    // ──────────────────────────────────────────────────────────────

    /**
     * Test processes schemas - create action.
     */
    public function testPreviewConfigurationChangesWithSchemas(): void
    {
        $configuration = $this->makeConfiguration();

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'schemas' => [
                        'schema-one' => ['title' => 'Schema One', 'version' => '1.0.0'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertCount(1, $result['schemas']);
        $this->assertSame('schema', $result['schemas'][0]['type']);
        $this->assertSame('create', $result['schemas'][0]['action']);
        $this->assertSame('schema-one', $result['schemas'][0]['slug']);
    }


    /**
     * Test schema update with newer version.
     */
    public function testPreviewConfigurationChangesSchemaUpdate(): void
    {
        $configuration = $this->makeConfiguration();

        $existingSchema = $this->makeSchema(1, 'my-schema', '1.0.0', 'Old Schema');

        $this->schemaMapper->method('find')
            ->willReturn($existingSchema);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'schemas' => [
                        'my-schema' => ['title' => 'Updated Schema', 'version' => '2.0.0'],
                    ],
                ],
                'version' => '2.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertCount(1, $result['schemas']);
        $this->assertSame('update', $result['schemas'][0]['action']);
        $this->assertNotNull($result['schemas'][0]['current']);
    }


    /**
     * Test schema skip with same version.
     */
    public function testPreviewConfigurationChangesSchemaSkip(): void
    {
        $configuration = $this->makeConfiguration();

        $existingSchema = $this->makeSchema(1, 'my-schema', '2.0.0', 'Schema');

        $this->schemaMapper->method('find')
            ->willReturn($existingSchema);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'schemas' => [
                        'my-schema' => ['title' => 'Schema', 'version' => '1.0.0'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame('skip', $result['schemas'][0]['action']);
        $this->assertArrayHasKey('reason', $result['schemas'][0]);
    }


    /**
     * Test schema with null version defaults (both become 0.0.0 => skip).
     */
    public function testPreviewConfigurationChangesSchemaNoVersion(): void
    {
        $configuration = $this->makeConfiguration();

        $existingSchema = $this->makeSchema(1, 'my-schema', null, 'Schema');

        $this->schemaMapper->method('find')
            ->willReturn($existingSchema);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'schemas' => [
                        'my-schema' => ['title' => 'Schema'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame('skip', $result['schemas'][0]['action']);
    }


    /**
     * Test schema title fallback to slug when no title in data.
     */
    public function testPreviewConfigurationChangesSchemaTitleFallback(): void
    {
        $configuration = $this->makeConfiguration();

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'schemas' => [
                        'my-schema' => ['version' => '1.0.0'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame('my-schema', $result['schemas'][0]['title']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — with objects
    // ──────────────────────────────────────────────────────────────

    /**
     * Test processes objects and builds slug-to-id maps.
     */
    public function testPreviewConfigurationChangesWithObjects(): void
    {
        $configuration = $this->makeConfiguration();

        $register = $this->makeRegister(10, 'my-register', '1.0.0', 'Reg');
        $schema   = $this->makeSchema(20, 'my-schema', '1.0.0', 'Sch');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'objects' => [
                        ['uuid' => 'obj-1', 'register' => 'my-register', 'schema' => 'my-schema'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        // previewObjectChange is a placeholder returning [].
        $this->assertCount(1, $result['objects']);
        $this->assertSame(1, $result['metadata']['totalChanges']);
    }


    /**
     * Test handles null slug from register in slug map.
     */
    public function testPreviewConfigurationChangesObjectsNullSlugRegister(): void
    {
        $configuration = $this->makeConfiguration();

        $register = $this->makeRegister(10, null, '1.0.0', 'Reg');
        $schema   = $this->makeSchema(20, null, '1.0.0', 'Sch');

        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'objects' => [
                        ['uuid' => 'obj-1'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertCount(1, $result['objects']);
    }


    /**
     * Test with multiple objects.
     */
    public function testPreviewConfigurationChangesMultipleObjects(): void
    {
        $configuration = $this->makeConfiguration();

        $this->registerMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'objects' => [
                        ['uuid' => 'obj-1'],
                        ['uuid' => 'obj-2'],
                        ['uuid' => 'obj-3'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertCount(3, $result['objects']);
        $this->assertSame(3, $result['metadata']['totalChanges']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — null/non-array component sections
    // ──────────────────────────────────────────────────────────────

    /**
     * Test null component sections are handled gracefully.
     */
    public function testPreviewConfigurationChangesNullSections(): void
    {
        $configuration = $this->makeConfiguration();

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'registers' => null,
                    'schemas'   => null,
                    'objects'   => null,
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
        $this->assertSame([], $result['objects']);
    }


    /**
     * Test non-array component sections are handled gracefully.
     */
    public function testPreviewConfigurationChangesNonArraySections(): void
    {
        $configuration = $this->makeConfiguration();

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'registers' => 'not-an-array',
                    'schemas'   => 42,
                    'objects'   => false,
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame([], $result['registers']);
        $this->assertSame([], $result['schemas']);
        $this->assertSame([], $result['objects']);
    }


    // ──────────────────────────────────────────────────────────────
    // previewConfigurationChanges — totalChanges
    // ──────────────────────────────────────────────────────────────

    /**
     * Test totalChanges counts registers + schemas + objects.
     */
    public function testPreviewConfigurationChangesTotalChanges(): void
    {
        $configuration = $this->makeConfiguration();

        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->registerMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->fetchHandler->method('fetchRemoteConfiguration')
            ->willReturn([
                'components' => [
                    'registers' => [
                        'reg-1' => ['title' => 'R1'],
                    ],
                    'schemas' => [
                        'sch-1' => ['title' => 'S1'],
                        'sch-2' => ['title' => 'S2'],
                    ],
                    'objects' => [
                        ['uuid' => 'o1'],
                    ],
                ],
                'version' => '1.0.0',
            ]);

        $result = $this->handler->previewConfigurationChanges($configuration);

        $this->assertSame(4, $result['metadata']['totalChanges']);
    }


    // ──────────────────────────────────────────────────────────────
    // compareArrays — placeholder
    // ──────────────────────────────────────────────────────────────

    /**
     * Test compareArrays returns empty array (placeholder).
     */
    public function testCompareArraysReturnsEmpty(): void
    {
        $result = $this->handler->compareArrays(
            ['key' => 'old'],
            ['key' => 'new']
        );

        $this->assertSame([], $result);
    }


    /**
     * Test compareArrays with prefix parameter returns empty (placeholder).
     */
    public function testCompareArraysWithPrefixReturnsEmpty(): void
    {
        $result = $this->handler->compareArrays(
            ['key' => 'old'],
            ['key' => 'new'],
            'prefix.'
        );

        $this->assertSame([], $result);
    }


    /**
     * Test compareArrays with empty arrays returns empty (placeholder).
     */
    public function testCompareArraysEmptyInputs(): void
    {
        $result = $this->handler->compareArrays([], []);

        $this->assertSame([], $result);
    }


    // ──────────────────────────────────────────────────────────────
    // importConfigurationWithSelection — placeholder
    // ──────────────────────────────────────────────────────────────

    /**
     * Test importConfigurationWithSelection returns empty array (placeholder).
     */
    public function testImportConfigurationWithSelectionReturnsEmpty(): void
    {
        $config = $this->makeConfiguration();

        $result = $this->handler->importConfigurationWithSelection($config, []);

        $this->assertSame([], $result);
    }


    /**
     * Test importConfigurationWithSelection with non-empty selection returns empty.
     */
    public function testImportConfigurationWithSelectionNonEmptySelection(): void
    {
        $config = $this->makeConfiguration();

        $result = $this->handler->importConfigurationWithSelection($config, [
            'registers' => ['reg-1'],
            'schemas'   => ['sch-1'],
        ]);

        $this->assertSame([], $result);
    }
}
