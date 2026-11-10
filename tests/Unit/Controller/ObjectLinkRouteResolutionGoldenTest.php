<?php

/**
 * Golden for which object the object-link route actions resolve.
 *
 * Written against the controllers before their route actions were thinned to
 * ObjectRef delegates: every action resolves exactly the register, schema and
 * object its route names (allowed), and answers 404 without touching its
 * backing service when that object does not resolve (refused). The
 * controllers are built from their constructors' parameter types, so the
 * golden does not pin how a constructor is shaped.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ActivityLinksController;
use OCA\OpenRegister\Controller\AnalyticsLinksController;
use OCA\OpenRegister\Controller\BookmarkLinksController;
use OCA\OpenRegister\Controller\CospendLinksController;
use OCA\OpenRegister\Controller\DeckController;
use OCA\OpenRegister\Controller\DeckLinksController;
use OCA\OpenRegister\Controller\EmailLinksController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Each object-link route resolves the routed object, and refuses a missing one.
 */
class ObjectLinkRouteResolutionGoldenTest extends TestCase {

	/**
	 * The register, schema and object the controller set, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $resolved = [];

	/**
	 * The calls that reached a backing service other than the object resolver.
	 *
	 * @var array<int, string>
	 */
	private array $serviceCalls = [];

	/**
	 * @return array<string, array{0: class-string, 1: string, 2: array<int, string>}>
	 */
	public static function routes(): array {
		$routes = [
			ActivityLinksController::class => ['index' => []],
			AnalyticsLinksController::class => ['index' => [], 'link' => [], 'createAndLink' => [], 'destroy' => ['5']],
			BookmarkLinksController::class => ['index' => [], 'link' => [], 'createNew' => [], 'destroy' => ['5']],
			CospendLinksController::class => ['index' => [], 'link' => [], 'createAndLink' => [], 'destroy' => ['5']],
			DeckLinksController::class => ['index' => [], 'link' => [], 'createNew' => [], 'destroy' => ['5']],
			EmailLinksController::class => ['index' => [], 'link' => [], 'destroy' => ['5']],
			DeckController::class => ['index' => [], 'create' => []],
		];
		$cases = [];
		foreach ($routes as $class => $actions) {
			foreach ($actions as $action => $extra) {
				$cases[(new ReflectionClass($class))->getShortName() . '::' . $action] = [$class, $action, $extra];
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider routes
	 *
	 * @param class-string       $class  The controller.
	 * @param string             $action The route action.
	 * @param array<int, string> $extra  Route parameters after the object id.
	 */
	public function testTheRoutedObjectIsResolved(string $class, string $action, array $extra): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-c');
		$object->setRegister('7');
		$object->setSchema('8');

		$response = $this->build(class: $class, object: $object)->$action('reg-a', 'sch-b', 'obj-c', ...$extra);

		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame(['reg-a', 'sch-b', 'obj-c'], array_slice($this->resolved, 0, 3));
	}

	/**
	 * @dataProvider routes
	 *
	 * @param class-string       $class  The controller.
	 * @param string             $action The route action.
	 * @param array<int, string> $extra  Route parameters after the object id.
	 */
	public function testAnObjectThatDoesNotResolveIsA404AndReachesNoService(string $class, string $action, array $extra): void {
		$response = $this->build(class: $class, object: null)->$action('reg-a', 'sch-b', 'obj-c', ...$extra);

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['reg-a', 'sch-b', 'obj-c'], array_slice($this->resolved, 0, 3));
		$this->assertSame([], $this->serviceCalls);
	}

	/**
	 * A controller or bundle from doubles: the object resolver records, every service reports itself available.
	 *
	 * @param class-string      $class  The class.
	 * @param ObjectEntity|null $object What the resolver finds.
	 *
	 * @return object
	 */
	private function build(string $class, ?ObjectEntity $object): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
			$args[] = match (true) {
				$name === 'string' => 'openregister',
				$name === IRequest::class => $this->request(),
				$name === ObjectOperationsInterface::class => $this->resolver(object: $object),
				(new ReflectionClass($name))->isFinal() === true => $this->build(class: $name, object: $object),
				default => $this->service(type: $name),
			};
		}

		return new $class(...$args);
	}

	/**
	 * The request: no parameters.
	 *
	 * @return IRequest&MockObject
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnArgument(1);
		$request->method('getParams')->willReturn([]);

		return $request;
	}

	/**
	 * The object resolver, recording what it is set to.
	 *
	 * @param ObjectEntity|null $object What it finds.
	 *
	 * @return ObjectOperationsInterface&MockObject
	 */
	private function resolver(?ObjectEntity $object): ObjectOperationsInterface {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
			$objects->method($setter)->willReturnCallback(
				function ($value) use ($objects) {
					$this->resolved[] = $value;
					return $objects;
				}
			);
		}

		$objects->method('getObject')->willReturn($object);
		$objects->method('find')->willReturn($object);

		return $objects;
	}

	/**
	 * A backing service that is available and records every other call.
	 *
	 * @param class-string $type The service type.
	 *
	 * @return MockObject
	 */
	private function service(string $type): MockObject {
		$service = $this->createMock($type);
		foreach ((new ReflectionClass($type))->getMethods() as $method) {
			if ($method->isStatic() === true || $method->isPublic() === false || $method->isConstructor() === true) {
				continue;
			}

			$name = $method->getName();
			if (preg_match('/^is\w*Available$/', $name) === 1) {
				$service->method($name)->willReturn(true);
				continue;
			}

			$service->method($name)->willReturnCallback(
				function () use ($name, $method) {
					$this->serviceCalls[] = $name;
					$returnType = $method->getReturnType();
					if ($returnType instanceof ReflectionNamedType && $returnType->getName() === 'array') {
						return [];
					}

					throw new \RuntimeException('not stubbed: ' . $name);
				}
			);
		}

		return $service;
	}
}
