<?php

/**
 * Golden test for the lifecycle action and provider registries.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Lifecycle\Action\SetFieldsAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Golden test for LifecycleActionRegistry::resolve() and
 * LifecycleActionProviderRegistry::resolve().
 *
 * Each scenario wires an app container and a server container from fixed
 * maps (an entry is either the service or a Throwable to throw) and records
 * the resolved service, or the refusal's class and message, plus every
 * logged error and every container lookup. The expected transcripts were
 * recorded from the code as it stood before the refactor.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleActionRegistry
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry
 */
class LifecycleRegistriesGoldenTest extends TestCase {

	/**
	 * Lookups and log lines recorded during one scenario.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * Labels for the services handed out, keyed by object id.
	 *
	 * @var array<int, string>
	 */
	private array $labels = [];

	public function testActionRegistryMatchesTheRecordedTranscripts(): void {
		$handler = $this->createMock(LifecycleActionInterface::class);
		$builtin = new SetFieldsAction();
		$this->labels = [spl_object_id($handler) => 'handler', spl_object_id($builtin) => 'builtin'];
		$missing = new RuntimeException('app: not found');
		$serverMissing = new RuntimeException('server: not found');

		$scenarios = [
			'builtin set-fields' => [['set-fields'], [SetFieldsAction::class => $builtin], []],
			'builtin set-field alias' => [['set-field'], [SetFieldsAction::class => $builtin], []],
			'app handler, cached' => [['app.do', 'app.do'], ['app.do' => $handler], []],
			'server fallback' => [['app.do'], ['app.do' => $missing], ['app.do' => $handler]],
			'nowhere' => [['app.do', 'app.do'], ['app.do' => $missing], ['app.do' => $serverMissing]],
			'builtin nowhere' => [['set-fields'], [], []],
			'null from app' => [['app.do'], ['app.do' => null], ['app.do' => $handler]],
			'wrong type' => [['app.do'], ['app.do' => new stdClass()], []],
			'wrong type, server' => [['app.do'], ['app.do' => $missing], ['app.do' => new stdClass()]],
		];

		$undeclared = fn (string $id): string => $id . ' => RuntimeException: Lifecycle action "' . $id
			. '" is declared but no handler is registered. Register a service implementing '
			. LifecycleActionInterface::class . ' under the id "' . $id . '", or remove the action from the schema.';

		$expected = [
			'builtin set-fields' => [
				'app.get(' . SetFieldsAction::class . ')',
				'set-fields => builtin',
			],
			'builtin set-field alias' => [
				'app.get(' . SetFieldsAction::class . ')',
				'set-field => builtin',
			],
			'app handler, cached' => ['app.get(app.do)', 'app.do => handler', 'app.do => handler'],
			'server fallback' => ['app.get(app.do)', 'server.get(app.do)', 'app.do => handler'],
			'nowhere' => [
				'app.get(app.do)',
				'server.get(app.do)',
				'log: Lifecycle action "app.do" could not be resolved: app: not found | server: not found',
				$undeclared('app.do'),
				'app.get(app.do)',
				'server.get(app.do)',
				'log: Lifecycle action "app.do" could not be resolved: app: not found | server: not found',
				$undeclared('app.do'),
			],
			'builtin nowhere' => [
				'app.get(' . SetFieldsAction::class . ')',
				'server.get(' . SetFieldsAction::class . ')',
				'log: Lifecycle action "set-fields" could not be resolved: '
				. 'no app entry | no server entry',
				$undeclared('set-fields'),
			],
			'null from app' => [
				'app.get(app.do)',
				'log: Lifecycle action "app.do" could not be resolved: ',
				$undeclared('app.do'),
			],
			'wrong type' => [
				'app.get(app.do)',
				'app.do => RuntimeException: Service resolved for lifecycle action "app.do" does not implement '
				. LifecycleActionInterface::class . '.',
			],
			'wrong type, server' => [
				'app.get(app.do)',
				'server.get(app.do)',
				'app.do => RuntimeException: Service resolved for lifecycle action "app.do" does not implement '
				. LifecycleActionInterface::class . '.',
			],
		];

		foreach ($scenarios as $name => [$ids, $app, $server]) {
			$this->trace = [];
			$registry = new LifecycleActionRegistry(new \OCA\OpenRegister\Service\Lifecycle\ContainerLookup($this->container('app', $app), $this->serverContainer($server), $this->logger()));
			foreach ($ids as $id) {
				$this->record($id, fn () => $registry->resolve($id));
			}

			$this->assertSame($expected[$name], $this->trace, $name);
		}
	}//end testActionRegistryMatchesTheRecordedTranscripts()

	public function testProviderRegistryMatchesTheRecordedTranscripts(): void {
		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$this->labels = [spl_object_id($provider) => 'provider'];
		$missing = new RuntimeException('app: not found');
		$serverMissing = new RuntimeException('server: not found');

		$scenarios = [
			'app provider, cached' => [['p.tag', 'p.tag'], ['p.tag' => $provider], []],
			'server fallback' => [['p.tag'], ['p.tag' => $missing], ['p.tag' => $provider]],
			'nowhere' => [['p.tag'], ['p.tag' => $missing], ['p.tag' => $serverMissing]],
			'null from app' => [['p.tag'], ['p.tag' => null], ['p.tag' => $provider]],
			'wrong type' => [['p.tag'], ['p.tag' => new stdClass()], []],
		];

		$expected = [
			'app provider, cached' => ['app.get(p.tag)', 'p.tag => provider', 'p.tag => provider'],
			'server fallback' => ['app.get(p.tag)', 'server.get(p.tag)', 'p.tag => provider'],
			'nowhere' => [
				'app.get(p.tag)',
				'server.get(p.tag)',
				'log: Lifecycle provider tag "p.tag" could not be resolved: app: not found | server: not found',
				'p.tag => OCA\OpenRegister\Exception\LifecycleProviderException: Lifecycle provider "p.tag" is not registered.',
			],
			'null from app' => [
				'app.get(p.tag)',
				'log: Lifecycle provider tag "p.tag" could not be resolved: ',
				'p.tag => OCA\OpenRegister\Exception\LifecycleProviderException: Lifecycle provider "p.tag" is not registered.',
			],
			'wrong type' => [
				'app.get(p.tag)',
				'p.tag => OCA\OpenRegister\Exception\LifecycleProviderException: Service "p.tag" does not implement '
				. LifecycleActionProviderInterface::class . '.',
			],
		];

		foreach ($scenarios as $name => [$ids, $app, $server]) {
			$this->trace = [];
			$registry = new LifecycleActionProviderRegistry(new \OCA\OpenRegister\Service\Lifecycle\ContainerLookup($this->container('app', $app), $this->serverContainer($server), $this->logger()));
			foreach ($ids as $id) {
				$this->record($id, fn () => $registry->resolve($id));
			}

			$this->assertSame($expected[$name], $this->trace, $name);
		}
	}//end testProviderRegistryMatchesTheRecordedTranscripts()

	public function testGuardRegistryMatchesTheRecordedTranscripts(): void {
		$guard = $this->createMock(LifecycleGuardInterface::class);
		$this->labels = [spl_object_id($guard) => 'guard'];
		$missing = new RuntimeException('app: not found');
		$serverMissing = new RuntimeException('server: not found');

		$scenarios = [
			'app guard, cached' => [['g.tag', 'g.tag'], ['g.tag' => $guard], []],
			'server fallback' => [['g.tag'], ['g.tag' => $missing], ['g.tag' => $guard]],
			'nowhere, twice' => [['g.tag', 'g.tag'], ['g.tag' => $missing], ['g.tag' => $serverMissing]],
			'unknown everywhere' => [['g.tag'], [], []],
			'null from app' => [['g.tag'], ['g.tag' => null], ['g.tag' => $guard]],
			'wrong type' => [['g.tag'], ['g.tag' => new stdClass()], []],
			'wrong type, server' => [['g.tag'], ['g.tag' => $missing], ['g.tag' => new stdClass()]],
		];

		$unregistered = 'g.tag => RuntimeException: Lifecycle guard "g.tag" is not registered.';
		$wrongType = 'g.tag => RuntimeException: Service "g.tag" does not implement ' . LifecycleGuardInterface::class . '.';
		$expected = [
			'app guard, cached' => ['app.get(g.tag)', 'g.tag => guard', 'g.tag => guard'],
			'server fallback' => ['app.get(g.tag)', 'server.get(g.tag)', 'g.tag => guard'],
			'nowhere, twice' => [
				'app.get(g.tag)',
				'server.get(g.tag)',
				'log: Lifecycle guard tag "g.tag" could not be resolved: app: not found | server: not found',
				$unregistered,
				'app.get(g.tag)',
				'server.get(g.tag)',
				'log: Lifecycle guard tag "g.tag" could not be resolved: app: not found | server: not found',
				$unregistered,
			],
			'unknown everywhere' => [
				'app.get(g.tag)',
				'server.get(g.tag)',
				'log: Lifecycle guard tag "g.tag" could not be resolved: no app entry | no server entry',
				$unregistered,
			],
			'null from app' => [
				'app.get(g.tag)',
				'log: Lifecycle guard tag "g.tag" could not be resolved: ',
				$unregistered,
			],
			'wrong type' => ['app.get(g.tag)', $wrongType],
			'wrong type, server' => ['app.get(g.tag)', 'server.get(g.tag)', $wrongType],
		];

		foreach ($scenarios as $name => [$ids, $app, $server]) {
			$this->trace = [];
			$registry = new LifecycleGuardRegistry(new \OCA\OpenRegister\Service\Lifecycle\ContainerLookup($this->container('app', $app), $this->serverContainer($server), $this->logger()));
			foreach ($ids as $id) {
				$this->record($id, fn () => $registry->resolve($id));
			}

			$this->assertSame($expected[$name], $this->trace, $name);
		}
	}//end testGuardRegistryMatchesTheRecordedTranscripts()

	/**
	 * Record a resolution: the label of the service, or the refusal.
	 *
	 * @param string   $id      The resolved id.
	 * @param callable $resolve The resolution.
	 *
	 * @return void
	 */
	private function record(string $id, callable $resolve): void {
		try {
			$service = $resolve();
			$this->trace[] = $id . ' => ' . ($this->labels[spl_object_id($service)] ?? get_class($service));
		} catch (Throwable $exception) {
			$this->trace[] = $id . ' => ' . get_class($exception) . ': ' . $exception->getMessage();
		}
	}//end record()

	/**
	 * A container answering from a fixed map.
	 *
	 * @param string               $name    Name used in the trace.
	 * @param array<string, mixed> $entries Id → service, or Throwable to throw.
	 *
	 * @return ContainerInterface
	 */
	private function container(string $name, array $entries): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback($this->answer($name, $entries));
		return $container;
	}//end container()

	/**
	 * A server container answering from a fixed map.
	 *
	 * @param array<string, mixed> $entries Id → service, or Throwable to throw.
	 *
	 * @return IServerContainer
	 */
	private function serverContainer(array $entries): IServerContainer {
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback($this->answer('server', $entries));
		return $container;
	}//end serverContainer()

	/**
	 * The lookup callback shared by both containers.
	 *
	 * @param string               $name    Name used in the trace.
	 * @param array<string, mixed> $entries Id → service, or Throwable to throw.
	 *
	 * @return callable
	 */
	private function answer(string $name, array $entries): callable {
		return function (string $id) use ($name, $entries) {
			$this->trace[] = $name . '.get(' . $id . ')';
			if (array_key_exists($id, $entries) === false) {
				throw new RuntimeException('no ' . $name . ' entry');
			}

			if ($entries[$id] instanceof Throwable) {
				throw $entries[$id];
			}

			return $entries[$id];
		};
	}//end answer()

	/**
	 * A logger that writes every error into the trace.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->trace[] = 'log: ' . $message;
			}
		);
		return $logger;
	}//end logger()
}//end class
