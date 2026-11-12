<?php

/**
 * Behaviour tests for DbalConnectionFactory: parameter validation, credential resolution and caching.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Connections are built lazily by DBAL, so a MySQL/PostgreSQL source is checked
 * through its parameters without any server being reached.
 *
 * @covers \OCA\OpenRegister\Service\Dbal\DbalConnectionFactory
 * @uses   \OCA\OpenRegister\Service\Dbal\DbalConnectionException
 * @uses   \OCA\OpenRegister\Db\Source
 */
class DbalConnectionFactoryBehaviourTest extends TestCase
{
    /** @var CredentialStore&MockObject */
    private CredentialStore $credentials;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->credentials = $this->createMock(CredentialStore::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
    }//end setUp()

    private function factory(): DbalConnectionFactory
    {
        return new DbalConnectionFactory($this->credentials, $this->logger);
    }//end factory()

    private function source(?array $config, ?string $uuid = 'src-uuid', int $id = 7): Source
    {
        $source = new Source();
        $source->setId($id);
        $source->setUuid($uuid);
        $source->setType('database');
        $source->setAuthConfig($config);
        return $source;
    }//end source()

    private function assertRefused(Source $source, string $message): void
    {
        try {
            $this->factory()->getConnection($source);
            $this->fail('The source must be refused.');
        } catch (DbalConnectionException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }//end assertRefused()

    public function testASourceWithoutConfigurationIsRefused(): void
    {
        $this->credentials->expects($this->never())->method('get');

        $this->assertRefused($this->source(null), 'Database source has no connection configuration.');
        $this->assertRefused($this->source([]), 'Database source has no connection configuration.');
    }//end testASourceWithoutConfigurationIsRefused()

    public function testASqliteSourceWithoutAPathIsRefused(): void
    {
        $this->assertRefused($this->source(['driver' => 'pdo_sqlite', 'path' => '']), 'SQLite database source requires a file path.');
    }//end testASqliteSourceWithoutAPathIsRefused()

    public function testANetworkSourceWithoutDbnameIsRefused(): void
    {
        $this->assertRefused(
            $this->source(['driver' => 'pdo_pgsql', 'host' => 'db.internal', 'dbname' => '']),
            'Database source requires host and dbname.'
        );
    }//end testANetworkSourceWithoutDbnameIsRefused()

    public function testANetworkSourceGetsItsPortAsIntegerAndThePasswordFromTheCredentialStore(): void
    {
        $this->credentials->expects($this->once())
            ->method('get')
            ->with('cred-1', 'personal')
            ->willReturn('s3cret');

        $connection = $this->factory()->getConnection(
            $this->source(
                [
                    'driver' => 'pdo_mysql', 'host' => 'db.internal', 'port' => '3307', 'dbname' => 'permits',
                    'user' => 'reader', 'credential' => 'cred-1', 'credentialScope' => 'personal',
                ]
            )
        );

        $params = $connection->getParams();
        $this->assertSame(
            ['driver' => 'pdo_mysql', 'host' => 'db.internal', 'dbname' => 'permits', 'user' => 'reader', 'port' => 3307, 'password' => 's3cret'],
            array_intersect_key($params, array_flip(['driver', 'host', 'dbname', 'user', 'port', 'password']))
        );
    }//end testANetworkSourceGetsItsPortAsIntegerAndThePasswordFromTheCredentialStore()

    public function testAnEmptyPortIsLeftOutAndTheDefaultScopeIsOrganisation(): void
    {
        $this->credentials->expects($this->once())->method('get')->with('cred-1', 'organisation')->willReturn('pw');

        $params = $this->factory()->getConnection(
            $this->source(['driver' => 'pdo_pgsql', 'host' => 'h', 'dbname' => 'd', 'port' => '', 'credential' => 'cred-1'])
        )->getParams();

        $this->assertArrayNotHasKey('port', $params);
        $this->assertSame('pw', $params['password']);
        $this->assertSame('', $params['user']);
    }//end testAnEmptyPortIsLeftOutAndTheDefaultScopeIsOrganisation()

    public function testWithoutACredentialThePasswordIsEmptyAndTheStoreIsNotAsked(): void
    {
        $this->credentials->expects($this->never())->method('get');

        $params = $this->factory()->getConnection($this->source(['driver' => 'pdo_pgsql', 'host' => 'h', 'dbname' => 'd']))->getParams();

        $this->assertSame('', $params['password']);
    }//end testWithoutACredentialThePasswordIsEmptyAndTheStoreIsNotAsked()

    public function testAnUnresolvableCredentialOfASourceWithoutUuidIsLoggedByIdAndFailsClosed(): void
    {
        $this->credentials->method('get')->willReturn(null);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('[DbalConnectionFactory] credential could not be resolved for source #12 — failing closed');

        $this->assertRefused(
            $this->source(['driver' => 'pdo_pgsql', 'host' => 'h', 'dbname' => 'd', 'credential' => 'gone'], uuid: null, id: 12),
            'The database credential could not be resolved from the credential store.'
        );
    }//end testAnUnresolvableCredentialOfASourceWithoutUuidIsLoggedByIdAndFailsClosed()

    public function testConnectionsAreCachedPerIdAndUuid(): void
    {
        $factory = $this->factory();
        $config  = ['driver' => 'pdo_pgsql', 'host' => 'h', 'dbname' => 'd'];

        $first  = $factory->getConnection($this->source($config, 'a'));
        $again  = $factory->getConnection($this->source(['driver' => 'pdo_pgsql', 'host' => 'other', 'dbname' => 'x'], 'a'));
        $second = $factory->getConnection($this->source($config, 'b'));

        $this->assertSame($first, $again);
        $this->assertNotSame($first, $second);
        $this->assertSame('h', $again->getParams()['host']);
    }//end testConnectionsAreCachedPerIdAndUuid()

    public function testUnknownDriversAreNotAvailable(): void
    {
        $this->assertFalse($this->factory()->isDriverAvailable('oci8'));
        $this->assertFalse($this->factory()->isDriverAvailable(''));
    }//end testUnknownDriversAreNotAvailable()
}//end class
