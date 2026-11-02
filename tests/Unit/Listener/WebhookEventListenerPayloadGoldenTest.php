<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event;
use OCA\OpenRegister\Listener\WebhookEventListener;
use OCA\OpenRegister\Service\WebhookService;
use OCP\EventDispatcher\Event as BaseEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Golden test for the payload WebhookEventListener builds per event type.
 *
 * Every event is a test double of its class, so the recording also pins that a
 * subclass of an event type is handled like the type itself. Every getter on
 * the event returns a distinct marker, so the recording shows which getter fed
 * which payload key. Recordings live in fixtures/webhook-payload-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite them.
 */
class WebhookEventListenerPayloadGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/webhook-payload-golden.json';

	private const EVENTS = [
		Event\ObjectCreatingEvent::class,
		Event\ObjectUpdatingEvent::class,
		Event\ObjectDeletingEvent::class,
		Event\ObjectCreatedEvent::class,
		Event\ObjectUpdatedEvent::class,
		Event\ObjectDeletedEvent::class,
		Event\ObjectLockedEvent::class,
		Event\ObjectUnlockedEvent::class,
		Event\ObjectRevertedEvent::class,
		Event\RegisterCreatedEvent::class,
		Event\RegisterUpdatedEvent::class,
		Event\RegisterDeletedEvent::class,
		Event\SchemaCreatedEvent::class,
		Event\SchemaUpdatedEvent::class,
		Event\SchemaDeletedEvent::class,
		Event\ApplicationCreatedEvent::class,
		Event\ApplicationUpdatedEvent::class,
		Event\ApplicationDeletedEvent::class,
		Event\AgentCreatedEvent::class,
		Event\AgentUpdatedEvent::class,
		Event\AgentDeletedEvent::class,
		Event\SourceCreatedEvent::class,
		Event\SourceUpdatedEvent::class,
		Event\SourceDeletedEvent::class,
		Event\ConfigurationCreatedEvent::class,
		Event\ConfigurationUpdatedEvent::class,
		Event\ConfigurationDeletedEvent::class,
		Event\ViewCreatedEvent::class,
		Event\ViewUpdatedEvent::class,
		Event\ViewDeletedEvent::class,
		Event\ConversationCreatedEvent::class,
		Event\ConversationUpdatedEvent::class,
		Event\ConversationDeletedEvent::class,
		Event\OrganisationCreatedEvent::class,
		Event\OrganisationUpdatedEvent::class,
		Event\OrganisationDeletedEvent::class,
		Event\ObjectTransitionedEvent::class,
		BaseEvent::class,
	];

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::EVENTS as $class) {
			$cases[$class] = [$class, false];
		}

		$cases['ObjectUpdatingEvent without old object'] = [Event\ObjectUpdatingEvent::class, true];
		$cases['ObjectRevertedEvent without revert point'] = [Event\ObjectRevertedEvent::class, true];

		return $cases;
	}//end cases()

	#[DataProvider('cases')]
	public function testPayloadMatchesGolden(string $class, bool $nullOptional): void {
		$name = $this->dataName();
		$this->calls = [];

		$service = $this->createMock(WebhookService::class);
		$service->method('dispatchEvent')->willReturnCallback(function ($event, string $eventName, array $payload) {
			$this->calls[] = ['dispatch', $this->className(class: $eventName), $this->normalise(value: $payload)];
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level) {
				$this->calls[] = [$level, $message, $this->className(class: (string)($context['event'] ?? ''))];
			});
		}

		$listener = new WebhookEventListener($service, $logger);
		$listener->handle($this->event(class: $class, nullOptional: $nullOptional));

		$actual = json_decode(json_encode($this->calls), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testPayloadMatchesGolden()

	/**
	 * A double of the event class whose getters each return a distinct marker.
	 */
	private function event(string $class, bool $nullOptional): BaseEvent {
		$event = $this->createMock($class);
		foreach ((new \ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$getter = $method->getName();
			if (str_starts_with($getter, 'get') === false || $method->getNumberOfRequiredParameters() > 0
				|| $method->getDeclaringClass()->getName() === BaseEvent::class
			) {
				continue;
			}

			$type = $method->getReturnType();
			if ($nullOptional === true && ($type === null || $type->allowsNull() === true)) {
				$event->method($getter)->willReturn(null);
				continue;
			}

			$event->method($getter)->willReturn($this->marker(type: $type, getter: $getter));
		}

		return $event;
	}//end event()

	private function marker(?\ReflectionType $type, string $getter): mixed {
		if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true) {
			if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
				return ['from' => $getter];
			}

			if ($type instanceof ReflectionNamedType && $type->getName() === 'string') {
				return 'from-' . $getter;
			}

			if ($type instanceof ReflectionNamedType && $type->getName() === 'bool') {
				return true;
			}

			if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
				return 3;
			}

			return 'from-' . $getter;
		}

		$class = $type->getName();
		if ($class === ObjectEntity::class) {
			$object = new ObjectEntity();
			$object->setUuid('uuid-' . $getter);
			$object->setRegister('register-' . $getter);
			$object->setSchema('schema-' . $getter);
			return $object;
		}

		$entity = $this->createMock($class);
		if (method_exists($class, 'jsonSerialize') === true) {
			$entity->method('jsonSerialize')->willReturn(['entity' => $this->className(class: $class), 'from' => $getter]);
		}

		return $entity;
	}//end marker()

	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			$out = [];
			foreach ($value as $key => $item) {
				$out[$key] = $this->normalise(value: $item);
				if ($key === 'timestamp' && is_string($item) === true && strtotime($item) !== false) {
					$out[$key] = '<timestamp>';
				}
			}

			return $out;
		}

		if ($value instanceof \DateTimeInterface) {
			return '<datetime>';
		}

		return $value;
	}//end normalise()

	private function className(string $class): string {
		$class = preg_replace('/^MockObject_(\w+?)_[0-9a-f]{8}$/', '$1', $class);
		$parts = explode('\\', (string)$class);
		return end($parts);
	}//end className()
}//end class
