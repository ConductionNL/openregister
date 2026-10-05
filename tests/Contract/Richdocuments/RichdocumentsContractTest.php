<?php

/**
 * What OpenRegister's Office path relies on in richdocuments.
 *
 * OfficeSessionService (openregister#4316) issues Nextcloud Office sessions
 * through richdocuments classes that are not Nextcloud's public API:
 * `TokenManager`, `PermissionManager`, `Db\WopiMapper`, `Db\Wopi` and the
 * WOPI endpoints of `Controller\WopiController`. A richdocuments release can
 * change any of them without notice, and OpenRegister then answers 409 (a
 * missing class or method) or, worse, issues a token that means something
 * else. This suite runs against a real richdocuments tree and goes red on
 * the first such change, before an instance updates.
 *
 * Each test names the line of OfficeSessionService that depends on it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Contract\Richdocuments
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Contract\Richdocuments;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * The richdocuments contract of OpenRegister's Office path.
 */
class RichdocumentsContractTest extends TestCase {

	private const TOKEN_MANAGER = 'OCA\\Richdocuments\\TokenManager';
	private const PERMISSION_MANAGER = 'OCA\\Richdocuments\\PermissionManager';
	private const WOPI_MAPPER = 'OCA\\Richdocuments\\Db\\WopiMapper';
	private const WOPI = 'OCA\\Richdocuments\\Db\\Wopi';
	private const WOPI_CONTROLLER = 'OCA\\Richdocuments\\Controller\\WopiController';
	private const APP_CONFIG = 'OCA\\Richdocuments\\AppConfig';
	private const HELPER = 'OCA\\Richdocuments\\Helper';

	/**
	 * Print which richdocuments the suite runs against, so a red run names it.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$info = simplexml_load_file(RICHDOCUMENTS_CONTRACT_PATH . '/appinfo/info.xml');
		$version = ($info !== false) ? (string)$info->version : 'unknown';
		fwrite(STDERR, 'richdocuments contract against version ' . $version . ' at ' . RICHDOCUMENTS_CONTRACT_PATH . "\n");
	}//end setUpBeforeClass()

	/**
	 * OfficeSessionService::richdocuments() resolves these through the container.
	 *
	 * @return void
	 */
	public function testTheClassesOpenRegisterResolvesExist(): void {
		foreach ([self::TOKEN_MANAGER, self::PERMISSION_MANAGER, self::WOPI_MAPPER, self::WOPI, self::WOPI_CONTROLLER] as $class) {
			$this->assertTrue(class_exists($class), $class . ' is gone: OpenRegister answers 409 on every Office open.');
		}
	}//end testTheClassesOpenRegisterResolvesExist()

	/**
	 * `$tokenManager->getUrlSrcForMimeType($file->getMimeType())`, null or '' means 415.
	 *
	 * @return void
	 */
	public function testTokenManagerGivesTheOfficeAddressForAMimeType(): void {
		$method = $this->publicMethod(class: self::TOKEN_MANAGER, name: 'getUrlSrcForMimeType');

		$this->assertCallableWith(method: $method, arguments: 1);
		$this->assertAcceptsString(method: $method, position: 0);
		$this->assertReturnAllows(method: $method, types: ['string', 'null']);
	}//end testTokenManagerGivesTheOfficeAddressForAMimeType()

	/**
	 * `isEnabledForUser($editorUid)` refuses with 403; `userCanEdit($editorUid)` narrows the write flag.
	 *
	 * @return void
	 */
	public function testPermissionManagerAnswersForOnePerson(): void {
		foreach (['isEnabledForUser', 'userCanEdit'] as $name) {
			$method = $this->publicMethod(class: self::PERMISSION_MANAGER, name: $name);

			$this->assertCallableWith(method: $method, arguments: 1);
			$this->assertAcceptsString(method: $method, position: 0);
			$this->assertReturnAllows(method: $method, types: ['bool']);
		}
	}//end testPermissionManagerAnswersForOnePerson()

	/**
	 * OpenRegister calls generateFileToken with seven POSITIONAL arguments.
	 *
	 * A reorder would silently make the person the owner, or the owner the
	 * editor, so the names stand in for the meaning of each position.
	 *
	 * @return void
	 */
	public function testGenerateFileTokenTakesTheArgumentsInOpenRegistersOrder(): void {
		$method = $this->publicMethod(class: self::WOPI_MAPPER, name: 'generateFileToken');

		$names = array_map(static fn ($param): string => $param->getName(), array_slice($method->getParameters(), 0, 7));
		$this->assertSame(
			['fileId', 'owner', 'editor', 'version', 'updatable', 'serverHost', 'guestDisplayname'],
			$names,
			'generateFileToken() changed its leading parameters; OpenRegister passes them by position.'
		);
		$this->assertCallableWith(method: $method, arguments: 7);
	}//end testGenerateFileTokenTakesTheArgumentsInOpenRegistersOrder()

	/**
	 * The token OpenRegister issues: owner openregister, editor the person, a display name.
	 *
	 * A display name makes it a guest-type token, and for those richdocuments
	 * reads the file through the owner's home. That is the whole reason the
	 * file, which lives in the openregister account, opens for the person.
	 *
	 * @return void
	 */
	public function testAFileTokenWithADisplayNameReadsTheFileThroughTheOwner(): void {
		$wopi = $this->issueToken(canWrite: true);

		$this->assertSame('openregister', $wopi->getOwnerUid());
		$this->assertSame('behandelaar', $wopi->getEditorUid());
		$this->assertTrue((bool)$wopi->getCanwrite());
		$this->assertSame(constant(self::WOPI . '::TOKEN_TYPE_GUEST'), $wopi->getTokenType());
		$this->assertTrue($wopi->isGuest());
		$this->assertSame('openregister', $wopi->getUserForFileAccess(), 'The file is no longer read through the token owner.');
		$this->assertSame('Behandelaar', $wopi->getGuestDisplayname());
	}//end testAFileTokenWithADisplayNameReadsTheFileThroughTheOwner()

	/**
	 * `(string)$wopi->getToken()` and `(int)$wopi->getExpiry() * 1000` feed the frame.
	 *
	 * @return void
	 */
	public function testTheTokenCarriesAValueAndAnExpiry(): void {
		$wopi = $this->issueToken(canWrite: true);

		$this->assertIsString($wopi->getToken());
		$this->assertNotSame('', $wopi->getToken());
		$this->assertSame(1_700_000_000 + 3600, (int)$wopi->getExpiry());
	}//end testTheTokenCarriesAValueAndAnExpiry()

	/**
	 * A reader's token is made with `$writable === false`.
	 *
	 * @return void
	 */
	public function testAReadersTokenCannotWrite(): void {
		$wopi = $this->issueToken(canWrite: false);

		$this->assertFalse((bool)$wopi->getCanwrite());
	}//end testAReadersTokenCannotWrite()

	/**
	 * OfficeSessionService::wopiSrc() builds `/index.php/apps/richdocuments/wopi/files/{fileId}_{instanceId}`.
	 *
	 * @return void
	 */
	public function testTheWopiEndpointsCollaboraCallsAreWhereOpenRegisterPoints(): void {
		$routes = [
			'checkFileInfo' => ['GET', 'wopi/files/{fileId}'],
			'getFile' => ['GET', 'wopi/files/{fileId}/contents'],
			'putFile' => ['POST', 'wopi/files/{fileId}/contents'],
		];

		foreach ($routes as $name => [$verb, $url]) {
			$method = $this->publicMethod(class: self::WOPI_CONTROLLER, name: $name);
			$this->assertContains(
				$verb . ' ' . $url,
				$this->routesOf(method: $method),
				'WopiController::' . $name . '() is no longer routed at ' . $verb . ' ' . $url . '.'
			);
		}

		$parsed = call_user_func([self::HELPER, 'parseFileId'], '77_ocinst');
		$this->assertSame('77', (string)$parsed[0], 'richdocuments no longer reads {fileId}_{instanceId}.');
		$this->assertSame('ocinst', (string)$parsed[1]);
	}//end testTheWopiEndpointsCollaboraCallsAreWhereOpenRegisterPoints()

	/**
	 * PutFile refuses a token without canwrite and saves as the editor.
	 *
	 * This reads the method's source, so a refactor that moves either line
	 * into a helper also turns it red. That is wanted: a person then checks
	 * that a reader still cannot save and that a version still names the
	 * person, and moves the needle here.
	 *
	 * @return void
	 */
	public function testPutFileRefusesAReaderAndSavesAsTheEditor(): void {
		$source = $this->sourceOf(method: $this->publicMethod(class: self::WOPI_CONTROLLER, name: 'putFile'));

		$this->assertStringContainsString('getCanwrite()', $source, 'putFile() no longer checks canwrite: a read-only token may save.');
		$this->assertMatchesRegularExpression(
			'/setUserScope\(\s*\$wopi->getEditorUid\(\)\s*\)/',
			$source,
			'putFile() no longer saves under the editor: versions would name the openregister account.'
		);
	}//end testPutFileRefusesAReaderAndSavesAsTheEditor()

	/**
	 * Make a token the way OfficeSessionService does, without a database.
	 *
	 * The real generateFileToken() runs with its real constructor; only
	 * QBMapper::insert() is replaced, returning the entity it was handed.
	 *
	 * @param bool $canWrite The write flag OpenRegister passes.
	 *
	 * @return object The Wopi entity.
	 */
	private function issueToken(bool $canWrite): object {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('contracttoken0123456789abcdefghij');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_700_000_000);
		$appConfig = $this->getMockBuilder(self::APP_CONFIG)->disableOriginalConstructor()->getMock();
		$appConfig->method('getAppValue')->willReturn('3600');

		$mapper = $this->getMockBuilder(self::WOPI_MAPPER)
			->setConstructorArgs([
				$this->createMock(IDBConnection::class),
				$random,
				$this->createMock(LoggerInterface::class),
				$time,
				$appConfig,
			])
			->onlyMethods(['insert'])
			->getMock();
		$mapper->method('insert')->willReturnArgument(0);

		return $mapper->generateFileToken('77', 'openregister', 'behandelaar', '0', $canWrite, 'https://nc.test/', 'Behandelaar');
	}//end issueToken()

	/**
	 * A public method, or a failure naming it.
	 *
	 * @param string $class The class.
	 * @param string $name  The method.
	 *
	 * @return ReflectionMethod The method.
	 */
	private function publicMethod(string $class, string $name): ReflectionMethod {
		$this->assertTrue(class_exists($class), $class . ' is gone.');
		$reflection = new ReflectionClass($class);
		$this->assertTrue($reflection->hasMethod($name), $class . '::' . $name . '() is gone.');
		$method = $reflection->getMethod($name);
		$this->assertTrue($method->isPublic(), $class . '::' . $name . '() is no longer public.');
		$this->assertFalse($method->isStatic(), $class . '::' . $name . '() became static.');

		return $method;
	}//end publicMethod()

	/**
	 * The method takes this many arguments: no more required, at least this many accepted.
	 *
	 * @param ReflectionMethod $method    The method.
	 * @param int              $arguments The argument count OpenRegister passes.
	 *
	 * @return void
	 */
	private function assertCallableWith(ReflectionMethod $method, int $arguments): void {
		$label = $method->class . '::' . $method->getName() . '()';
		$this->assertLessThanOrEqual($arguments, $method->getNumberOfRequiredParameters(), $label . ' now requires more arguments than OpenRegister passes.');
		$this->assertTrue(
			$method->isVariadic() === true || $method->getNumberOfParameters() >= $arguments,
			$label . ' takes fewer arguments than OpenRegister passes.'
		);
	}//end assertCallableWith()

	/**
	 * The parameter at this position accepts a string.
	 *
	 * @param ReflectionMethod $method   The method.
	 * @param int              $position The parameter position.
	 *
	 * @return void
	 */
	private function assertAcceptsString(ReflectionMethod $method, int $position): void {
		$type = $method->getParameters()[$position]->getType();
		if ($type === null) {
			return;
		}

		$this->assertContains(
			'string',
			$this->typeNames(type: $type),
			$method->class . '::' . $method->getName() . '() no longer takes a string at position ' . $position . '.'
		);
	}//end assertAcceptsString()

	/**
	 * The declared return type, when there is one, is within these.
	 *
	 * @param ReflectionMethod $method The method.
	 * @param array<string>    $types  The return types OpenRegister handles.
	 *
	 * @return void
	 */
	private function assertReturnAllows(ReflectionMethod $method, array $types): void {
		$type = $method->getReturnType();
		if ($type === null) {
			return;
		}

		foreach ($this->typeNames(type: $type) as $name) {
			$this->assertContains(
				$name,
				$types,
				$method->class . '::' . $method->getName() . '() may now return ' . $name . ', which OpenRegister does not handle.'
			);
		}
	}//end assertReturnAllows()

	/**
	 * The type names of a reflected type, nullability included.
	 *
	 * @param \ReflectionType $type The type.
	 *
	 * @return array<string> The names.
	 */
	private function typeNames(\ReflectionType $type): array {
		$names = [];
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $member) {
				$names = array_merge($names, $this->typeNames(type: $member));
			}

			return $names;
		}

		if ($type instanceof ReflectionNamedType) {
			$names[] = $type->getName();
			if ($type->allowsNull() === true && $type->getName() !== 'null' && $type->getName() !== 'mixed') {
				$names[] = 'null';
			}
		}

		return $names;
	}//end typeNames()

	/**
	 * The routes declared on a controller method, as "VERB url".
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return array<string> The routes.
	 */
	private function routesOf(ReflectionMethod $method): array {
		$routes = [];
		foreach ($method->getAttributes() as $attribute) {
			if (str_ends_with($attribute->getName(), 'Route') === false) {
				continue;
			}

			$arguments = $attribute->getArguments();
			$verb = (string)($arguments['verb'] ?? ($arguments[0] ?? ''));
			$url = (string)($arguments['url'] ?? ($arguments[1] ?? ''));
			$routes[] = strtoupper($verb) . ' ' . ltrim($url, '/');
		}

		return $routes;
	}//end routesOf()

	/**
	 * The source text of a method.
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return string The source.
	 */
	private function sourceOf(ReflectionMethod $method): string {
		$file = $method->getFileName();
		$this->assertIsString($file);
		$lines = file($file);
		$this->assertIsArray($lines);

		return implode('', array_slice($lines, ($method->getStartLine() - 1), ($method->getEndLine() - $method->getStartLine() + 1)));
	}//end sourceOf()
}//end class
