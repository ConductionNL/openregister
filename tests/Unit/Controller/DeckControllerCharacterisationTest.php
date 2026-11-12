<?php

/**
 * Characterisation of DeckController's object routes.
 *
 * Written against the controller before index() and create() were thinned to
 * ObjectRef delegates: which object is resolved, the 501 without Deck, the 404s
 * for a missing object, the error mapping of create() and what reaches the
 * card service. The controller is built from its constructor's own parameter
 * types, so the test does not pin how the constructor is shaped.
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
 * @spec openspec/specs/deck-integration/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\DeckController;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\DeckCardServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * DeckController index() and create(), every branch.
 */
class DeckControllerCharacterisationTest extends TestCase {

	private DeckCardServiceInterface&MockObject $cards;

	private ObjectOperationsInterface&MockObject $objects;

	private IRequest&MockObject $request;

	/**
	 * The register, schema and object the controller resolved.
	 *
	 * @var array<int, mixed>
	 */
	private array $resolved = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cards = $this->createMock(DeckCardServiceInterface::class);
		$this->cards->method('isDeckAvailable')->willReturn(true);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('setRegister')->willReturnCallback(fn ($v) => $this->record($v));
		$this->objects->method('setSchema')->willReturnCallback(fn ($v) => $this->record($v));
		$this->objects->method('setObject')->willReturnCallback(fn ($v) => $this->record($v));
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn(['cardId' => 7]);
	}

	/**
	 * Record a resolution step.
	 *
	 * @param mixed $value The value set.
	 *
	 * @return ObjectOperationsInterface
	 */
	private function record(mixed $value): ObjectOperationsInterface {
		$this->resolved[] = $value;

		return $this->objects;
	}

	/**
	 * The controller, built from its constructor's parameter types.
	 *
	 * @return DeckController
	 */
	private function controller(): DeckController {
		return $this->build(class: DeckController::class);
	}

	/**
	 * An instance of a class from the doubles of this test.
	 *
	 * @param class-string $class The class.
	 *
	 * @return object
	 */
	private function build(string $class): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
			$args[] = match ($name) {
				'string' => 'openregister',
				IRequest::class => $this->request,
				DeckCardServiceInterface::class => $this->cards,
				ObjectOperationsInterface::class => $this->objects,
				default => $this->build(class: $name),
			};
		}

		return new $class(...$args);
	}

	/**
	 * A stored object.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('12');

		return $object;
	}

	/**
	 * Status and data of a response.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function pair(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}

	public function testWithoutDeckBothRoutesAre501(): void {
		$cards = $this->createMock(DeckCardServiceInterface::class);
		$cards->method('isDeckAvailable')->willReturn(false);
		$this->cards = $cards;
		$expected = [501, ['error' => 'Nextcloud Deck app is not installed', 'code' => 'APP_NOT_AVAILABLE']];

		$this->assertSame($expected, $this->pair($this->controller()->index('r', 's', 'o')));
		$this->assertSame($expected, $this->pair($this->controller()->create('r', 's', 'o')));
		$this->assertSame([], $this->resolved);
	}

	public function testIndexResolvesTheRoutedObjectAndListsItsCards(): void {
		$this->objects->method('getObject')->willReturn($this->object());
		$this->cards->expects($this->once())->method('getCardsForObject')->with('obj-1')->willReturn(['results' => [1]]);

		$this->assertSame([200, ['results' => [1]]], $this->pair($this->controller()->index('zaken', 'zaak', 'o-9')));
		$this->assertSame(['zaken', 'zaak', 'o-9'], $this->resolved);
	}

	public function testIndexAnswers404ForANullOrMissingObjectAnd500ForAFailure(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('getObject')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new Exception('db down'))
		);
		$this->objects = $objects;
		$controller = $this->controller();

		$this->assertSame([404, ['error' => 'Object not found']], $this->pair($controller->index('r', 's', 'o')));
		$this->assertSame([404, ['error' => 'Object not found']], $this->pair($controller->index('r', 's', 'o')));
		$this->assertSame([500, ['error' => 'db down']], $this->pair($controller->index('r', 's', 'o')));
	}

	public function testCreatePassesTheObjectItsRegisterAndTheBody(): void {
		$this->objects->method('getObject')->willReturn($this->object());
		$link = new DeckLink();
		$this->cards->expects($this->once())->method('linkOrCreateCard')->with('obj-1', 12, ['cardId' => 7])->willReturn($link);

		$response = $this->controller()->create('zaken', 'zaak', 'o-9');

		$this->assertSame([201, $link], $this->pair($response));
		$this->assertSame(['zaken', 'zaak', 'o-9'], $this->resolved);
	}

	/**
	 * @return array<string, array{0: Exception, 1: int, 2: string}>
	 */
	public static function createFailures(): array {
		return [
			'missing object' => [new DoesNotExistException('gone'), 404, 'Object not found'],
			'conflict' => [new Exception('already linked', 409), 409, 'already linked'],
			'not found below' => [new Exception('no card', 404), 404, 'no card'],
			'anything else' => [new Exception('bad', 500), 400, 'bad'],
		];
	}

	/**
	 * @dataProvider createFailures
	 *
	 * @param Exception $failure The failure.
	 * @param int       $status  The status.
	 * @param string    $error   The error text.
	 */
	public function testCreateMapsFailures(Exception $failure, int $status, string $error): void {
		$this->objects->method('getObject')->willReturn($this->object());
		$this->cards->method('linkOrCreateCard')->willThrowException($failure);

		$this->assertSame([$status, ['error' => $error]], $this->pair($this->controller()->create('r', 's', 'o')));
	}

	public function testCreateAnswers404ForANullObject(): void {
		$this->objects->method('getObject')->willReturn(null);
		$this->cards->expects($this->never())->method('linkOrCreateCard');

		$this->assertSame([404, ['error' => 'Object not found']], $this->pair($this->controller()->create('r', 's', 'o')));
	}
}
