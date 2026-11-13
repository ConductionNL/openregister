<?php

/**
 * Every built-in node form describes only keys its node reads.
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

use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A form field over a key the node never reads looks like it works and
 * changes nothing. This walks every node class in lib/Service/Flow/Nodes that
 * declares a form, rather than a hand-kept list, so a new form is checked the
 * day it lands.
 *
 * @coversNothing
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */
class FlowNodeConfigFormKeysTest extends TestCase {

	/**
	 * The field types the editor knows how to draw: the five the interface
	 * documents, plus `principal` (a user or group picker) that UserTaskNode's
	 * form already uses.
	 */
	private const FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'principal'];

	/**
	 * Every concrete node class that declares a form.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function formNodes(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/../../../../lib/Service/Flow/Nodes/*Node.php') as $file) {
			$class = 'OCA\\OpenRegister\\Service\\Flow\\Nodes\\' . basename($file, '.php');
			if (class_exists($class) === false) {
				continue;
			}

			$reflection = new ReflectionClass($class);
			if ($reflection->isAbstract() === true || $reflection->implementsInterface(IFlowNodeConfigForm::class) === false) {
				continue;
			}

			$cases[$reflection->getShortName()] = [$class];
		}

		ksort($cases);
		return $cases;
	}//end formNodes()

	public function testTheWalkFindsTheFormsThisChangeAdded(): void {
		$found = array_keys(self::formNodes());
		$expected = [
			'TriggerObjectNode', 'TriggerScheduleNode', 'TriggerManualNode', 'EndNode', 'WaitNode', 'FilterNode',
			'MergeNode', 'ExplodeNode', 'IterateNode', 'SubFlowNode', 'AwaitSignalNode',
		];
		foreach ($expected as $node) {
			$this->assertContains($node, $found);
		}
	}//end testTheWalkFindsTheFormsThisChangeAdded()

	/**
	 * @param class-string $class The node class.
	 *
	 * @return void
	 */
	#[DataProvider('formNodes')]
	public function testEveryFieldNamesAKeyTheNodeReads(string $class): void {
		$node = $this->build(class: $class);
		$this->assertInstanceOf(IFlowNodeConfigKeys::class, $node, $class . ' declares a form but no config keys to check it against');

		$this->assertFieldsWithin(fields: $node->configForm(), keys: $node->configKeys(), node: $class);
	}//end testEveryFieldNamesAKeyTheNodeReads()

	/**
	 * Each new form, field by field: key => [type, required, optionsFrom].
	 *
	 * @return array<string, array{0: string, 1: array<string, array{0: string, 1: bool, 2: string|null}>}>
	 */
	public static function newForms(): array {
		$nodes = 'OCA\\OpenRegister\\Service\\Flow\\Nodes\\';
		return [
			'object trigger names one subject' => [$nodes . 'TriggerObjectNode', [
				'event' => ['select', true, '/apps/openregister/api/flow/event-catalog'],
				'register' => ['select', true, '/apps/openregister/api/registers'],
				'schema' => ['select', true, '/apps/openregister/api/schemas'],
			]],
			'schedule needs a cron and an account' => [$nodes . 'TriggerScheduleNode', [
				'cron' => ['text', true, null],
				'runAs' => ['text', true, null],
			]],
			'manual trigger only says where to go next' => [$nodes . 'TriggerManualNode', [
				'next' => ['text', false, null],
			]],
			'end' => [$nodes . 'EndNode', [
				'error' => ['boolean', false, null],
				'message' => ['text', false, null],
				'next' => ['text', false, null],
			]],
			'wait' => [$nodes . 'WaitNode', [
				'for' => ['text', false, null],
				'until' => ['text', false, null],
			]],
			'filter' => [$nodes . 'FilterNode', [
				'condition' => ['textarea', true, null],
			]],
			'merge' => [$nodes . 'MergeNode', [
				'mode' => ['text', false, null],
				'key' => ['text', false, null],
			]],
			'explode' => [$nodes . 'ExplodeNode', [
				'path' => ['text', true, null],
				'as' => ['text', false, null],
				'keepRecord' => ['boolean', false, null],
			]],
			'iterate keeps source and body in the JSON pane' => [$nodes . 'IterateNode', [
				'maxIterations' => ['number', false, null],
				'onLimit' => ['text', false, null],
			]],
			'sub-flow picks a flow' => [$nodes . 'SubFlowNode', [
				'flowId' => ['select', true, '/apps/openregister/api/flows'],
				'wait' => ['boolean', false, null],
				'fanOut' => ['boolean', false, null],
			]],
		];
	}//end newForms()

	/**
	 * @param class-string                                          $class    The node class.
	 * @param array<string, array{0: string, 1: bool, 2: string|null}> $expected The fields.
	 *
	 * @return void
	 */
	#[DataProvider('newForms')]
	public function testEachNewFormDescribesItsKeys(string $class, array $expected): void {
		$actual = [];
		foreach ($this->build(class: $class)->configForm() as $field) {
			$this->assertNotSame('', trim((string)($field['help'] ?? '')), "{$field['key']} has no help");
			$actual[$field['key']] = [$field['type'], ($field['required'] ?? false), ($field['optionsFrom'] ?? null)];
		}

		$this->assertSame($expected, $actual);
	}//end testEachNewFormDescribesItsKeys()

	public function testAnEmptyFormIsStillADeclaration(): void {
		$this->assertContains(
			'TriggerManualNode',
			array_keys(self::formNodes()),
			'a node whose only key is optional still declares its form, so the editor can tell "nothing needed" from "nothing declared"'
		);
	}//end testAnEmptyFormIsStillADeclaration()

	public function testABogusFieldIsCaught(): void {
		$this->expectException(AssertionFailedError::class);

		$this->assertFieldsWithin(
			fields: [['key' => 'notRead', 'label' => 'x', 'type' => 'text']],
			keys: ['event'],
			node: 'positive control'
		);
	}//end testABogusFieldIsCaught()

	public function testAnUnknownFieldTypeIsCaught(): void {
		$this->expectException(AssertionFailedError::class);

		$this->assertFieldsWithin(
			fields: [['key' => 'event', 'label' => 'x', 'type' => 'table']],
			keys: ['event'],
			node: 'positive control'
		);
	}//end testAnUnknownFieldTypeIsCaught()

	/**
	 * Assert each field is well formed and over a key the node reads, once.
	 *
	 * @param array<int, array<string, mixed>> $fields The form.
	 * @param array<int, string>               $keys   The node's config keys.
	 * @param string                           $node   The node, for the message.
	 *
	 * @return void
	 */
	private function assertFieldsWithin(array $fields, array $keys, string $node): void {
		$seen = [];
		foreach ($fields as $field) {
			$this->assertContains($field['key'] ?? null, $keys, "{$node}: a field names a key the node does not read");
			$this->assertNotContains($field['key'], $seen, "{$node}: two fields write {$field['key']}");
			$this->assertContains($field['type'] ?? null, self::FIELD_TYPES, "{$node}: {$field['key']} has an unknown field type");
			$this->assertNotSame('', (string)($field['label'] ?? ''), "{$node}: {$field['key']} has no label");
			if (isset($field['optionsFrom']) === true) {
				$this->assertSame('select', $field['type'], "{$node}: {$field['key']} has options but is not a select");
				$this->assertStringStartsWith('/apps/', $field['optionsFrom'], "{$node}: {$field['key']} options are not a reference");
			}

			$seen[] = $field['key'];
		}
	}//end assertFieldsWithin()

	/**
	 * A node built over test doubles of every constructor dependency.
	 *
	 * @param class-string $class The node class.
	 *
	 * @return IFlowNodeConfigForm The node.
	 */
	private function build(string $class): IFlowNodeConfigForm {
		return $this->instance(class: $class, depth: 0);
	}//end build()

	/**
	 * An instance of a class over doubles of its dependencies. A final class
	 * (the nodes' value holders) cannot be doubled, so it is built the same way.
	 *
	 * @param class-string $class The class.
	 * @param int          $depth How deep the build has gone.
	 *
	 * @return object The instance.
	 */
	private function instance(string $class, int $depth): object {
		$args = [];
		$constructor = (new ReflectionClass($class))->getConstructor();
		foreach (($constructor?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true || $depth > 3) {
				$this->markTestIncomplete("{$class}: cannot build parameter \${$parameter->getName()}");
			}

			$args[] = $this->dependency(type: $type->getName(), depth: $depth);
		}

		return new $class(...$args);
	}//end instance()

	/**
	 * A double of one dependency; IL10N answers with the text it is given.
	 *
	 * @param class-string $type  The dependency type.
	 * @param int          $depth How deep the build has gone.
	 *
	 * @return object The double, or a built instance of a final class.
	 */
	private function dependency(string $type, int $depth): object {
		if ((new ReflectionClass($type))->isFinal() === true) {
			return $this->instance(class: $type, depth: ($depth + 1));
		}

		$double = $this->createMock($type);
		if ($type === IL10N::class) {
			$double->method('t')->willReturnCallback(
				static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters)
			);
		}

		return $double;
	}//end dependency()
}//end class
