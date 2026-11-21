<?php

/**
 * Every built-in node type in the palette carries a form declaration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Listener\FlowNodeRegistrationListener;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The sweep enumerates the REGISTRY: the real registration listener, wired
 * with the real node classes, registers into a real FlowNodeRegistry, and
 * every palette entry whose type id starts with `openregister.` must carry
 * `configForm`. A built-in added later without a form turns this red instead
 * of silently joining the raw-JSON set, and no list of node names is kept here.
 *
 * Each node interface the listener's constructor graph asks for is built as
 * the class of the same name without `Interface`, over doubles of its own
 * dependencies, so the walk follows the wiring rather than a directory.
 *
 * @coversNothing
 *
 * @spec openspec/changes/flow-node-config-forms/tasks.md
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
class FlowNodeFormSweepTest extends TestCase {

	/**
	 * What the IL10N double wraps a translated string in; empty answers with the text as given.
	 *
	 * @var string
	 */
	private string $mark = '';

	public function testEveryBuiltInPaletteEntryCarriesAForm(): void {
		$registry = $this->registryWith(listener: $this->instance(class: FlowNodeRegistrationListener::class, depth: 0));

		$entries = $this->builtInEntries(registry: $registry);

		// The walk itself must reach the built-ins, or an empty palette passes.
		$this->assertGreaterThanOrEqual(19, count($entries), 'the sweep found too few built-in node types to mean anything');
		$this->assertArrayHasKey('openregister.trigger-manual', $entries);
		$this->assertArrayHasKey('openregister.switch', $entries);

		$this->assertEveryEntryHasAForm(entries: $entries);
	}//end testEveryBuiltInPaletteEntryCarriesAForm()

	public function testAnEmptyFormCountsAsADeclaration(): void {
		$entries = $this->builtInEntries(
			registry: $this->registryWith(listener: $this->instance(class: FlowNodeRegistrationListener::class, depth: 0))
		);

		$this->assertSame([], $entries['openregister.switch']['configForm']);
	}//end testAnEmptyFormCountsAsADeclaration()

	public function testEveryLabelAndHelpIsTranslated(): void {
		$this->mark = '§';
		$entries    = $this->builtInEntries(
			registry: $this->registryWith(listener: $this->instance(class: FlowNodeRegistrationListener::class, depth: 0))
		);

		$untranslated = [];
		foreach ($entries as $id => $entry) {
			foreach (($entry['configForm'] ?? []) as $field) {
				foreach (['label', 'help'] as $part) {
					if (isset($field[$part]) === true && str_starts_with((string)$field[$part], '§') === false) {
						$untranslated[] = "{$id}.{$field['key']}.{$part}";
					}
				}
			}
		}

		$this->assertSame([], $untranslated, 'form text shown to an operator without IL10N');
	}//end testEveryLabelAndHelpIsTranslated()

	public function testAFormlessBuiltInIsCaught(): void {
		$formless = new class implements IFlowNode {
			public function getId(): string {
				return 'openregister.formless';
			}

			public function getDisplayName(): string {
				return 'Formless';
			}

			public function getDescription(): string {
				return 'A built-in that describes no form.';
			}

			public function getIcon(): string {
				return '';
			}

			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			public function validateConfig(array $config): void {
			}

			public function execute(FlowNodeCall $call): array {
				return $call->items;
			}
		};

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$registry   = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($formless): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					$event->registerNode($formless);
				}
			}
		);

		$entries = $this->builtInEntries(registry: $registry);
		$this->assertArrayHasKey('openregister.formless', $entries);

		$this->expectException(AssertionFailedError::class);
		$this->assertEveryEntryHasAForm(entries: $entries);
	}//end testAFormlessBuiltInIsCaught()

	/**
	 * The palette entries of both scopes whose type id starts with `openregister.`,
	 * keyed by id. Every registered built-in must appear in at least one scope,
	 * so a node offered in neither cannot slip past the sweep.
	 *
	 * @param FlowNodeRegistry $registry The registry.
	 *
	 * @return array<string, array<string, mixed>> The entries.
	 */
	private function builtInEntries(FlowNodeRegistry $registry): array {
		$entries = [];
		foreach ([IManager::SCOPE_ADMIN, IManager::SCOPE_USER] as $scope) {
			foreach ($registry->palette($scope) as $entry) {
				if (str_starts_with((string)$entry['id'], 'openregister.') === true) {
					$entries[$entry['id']] = $entry;
				}
			}
		}

		foreach ($registry->all() as $node) {
			if (str_starts_with($node->getId(), 'openregister.') === true) {
				$this->assertArrayHasKey($node->getId(), $entries, $node->getId() . ' is registered but in no palette scope');
			}
		}

		ksort($entries);
		return $entries;
	}//end builtInEntries()

	/**
	 * Assert every entry carries a form declaration, possibly empty.
	 *
	 * @param array<string, array<string, mixed>> $entries The entries, keyed by id.
	 *
	 * @return void
	 */
	private function assertEveryEntryHasAForm(array $entries): void {
		$missing = [];
		foreach ($entries as $id => $entry) {
			if (array_key_exists('configForm', $entry) === false || is_array($entry['configForm']) === false) {
				$missing[] = $id;
			}
		}

		$this->assertSame([], $missing, 'built-in node types on the raw-JSON pane: ' . implode(', ', $missing));
	}//end assertEveryEntryHasAForm()

	/**
	 * A registry whose contribution event is handled by the given listener.
	 *
	 * @param FlowNodeRegistrationListener $listener The built-in registration listener.
	 *
	 * @return FlowNodeRegistry The registry.
	 */
	private function registryWith(FlowNodeRegistrationListener $listener): FlowNodeRegistry {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$registry   = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				$listener->handle($event);
			}
		);

		return $registry;
	}//end registryWith()

	/**
	 * An instance of a class over doubles of its dependencies. A node
	 * interface is built as its concrete class; a final class cannot be
	 * doubled, so it is built the same way.
	 *
	 * @param class-string $class The class.
	 * @param int          $depth How deep the build has gone.
	 *
	 * @return object The instance.
	 */
	private function instance(string $class, int $depth): object {
		$args        = [];
		$constructor = (new ReflectionClass($class))->getConstructor();
		foreach (($constructor?->getParameters() ?? []) as $parameter) {
			if ($parameter->isDefaultValueAvailable() === true) {
				break;
			}

			$type = $parameter->getType();
			$this->assertTrue(
				$type instanceof ReflectionNamedType && $type->isBuiltin() === false && $depth < 8,
				"{$class}: cannot build parameter \${$parameter->getName()}"
			);
			$args[] = $this->dependency(type: $type->getName(), depth: $depth);
		}

		return new $class(...$args);
	}//end instance()

	/**
	 * One dependency: a node built from its interface, a wiring class built
	 * for real, anything else doubled. IL10N answers with the text it is given,
	 * prefixed with the mark when one is set.
	 *
	 * @param class-string $type  The dependency type.
	 * @param int          $depth How deep the build has gone.
	 *
	 * @return object The dependency.
	 */
	private function dependency(string $type, int $depth): object {
		$reflection = new ReflectionClass($type);
		if ($reflection->isInterface() === true && str_ends_with($type, 'NodeInterface') === true) {
			$concrete = substr($type, 0, -strlen('Interface'));
			$this->assertTrue(class_exists($concrete), "{$type} has no node class of the same name");
			return $this->instance(class: $concrete, depth: ($depth + 1));
		}

		if ($reflection->isFinal() === true || str_starts_with($type, 'OCA\\OpenRegister\\Listener\\FlowNodes\\') === true) {
			return $this->instance(class: $type, depth: ($depth + 1));
		}

		$double = $this->createMock($type);
		if ($type === IL10N::class) {
			$mark = $this->mark;
			$double->method('t')->willReturnCallback(
				static fn (string $text, $parameters = []): string => $mark . vsprintf($text, (array)$parameters)
			);
		}

		return $double;
	}//end dependency()
}//end class
