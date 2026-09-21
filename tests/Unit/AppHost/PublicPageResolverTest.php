<?php

/**
 * Unit tests for PublicPageResolver.
 *
 * The claim under test is that a page opens without a session only when the app
 * said so, twice: a public mode AND a route under `/public/`. So every test in
 * here removes one of the two and checks the page stays shut, and the caller
 * used throughout is the one that must be refused, an anonymous visitor.
 *
 * The manifest is written to a real temporary directory rather than mocked,
 * because the fail-closed case is a filesystem fact: an app whose manifest
 * cannot be read declares nothing, and a mock returning an array can never
 * exercise that.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use Error;
use OCA\OpenRegister\AppHost\Service\PublicPageResolver;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the two conditions, the fail-closed manifest and the login redirect.
 */
class PublicPageResolverTest extends TestCase {

	private IAppManager&MockObject $appManager;
	private IUserSession&MockObject $session;
	private IURLGenerator&MockObject $urls;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;
	private string $appRoot = '';

	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appRoot = sys_get_temp_dir() . '/public-page-resolver-' . bin2hex(random_bytes(6));
		mkdir($this->appRoot . '/src', 0o777, true);
		$this->appManager->method('getAppPath')->willReturn($this->appRoot);
		$this->urls->method('linkToRoute')->willReturn('/index.php/login');
		$this->request->method('getRequestUri')->willReturn('/apps/dossiq/public/status/tok');
	}

	protected function tearDown(): void {
		$manifest = $this->appRoot . '/src/manifest.json';
		if (is_file($manifest) === true) {
			unlink($manifest);
		}

		if (is_dir($this->appRoot . '/src') === true) {
			rmdir($this->appRoot . '/src');
			rmdir($this->appRoot);
		}

		parent::tearDown();
	}

	/**
	 * Write a manifest the resolver will read.
	 *
	 * @param string $contents The raw file contents, valid JSON or not.
	 */
	private function manifest(string $contents): void {
		file_put_contents($this->appRoot . '/src/manifest.json', $contents);
	}

	private function resolver(): PublicPageResolver {
		return new PublicPageResolver(
			appManager: $this->appManager,
			userSession: $this->session,
			urlGenerator: $this->urls,
			request: $this->request,
			logger: $this->logger
		);
	}

	// ---- Task 1.2: both conditions, or the page stays shut. ----------------

	public function testADeclaredPublicPageUnderThePublicPrefixIsDeclared(): void {
		$this->manifest((string)json_encode([
			'pages' => [['route' => '/public/status/:token', 'config' => ['mode' => 'public']]],
		]));

		$this->assertTrue($this->resolver()->isDeclared(appId: 'dossiq', path: '/public/status/Ab12'));
	}

	public function testThePublicModeAloneDoesNotDeclareAPageOutsideThePrefix(): void {
		$this->manifest((string)json_encode([
			'pages' => [['route' => '/cases/:id', 'config' => ['mode' => 'public']]],
		]));

		$this->assertFalse($this->resolver()->isDeclared(appId: 'dossiq', path: '/cases/1'));
		$this->assertSame([], PublicPageResolver::declaredRoutes([
			'pages' => [['route' => '/cases/:id', 'config' => ['mode' => 'public']]],
		]));
	}

	public function testThePrefixAloneDoesNotDeclareAPageWithoutThePublicMode(): void {
		$this->manifest((string)json_encode([
			'pages' => [['route' => '/public/report', 'config' => ['mode' => 'authenticated']]],
		]));

		$this->assertFalse($this->resolver()->isDeclared(appId: 'dossiq', path: '/public/report'));
	}

	public function testARouteNeverMatchesALongerPathThatMerelyStartsLikeIt(): void {
		$this->assertFalse(PublicPageResolver::routeMatches('/public/status/:token', '/public/status/Ab12/edit'));
		$this->assertFalse(PublicPageResolver::routeMatches('/public/status/:token', '/public/status'));
	}

	// ---- Task 1.3: an unreadable manifest declares nothing. ----------------

	public function testAManifestThatIsNotValidJsonDeclaresNothing(): void {
		$this->manifest('{ this is not json');

		$this->assertFalse($this->resolver()->isDeclared(appId: 'dossiq', path: '/public/status/Ab12'));
	}

	public function testAMissingManifestDeclaresNothing(): void {
		$this->assertFalse($this->resolver()->isDeclared(appId: 'dossiq', path: '/public/status/Ab12'));
	}

	// ---- Task 1.4: who gets what. ------------------------------------------

	public function testADeclaredPageTakesTheShellBranchAndNeverAsksForASession(): void {
		$this->manifest((string)json_encode([
			'pages' => [['route' => '/public/status/:token', 'config' => ['mode' => 'public']]],
		]));

		// A declared page is served to everybody who holds the link, so the
		// session is never consulted. The redirect branch cannot reach the
		// shell without asking, which is what makes this assertion sharp.
		$this->session->expects($this->never())->method('isLoggedIn');

		try {
			$answer = $this->resolver()->respond(appId: 'dossiq', path: '/public/status/Ab12');
			$this->assertInstanceOf(PublicTemplateResponse::class, $answer);
		} catch (Error $outsideNextcloud) {
			// `PublicTemplateResponse` loads scripts through `OCP\\Util`, which
			// needs a running Nextcloud that a unit test does not have. Getting
			// as far as that failure is the proof the shell branch was taken;
			// the login redirect never constructs one. The shell itself is
			// asserted over real HTTP in tests/e2e/ci/public-pages.spec.ts.
			$this->assertStringContainsString('AppScriptDependency', $outsideNextcloud->getMessage());
		}
	}

	public function testAnUndeclaredPathSendsACallerWithNoSessionToTheLogin(): void {
		$this->manifest((string)json_encode([
			'pages' => [['route' => '/public/status/:token', 'config' => ['mode' => 'public']]],
		]));
		$this->session->method('isLoggedIn')->willReturn(false);

		$answer = $this->resolver()->respond(appId: 'dossiq', path: '/public/cases/1');

		$this->assertInstanceOf(RedirectResponse::class, $answer);
	}

	public function testAnUndeclaredPathLeavesTheOrdinaryShellToASignedInCaller(): void {
		$this->manifest((string)json_encode(['pages' => []]));
		$this->session->method('isLoggedIn')->willReturn(true);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->assertNull($this->resolver()->respond(appId: 'dossiq', path: '/public/cases/1'));
	}
}//end class
