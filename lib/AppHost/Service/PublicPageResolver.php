<?php

/**
 * OpenRegister AppHost: which SPA pages open without a session.
 *
 * A leaf app's SPA shell is served by `dashboard#page` and `dashboard#catchAll`,
 * and both require a logged-in user. That is right for almost every page, and
 * wrong for the few a citizen opens from a link: a status page, a form, a
 * receipt. Those pages read through a public endpoint of their own, but the
 * shell that would run them never reached an anonymous browser.
 *
 * This class answers one question for the public variant of the shell: did the
 * app DECLARE this path public? The declaration lives in the app's own
 * `src/manifest.json`, as `config.mode: "public"` on the page, which is the flag
 * the manifest schema already defines for an unauthenticated route. A page is
 * public only when it says so and its route sits under `/public/`. Everything
 * else stays behind the login, so a mistyped route or a forgotten flag fails
 * closed.
 *
 * Serving the shell anonymously publishes nothing but the app's JavaScript. The
 * data a public page shows comes from the endpoint it reads, which carries its
 * own capability check (an access link, a share token). This class never
 * widens what any endpoint answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a leaf app declared a path public, and serves the shell.
 *
 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
 */
class PublicPageResolver {

	/**
	 * The initial-state key that tells the SPA it runs as a public page.
	 *
	 * @var string
	 */
	public const INITIAL_STATE_KEY = 'public_page';

	/**
	 * The page `config.mode` value that marks a route unauthenticated.
	 *
	 * @var string
	 */
	public const PUBLIC_MODE = 'public';

	/**
	 * The URL prefix every public page route must sit under.
	 *
	 * @var string
	 */
	public const PUBLIC_PREFIX = '/public/';

	/**
	 * Declared public routes per app, for the life of one request.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $declared = [];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Resolves a leaf app's install path.
	 * @param IUserSession $userSession Tells a signed-in visitor from an anonymous one.
	 * @param IURLGenerator $urlGenerator Builds the login redirect.
	 * @param IRequest $request The current request, for the address to return to.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the app declared this path a public page.
	 *
	 * @param string $appId The leaf app id.
	 * @param string $path The path inside the app, starting with `/public/`.
	 *
	 * @return bool True when a public page's route matches the path.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public function isDeclared(string $appId, string $path): bool {
		if (isset($this->declared[$appId]) === false) {
			$this->declared[$appId] = self::declaredRoutes(manifest: $this->loadManifest(appId: $appId));
		}

		foreach ($this->declared[$appId] as $route) {
			if (self::routeMatches(route: $route, path: $path) === true) {
				return true;
			}
		}

		return false;
	}//end isDeclared()

	/**
	 * What the public variant of the shell answers for this path.
	 *
	 * A declared page gets the public shell, signed in or not, so the page
	 * looks the same to everyone who holds the link. An undeclared path gets
	 * what the ordinary shell would give: the app for a signed-in user (the
	 * caller renders it, so null is returned), and the login page for anybody
	 * else.
	 *
	 * @param string $appId The leaf app id.
	 * @param string $path The path inside the app, starting with `/public/`.
	 *
	 * @return Response|null The response, or null when the caller serves its own shell.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-an-undeclared-path-keeps-the-login-req-pub-002
	 */
	public function respond(string $appId, string $path): ?Response {
		if ($this->isDeclared(appId: $appId, path: $path) === true) {
			return $this->publicShell(appId: $appId);
		}

		if ($this->userSession->isLoggedIn() === true) {
			return null;
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute(
				'core.login.showLoginForm',
				['redirect_url' => $this->request->getRequestUri()]
			)
		);
	}//end respond()

	/**
	 * The app's `index` template in the public layout.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return TemplateResponse The shell.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public function publicShell(string $appId): TemplateResponse {
		$response = new PublicTemplateResponse($appId, 'index');
		$response->setStatus(Http::STATUS_OK);

		return $response;
	}//end publicShell()

	/**
	 * The routes of the pages a manifest declares public.
	 *
	 * A page qualifies when its `config.mode` is `public` AND its route sits
	 * under `/public/`. The second condition is what keeps a mistake contained:
	 * a detail page flagged public by accident still cannot open its
	 * authenticated route without a session.
	 *
	 * @param array<string, mixed> $manifest The decoded manifest.
	 *
	 * @return array<int, string> The declared routes.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public static function declaredRoutes(array $manifest): array {
		$pages = ($manifest['pages'] ?? []);
		if (is_array($pages) === false) {
			return [];
		}

		$routes = [];
		foreach ($pages as $page) {
			if (is_array($page) === false || is_string($page['route'] ?? null) === false) {
				continue;
			}

			$config = ($page['config'] ?? []);
			if (is_array($config) === false || ($config['mode'] ?? null) !== self::PUBLIC_MODE) {
				continue;
			}

			if (str_starts_with($page['route'], self::PUBLIC_PREFIX) === false) {
				continue;
			}

			$routes[] = $page['route'];
		}

		return $routes;
	}//end declaredRoutes()

	/**
	 * Whether a manifest route pattern matches a concrete path.
	 *
	 * Segment by segment: a `:param` segment matches any one non-empty segment,
	 * every other segment must be equal. The counts must agree, so a route
	 * never matches a longer path that merely starts like it.
	 *
	 * @param string $route The manifest route, e.g. `/public/status/:token`.
	 * @param string $path The requested path, e.g. `/public/status/Ab12`.
	 *
	 * @return bool True on a match.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	public static function routeMatches(string $route, string $path): bool {
		$routeParts = explode('/', trim($route, '/'));
		$pathParts = explode('/', trim($path, '/'));
		if (count($routeParts) !== count($pathParts)) {
			return false;
		}

		foreach ($routeParts as $index => $segment) {
			$actual = $pathParts[$index];
			if ($actual === '') {
				return false;
			}

			if (str_starts_with($segment, ':') === true) {
				continue;
			}

			if ($segment !== $actual) {
				return false;
			}
		}

		return true;
	}//end routeMatches()

	/**
	 * The app's bundled manifest, or an empty array.
	 *
	 * An unreadable or invalid manifest declares nothing, so every path stays
	 * behind the login.
	 *
	 * @param string $appId The leaf app id.
	 *
	 * @return array<string, mixed> The decoded manifest.
	 */
	private function loadManifest(string $appId): array {
		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (Throwable $missing) {
			$this->logger->debug(
				'[PublicPageResolver] App path not found for ' . $appId . ': ' . $missing->getMessage()
			);
			return [];
		}

		$file = $appPath . '/src/manifest.json';
		if (is_readable($file) === false) {
			return [];
		}

		$raw = file_get_contents($file);
		if ($raw === false) {
			return [];
		}

		$decoded = json_decode($raw, associative: true);
		if (is_array($decoded) === false) {
			$this->logger->warning('[PublicPageResolver] The manifest of ' . $appId . ' is not valid JSON');
			return [];
		}

		return $decoded;
	}//end loadManifest()
}//end class
