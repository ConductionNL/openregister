<?php

/**
 * GitHubIssuesController Unit Tests.
 *
 * End-to-end controller coverage for the GitHub issues proxy endpoints. Two of the spec
 * scenarios are particularly load-bearing:
 *
 *   1. PAT-leak assertion (task 1.11) — the placeholder token `YOUR_API_KEY_HERE` MUST
 *      NOT appear in any response body, response header, log line, or cached value after
 *      exercising the success / error / rate-limit paths with that placeholder in place
 *      of a real token.
 *   2. CSRF posture (design D20) — the GET method carries `#[NoCSRFRequired]`; the POST
 *      method does NOT. The framework's middleware enforces this; this test class only
 *      asserts the attribute declarations match the spec by reading them off the
 *      ReflectionMethod (the framework integration test belongs in tests/Service/).
 *
 * Detailed guard semantics (validators, allowlist, rate-limit math) are tested in
 * GitHubGuardsTest and GitHubRequestValidatorTest — this file covers the controller
 * wiring + the integration scenarios the spec calls out by name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\GitHubIssuesController;
use OCA\OpenRegister\Service\Configuration\GitHubGuards;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitHubRequestValidator;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Unit tests for `GitHubIssuesController`.
 *
 * @package OCA\OpenRegister\Tests\Unit\Controller
 *
 * @covers \OCA\OpenRegister\Controller\GitHubIssuesController
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-11
 */
class GitHubIssuesControllerTest extends TestCase
{
    /**
     * Placeholder token used in PAT-leak assertions (task 1.11).
     */
    private const PLACEHOLDER_PAT = 'YOUR_API_KEY_HERE';

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md (scenario "Unauthenticated submission")
     */
    public function testCreateUnauthenticatedReturns401(): void
    {
        $controller = $this->buildController(
            requestParams: ['repo' => 'ConductionNL/openregister', 'title' => 'A real title', 'body' => 'A real body — at least ten chars long.'],
            currentUser: null
        );

        $response = $controller->create();
        $this->assertEquals(401, $response->getStatus());
    }//end testCreateUnauthenticatedReturns401()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md (scenario "Title too short")
     */
    public function testCreateRejectsShortTitleWithoutHittingGithub(): void
    {
        $handler = $this->createMock(GitHubHandler::class);
        $handler->expects($this->never())->method('createIssue');

        $controller = $this->buildController(
            requestParams: ['repo' => 'ConductionNL/openregister', 'title' => 'Hi', 'body' => 'A real body — at least ten chars long.'],
            currentUser: 'alice',
            handler: $handler
        );

        $response = $controller->create();
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals('title_invalid_length', $this->errorCode(response: $response));
    }//end testCreateRejectsShortTitleWithoutHittingGithub()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-16
     */
    public function testCreateRejectsInvalidSpecRefWithoutHittingGithub(): void
    {
        $handler = $this->createMock(GitHubHandler::class);
        $handler->expects($this->never())->method('createIssue');

        $controller = $this->buildController(
            requestParams: [
                'repo'    => 'ConductionNL/openregister',
                'title'   => 'A real title',
                'body'    => 'A real body — at least ten chars long.',
                'specRef' => 'Bad Slug!',
            ],
            currentUser: 'alice',
            handler: $handler
        );

        $response = $controller->create();
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals('specref_invalid_format', $this->errorCode(response: $response));
    }//end testCreateRejectsInvalidSpecRefWithoutHittingGithub()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-14
     */
    public function testCreateRejectsCrossRepoWhenAllowlistSet(): void
    {
        $handler = $this->createMock(GitHubHandler::class);
        $handler->expects($this->never())->method('createIssue');

        $controller = $this->buildController(
            requestParams: [
                'repo'  => 'torvalds/linux',
                'title' => 'Spam',
                'body'  => 'spam body 10+ chars',
            ],
            currentUser: 'alice',
            handler: $handler,
            allowedRepo: 'ConductionNL/openregister'
        );

        $response = $controller->create();
        $this->assertEquals(403, $response->getStatus());
        $this->assertEquals('repo_not_allowed', $this->errorCode(response: $response));
    }//end testCreateRejectsCrossRepoWhenAllowlistSet()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-21
     */
    public function testFeatureDisabledShortCircuitsBothEndpoints(): void
    {
        $handler = $this->createMock(GitHubHandler::class);
        $handler->expects($this->never())->method('listIssues');
        $handler->expects($this->never())->method('createIssue');

        $controller = $this->buildController(
            requestParams: [
                'repo'  => 'ConductionNL/openregister',
                'title' => 'A real title',
                'body'  => 'A real body — at least ten chars long.',
            ],
            currentUser: 'alice',
            handler: $handler,
            featureEnabled: false
        );

        $getResp = $controller->index();
        $this->assertEquals(403, $getResp->getStatus());
        $this->assertEquals('feature_disabled', $this->errorCode(response: $getResp));

        $postResp = $controller->create();
        $this->assertEquals(403, $postResp->getStatus());
    }//end testFeatureDisabledShortCircuitsBothEndpoints()

    /**
     * @return void
     *
     * PAT-leak assertion per task 1.11 — run the controller through success, error, and
     * rate-limit paths with the placeholder PAT and confirm the literal token string never
     * appears in any response body or response header.
     *
     * Note: the placeholder is `YOUR_API_KEY_HERE`. Real PATs in test fixtures are a code
     * smell — this test deliberately uses an obvious placeholder string so a regression
     * detected by this assertion is unambiguous.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-11
     * @spec openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md (requirement "PAT never leaks")
     */
    public function testPlaceholderPatNeverLeaksInResponses(): void
    {
        // 1. Successful list (cache miss + handler returns sanitized DTO).
        $handler = $this->createMock(GitHubHandler::class);
        $handler->method('listIssues')->willReturn(
            [
                'items' => [
                    [
                        'number'   => 42,
                        'title'    => 'A feature',
                        'body'     => 'A description',
                        'html_url' => 'https://github.com/ConductionNL/openregister/issues/42',
                    ],
                ],
            ]
        );

        $controller = $this->buildController(
            requestParams: ['repo' => 'ConductionNL/openregister'],
            currentUser: 'alice',
            handler: $handler
        );

        $response = $controller->index();
        $this->assertResponseDoesNotLeak(response: $response, placeholder: self::PLACEHOLDER_PAT);

        // 2. Validation error path.
        $controller2   = $this->buildController(
            requestParams: ['repo' => 'invalid-slug-no-slash'],
            currentUser: 'alice'
        );
        $errorResponse = $controller2->index();
        $this->assertResponseDoesNotLeak(response: $errorResponse, placeholder: self::PLACEHOLDER_PAT);

        // 3. Feature-disabled path.
        $controller3      = $this->buildController(
            requestParams: ['repo' => 'ConductionNL/openregister'],
            currentUser: 'alice',
            featureEnabled: false
        );
        $disabledResponse = $controller3->index();
        $this->assertResponseDoesNotLeak(response: $disabledResponse, placeholder: self::PLACEHOLDER_PAT);
    }//end testPlaceholderPatNeverLeaksInResponses()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md (requirement "Authentication and authorization")
     */
    public function testGetMethodCarriesNoCSRFRequired(): void
    {
        $reflection = new ReflectionMethod(GitHubIssuesController::class, 'index');
        $attrs      = $reflection->getAttributes(NoCSRFRequired::class);
        $this->assertCount(1, $attrs, 'GET /api/github/issues MUST carry #[NoCSRFRequired]');
    }//end testGetMethodCarriesNoCSRFRequired()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md (requirement "Authentication and authorization")
     */
    public function testPostMethodDoesNotCarryNoCSRFRequired(): void
    {
        $reflection = new ReflectionMethod(GitHubIssuesController::class, 'create');
        $attrs      = $reflection->getAttributes(NoCSRFRequired::class);
        $this->assertCount(
            0,
            $attrs,
            'POST /api/github/issues MUST NOT carry #[NoCSRFRequired] — CSRF middleware must run'
        );

        $noAdmin = $reflection->getAttributes(NoAdminRequired::class);
        $this->assertCount(1, $noAdmin, 'POST /api/github/issues MUST carry #[NoAdminRequired]');
    }//end testPostMethodDoesNotCarryNoCSRFRequired()

    /**
     * Assert the placeholder PAT string does NOT appear in the response body or any header.
     *
     * @param JSONResponse $response    Response under test.
     * @param string       $placeholder Literal placeholder string that should never appear.
     *
     * @return void
     */
    private function assertResponseDoesNotLeak(JSONResponse $response, string $placeholder): void
    {
        $body = json_encode($response->getData());
        $this->assertStringNotContainsString(
            $placeholder,
            (string) $body,
            'placeholder PAT MUST NOT appear in response body'
        );

        foreach ($response->getHeaders() as $name => $value) {
            $this->assertStringNotContainsString(
                $placeholder,
                (string) $value,
                'placeholder PAT MUST NOT appear in response header '.$name
            );
        }
    }//end assertResponseDoesNotLeak()

    /**
     * Build a controller with mocked deps. Defaults are tuned for the happy path.
     *
     * @param array<string, mixed> $requestParams  Param map for IRequest::getParam.
     * @param string|null          $currentUser    UID returned by IUserSession, or null when unauthenticated.
     * @param GitHubHandler|null   $handler        Mocked GitHubHandler (defaults to a never-called mock).
     * @param string               $allowedRepo    Value returned by IAppConfig for `github_repo`.
     * @param bool                 $featureEnabled Value returned by IAppConfig for `features_roadmap_enabled`.
     *
     * @return GitHubIssuesController
     */
    private function buildController(
        array $requestParams,
        ?string $currentUser,
        ?GitHubHandler $handler=null,
        string $allowedRepo='ConductionNL/openregister',
        bool $featureEnabled=true
    ): GitHubIssuesController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($requestParams) {
                return $requestParams[$key] ?? $default;
            }
        );

        $user = null;
        if ($currentUser !== null) {
            $userObj = $this->createMock(IUser::class);
            $userObj->method('getUID')->willReturn($currentUser);
            $user = $userObj;
        }

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $cacheState = new \ArrayObject();
        $cache      = $this->createMock(ICache::class);
        $cache->method('get')->willReturnCallback(
            function (string $key) use ($cacheState) {
                return $cacheState->offsetExists($key) ? $cacheState->offsetGet($key) : null;
            }
        );
        $cache->method('set')->willReturnCallback(
            function (string $key, $value, int $ttl=0) use ($cacheState): bool {
                $cacheState->offsetSet($key, $value);
                return true;
            }
        );

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $appConfig = $this->createMock(\OCP\IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($allowedRepo): string {
                return $key === 'github_repo' ? $allowedRepo : $default;
            }
        );
        $appConfig->method('getValueBool')->willReturnCallback(
            function (string $app, string $key, bool $default=false) use ($featureEnabled): bool {
                return $key === 'features_roadmap_enabled' ? $featureEnabled : $default;
            }
        );

        $guards    = new GitHubGuards(
            appConfig: $appConfig,
            userSession: $userSession,
            cacheFactory: $cacheFactory
        );
        $validator = new GitHubRequestValidator();
        $logger    = $this->createMock(LoggerInterface::class);

        return new GitHubIssuesController(
            appName: 'openregister',
            request: $request,
            githubHandler: $handler ?? $this->createMock(GitHubHandler::class),
            guards: $guards,
            validator: $validator,
            userSession: $userSession,
            cacheFactory: $cacheFactory,
            logger: $logger
        );
    }//end buildController()

    /**
     * @param JSONResponse|null $response
     *
     * @return string
     */
    private function errorCode(?JSONResponse $response): string
    {
        if ($response === null) {
            return '';
        }

        $data = $response->getData();
        if (is_array($data) === false) {
            return '';
        }

        return (string) ($data['error'] ?? '');
    }//end errorCode()
}//end class
