<?php

/**
 * Golden test: every property a declaration is read into, pinned before the value is reshaped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use PHPUnit\Framework\TestCase;

class ObjectActionDefinitionGoldenTest extends TestCase {

	/**
	 * The public properties of a definition, in declaration order.
	 *
	 * @param ObjectActionDefinition $definition The definition.
	 *
	 * @return array<string, mixed> Property => value.
	 */
	private function read(ObjectActionDefinition $definition): array {
		return [
			'key' => $definition->key,
			'label' => $definition->label,
			'description' => $definition->description,
			'icon' => $definition->icon,
			'surfaces' => $definition->surfaces,
			'inputs' => $definition->inputs,
			'when' => $definition->when,
			'whenMessage' => $definition->whenMessage,
			'confirm' => $definition->confirm,
			'mode' => $definition->mode,
			'next' => $definition->next,
			'kind' => $definition->kind,
			'body' => $definition->body,
			'elevate' => $definition->elevate,
			'reversible' => $definition->reversible,
		];
	}//end read()

	/**
	 * A declaration carrying every key.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function full(): array {
		return [
			'name' => 'Publish',
			'label' => ['nl' => 'Publiceren', 'en' => 'Publish'],
			'description' => 'Make it public.',
			'icon' => 'Publish',
			'surfaces' => ['bulk', 7],
			'inputs' => ['type' => 'object', 'properties' => ['from' => ['type' => 'string'], 'pin' => ['type' => 'string', 'writeOnly' => true]]],
			'when' => ['==' => [['var' => 'object.status'], 'decided']],
			'whenMessage' => 'Only decided.',
			'confirm' => 'Sure?',
			'mode' => 'async',
			'next' => 'stay',
			'steps' => [['id' => 'a', 'type' => 'openregister.set-fields']],
			'elevate' => [['register' => 'r', 'schema' => 's', 'verbs' => ['update']]],
			'reversible' => true,
		];
	}//end full()

	public function testAFullDeclarationIsReadKeyForKey(): void {
		$definition = ObjectActionDefinition::fromDeclaration('publish', $this->full());

		$this->assertSame(
			[
				'key' => 'publish',
				'label' => ['nl' => 'Publiceren', 'en' => 'Publish'],
				'description' => 'Make it public.',
				'icon' => 'Publish',
				'surfaces' => ['bulk', '7'],
				'inputs' => $this->full()['inputs'],
				'when' => ['==' => [['var' => 'object.status'], 'decided']],
				'whenMessage' => 'Only decided.',
				'confirm' => 'Sure?',
				'mode' => 'async',
				'next' => 'stay',
				'kind' => 'steps',
				'body' => [['id' => 'a', 'type' => 'openregister.set-fields']],
				'elevate' => $definition->elevate,
				'reversible' => true,
			],
			$this->read($definition)
		);
		$this->assertTrue($definition->isRunnable());
		$this->assertTrue($definition->offersBulk());
		$this->assertSame(['pin'], $definition->writeOnlyInputs());
		$this->assertNull($definition->selfAssignField());
	}//end testAFullDeclarationIsReadKeyForKey()

	public function testAnEmptyDeclarationFallsBackToDefaults(): void {
		$definition = ObjectActionDefinition::fromDeclaration('archive', ['description' => '  ', 'icon' => 3, 'next' => '', 'reversible' => 'yes']);

		$this->assertSame(
			[
				'key' => 'archive',
				'label' => 'archive',
				'description' => null,
				'icon' => null,
				'surfaces' => ['header', 'row'],
				'inputs' => null,
				'when' => null,
				'whenMessage' => null,
				'confirm' => null,
				'mode' => 'sync',
				'next' => null,
				'kind' => null,
				'body' => null,
				'elevate' => [],
				'reversible' => false,
			],
			$this->read($definition)
		);
		$this->assertFalse($definition->isRunnable());
		$this->assertFalse($definition->offersBulk());
		$this->assertSame([], $definition->writeOnlyInputs());
	}//end testAnEmptyDeclarationFallsBackToDefaults()

	public function testTheLabelFallsBackToTheNameAndAnOddLabelToTheKey(): void {
		$this->assertSame('Archive', ObjectActionDefinition::fromDeclaration('archive', ['name' => 'Archive'])->label);
		$this->assertSame('archive', ObjectActionDefinition::fromDeclaration('archive', ['label' => 5])->label);
	}//end testTheLabelFallsBackToTheNameAndAnOddLabelToTheKey()

	public function testTheFirstBodyKeyIsTheKind(): void {
		$selfAssign = ObjectActionDefinition::fromDeclaration('claim', ['selfAssign' => ['field' => 'owner']]);
		$transition = ObjectActionDefinition::fromDeclaration('finish', ['flow' => 'f', 'transition' => 'finish']);

		$this->assertSame('selfAssign', $selfAssign->kind);
		$this->assertSame('owner', $selfAssign->selfAssignField());
		$this->assertSame('transition', $transition->kind);
		$this->assertSame('finish', $transition->body);
		$this->assertSame(['transition', 'flow'], ObjectActionDefinition::bodiesOf(['flow' => 'f', 'transition' => 'finish']));
	}//end testTheFirstBodyKeyIsTheKind()

	public function testWithoutElevationKeepsEverythingButTheElevation(): void {
		$definition = ObjectActionDefinition::fromDeclaration('publish', $this->full());
		$stripped   = $definition->withoutElevation();

		$expected            = $this->read($definition);
		$expected['elevate'] = [];
		$this->assertNotSame([], $definition->elevate);
		$this->assertSame($expected, $this->read($stripped));
	}//end testWithoutElevationKeepsEverythingButTheElevation()

	public function testDeclarationsKeepOnlyStringKeyedArrayEntries(): void {
		$declared = ObjectActionDefinition::declarations(
			['x-openregister-action' => ['ok' => ['name' => 'Ok'], '' => ['name' => 'x'], 'bad' => 'string', 0 => ['name' => 'zero']]]
		);

		$this->assertSame(['ok' => ['name' => 'Ok']], $declared);
		$this->assertSame([], ObjectActionDefinition::declarations(['x-openregister-action' => 'nope']));
		$this->assertSame([], ObjectActionDefinition::declarations([]));
	}//end testDeclarationsKeepOnlyStringKeyedArrayEntries()
}//end class
