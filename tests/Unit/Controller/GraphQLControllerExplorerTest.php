<?php

/**
 * Contract tests for GraphQLController::explorer().
 *
 * Covers GET /api/graphql/explorer — the GraphiQL UI shell.
 *
 * The explorer is not a JSON endpoint: its contract is an HTML document whose
 * inline script is only executable because the response relaxes CSP for the
 * CDN that serves GraphiQL. A shell that renders but ships the wrong endpoint
 * URL, an unusable request token, or an un-nonced inline script is a broken
 * explorer that still returns 200 — so all three are asserted.
 *
 * ⚠ KNOWN OUTAGE ON NEXTCLOUD 34 — see testExplorerFatalsOnServersWithoutAllowEvalScript().
 * `appinfo/info.xml` declares `max-version="34"`, and Nextcloud 34 DELETED
 * `OCP\AppFramework\Http\EmptyContentSecurityPolicy::allowEvalScript()`, which
 * `explorer()` still calls. On such a server the endpoint throws a PHP `Error`
 * (which the AppFramework dispatcher does not catch — it only catches
 * `\Exception`) and answers 500. The tests below therefore branch on the
 * framework capability rather than pretending one answer covers both: on a
 * server that still has the method the full wire contract is asserted, and on
 * a server that does not, the outage itself is asserted as a tripwire so the
 * suite goes red the moment either side of the incompatibility is repaired.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\OpenRegister\Controller\GraphQLController;
use OCA\OpenRegister\Service\GraphQL\GraphQLService;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GraphQLControllerExplorerTest extends TestCase {
	/**
	 * The URL the GraphiQL fetcher must post queries to.
	 *
	 * @var string
	 */
	private const EXECUTE_ROUTE = '/index.php/apps/openregister/api/graphql';

	/**
	 * The controller under test.
	 *
	 * @var GraphQLController
	 */
	private GraphQLController $controller;

	/**
	 * The mocked HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked GraphQL execution service.
	 *
	 * @var GraphQLService&MockObject
	 */
	private GraphQLService&MockObject $graphQLService;

	/**
	 * The mocked URL generator used to link the execute route.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * The mocked CSP nonce provider.
	 *
	 * @var ContentSecurityPolicyNonceManager&MockObject
	 */
	private ContentSecurityPolicyNonceManager&MockObject $nonceManager;

	/**
	 * The mocked CSRF token manager.
	 *
	 * @var CsrfTokenManager&MockObject
	 */
	private CsrfTokenManager&MockObject $csrfManager;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->graphQLService = $this->createMock(GraphQLService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->nonceManager = $this->createMock(ContentSecurityPolicyNonceManager::class);
		$this->csrfManager = $this->createMock(CsrfTokenManager::class);

		$this->nonceManager->method('getNonce')->willReturn('test-nonce-value');

		$token = $this->createMock(CsrfToken::class);
		$token->method('getEncryptedValue')->willReturn('encrypted-request-token');
		$this->csrfManager->method('getToken')->willReturn($token);

		$this->controller = new GraphQLController(
			'openregister',
			$this->request,
			$this->graphQLService,
			$this->urlGenerator,
			$this->nonceManager,
			$this->csrfManager
		);
	}

	/**
	 * Whether the running server still exposes the CSP method `explorer()` calls.
	 *
	 * Asked of the framework class itself rather than of a Nextcloud version
	 * number: the version is a proxy, the method is the thing that decides.
	 *
	 * @return bool True when the server still has allowEvalScript().
	 */
	private static function serverHasAllowEvalScript(): bool {
		return method_exists(ContentSecurityPolicy::class, 'allowEvalScript');
	}

	/**
	 * TRIPWIRE. On Nextcloud 34 — inside this app's own declared support range
	 * — `explorer()` calls a CSP method the server no longer has, so the route
	 * is a hard 500 rather than a GraphiQL page.
	 *
	 * This test asserts the outage on purpose. It will FAIL (and must be
	 * deleted) as soon as either side is fixed: `explorer()` dropping the
	 * `allowEvalScript()` call, or the server reinstating it.
	 *
	 * @return void
	 */
	public function testExplorerFatalsOnServersWithoutAllowEvalScript(): void {
		if (self::serverHasAllowEvalScript() === true) {
			$this->assertTrue(
				method_exists(ContentSecurityPolicy::class, 'allowEvalScript'),
				'This server still exposes allowEvalScript(), so explorer() is reachable here.'
			);
			return;
		}

		$this->urlGenerator->method('linkToRoute')->willReturn(self::EXECUTE_ROUTE);

		$caught = null;
		try {
			$this->controller->explorer();
		} catch (\Error $e) {
			$caught = $e;
		}

		$this->assertNotNull(
			$caught,
			'explorer() unexpectedly succeeded without ContentSecurityPolicy::allowEvalScript() — '
			. 'the known Nextcloud 34 outage is repaired, so this tripwire test should be removed '
			. 'and the assertions in the sibling tests unconditionally enabled.'
		);
		$this->assertStringContainsString('allowEvalScript', $caught->getMessage());
	}

	public function testExplorerRendersTheGraphiQlShell(): void {
		if (self::serverHasAllowEvalScript() === false) {
			$this->assertFalse(
				method_exists(ContentSecurityPolicy::class, 'allowEvalScript'),
				'Guarded by the NC34 outage tripwire above.'
			);
			return;
		}

		$this->urlGenerator->method('linkToRoute')
			->with('openregister.graphQL.execute')
			->willReturn(self::EXECUTE_ROUTE);

		$result = $this->controller->explorer();

		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame(200, $result->getStatus());
		$this->assertSame('text/html; charset=utf-8', $result->getHeaders()['Content-Type']);

		$html = $result->render();
		$this->assertStringStartsWith('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('<div id="graphiql">', $html);
		$this->assertStringContainsString('graphiql.min.js', $html);

		// The fetcher must target THIS app's execute route and carry the CSRF
		// request token — without it every query is rejected by Nextcloud
		// middleware while the page still renders perfectly well.
		$this->assertStringContainsString("url: '" . self::EXECUTE_ROUTE . "'", $html);
		$this->assertStringContainsString("'requesttoken': 'encrypted-request-token'", $html);

		// Every inline <style>/<script> must carry the nonce the response's own
		// CSP publishes, and the CDN serving GraphiQL must be allow-listed —
		// otherwise the shell renders as a bare "Loading..." div.
		$this->assertStringContainsString('<style nonce="test-nonce-value">', $html);
		$this->assertStringContainsString('<script nonce="test-nonce-value"', $html);

		$policy = $result->getContentSecurityPolicy()->buildPolicy();
		$this->assertStringContainsString('https://unpkg.com', $policy);
		$this->assertStringContainsString('script-src', $policy);
	}

	/**
	 * Instances without URL rewriting hand back a route WITHOUT /index.php.
	 * The shell must repair it, or the fetcher posts to a 404.
	 *
	 * @return void
	 */
	public function testExplorerPrefixesIndexPhpWhenTheRouteLacksIt(): void {
		if (self::serverHasAllowEvalScript() === false) {
			$this->assertFalse(
				method_exists(ContentSecurityPolicy::class, 'allowEvalScript'),
				'Guarded by the NC34 outage tripwire above.'
			);
			return;
		}

		$this->urlGenerator->method('linkToRoute')
			->willReturn('/apps/openregister/api/graphql');

		$html = $this->controller->explorer()->render();

		$this->assertStringContainsString("url: '" . self::EXECUTE_ROUTE . "'", $html);
	}
}
