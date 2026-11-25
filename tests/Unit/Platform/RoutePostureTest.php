<?php

/**
 * Every route's access posture, read the way Nextcloud reads it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-001
 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-004
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Platform;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Sign-in stays Nextcloud's: every routed method has a posture Nextcloud enforces, and every public one says what guards it.
 *
 * A route is public only through `#[PublicPage]` (or a `@PublicPage` annotation where Nextcloud
 * reads one: at the start of a docblock line). A method with no access marker is administrator-only
 * by Nextcloud's own middleware, which is where `allowed_admin_ranges` applies (REQ-PPC-005).
 * The public list below names, per method, the check that stands in for the session, so a new
 * `#[PublicPage]` cannot land without someone saying what protects it.
 *
 * Reads files and reflects classes; executes no app code.
 *
 * @coversNothing
 */
final class RoutePostureTest extends TestCase {

	/**
	 * A share, access or feed token in the URL, refused when unknown, expired or revoked.
	 *
	 * @var string
	 */
	private const TOKEN = 'a token in the URL, refused when unknown, expired or revoked';

	/**
	 * A server-to-server assertion the method verifies before it acts.
	 *
	 * @var string
	 */
	private const SIGNATURE = 'a signed assertion of the acting subject, verified before the action';

	/**
	 * The object rule: the schema's authorization decides what an anonymous caller may do.
	 *
	 * @var string
	 */
	private const OBJECT_RULE = "the schema's authorization (the public group) through the object rule";

	/**
	 * A file served anonymously only when it is published.
	 *
	 * @var string
	 */
	private const PUBLISHED_FILE = 'the published check on the file for an anonymous caller';

	/**
	 * A form that answers only while it is published.
	 *
	 * @var string
	 */
	private const PUBLISHED_FORM = "the form's published state";

	/**
	 * A journey that answers only while it is published, refuses an anonymous
	 * caller when its access asks for a sign-in or a trust level, and resumes
	 * a saved anonymous run only with that run's resume token.
	 *
	 * @var string
	 */
	private const PUBLISHED_JOURNEY = "the journey's published state and access level, and a saved run's resume token";

	/**
	 * A public document carrying no per-user data.
	 *
	 * @var string
	 */
	private const PUBLIC_DOCUMENT = 'a public document with no per-user data';

	/**
	 * The method reads the session itself and refuses or no-ops without one.
	 *
	 * @var string
	 */
	private const SESSION = 'the session, read by the method itself';

	/**
	 * The password, and the second factor when one is enabled.
	 *
	 * @var string
	 */
	private const CREDENTIALS = 'the password, and the second factor when one is enabled';

	/**
	 * The OAuth state issued when the connection was started.
	 *
	 * @var string
	 */
	private const OAUTH_STATE = 'the OAuth state issued when the connection was started';

	/**
	 * The metrics access guard: an administrator session, the scraper token or an allowed address.
	 *
	 * @var string
	 */
	private const METRICS_ACCESS = 'the metrics access guard: an administrator, the scraper bearer token or an allowed address, nobody without a guard';

	/**
	 * Every public routed method, relative to `OCA\OpenRegister\`, with what it relies on instead of a session.
	 *
	 * @var array<string, string>
	 */
	private const PUBLIC_METHODS = [
		'AppHost\\Controller\\GenericHealthController::index' => self::PUBLIC_DOCUMENT,
		'AppHost\\Controller\\GenericMetricsController::index' => self::METRICS_ACCESS,
		'AppHost\\Controller\\GenericReadinessController::index' => self::PUBLIC_DOCUMENT,
		'Controller\\AccessLinkController::comment' => self::TOKEN,
		'Controller\\AccessLinkController::open' => self::TOKEN,
		'Controller\\AccessLinkController::upload' => self::TOKEN,
		'Controller\\AccessLinkJourneyController::show' => self::TOKEN,
		'Controller\\AccessLinkJourneyController::submit' => self::TOKEN,
		'Controller\\AccessLinkPageController::show' => self::TOKEN,
		'Controller\\ApiSurfaceController::capabilities' => self::PUBLIC_DOCUMENT,
		'Controller\\ApiSurfaceController::versions' => self::PUBLIC_DOCUMENT,
		'Controller\\BagAddressController::index' => self::PUBLIC_DOCUMENT,
		'Controller\\CalendarFeedController::feed' => self::TOKEN,
		'Controller\\CaseTokenController::resolve' => self::TOKEN,
		'Controller\\ChatHealthController::health' => self::PUBLIC_DOCUMENT,
		'Controller\\ContextsController::register' => self::PUBLIC_DOCUMENT,
		'Controller\\ContextsController::schema' => self::PUBLIC_DOCUMENT,
		'Controller\\CredentialOauth2Controller::callback' => self::OAUTH_STATE,
		'Controller\\CredentialOauth2Controller::clientMetadata' => self::PUBLIC_DOCUMENT,
		'Controller\\FederationController::createObject' => self::TOKEN,
		'Controller\\FederationController::deleteObject' => self::TOKEN,
		'Controller\\FederationController::meta' => self::TOKEN,
		'Controller\\FederationController::object' => self::TOKEN,
		'Controller\\FederationController::objects' => self::TOKEN,
		'Controller\\FederationController::updateObject' => self::TOKEN,
		'Controller\\FormsController::submit' => self::PUBLISHED_FORM,
		'Controller\\FormsController::upload' => self::PUBLISHED_FORM,
		'Controller\\IconController::mdi' => self::PUBLIC_DOCUMENT,
		'Controller\\JourneyRunController::answer' => self::PUBLISHED_JOURNEY,
		'Controller\\JourneyRunController::resume' => self::PUBLISHED_JOURNEY,
		'Controller\\JourneyRunController::save' => self::PUBLISHED_JOURNEY,
		'Controller\\JourneyRunController::start' => self::PUBLISHED_JOURNEY,
		'Controller\\ManifestController::index' => self::PUBLIC_DOCUMENT,
		'Controller\\McpController::discover' => self::PUBLIC_DOCUMENT,
		'Controller\\OasController::conformance' => self::PUBLIC_DOCUMENT,
		'Controller\\OasController::conformanceAll' => self::PUBLIC_DOCUMENT,
		'Controller\\OasController::generate' => self::PUBLIC_DOCUMENT,
		'Controller\\OasController::generateAll' => self::PUBLIC_DOCUMENT,
		'Controller\\ObjectExportController::export' => self::OBJECT_RULE,
		'Controller\\ObjectShareLinkController::show' => self::TOKEN,
		'Controller\\ObjectsController::create' => self::OBJECT_RULE,
		'Controller\\ObjectsController::destroy' => self::OBJECT_RULE,
		'Controller\\ObjectsController::destroyByKey' => self::OBJECT_RULE,
		'Controller\\ObjectsController::geoSearch' => self::OBJECT_RULE,
		'Controller\\ObjectsController::index' => self::OBJECT_RULE,
		'Controller\\ObjectsController::objects' => self::OBJECT_RULE,
		'Controller\\ObjectsController::patch' => self::OBJECT_RULE,
		'Controller\\ObjectsController::patchByKey' => self::OBJECT_RULE,
		'Controller\\ObjectsController::postPatch' => self::OBJECT_RULE,
		'Controller\\ObjectsController::show' => self::OBJECT_RULE,
		'Controller\\ObjectsController::showByKey' => self::OBJECT_RULE,
		'Controller\\ObjectsController::update' => self::OBJECT_RULE,
		'Controller\\ObjectsController::updateByKey' => self::OBJECT_RULE,
		'Controller\\OpenApiController::show' => self::PUBLIC_DOCUMENT,
		'Controller\\PortalTaskController::complete' => self::SIGNATURE,
		'Controller\\PortalTaskController::index' => self::SIGNATURE,
		'Controller\\PortalTaskController::show' => self::SIGNATURE,
		'Controller\\PublicFormPageController::show' => self::PUBLISHED_FORM,
		'Controller\\RegistersController::index' => self::PUBLIC_DOCUMENT,
		'Controller\\RegistersController::show' => self::PUBLIC_DOCUMENT,
		'Controller\\SchemasController::index' => self::PUBLIC_DOCUMENT,
		'Controller\\SchemasController::resolveByImplements' => self::PUBLIC_DOCUMENT,
		'Controller\\SchemasController::show' => self::PUBLIC_DOCUMENT,
		'Controller\\SurveyAnswerController::show' => self::TOKEN,
		'Controller\\SurveyAnswerController::submit' => self::TOKEN,
		'Controller\\TagsController::getAllTags' => self::SESSION,
		'Controller\\UserController::login' => self::CREDENTIALS,
		'Controller\\UserController::logout' => self::SESSION,
		'Controller\\VocabularyController::listConcepts' => self::PUBLIC_DOCUMENT,
		'Controller\\VocabularyController::propertyOptions' => self::PUBLIC_DOCUMENT,
		'Controller\\VocabularyController::resolveByNotation' => self::PUBLIC_DOCUMENT,
		'Controller\\VocabularyController::resolveByUri' => self::PUBLIC_DOCUMENT,
		'Controller\\WellKnownController::index' => self::PUBLIC_DOCUMENT,
		'Controller\\WellKnownController::securityTxt' => self::PUBLIC_DOCUMENT,
		'Files\\Controller\\FilesController::batch' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::copy' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::create' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::createMultipart' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::delete' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::downloadById' => self::PUBLISHED_FILE,
		'Files\\Controller\\FilesController::index' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::listVersions' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::lock' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::move' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::preview' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::rename' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::restoreVersion' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::save' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::show' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::unlock' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::update' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::updateLabels' => self::OBJECT_RULE,
		'Files\\Controller\\FilesController::verifyDigest' => self::PUBLISHED_FILE,
		'Notifications\\Controller\\WebPushController::hexBadge' => self::PUBLIC_DOCUMENT,
		'Notifications\\Controller\\WebPushController::hexIcon' => self::PUBLIC_DOCUMENT,
		'Search\\Controller\\GraphQLController::execute' => self::OBJECT_RULE,
	];

	/**
	 * The one controller method allowed to put a user into the session.
	 *
	 * @var string
	 */
	private const SESSION_WRITER = 'lib/Controller/UserController.php';

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every declared route as name, URL and verb, the resource routes expanded the way Nextcloud expands them.
	 *
	 * @return list<array{name: string, url: string, verb: string}>
	 */
	private function routes(): array {
		$declared = require $this->root() . '/appinfo/routes.php';
		$routes   = [];
		foreach (($declared['routes'] ?? []) as $route) {
			$routes[] = ['name' => $route['name'], 'url' => $route['url'], 'verb' => ($route['verb'] ?? 'GET')];
		}

		$actions = ['index' => 'GET', 'show' => 'GET', 'create' => 'POST', 'update' => 'PUT', 'destroy' => 'DELETE'];
		foreach (($declared['resources'] ?? []) as $resource => $definition) {
			foreach ($actions as $action => $verb) {
				$routes[] = ['name' => lcfirst((string) $resource) . '#' . $action, 'url' => $definition['url'], 'verb' => $verb];
			}
		}

		return $routes;
	}//end routes()

	/**
	 * The controller class a route name reaches: Nextcloud's naming, then the area alias map.
	 *
	 * @param string $controller The part of the route name before `#`.
	 *
	 * @return string The class name.
	 */
	public static function controllerClass(string $controller): string {
		$short = str_replace(' ', '', ucwords(str_replace('_', ' ', ucfirst($controller)))) . 'Controller';
		$class = 'OCA\\OpenRegister\\Controller\\' . $short;
		if (str_contains($short, '\\Controller\\') === true) {
			$class = 'OCA\\OpenRegister\\' . $short;
		}

		static $aliases = null;
		if ($aliases === null) {
			$aliases = [];
			foreach (ClassAliasMap::fromFile()->entries() as $alias => $entry) {
				$aliases[$alias] = $entry['class'];
			}
		}

		return ($aliases[$class] ?? $class);
	}//end controllerClass()

	/**
	 * The posture Nextcloud's security middleware gives a method: public, signed-in or administrator.
	 *
	 * Attributes count on the method only. An annotation counts only at the start of a docblock
	 * line, which is the only place Nextcloud's reflector reads one; a marker mentioned inside a
	 * sentence is prose.
	 *
	 * @param ReflectionMethod $method The controller method.
	 *
	 * @return string One of `public`, `signed-in` or `administrator`.
	 */
	public static function posture(ReflectionMethod $method): string {
		$markers = [];
		foreach ($method->getAttributes() as $attribute) {
			$markers[] = (new ReflectionClass($attribute->getName()))->getShortName();
		}

		preg_match_all('/^\h+\*\h+@(?P<annotation>[A-Z]\w+)/m', (string) $method->getDocComment(), $found);
		$markers = array_merge($markers, $found['annotation']);

		if (in_array('PublicPage', $markers, true) === true) {
			return 'public';
		}

		if (in_array('NoAdminRequired', $markers, true) === true) {
			return 'signed-in';
		}

		return 'administrator';
	}//end posture()

	/**
	 * The routed methods, keyed `Class::method` relative to `OCA\OpenRegister\`.
	 *
	 * @return array<string, ReflectionMethod>
	 */
	private function routedMethods(): array {
		$methods = [];
		foreach ($this->routes() as $route) {
			[$controller, $action] = explode('#', $route['name']);
			$class = self::controllerClass(controller: $controller);
			if (class_exists($class) === false || method_exists($class, $action) === false) {
				continue;
			}

			$key = substr($class, strlen('OCA\\OpenRegister\\')) . '::' . $action;
			$methods[$key] = new ReflectionMethod($class, $action);
		}

		return $methods;
	}//end routedMethods()

	/**
	 * Every route reaches a public method of an existing controller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-001
	 */
	public function testEveryRouteReachesAPublicControllerMethod(): void {
		$unreachable = [];
		foreach ($this->routes() as $route) {
			[$controller, $action] = explode('#', $route['name']);
			$class = self::controllerClass(controller: $controller);
			if (class_exists($class) === false || method_exists($class, $action) === false
				|| (new ReflectionMethod($class, $action))->isPublic() === false
			) {
				$unreachable[] = $route['verb'] . ' ' . $route['url'] . ' (' . $route['name'] . ')';
			}
		}

		$this->assertSame([], array_values(array_unique($unreachable)), 'These routes reach no public controller method.');
	}//end testEveryRouteReachesAPublicControllerMethod()

	/**
	 * Every public routed method is listed with what it relies on instead of a session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-004
	 */
	public function testEveryPublicMethodNamesWhatGuardsIt(): void {
		$unlisted = [];
		foreach ($this->routedMethods() as $key => $method) {
			if (self::posture(method: $method) === 'public' && isset(self::PUBLIC_METHODS[$key]) === false) {
				$unlisted[] = $key;
			}
		}

		$this->assertSame(
			[],
			$unlisted,
			'A #[PublicPage] route opens without a Nextcloud session. Add it to PUBLIC_METHODS with the token, signature or published check it relies on.'
		);
	}//end testEveryPublicMethodNamesWhatGuardsIt()

	/**
	 * Every listed method is still routed and still public, so the list cannot rot into a blanket exemption.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-004
	 */
	public function testThePublicListHasNoStaleEntry(): void {
		$routed = $this->routedMethods();
		$stale  = [];
		foreach (array_keys(self::PUBLIC_METHODS) as $key) {
			if (isset($routed[$key]) === false || self::posture(method: $routed[$key]) !== 'public') {
				$stale[] = $key;
			}
		}

		$this->assertSame([], $stale, 'These PUBLIC_METHODS entries are no longer public routes; remove them.');
	}//end testThePublicListHasNoStaleEntry()

	/**
	 * Routed methods without a public or signed-in marker are left to Nextcloud's administrator check.
	 *
	 * Pins the split so a change that quietly opened the administrator routes shows up as a count
	 * moving the wrong way in review, and proves the reader sees all three postures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testEveryRoutedMethodHasAPostureNextcloudEnforces(): void {
		$counts = ['public' => 0, 'signed-in' => 0, 'administrator' => 0];
		foreach ($this->routedMethods() as $method) {
			$counts[self::posture(method: $method)]++;
		}

		$this->assertSame(['public', 'signed-in', 'administrator'], array_keys($counts));
		$this->assertSame(count(self::PUBLIC_METHODS), $counts['public']);
		$this->assertGreaterThan(0, $counts['signed-in']);
		$this->assertGreaterThan(0, $counts['administrator']);
	}//end testEveryRoutedMethodHasAPostureNextcloudEnforces()

	/**
	 * The reader follows Nextcloud: attributes and line-start annotations count, prose does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-005
	 */
	public function testThePostureReaderReadsMarkersTheWayNextcloudDoes(): void {
		$fixture = new ReflectionClass(RoutePostureFixture::class);

		$this->assertSame('public', self::posture(method: $fixture->getMethod('byAttribute')));
		$this->assertSame('public', self::posture(method: $fixture->getMethod('byAnnotation')));
		$this->assertSame('signed-in', self::posture(method: $fixture->getMethod('signedIn')));
		$this->assertSame('administrator', self::posture(method: $fixture->getMethod('mentionedInProse')));
		$this->assertSame('administrator', self::posture(method: $fixture->getMethod('unmarked')));
	}//end testThePostureReaderReadsMarkersTheWayNextcloudDoes()

	/**
	 * Only the login route puts a user into the session, so no other route can skip a second factor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-provided-capabilities/specs/platform-provided-capabilities/spec.md#REQ-PPC-004
	 */
	public function testOnlyTheLoginRouteCreatesASession(): void {
		$writers  = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/lib'));
		foreach ($iterator as $file) {
			$path = $file->getPathname();
			if ($file->isFile() === false || str_ends_with($path, '.php') === false || str_contains($path, '/Controller/') === false) {
				continue;
			}

			$source = (string) file_get_contents($path);
			if (preg_match('/[Ss]ession->(setUser\(\s*\$|login\(|completeLogin\(|createSessionToken\()/', $source) === 1) {
				$writers[] = substr($path, strlen($this->root()) + 1);
			}
		}

		$this->assertSame([self::SESSION_WRITER], $writers);
	}//end testOnlyTheLoginRouteCreatesASession()
}//end class

/**
 * Methods marked each way, for the posture reader's own test.
 *
 * @SuppressWarnings(PHPMD)
 */
final class RoutePostureFixture {

	/**
	 * Public by attribute.
	 *
	 * @return void
	 */
	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	public function byAttribute(): void {
	}//end byAttribute()

	/**
	 * Public by annotation.
	 *
	 * @PublicPage
	 *
	 * @return void
	 */
	public function byAnnotation(): void {
	}//end byAnnotation()

	/**
	 * Signed-in by attribute.
	 *
	 * @return void
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function signedIn(): void {
	}//end signedIn()

	/**
	 * Administrator-only: there is no @NoAdminRequired here, and the @PublicPage in this sentence is prose.
	 *
	 * @return void
	 */
	public function mentionedInProse(): void {
	}//end mentionedInProse()

	/**
	 * No marker at all.
	 *
	 * @return void
	 */
	public function unmarked(): void {
	}//end unmarked()
}//end class
