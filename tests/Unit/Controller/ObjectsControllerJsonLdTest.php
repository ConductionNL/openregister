<?php

/**
 * Content-negotiation regression tests for ObjectsController JSON-LD output
 * (json-ld-output).
 *
 * The JSON-LD path only fires in show()/index() when the JSON-LD services are
 * wired AND the register/schema entities resolve. Entity resolution goes
 * through `\OC::$server`, so the positive JSON-LD assertions require the NC
 * container and are skipped in pure-unit mode. The default-unchanged and
 * write-verb-unaffected guarantees are asserted unconditionally: with the
 * JSON-LD services left unwired (null), an `Accept: application/ld+json`
 * header MUST NOT change the response.
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

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializer;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ObjectsControllerJsonLdTest extends TestCase
{
    private IRequest $request;
    private ObjectService $objectService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private ?JsonLdSerializer $serializer;


    private function buildController(bool $withJsonLd): ObjectsController
    {
        $this->request       = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.test/x');
        $contextService   = new JsonLdContextService($urlGenerator);
        $this->serializer = $withJsonLd ? new JsonLdSerializer($contextService, $urlGenerator) : null;

        return new ObjectsController(
            'openregister',
            $this->request,
            $this->createMock(IAppConfig::class),
            $this->createMock(IAppManager::class),
            $this->createMock(ContainerInterface::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(AuditTrailMapper::class),
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->createMock(ExportService::class),
            $this->createMock(ImportService::class),
            $this->createMock(WebhookService::class),
            $this->createMock(LoggerInterface::class),
            null,
            null,
            $this->serializer,
            $withJsonLd ? $contextService : null
        );
    }


    private function requireOcServer(): void
    {
        if (class_exists('\OC', false) === false || isset(\OC::$server) === false) {
            $this->markTestSkipped('Requires the Nextcloud container (\OC::$server) for entity resolution.');
        }
    }


    private function setupAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
    }


    public function testShowDefaultJsonUnaffectedByLdAcceptWhenServicesUnwired(): void
    {
        $this->requireOcServer();
        $controller = $this->buildController(withJsonLd: false);
        $this->setupAdmin();

        // Entity resolution throws → entities null → JSON-LD never fires regardless.
        \OC::$server->registerService(RegisterMapper::class, fn() => $this->throwingMapper(RegisterMapper::class));
        \OC::$server->registerService(SchemaMapper::class, fn() => $this->throwingMapper(SchemaMapper::class));

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getHeader')->willReturn('application/ld+json');

        $entity = new ObjectEntity();
        $entity->setUuid('uuid-1');
        $entity->setObject(['title' => 'Test']);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($entity);
        $this->objectService->method('renderEntity')->willReturn(['title' => 'Test', '@self' => ['id' => 'uuid-1']]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $controller->show('uuid-1', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        // Plain JSON: no JSON-LD content type, body keeps the @self envelope.
        $this->assertArrayNotHasKey('Content-Type', array_filter(
            $result->getHeaders(),
            fn($v, $k) => $k === 'Content-Type' && $v === 'application/ld+json',
            ARRAY_FILTER_USE_BOTH
        ));
        $this->assertArrayHasKey('@self', $result->getData());
    }


    public function testShowEmitsJsonLdWhenNegotiatedAndEntitiesResolve(): void
    {
        $this->requireOcServer();
        $controller = $this->buildController(withJsonLd: true);
        $this->setupAdmin();

        $register = new \OCA\OpenRegister\Db\Register();
        $register->setSlug('personen');
        $schema = new \OCA\OpenRegister\Db\Schema();
        $schema->setId(1);
        $schema->setSlug('persoon');
        $schema->setProperties(['title' => ['type' => 'string']]);

        \OC::$server->registerService(RegisterMapper::class, function () use ($register) {
            $m = $this->createMock(RegisterMapper::class);
            $m->method('find')->willReturn($register);
            return $m;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($schema) {
            $m = $this->createMock(SchemaMapper::class);
            $m->method('find')->willReturn($schema);
            return $m;
        });

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getHeader')->willReturn('application/ld+json');

        $entity = new ObjectEntity();
        $entity->setUuid('uuid-1');
        $entity->setObject(['title' => 'Test']);
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('find')->willReturn($entity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['id' => 'uuid-1', 'uri' => 'https://nc.test/o/uuid-1', 'register' => 'personen', 'schema' => 'persoon'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $controller->show('uuid-1', 'personen', 'persoon', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame('application/ld+json', $result->getHeaders()['Content-Type']);
        $this->assertSame('Accept', $result->getHeaders()['Vary']);
        $data = $result->getData();
        $this->assertArrayHasKey('@context', $data);
        $this->assertSame('https://nc.test/o/uuid-1', $data['@id']);
        $this->assertArrayNotHasKey('@self', $data);
    }


    private function throwingMapper(string $class)
    {
        $m = $this->createMock($class);
        $m->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));
        return $m;
    }
}
