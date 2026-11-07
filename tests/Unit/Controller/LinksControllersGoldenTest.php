<?php

/**
 * Golden test for the object link controllers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use OCA\OpenRegister\Tests\Unit\Architecture\ControllerSources;

/**
 * Records every public action of every *LinksController under a fixed set of
 * scenarios (app unavailable, object missing or not found, a service failure
 * with several codes, success with filled and with empty request parameters)
 * and compares the status and body with the recorded golden, case by case.
 *
 * Set OPENREGISTER_GOLDEN_RECORD=1 to re-record the fixture.
 */
class LinksControllersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/links-controllers-golden.json';

	/**
	 * Controllers whose actions read service results these doubles cannot shape (activity feed).
	 */
	private const NOT_LINK_ACTIONS = ['ActivityLinksController'];

	private const SCENARIOS = ['unavailable', 'missing', 'notfound', 'boom-0', 'boom-404', 'boom-409', 'boom-503', 'ok', 'ok-empty'];

	/**
	 * The recorded outcome of one action in one scenario.
	 *
	 * @param string $controller The controller class.
	 * @param string $action     The action name.
	 * @param string $scenario   The scenario.
	 *
	 * @return string The status and JSON body.
	 */
	private function outcome(string $controller, string $action, string $scenario): string {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => $scenario === 'ok-empty' ? '' : '7'
		);
		$request->method('getParams')->willReturn($scenario === 'ok-empty' ? [] : ['name' => 'n', 'url' => 'https://x.example']);

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setRegister('3');
		$object->setSchema('4');

		$args = [];
		foreach ((new ReflectionClass($controller))->getConstructor()->getParameters() as $param) {
			$args[] = $this->double(param: $param, context: ['scenario' => $scenario, 'object' => $object, 'request' => $request]);
		}

		$instance = new $controller(...$args);
		$method   = new ReflectionMethod($controller, $action);
		$callArgs = [];
		foreach ($method->getParameters() as $param) {
			$type = $param->getType();
			$callArgs[] = ($type instanceof ReflectionNamedType && $type->getName() === 'int') ? 7 : '7';
		}

		try {
			$response = $method->invokeArgs($instance, $callArgs);
		} catch (\Throwable $e) {
			return 'THROWS ' . get_class($e) . ': ' . $e->getMessage();
		}

		if ($response instanceof JSONResponse === false) {
			return 'RESPONSE ' . get_class($response);
		}

		return $response->getStatus() . ' ' . json_encode($response->getData(), JSON_UNESCAPED_SLASHES);
	}//end outcome()

	/**
	 * A constructor argument for a controller under a scenario.
	 *
	 * @param \ReflectionParameter $param   The constructor parameter.
	 * @param array                $context Scenario, object and request.
	 *
	 * @return mixed The argument.
	 */
	private function double(\ReflectionParameter $param, array $context): mixed {
		$type = $param->getType();
		if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true) {
			return 'openregister';
		}

		$class = $type->getName();
		if ($class === IRequest::class) {
			return $context['request'];
		}

		$mock = $this->createMock($class);
		if ($class === IL10N::class) {
			$mock->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));
			return $mock;
		}

		if ($class === \OCA\OpenRegister\Service\Object\PermissionHandlerInterface::class) {
			$mock->method('hasPermission')->willReturn(true);
			return $mock;
		}

		if (in_array($class, [ObjectService::class, \OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface::class], true) === true) {
			if ($context['scenario'] === 'missing') {
				$mock->method('getObject')->willReturn(null);
			} else if ($context['scenario'] === 'notfound') {
				$mock->method('getObject')->willThrowException(new DoesNotExistException('gone'));
			} else {
				$mock->method('getObject')->willReturn($context['object']);
			}

			$mock->method('getCurrentSchemaEntity')->willReturn(new \OCA\OpenRegister\Db\Schema());
			return $mock;
		}

		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$name = $method->getName();
			if ($method->isStatic() === true || $method->isConstructor() === true || str_starts_with($name, '__') === true) {
				continue;
			}

			if (preg_match('/^(is[A-Za-z]*Available|available)$/', $name) === 1) {
				$mock->method($name)->willReturn($context['scenario'] !== 'unavailable');
				continue;
			}

			if (str_starts_with($context['scenario'], 'boom-') === true) {
				$code = (int)substr($context['scenario'], 5);
				$mock->method($name)->willThrowException(new Exception('boom ' . $name, $code));
				continue;
			}

			$returns = $method->getReturnType();
			if ($returns instanceof ReflectionNamedType && $returns->getName() === 'array') {
				$mock->method($name)->willReturn(['results' => [], 'total' => 0, 'limit' => 20, 'offset' => 0]);
			}
		}

		return $mock;
	}//end double()

	/**
	 * Every link controller, action and scenario.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function caseProvider(): array {
		$cases = [];
		foreach (ControllerSources::controllers() as $file) {
			if (str_ends_with($file, 'LinksController.php') === false) {
				continue;
			}

			$relative = substr($file, (strpos($file, '/lib/') + 5), -4);
			$class = 'OCA\\OpenRegister\\' . str_replace('/', '\\', $relative);
			if (in_array(basename($file, '.php'), self::NOT_LINK_ACTIONS, true) === true) {
				continue;
			}

			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() === true) {
					continue;
				}

				foreach (self::SCENARIOS as $scenario) {
					$cases[basename($file, '.php') . '::' . $method->getName() . ' ' . $scenario] = [$class, $method->getName(), $scenario];
				}
			}
		}

		return $cases;
	}//end caseProvider()

	/**
	 * A link action resolves the object its route names: register, schema and id, in that order.
	 *
	 * @return void
	 */
	public function testTheRouteNamesTheObject(): void {
		$objectService = $this->createMock(ObjectService::class);
		$calls         = [];
		foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
			$objectService->expects($this->once())->method($setter)->willReturnCallback(
				function ($value) use (&$calls, $setter, &$objectService) {
					$calls[] = $setter . ' ' . $value;
					return $objectService;
				}
			);
		}

		$objectService->method('getObject')->willReturn(null);
		$talk = $this->createMock(\OCA\OpenRegister\Service\TalkLinkService::class);
		$talk->method('isTalkAvailable')->willReturn(true);
		$controller = new \OCA\OpenRegister\Controller\TalkLinksController('openregister', $this->createMock(IRequest::class), $talk, $objectService);

		$this->assertSame(404, $controller->index('reg', 'sch', 'obj')->getStatus());
		$this->assertSame(['setRegister reg', 'setSchema sch', 'setObject obj'], $calls);
	}//end testTheRouteNamesTheObject()

	/**
	 * One action in one scenario answers as recorded.
	 *
	 * @param string $controller The controller class.
	 * @param string $action     The action name.
	 * @param string $scenario   The scenario.
	 *
	 * @return void
	 *
	 * @dataProvider caseProvider
	 */
	public function testTheActionAnswersAsRecorded(string $controller, string $action, string $scenario): void {
		$key    = substr(strrchr($controller, '\\'), 1) . '::' . $action . ' ' . $scenario;
		$actual = $this->outcome(controller: $controller, action: $action, scenario: $scenario);
		if (getenv('OPENREGISTER_GOLDEN_RECORD') === '1') {
			$golden       = is_file(self::FIXTURE) ? json_decode(file_get_contents(self::FIXTURE), true) : [];
			$golden[$key] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$golden = json_decode(file_get_contents(self::FIXTURE), true);
		$this->assertArrayHasKey($key, $golden, 'no golden recorded for ' . $key);
		$this->assertSame($golden[$key], $actual, $key);
	}//end testTheActionAnswersAsRecorded()
}//end class
