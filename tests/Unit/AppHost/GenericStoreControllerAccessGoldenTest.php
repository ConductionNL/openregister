<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Controller\GenericStoreController;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreControllerServices;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreInstall;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStorePlatform;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreSources;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog;
use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCA\OpenRegister\AppHost\Store\StoreManifestContent;
use OCA\OpenRegister\AppHost\Store\StoreManifestPermissions;
use OCA\OpenRegister\AppHost\Store\StoreManifestTarget;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden table of who may search and install from an app's store, and what
 * every refusal answers. Pins allowed AND refused outcomes (status + body)
 * and which collaborators are reached before the refusal.
 *
 * @covers \OCA\OpenRegister\AppHost\Controller\GenericStoreController
 */
class GenericStoreControllerAccessGoldenTest extends TestCase
{

    /** @var list<string> */
    private array $calls = [];

    /**
     * @param string     $caller  anon | user | admin
     * @param mixed      $store   StoreManifest to serve
     * @param array      $options resolve: mixed|'throw', can: bool, params: array, search: array|'throw'
     */
    private function controller(string $caller, StoreManifest $store, array $options = []): GenericStoreController
    {
        $this->calls = [];
        $manifest    = $this->getMockBuilder(ManifestLoader::class)->disableOriginalConstructor()->onlyMethods(['loadStore'])->getMock();
        $manifest->method('loadStore')->willReturn($store);

        $resolve = array_key_exists('resolve', $options) === true ? $options['resolve'] : ['slug' => 'item'];
        $resolver = function () use ($resolve) {
            $this->calls[] = 'resolve';
            if ($resolve === 'throw') {
                throw new RuntimeException('registry down');
            }
            return $resolve;
        };
        $search = $options['search'] ?? ['outcome' => 'ok', 'cards' => [['slug' => 'a']]];
        $searcher = function () use ($search): array {
            $this->calls[] = 'search';
            if ($search === 'throw') {
                throw new RuntimeException('registry down');
            }
            return $search;
        };

        $service = $this->getMockBuilder(GenericStoreService::class)->disableOriginalConstructor()->onlyMethods(['search', 'resolve', 'publish'])->getMock();
        $service->method('resolve')->willReturnCallback($resolver);
        $service->method('search')->willReturnCallback($searcher);
        $catalog = $this->getMockBuilder(FederatedStoreCatalog::class)->disableOriginalConstructor()->onlyMethods(['search', 'resolve', 'install'])->getMock();
        $catalog->method('resolve')->willReturnCallback($resolver);
        $catalog->method('search')->willReturnCallback($searcher);
        $catalog->method('install')->willReturnCallback(
            function (): array {
                $this->calls[] = 'catalog.install';
                return ['success' => true, 'components' => ['federated']];
            }
        );
        $github = $this->getMockBuilder(GitHubStoreSource::class)->disableOriginalConstructor()->onlyMethods(['search', 'sourceId'])->getMock();
        $github->method('search')->willReturnCallback(
            function ($manifest, string $query, ?string $kind) use ($searcher): array {
                $this->calls[] = 'github:'.$query.':'.var_export($kind, true);
                return $searcher();
            }
        );
        $installer = $this->getMockBuilder(GenericStoreInstaller::class)->disableOriginalConstructor()->onlyMethods(['install'])->getMock();
        $installer->method('install')->willReturnCallback(
            function (): array {
                $this->calls[] = 'installer.install';
                return ['success' => true, 'components' => ['local']];
            }
        );
        $authorizer = $this->getMockBuilder(StoreActionAuthorizer::class)->disableOriginalConstructor()->onlyMethods(['can', 'canPublish'])->getMock();
        $authorizer->method('can')->willReturnCallback(
            function (string $appId, string $action) use ($options): bool {
                $this->calls[] = 'can:'.$appId.':'.$action;
                return ($options['can'] ?? false);
            }
        );

        $session = $this->createMock(IUserSession::class);
        $groups  = $this->createMock(IGroupManager::class);
        if ($caller !== 'anon') {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($caller);
            $session->method('getUser')->willReturn($user);
        }
        $groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin');

        $request = $this->createMock(IRequest::class);
        $params  = ($options['params'] ?? []);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $params[$key] ?? null);

        return new GenericStoreController(
            appName: 'dossiq',
            request: $request,
            services: new GenericStoreControllerServices(
                sources: new GenericStoreSources(manifestLoader: $manifest, storeService: $service, gitHubSource: $github, catalog: $catalog),
                install: new GenericStoreInstall(installer: $installer, actionAuthorizer: $authorizer),
                platform: new GenericStorePlatform(userSession: $session, groupManager: $groups, logger: $this->createMock(LoggerInterface::class))
            )
        );
    }

    private static function store(array $block): StoreManifest
    {
        return StoreManifest::fromManifest(appId: 'dossiq', manifest: ['store' => $block]);
    }

    private static function local(array $extra = []): StoreManifest
    {
        return self::store(array_merge(['schema' => 'case-type-template', 'installable' => ['caseType']], $extra));
    }

    /** @return array<string, array{0: string, 1: StoreManifest, 2: array, 3: string, 4: int, 5: array, 6: list<string>}> */
    public static function installProvider(): array
    {
        $refusedAdmin  = ['success' => false, 'message' => 'Installing a store item requires an administrator.'];
        $installed     = ['success' => true, 'components' => ['local']];
        $unresolved    = ['success' => false, 'message' => 'The store item could not be resolved.'];
        $weird         = new StoreManifest(appId: 'dossiq', enabled: true, content: new StoreManifestContent(target: new StoreManifestTarget(schema: 's')), permissions: new StoreManifestPermissions(installAuth: 'owner'));
        return [
            'malformed slug before anything' => ['anon', self::local(), [], 'Bad_Slug', 400, ['success' => false, 'message' => 'Malformed item slug.'], []],
            'one-char slug is malformed'     => ['admin', self::local(), [], 'a', 400, ['success' => false, 'message' => 'Malformed item slug.'], []],
            'no store'                       => ['admin', new StoreManifest(appId: 'dossiq', enabled: false), [], 'item-1', 404, ['success' => false, 'message' => 'This app declares no store.'], []],
            'unenforceable posture'          => ['admin', $weird, [], 'item-1', 403, ['success' => false, 'message' => 'This store declares an install posture this server cannot enforce.'], []],
            'admin posture, anonymous'       => ['anon', self::local(), [], 'item-1', 403, $refusedAdmin, []],
            'admin posture, user'            => ['user', self::local(), [], 'item-1', 403, $refusedAdmin, []],
            'admin posture, admin'           => ['admin', self::local(), [], 'item-1', 200, $installed, ['resolve', 'installer.install']],
            'authenticated, anonymous'       => ['anon', self::local(['installAuth' => 'authenticated']), [], 'item-1', 403, $refusedAdmin, []],
            'authenticated, user'            => ['user', self::local(['installAuth' => 'authenticated']), [], 'item-1', 200, $installed, ['resolve', 'installer.install']],
            'action, anonymous'              => ['anon', self::local(['installAuth' => 'action:store.install']), ['can' => true], 'item-1', 403, $refusedAdmin, []],
            'action, user allowed'           => ['user', self::local(['installAuth' => 'action:store.install']), ['can' => true], 'item-1', 200, $installed, ['can:dossiq:store.install', 'resolve', 'installer.install']],
            'action, user refused'           => ['user', self::local(['installAuth' => 'action:store.install']), ['can' => false], 'item-1', 403, $refusedAdmin, ['can:dossiq:store.install']],
            'action, admin refused'          => ['admin', self::local(['installAuth' => 'action:store.install']), ['can' => false], 'item-1', 403, $refusedAdmin, ['can:dossiq:store.install']],
            'unresolved'                     => ['admin', self::local(), ['resolve' => null], 'item-1', 404, $unresolved, ['resolve']],
            'resolve throws'                 => ['admin', self::local(), ['resolve' => 'throw'], 'item-1', 404, $unresolved, ['resolve']],
            'federated'                      => ['admin', self::store(['types' => ['openregister.configset']]), [], 'item-1', 200, ['success' => true, 'components' => ['federated']], ['resolve', 'catalog.install']],
            'federated, user refused'        => ['user', self::store(['types' => ['openregister.configset']]), [], 'item-1', 403, $refusedAdmin, []],
        ];
    }

    #[DataProvider('installProvider')]
    public function testInstall(string $caller, StoreManifest $store, array $options, string $slug, int $status, array $body, array $calls): void
    {
        $response = $this->controller($caller, $store, $options)->install($slug);
        $this->assertSame($status, $response->getStatus());
        $this->assertSame($body, $response->getData());
        $this->assertSame($calls, $this->calls);
    }

    /** @return array<string, array{0: string, 1: StoreManifest, 2: array, 3: int, 4: array, 5: list<string>}> */
    public static function searchProvider(): array
    {
        return [
            'anonymous'        => ['anon', self::local(), [], 401, ['outcome' => 'unauthenticated', 'cards' => []], []],
            'anonymous, none'  => ['anon', new StoreManifest(appId: 'dossiq', enabled: false), [], 401, ['outcome' => 'unauthenticated', 'cards' => []], []],
            'no store'         => ['user', new StoreManifest(appId: 'dossiq', enabled: false), [], 200, ['outcome' => 'not_configured', 'cards' => [], 'kinds' => [], 'builtIn' => []], []],
            'local'            => ['user', self::local(['kinds' => ['caseType']]), [], 200, ['outcome' => 'ok', 'cards' => [['slug' => 'a']], 'kinds' => ['caseType'], 'builtIn' => []], ['search']],
            'local throws'     => ['user', self::local(['kinds' => ['caseType']]), ['search' => 'throw'], 200, ['outcome' => 'store_unreachable', 'cards' => [], 'kinds' => ['caseType'], 'builtIn' => []], ['search']],
            'federated kinds'  => ['user', self::store(['types' => ['openregister.configset', 'openregister.flows']]), [], 200, ['outcome' => 'ok', 'cards' => [['slug' => 'a']], 'kinds' => ['openregister.configset', 'openregister.flows'], 'builtIn' => []], ['search']],
            'github, no query' => ['user', self::store(['source' => 'github', 'topics' => ['x']]), ['params' => ['q' => ['no'], 'kind' => 5]], 200, ['outcome' => 'ok', 'cards' => [['slug' => 'a']], 'kinds' => [], 'builtIn' => []], ["github::NULL", 'search']],
            'github, query'    => ['admin', self::store(['source' => 'github', 'topics' => ['x']]), ['params' => ['q' => 'abc', 'kind' => 'k']], 200, ['outcome' => 'ok', 'cards' => [['slug' => 'a']], 'kinds' => [], 'builtIn' => []], ["github:abc:'k'", 'search']],
        ];
    }

    #[DataProvider('searchProvider')]
    public function testSearch(string $caller, StoreManifest $store, array $options, int $status, array $body, array $calls): void
    {
        $response = $this->controller($caller, $store, $options)->search();
        $this->assertSame($status, $response->getStatus());
        $this->assertSame($body, $response->getData());
        $this->assertSame($calls, $this->calls);
    }
}
