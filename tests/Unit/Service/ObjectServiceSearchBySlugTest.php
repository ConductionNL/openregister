<?php

/**
 * ObjectService searchObjectsBySlug Unit Tests
 *
 * Covers the runtime-schema-api spec requirement:
 * "ObjectService.searchObjectsBySlug resolves slugs at the slug-aware layer"
 *
 * Tests in this file are SPEC-SPECIFIC for change `openregister-runtime-schema-api`
 * and do NOT overlap with the broader ObjectService test suite.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ObjectService::searchObjectsBySlug — spec REQ
 * "ObjectService.searchObjectsBySlug resolves slugs at the slug-aware layer".
 */
class ObjectServiceSearchBySlugTest extends TestCase
{

    /** @var QueryHandler&MockObject */
    private QueryHandler $queryHandler;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    private ObjectService $service;


    /**
     * Build an ObjectService with every dependency mocked except the ones
     * the test directly exercises (RegisterMapper, SchemaMapper, QueryHandler).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->queryHandler   = $this->createMock(QueryHandler::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);

        $this->service = new ObjectService(
            dataManipHandler:    $this->createMock(DataManipulationHandler::class),
            deleteHandler:       $this->createMock(DeleteObject::class),
            getHandler:          $this->createMock(GetObject::class),
            performanceHandler:  $this->createMock(PerformanceHandler::class),
            permissionHandler:   $this->createMock(PermissionHandler::class),
            renderHandler:       $this->createMock(RenderObject::class),
            saveHandler:         $this->createMock(SaveObject::class),
            saveObjectsHandler:  $this->createMock(SaveObjects::class),
            searchQueryHandler:  $this->createMock(SearchQueryHandler::class),
            validateHandler:     $this->createMock(ValidateObject::class),
            lockHandler:         $this->createMock(LockHandler::class),
            auditHandler:        $this->createMock(AuditHandler::class),
            relationHandler:     $this->createMock(RelationHandler::class),
            mergeHandler:        $this->createMock(MergeHandler::class),
            facetHandler:        $this->createMock(FacetHandler::class),
            metadataHandler:     $this->createMock(MetadataHandler::class),
            perfOptHandler:      $this->createMock(PerformanceOptimizationHandler::class),
            queryHandler:        $this->queryHandler,
            revertHandler:       $this->createMock(RevertHandler::class),
            utilityHandler:      $this->createMock(UtilityHandler::class),
            validationHandler:   $this->createMock(ValidationHandler::class),
            cascadingHandler:    $this->createMock(CascadingHandler::class),
            migrationHandler:    $this->createMock(MigrationHandler::class),
            registerMapper:      $this->registerMapper,
            schemaMapper:        $this->schemaMapper,
            viewMapper:          $this->createMock(ViewMapper::class),
            objectMapper:        $this->createMock(MagicMapper::class),
            fileService:         $this->createMock(FileService::class),
            userSession:         $this->createMock(IUserSession::class),
            searchTrailService:  $this->createMock(SearchTrailService::class),
            groupManager:        $this->createMock(IGroupManager::class),
            userManager:         $this->createMock(IUserManager::class),
            organisationService: $this->createMock(OrganisationService::class),
            logger:              $this->createMock(LoggerInterface::class),
            cacheHandler:        $this->createMock(CacheHandler::class),
            settingsService:     $this->createMock(SettingsService::class),
            dateTimeNormalizer:  $this->createMock(DateTimeNormalizer::class),
            container:           $this->createMock(IAppContainer::class)
        );

    }//end setUp()


    /**
     * REQ: searchObjectsBySlug resolves both slugs and delegates to searchObjects
     * with numeric @self.register / @self.schema.
     *
     * Scenario: "Search by slug-pair" — the query handed to the QueryHandler
     * MUST contain the numeric IDs derived from the mappers, with extra
     * filter keys preserved at the top level.
     */
    public function testSearchObjectsBySlugResolvesAndDelegates(): void
    {
        $register = new Register();
        $register->setId(7);
        $register->setSlug('openbuilt');

        $schema = new Schema();
        $schema->setId(42);
        $schema->setSlug('application');

        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo('openbuilt'))
            ->willReturn($register);

        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo('application'))
            ->willReturn($schema);

        $this->queryHandler
            ->expects($this->once())
            ->method('searchObjects')
            ->with(
                $this->callback(function (array $query): bool {
                    // Numeric IDs MUST land in @self, not slugs.
                    return ($query['@self']['register'] ?? null) === 7
                        && ($query['@self']['schema'] ?? null) === 42
                        && ($query['status'] ?? null) === 'published';
                })
            )
            ->willReturn([]);

        $result = $this->service->searchObjectsBySlug(
            'openbuilt',
            'application',
            ['status' => 'published']
        );

        $this->assertSame([], $result);

    }//end testSearchObjectsBySlugResolvesAndDelegates()


    /**
     * REQ: Unknown register slug throws DoesNotExistException with a message
     * that identifies which slug failed.
     */
    public function testSearchObjectsBySlugThrowsWhenRegisterSlugUnknown(): void
    {
        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo('ghost-register'))
            ->willThrowException(new DoesNotExistException('not found'));

        // The schema mapper must NOT be hit when the register lookup already failed.
        $this->schemaMapper->expects($this->never())->method('find');

        // The query handler MUST NOT be hit either.
        $this->queryHandler->expects($this->never())->method('searchObjects');

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessageMatches(
            '/searchObjectsBySlug: register slug not found.*ghost-register/'
        );

        $this->service->searchObjectsBySlug(
            'ghost-register',
            'application',
            []
        );

    }//end testSearchObjectsBySlugThrowsWhenRegisterSlugUnknown()


    /**
     * REQ: Unknown schema slug (with valid register) throws DoesNotExistException
     * identifying the schema slug — proves the exception chain rewraps the
     * underlying mapper exception so the caller can distinguish register-side
     * vs schema-side resolution failures.
     */
    public function testSearchObjectsBySlugThrowsWhenSchemaSlugUnknown(): void
    {
        $register = new Register();
        $register->setId(7);
        $register->setSlug('openbuilt');

        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with($this->equalTo('ghost-schema'))
            ->willThrowException(new DoesNotExistException('not found'));

        $this->queryHandler->expects($this->never())->method('searchObjects');

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessageMatches(
            '/searchObjectsBySlug: schema slug not found.*ghost-schema/'
        );

        $this->service->searchObjectsBySlug(
            'openbuilt',
            'ghost-schema',
            []
        );

    }//end testSearchObjectsBySlugThrowsWhenSchemaSlugUnknown()


}//end class
