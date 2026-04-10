<?php

declare(strict_types=1);

/**
 * RetentionService Unit Tests
 *
 * Tests archival metadata application, archiefactiedatum calculation,
 * selectielijst lookup, legal hold management, and immutability validation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for RetentionService
 */
class RetentionServiceTest extends TestCase
{

    private MagicMapper&MockObject $objectMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private AuditTrailMapper&MockObject $auditMapper;
    private ObjectRetentionHandler&MockObject $settingsHandler;
    private IAppConfig&MockObject $appConfig;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private RetentionService $service;


    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper    = $this->createMock(MagicMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->registerMapper  = $this->createMock(RegisterMapper::class);
        $this->auditMapper     = $this->createMock(AuditTrailMapper::class);
        $this->settingsHandler = $this->createMock(ObjectRetentionHandler::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new RetentionService(
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->auditMapper,
            $this->settingsHandler,
            $this->appConfig,
            $this->userSession,
            $this->logger,
        );
    }//end setUp()


    /**
     * Test that archival metadata is applied when schema has archive enabled.
     */
    public function testApplyArchivalMetadataWithEnabledSchema(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([]);

        $schema = $this->createMock(Schema::class);
        $schema->method('getArchive')->willReturn([
            'enabled'             => true,
            'defaultNominatie'    => 'vernietigen',
            'defaultBewaartermijn' => 'P5Y',
        ]);

        $this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
            'selectielijstRegister' => null,
            'selectielijstSchema'   => null,
        ]);

        $result = $this->service->applyArchivalMetadata($object, $schema);
        $retention = $result->getRetention();

        $this->assertEquals('vernietigen', $retention['archiefnominatie']);
        $this->assertEquals('nog_te_archiveren', $retention['archiefstatus']);
        $this->assertEquals('P5Y', $retention['bewaartermijn']);
        $this->assertNotNull($retention['archiefactiedatum']);
    }//end testApplyArchivalMetadataWithEnabledSchema()


    /**
     * Test that archival metadata is NOT applied when schema has no archive config.
     */
    public function testApplyArchivalMetadataSkipsWhenDisabled(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([]);

        $schema = $this->createMock(Schema::class);
        $schema->method('getArchive')->willReturn([]);

        $result    = $this->service->applyArchivalMetadata($object, $schema);
        $retention = $result->getRetention();

        $this->assertArrayNotHasKey('archiefnominatie', $retention);
    }//end testApplyArchivalMetadataSkipsWhenDisabled()


    /**
     * Test that destroyed objects are flagged as immutable.
     */
    public function testValidateNotImmutableReturnsDestroyedCode(): void
    {
        $object = new ObjectEntity();
        $object->setRetention(['archiefstatus' => 'vernietigd']);

        $result = $this->service->validateNotImmutable($object);

        $this->assertEquals('OBJECT_DESTROYED', $result);
    }//end testValidateNotImmutableReturnsDestroyedCode()


    /**
     * Test that transferred objects are flagged as immutable.
     */
    public function testValidateNotImmutableReturnsTransferredCode(): void
    {
        $object = new ObjectEntity();
        $object->setRetention(['archiefstatus' => 'overgebracht']);

        $result = $this->service->validateNotImmutable($object);

        $this->assertEquals('OBJECT_TRANSFERRED', $result);
    }//end testValidateNotImmutableReturnsTransferredCode()


    /**
     * Test that mutable objects return null.
     */
    public function testValidateNotImmutableReturnsNullForMutable(): void
    {
        $object = new ObjectEntity();
        $object->setRetention(['archiefstatus' => 'nog_te_archiveren']);

        $result = $this->service->validateNotImmutable($object);

        $this->assertNull($result);
    }//end testValidateNotImmutableReturnsNullForMutable()


    /**
     * Test placing a legal hold.
     */
    public function testPlaceLegalHold(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $result    = $this->service->placeLegalHold($object, 'WOO-verzoek 2025-0142');
        $retention = $result->getRetention();

        $this->assertTrue($retention['legalHold']['active']);
        $this->assertEquals('WOO-verzoek 2025-0142', $retention['legalHold']['reason']);
        $this->assertEquals('test-user', $retention['legalHold']['placedBy']);
    }//end testPlaceLegalHold()


    /**
     * Test releasing a legal hold preserves history.
     */
    public function testReleaseLegalHold(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([
            'legalHold' => [
                'active'     => true,
                'reason'     => 'WOO-verzoek',
                'placedBy'   => 'admin',
                'placedDate' => '2026-01-01T00:00:00+00:00',
                'history'    => [],
            ],
        ]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $result    = $this->service->releaseLegalHold($object, 'WOO afgehandeld');
        $retention = $result->getRetention();

        $this->assertFalse($retention['legalHold']['active']);
        $this->assertCount(1, $retention['legalHold']['history']);
        $this->assertEquals('WOO afgehandeld', $retention['legalHold']['history'][0]['releaseReason']);
    }//end testReleaseLegalHold()


    /**
     * Test hasActiveLegalHold returns true when hold is active.
     */
    public function testHasActiveLegalHoldTrue(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([
            'legalHold' => ['active' => true],
        ]);

        $this->assertTrue($this->service->hasActiveLegalHold($object));
    }//end testHasActiveLegalHoldTrue()


    /**
     * Test hasActiveLegalHold returns false when no hold.
     */
    public function testHasActiveLegalHoldFalseWhenNoHold(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([]);

        $this->assertFalse($this->service->hasActiveLegalHold($object));
    }//end testHasActiveLegalHoldFalseWhenNoHold()


    /**
     * Test extending archiefactiedatum by default period.
     */
    public function testExtendArchiefactiedatum(): void
    {
        $object = new ObjectEntity();
        $object->setRetention([
            'archiefactiedatum' => '2026-01-01',
        ]);

        $this->settingsHandler->method('getArchivalSettingsOnly')->willReturn([
            'defaultExtensionPeriod' => 'P1Y',
        ]);

        $result    = $this->service->extendArchiefactiedatum($object);
        $retention = $result->getRetention();

        $this->assertEquals('2027-01-01', $retention['archiefactiedatum']);
    }//end testExtendArchiefactiedatum()


    /**
     * Test destruction certificate generation.
     */
    public function testGenerateDestructionCertificate(): void
    {
        $listData = [
            'objects' => [
                ['schema' => '1', 'classificatie' => 'B1'],
                ['schema' => '1', 'classificatie' => 'B1'],
                ['schema' => '2', 'classificatie' => 'A1'],
            ],
            'approvals' => [
                ['userId' => 'archivist-1'],
            ],
        ];

        $result = $this->service->generateDestructionCertificate($listData, 3, '2026-03-22T10:00:00+00:00');

        $this->assertEquals('verklaring_van_vernietiging', $result['type']);
        $this->assertEquals(3, $result['totalDestroyed']);
        $this->assertCount(2, $result['groupedBySchema']);
        $this->assertTrue($result['immutable']);
        $this->assertEquals(['archivist-1'], $result['approvedBy']);
    }//end testGenerateDestructionCertificate()
}//end class
