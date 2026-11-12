<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ActivityLinksControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CalendarEventsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\DeckLinksControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\EmailLinksControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\FormLinksControllerFactory;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Pins the outcomes of the form, calendar, activity, Deck and email link
 * actions for an object that is missing, one the caller may not change, and
 * one the caller may change.
 *
 * The controllers are built through their test factories, so the same cases
 * run against the flat constructors and against the bundled ones.
 */
final class B15ObjectLinkControllersGoldenTest extends TestCase {
	/**
	 * The object-scoped actions and the status each situation gets.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<int, string>, 3: int, 4: int, 5: int}>
	 */
	public static function caseProvider(): array {
		return [
			'form index' => [FormLinksControllerFactory::class, 'index', ['r', 's', 'o'], 404, 200, 200],
			'form link' => [FormLinksControllerFactory::class, 'link', ['r', 's', 'o'], 404, 400, 400],
			'form create' => [FormLinksControllerFactory::class, 'create', ['r', 's', 'o'], 404, 400, 400],
			'form destroy' => [FormLinksControllerFactory::class, 'destroyForm', ['r', 's', 'o', 'f'], 404, 200, 200],
			'form submission destroy' => [FormLinksControllerFactory::class, 'destroySubmission', ['r', 's', 'o', 'f', 'sub'], 404, 200, 200],
			'calendar index' => [CalendarEventsControllerFactory::class, 'index', ['r', 's', 'o'], 404, 200, 200],
			'calendar create' => [CalendarEventsControllerFactory::class, 'create', ['r', 's', 'o'], 404, 403, 400],
			'calendar link' => [CalendarEventsControllerFactory::class, 'link', ['r', 's', 'o'], 404, 403, 400],
			'calendar unlink' => [CalendarEventsControllerFactory::class, 'unlink', ['r', 's', 'o', 'e'], 404, 403, 200],
			'calendar destroy' => [CalendarEventsControllerFactory::class, 'destroy', ['r', 's', 'o', 'e'], 404, 403, 404],
			'activity index' => [ActivityLinksControllerFactory::class, 'index', ['r', 's', 'o'], 404, 200, 200],
			'deck index' => [DeckLinksControllerFactory::class, 'index', ['r', 's', 'o'], 404, 200, 200],
			'deck link' => [DeckLinksControllerFactory::class, 'link', ['r', 's', 'o'], 404, 400, 400],
			'deck create' => [DeckLinksControllerFactory::class, 'createNew', ['r', 's', 'o'], 404, 400, 400],
			'deck destroy' => [DeckLinksControllerFactory::class, 'destroy', ['r', 's', 'o', 'c'], 404, 200, 200],
			'email index' => [EmailLinksControllerFactory::class, 'index', ['r', 's', 'o'], 404, 200, 200],
			'email link' => [EmailLinksControllerFactory::class, 'link', ['r', 's', 'o'], 404, 403, 400],
			'email destroy' => [EmailLinksControllerFactory::class, 'destroy', ['r', 's', 'o', '1'], 404, 403, 200],
		];
	}//end caseProvider()

	/**
	 * A missing object, a refused update right and a granted one get the pinned statuses.
	 *
	 * @param class-string $factory The test factory of the controller.
	 * @param string $action The action to call.
	 * @param array<int, string> $arguments The action arguments.
	 * @param int $missing The status when the object is not found.
	 * @param int $refused The status when the caller may not update the object.
	 * @param int $allowed The status when the caller may update the object.
	 *
	 * @dataProvider caseProvider
	 *
	 * @return void
	 */
	public function testObjectLinkActionsGetThePinnedStatus(
		string $factory,
		string $action,
		array $arguments,
		int $missing,
		int $refused,
		int $allowed
	): void {
		$this->assertSame(
			[$missing, $refused, $allowed],
			[
				$this->statusOf($factory, $action, $arguments, 'missing'),
				$this->statusOf($factory, $action, $arguments, 'refused'),
				$this->statusOf($factory, $action, $arguments, 'allowed'),
			]
		);
	}//end testObjectLinkActionsGetThePinnedStatus()

	/**
	 * Call one action in one situation.
	 *
	 * @param class-string $factory The test factory of the controller.
	 * @param string $action The action to call.
	 * @param array<int, string> $arguments The action arguments.
	 * @param string $situation missing, refused or allowed.
	 *
	 * @return int The HTTP status of the response.
	 */
	private function statusOf(string $factory, string $action, array $arguments, string $situation): int {
		$object = new ObjectEntity();
		$object->setUuid('object-1');
		$object->setOwner('bob');
		$object->setRegister('1');
		$object->setSchema('2');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getObject')->willReturn($situation === 'missing' ? null : $object);
		$objects->method('find')->willReturn($situation === 'missing' ? null : $object);
		$objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturn($situation === 'allowed');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnArgument(1);
		$request->method('getParams')->willReturn([]);

		$args = [];
		foreach ((new ReflectionMethod($factory, 'args'))->getParameters() as $param) {
			$type = $param->getType();
			$name = 'string';
			if ($type instanceof ReflectionNamedType) {
				$name = $type->getName();
			}

			$args[] = match (true) {
				$param->getName() === 'appName' => 'openregister',
				$name === IRequest::class => $request,
				$name === ObjectOperationsInterface::class => $objects,
				$name === PermissionHandlerInterface::class => $permissions,
				default => $this->available($name),
			};
		}

		$response = $factory::create(...$args)->$action(...$arguments);
		$this->assertInstanceOf(Response::class, $response);

		return $response->getStatus();
	}//end statusOf()

	/**
	 * A mock of a link service that reports its app as available.
	 *
	 * @param string $type The collaborator type.
	 *
	 * @return mixed
	 */
	private function available(string $type): mixed {
		$mock = $this->createMock($type);
		foreach ((new ReflectionClass($type))->getMethods() as $method) {
			if (preg_match('/^is\w*Available$/', $method->getName()) === 1) {
				$mock->method($method->getName())->willReturn(true);
			}

			if ($method->getName() === 'getActivityEntries') {
				$mock->method('getActivityEntries')->willReturn(['results' => [], 'total' => 0, 'nextCursor' => null]);
			}
		}

		return $mock;
	}//end available()
}//end class
