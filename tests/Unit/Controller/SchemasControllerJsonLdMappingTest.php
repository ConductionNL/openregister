<?php

/**
 * Unit tests for JSON-LD mapping validation on the schemas API (json-ld-output).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemasControllerJsonLdMappingTest extends TestCase
{
    private IRequest $request;
    private SchemaMapper $schemaMapper;
    private SchemasController $controller;


    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);

        $userSession = $this->createMock(\OCP\IUserSession::class);
        $user        = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);
        $groupManager = $this->createMock(\OCP\IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturn(['admin']);
        $groupManager->method('isAdmin')->willReturn(true);

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function ($id) use ($userSession, $groupManager) {
                if ($id === \OCP\IUserSession::class) {
                    return $userSession;
                }

                if ($id === \OCP\IGroupManager::class) {
                    return $groupManager;
                }

                return null;
            }
        );

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.test/ctx');

        $this->controller = new SchemasController(
            'openregister',
            $this->request,
            $this->createMock(IAppConfig::class),
            $this->schemaMapper,
            $this->createMock(MagicMapper::class),
            $this->createMock(UploadService::class),
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(OrganisationService::class),
            $this->createMock(SchemaCacheHandler::class),
            $this->createMock(FacetCacheHandler::class),
            $this->createMock(SchemaService::class),
            $this->createMock(LoggerInterface::class),
            $container,
            $this->createMock(SchemaVersioningService::class),
            new JsonLdContextService($urlGenerator)
        );
    }


    public function testCreateRejectsInvalidMapping(): void
    {
        $this->request->method('getParams')->willReturn(
            [
                'title'         => 'Persoon',
                'configuration' => [
                    'jsonld' => ['properties' => ['name' => 'just a label']],
                ],
            ]
        );

        // The mapper must never be reached for an invalid mapping.
        $this->schemaMapper->expects($this->never())->method('createFromArray');

        $response = $this->controller->create();

        $this->assertSame(400, $response->getStatus());
        $this->assertArrayHasKey('errors', $response->getData());
    }


    public function testCreateAcceptsValidMapping(): void
    {
        $this->request->method('getParams')->willReturn(
            [
                'title'         => 'Persoon',
                'configuration' => [
                    'jsonld' => [
                        '@vocab'     => 'https://schema.org/',
                        'properties' => ['name' => 'https://schema.org/name'],
                    ],
                ],
            ]
        );

        $schema = new Schema();
        $schema->setId(1);
        $this->schemaMapper->expects($this->once())->method('createFromArray')->willReturn($schema);

        $response = $this->controller->create();

        $this->assertSame(201, $response->getStatus());
    }
}
