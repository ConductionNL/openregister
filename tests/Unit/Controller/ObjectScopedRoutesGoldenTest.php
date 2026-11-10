<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\ContactService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PersonLinkService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\CalendarEventsControllerFactory;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ContactsControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every object-scoped calendar and contact route: how it resolves the
 * object (register, schema and id, in that order), the answer to an object
 * that is null, missing or failing to load, the refusal without the update
 * right (and with it, for the read routes), and the status a service
 * failure gets for codes 0, 404 and 409.
 *
 * Recorded on the code before the routes delegated with an ObjectRef;
 * rewrite with UPDATE_GOLDEN=1 only for a deliberate change.
 */
final class ObjectScopedRoutesGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/object-scoped-routes-golden.json';

	/**
	 * @var list<string>
	 */
	private array $resolved = [];

	/**
	 * @return array<string, array{0: string, 1: string, 2: list<string>}>
	 */
	public static function routes(): array {
		return [
			'calendar index' => ['calendar', 'index', ['r', 's', 'o']],
			'calendar create' => ['calendar', 'create', ['r', 's', 'o']],
			'calendar link' => ['calendar', 'link', ['r', 's', 'o']],
			'calendar unlink' => ['calendar', 'unlink', ['r', 's', 'o', 'ev-uid']],
			'calendar destroy' => ['calendar', 'destroy', ['r', 's', 'o', 'ev.ics']],
			'contacts index' => ['contacts', 'index', ['r', 's', 'o']],
			'contacts create' => ['contacts', 'create', ['r', 's', 'o']],
			'contacts createNew' => ['contacts', 'createNew', ['r', 's', 'o']],
			'contacts update' => ['contacts', 'update', ['r', 's', 'o', 'c-uid']],
			'contacts destroy' => ['contacts', 'destroy', ['r', 's', 'o', 'c-uid']],
			'contacts destroy numeric' => ['contacts', 'destroy', ['r', 's', 'o', '12']],
		];
	}

	/**
	 * The situations each route is run in.
	 *
	 * @return list<string>
	 */
	private static function situations(): array {
		return ['null', 'notfound', 'load-0', 'load-404', 'refused', 'svc-0', 'svc-404', 'svc-409'];
	}

	#[DataProvider('routes')]
	public function testRouteOutcomesMatchTheRecording(string $controller, string $action, array $arguments): void {
		$actual = [];
		foreach (self::situations() as $situation) {
			$this->resolved = [];
			$response = $this->controller(name: $controller, situation: $situation)->$action(...$arguments);
			$actual[$situation] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'resolved' => $this->resolved];
		}

		$key      = $controller . ' ' . $action . ' ' . implode('/', $arguments);
		$recorded = [];
		if (is_file(self::FIXTURE) === true) {
			$recorded = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$recorded[$key] = $actual;
			ksort($recorded);
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($key, $recorded);
		$this->assertSame($recorded[$key], json_decode((string)json_encode($actual), true));
	}

	private function objectService(string $situation): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
			$objects->method($setter)->willReturnCallback(function (mixed $value) use ($setter, $objects): ObjectService {
				$this->resolved[] = $setter . ' ' . $value;
				return $objects;
			});
		}

		$objects->method('getCurrentSchemaEntity')->willReturn(new Schema());
		$object = new ObjectEntity();
		$object->setUuid('obj-uuid');
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setName('Title');
		match ($situation) {
			'null' => $objects->method('getObject')->willReturn(null),
			'notfound' => $objects->method('getObject')->willThrowException(new DoesNotExistException('gone')),
			'load-0' => $objects->method('getObject')->willThrowException(new Exception('load failed')),
			'load-404' => $objects->method('getObject')->willThrowException(new Exception('load failed', 404)),
			default => $objects->method('getObject')->willReturn($object),
		};

		return $objects;
	}

	private function permissions(string $situation): PermissionHandler {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static fn (Schema $schema, string $action): bool => $action !== 'update' || $situation !== 'refused'
		);

		return $permissions;
	}

	private function failure(string $situation): ?Exception {
		return match ($situation) {
			'svc-0' => new Exception('service failed'),
			'svc-404' => new Exception('service failed', 404),
			'svc-409' => new Exception('service failed', 409),
			default => null,
		};
	}

	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['summary' => 'S', 'calendarUri' => 'cal', 'eventUid' => 'ev-uid', 'userId' => 'u1', 'displayName' => 'D']);
		$request->method('getParam')->willReturn(null);
		return $request;
	}

	private function controller(string $name, string $situation): object {
		if ($name === 'calendar') {
			return $this->calendar(situation: $situation);
		}

		return $this->contacts(situation: $situation);
	}

	private function calendar(string $situation): object {
		$links   = $this->createMock(CalendarLinkService::class);
		$events  = $this->createMock(CalendarEventService::class);
		$failure = $this->failure(situation: $situation);
		$links->method('getLinkedEvents')->willReturn([['id' => 'ev.ics', 'calendarId' => 7, 'uid' => 'ev-uid']]);
		if ($failure !== null) {
			foreach (['createLinkedEvent', 'linkEvent', 'unlinkEvent'] as $method) {
				$links->method($method)->willThrowException($failure);
			}

			$events->method('deleteEvent')->willThrowException($failure);
		}

		return CalendarEventsControllerFactory::create(
			'openregister',
			$this->request(),
			$events,
			$links,
			$this->objectService(situation: $situation),
			$this->permissions(situation: $situation)
		);
	}

	private function contacts(string $situation): object {
		$contacts = $this->createMock(ContactService::class);
		$people   = $this->createMock(PersonLinkService::class);
		$failure  = $this->failure(situation: $situation);
		if ($failure !== null) {
			foreach (['listForObject', 'linkPerson', 'changeLink', 'removeLinks'] as $method) {
				$people->method($method)->willThrowException($failure);
			}

			foreach (['createAndLinkContact', 'unlinkContact'] as $method) {
				$contacts->method($method)->willThrowException($failure);
			}
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return ContactsControllerFactory::create(
			'openregister',
			$this->request(),
			$contacts,
			$this->objectService(situation: $situation),
			$this->createMock(ContactMatchingService::class),
			$this->createMock(DeepLinkRegistryService::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
			$people,
			$this->permissions(situation: $situation)
		);
	}
}
