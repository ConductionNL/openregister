<?php

/**
 * OpenRegister AppHost — Generic Dashboard Controller
 *
 * Engine-owned SPA-serving controller. A leaf app aliases its conventional
 * `OCA\{App}\Controller\DashboardController` at this class (via
 * {@see \OCA\OpenRegister\AppHost\Bootstrap::register()}); the controller then
 * renders the leaf app's own `templates/index.php`, preserving the shared-vendor
 * → shared-nc-vue → main chunk-loading order that the template establishes.
 *
 * The controller's `$appName` is the CALLING (leaf) app id, supplied by the
 * leaf's alias registration closure, so `new TemplateResponse($appName, 'index')`
 * resolves the leaf's own template — never OpenRegister's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Service\PublicPageResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Generic SPA page + catch-all controller for AppHost-adopting apps.
 *
 * Behavioural parity with the bespoke per-app `DashboardController`:
 *   - `page()`   → `TemplateResponse({appId}, 'index')`
 *   - `catchAll()` → delegates to `page()` (Vue history-mode deep links).
 *
 * Auth posture (authenticated user, no CSRF token for the GET page) is owned
 * here and matches every bespoke copy; leaf apps cannot drift it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-1.1
 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
 */
class GenericDashboardController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The calling (leaf) app id, supplied by the alias closure.
	 * @param IRequest $request HTTP request.
	 * @param PublicPageResolver|null $publicPages Decides which paths open without a session.
	 * @param IInitialState|null $initialState The leaf app's initial state, for the public flag.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ?PublicPageResolver $publicPages = null,
		private readonly ?IInitialState $initialState = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Render the main SPA page from the leaf app's `templates/index.php`.
	 *
	 * @return TemplateResponse The rendered template for the calling app.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function page(): TemplateResponse {
		return $this->renderIndex();
	}//end page()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
	 *
	 * @return TemplateResponse The rendered template for the calling app.
	 *
	 * @spec openspec/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function catchAll(): TemplateResponse {
		return $this->page();
	}//end catchAll()

	/**
	 * Serve the SPA to somebody with no account, for a declared public page.
	 *
	 * The route is public, the PAGE is not: this answers the shell only for a
	 * path the app declared public in its own manifest, and the app declares
	 * one by giving the page `config.mode: "public"` under a `/public/` route.
	 * Every other path behaves exactly as before, which is why the catch-all
	 * stays closed: making that one public would open every page in the app to
	 * anybody, and a page reached that way would then call authenticated
	 * endpoints it has no session for.
	 *
	 * What an anonymous visitor receives here is the app's JavaScript and
	 * nothing else. The record behind the page arrives from the endpoint the
	 * page reads, which keeps its own check: an access link, a share token.
	 *
	 * @param string $path The path under `/public/`, without the prefix.
	 *
	 * @return Response The public shell, the ordinary shell, or the login page.
	 *
	 * @spec openspec/changes/public-pages-open-without-a-session/specs/apphost-public-pages/spec.md#requirement-a-page-opens-without-a-session-only-when-the-app-declares-it-public-req-pub-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function publicPage(string $path = ''): Response {
		if ($this->publicPages === null) {
			// Nothing decides what is public here, so nothing is.
			return new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST);
		}

		$wanted = PublicPageResolver::PUBLIC_PREFIX . ltrim($path, '/');
		if ($this->publicPages->isDeclared(appId: $this->appName, path: $wanted) === true) {
			$this->initialState?->provideInitialState(PublicPageResolver::INITIAL_STATE_KEY, true);

			return $this->publicPages->publicShell(appId: $this->appName);
		}

		$answer = $this->publicPages->respond(appId: $this->appName, path: $wanted);
		if ($answer !== null) {
			return $answer;
		}

		return $this->page();
	}//end publicPage()

	/**
	 * Build the `index` TemplateResponse for the calling app.
	 *
	 * Overridable hook: a leaf app needing extra initial-state or a non-default
	 * template name aliases its DashboardController at a local subclass and
	 * overrides this single method — all routing stays generic.
	 *
	 * @return TemplateResponse
	 */
	protected function renderIndex(): TemplateResponse {
		return new TemplateResponse($this->appName, 'index');
	}//end renderIndex()
}//end class
