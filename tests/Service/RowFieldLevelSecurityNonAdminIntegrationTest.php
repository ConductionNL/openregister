<?php

/**
 * Non-admin integration tests for FLS read + export paths.
 *
 * The existing `RowFieldLevelSecurityIntegrationTest` runs as admin
 * (admin bypass short-circuits property RBAC). This complementary test
 * creates a real non-admin Nextcloud user, logs them in via
 * `IUserSession::setUser`, and asserts that:
 *
 * 1. `PropertyRbacHandler::canReadProperty()` returns `false` for
 *    fields whose `authorization.read` requires a group the test
 *    user is not a member of.
 * 2. `PropertyRbacHandler::filterReadableProperties()` strips those
 *    fields from rendered objects.
 * 3. `ExportService` headers exclude the same fields when the
 *    non-admin user runs an export.
 *
 * This closes spec requirement 10 ("FLS MUST strip restricted fields
 * from API responses and export outputs") for the non-admin path,
 * which the admin-bypass tests cannot exercise.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RowFieldLevelSecurityNonAdminIntegrationTest extends TestCase
{

    private PropertyRbacHandler $propertyRbacHandler;

    private ExportService $exportService;

    private SchemaMapper $schemaMapper;

    private RegisterMapper $registerMapper;

    private IUserManager $userManager;

    private IUserSession $userSession;

    private ?IUser $testUser = null;

    private string $testUserId = '';

    private ?IUser $previousUser = null;

    private ?Schema $authedSchema = null;

    private ?Register $testRegister = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->propertyRbacHandler = \OC::$server->get(PropertyRbacHandler::class);
        $this->exportService       = \OC::$server->get(ExportService::class);
        $this->schemaMapper        = \OC::$server->get(SchemaMapper::class);
        $this->registerMapper      = \OC::$server->get(RegisterMapper::class);
        $this->userManager         = \OC::$server->get(IUserManager::class);
        $this->userSession         = \OC::$server->get(IUserSession::class);

        $this->previousUser = $this->userSession->getUser();

        // Create a non-admin user with no special groups. The fixture
        // schema below requires `hr` group for the `salary` field;
        // `admin` group for `ssn`. This user is in neither, so both
        // fields MUST be filtered out.
        $this->testUserId = 'phpunit-fls-noadmin-'.uniqid();
        // Random non-compromised password — password_policy app rejects
        // common test strings as "compromised".
        $password       = bin2hex(random_bytes(12)).'Aa9!';
        $this->testUser = $this->userManager->createUser($this->testUserId, $password);
        $this->userSession->setUser($this->testUser);

        $this->createTestFixture();

    }//end setUp()

    protected function tearDown(): void
    {
        if ($this->previousUser !== null) {
            $this->userSession->setUser($this->previousUser);
        }

        if ($this->testUser !== null) {
            try {
                $this->testUser->delete();
            } catch (\Throwable) {
            }
        }

        $db = \OC::$server->get(\OCP\IDBConnection::class);
        if ($this->authedSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->authedSchema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();

    }//end tearDown()

    public function testNonAdminCannotReadRestrictedProperty(): void
    {
        $object = ['title' => 'visible', 'salary' => '95000', 'ssn' => '123-45-6789'];

        $this->assertTrue(
            $this->propertyRbacHandler->canReadProperty($this->authedSchema, 'title', $object),
            'unrestricted field MUST be readable by anyone'
        );

        $this->assertFalse(
            $this->propertyRbacHandler->canReadProperty($this->authedSchema, 'salary', $object),
            'salary requires admin; non-admin MUST be denied'
        );

        $this->assertFalse(
            $this->propertyRbacHandler->canReadProperty($this->authedSchema, 'ssn', $object),
            'ssn requires hr; non-admin MUST be denied'
        );

    }//end testNonAdminCannotReadRestrictedProperty()

    public function testNonAdminFilterReadablePropertiesStripsRestrictedFields(): void
    {
        $object = ['title' => 'visible', 'salary' => '95000', 'ssn' => '123-45-6789'];

        $filtered = $this->propertyRbacHandler->filterReadableProperties($this->authedSchema, $object);

        $this->assertSame(['title' => 'visible'], $filtered, 'non-admin MUST only see the unrestricted field');

    }//end testNonAdminFilterReadablePropertiesStripsRestrictedFields()

    public function testExportHeadersExcludeRestrictedFieldsForNonAdmin(): void
    {
        // Drive ExportService through the same path used by the API:
        // ask for headers + a single empty row. PhpSpreadsheet is heavy,
        // so we only need a stable "did the salary column get emitted?"
        // assertion that doesn't require Excel-rendering the spreadsheet.
        $headers = $this->extractHeaderRow();

        $this->assertContains('title', $headers, 'unrestricted column MUST appear in non-admin export headers');
        $this->assertNotContains(
            'salary',
            $headers,
            'salary is restricted to admin; non-admin export MUST NOT emit it as a header'
        );
        $this->assertNotContains(
            'ssn',
            $headers,
            'ssn is restricted to hr; non-admin export MUST NOT emit it as a header'
        );

    }//end testExportHeadersExcludeRestrictedFieldsForNonAdmin()

    private function extractHeaderRow(): array
    {
        // ExportService::buildHeaders is private; the public surface is
        // ExportService::exportToCsv (or exportRegister). We invoke
        // buildHeaders via reflection because re-implementing the
        // ExportService fixture (writes a file, generates an Excel
        // book) would be far heavier than this targeted check.
        $ref    = new \ReflectionObject($this->exportService);
        $method = null;
        foreach (['getHeaders', 'buildHeaders', 'getCsvHeaders'] as $candidate) {
            if ($ref->hasMethod($candidate) === true) {
                $method = $ref->getMethod($candidate);
                break;
            }
        }

        if ($method === null) {
            $this->fail('ExportService missing expected header-building method (getHeaders / buildHeaders / getCsvHeaders)');
        }

        $method->setAccessible(true);

        // Discover the parameter list so we can pass the right thing.
        $params = $method->getParameters();
        $args   = [];
        foreach ($params as $param) {
            $name = $param->getName();
            if ($name === 'schema' || $name === 'schemaObject' || str_contains($name, 'chema') === true) {
                $args[] = $this->authedSchema;
                continue;
            }

            if (str_contains($name, 'egister') === true) {
                $args[] = $this->testRegister;
                continue;
            }

            if ($param->isOptional() === true) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }

        $headers = (array) $method->invokeArgs($this->exportService, $args);
        return array_values($headers);

    }//end extractHeaderRow()

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-fls-noadmin-'.uniqid());
        $register->setSlug('phpunit-fls-noadmin-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setVersion('1.0.0');
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $authed = new Schema();
        $authed->setTitle('phpunit-fls-noadmin-schema-'.uniqid());
        $authed->setUuid(Uuid::v4()->toRfc4122());
        $authed->setSlug('phpunit-fls-noadmin-schema-'.uniqid());
        $authed->setProperties(
            [
                'title'  => ['type' => 'string', 'title' => 'Title'],
                'salary' => [
                    'type'          => 'number',
                    'title'         => 'Salary',
                    'authorization' => ['read' => [['group' => 'admin']]],
                ],
                'ssn'    => [
                    'type'          => 'string',
                    'title'         => 'Social security number',
                    'authorization' => [
                        'read'   => [['group' => 'hr']],
                        'manage' => [['group' => 'hr']],
                    ],
                ],
            ]
        );
        $this->authedSchema = $this->schemaMapper->insert($authed);

        $this->testRegister->setSchemas([$this->authedSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

    }//end createTestFixture()
}//end class
