<?php

/**
 * The performer fields of the user-task step, as the node catalog serves them.
 *
 * 🔑 THE EDITOR IS TOLD WHICH PERFORMER TYPES EXIST. A position, a function or
 * a case role is contributed by an app that may not be installed, so the list
 * the picker offers comes from the server's registry, never from the editor.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-user-task-node/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskHooks;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskServices;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The `principal` fields of {@see UserTaskNode::configForm()}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\NodeFormField
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\TaskConfigCommon
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskHooks
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskServices
 */
final class UserTaskPrincipalFieldsTest extends TestCase {

	/**
	 * Every field that names a performer.
	 *
	 * @var array<int, string>
	 */
	private const PERFORMER_FIELDS = [
		'assignee', 'candidates', 'candidateUsers', 'candidateGroups', 'candidateRole', 'routingFallback',
	];

	/**
	 * The node's form, wired with the given registry.
	 *
	 * @param PrincipalResolverRegistry|null $principals The registry, if any.
	 *
	 * @return array<string, array<string, mixed>> The fields by key.
	 */
	private function form(?PrincipalResolverRegistry $principals): array {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$node = new UserTaskNode(
			new UserTaskServices(
				$this->createMock(FlowTaskBridge::class),
				$this->createMock(TaskFormReader::class),
				$this->createMock(FlowTimerService::class)
			),
			new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)),
			new UserTaskHooks($principals)
		);

		$byKey = [];
		foreach ($node->configForm() as $field) {
			$byKey[$field['key']] = $field;
		}

		return $byKey;
	}//end form()

	/**
	 * A registry that understands the given types.
	 *
	 * @param array<int, string> $types The registered types.
	 *
	 * @return PrincipalResolverRegistry The registry.
	 */
	private function registry(array $types): PrincipalResolverRegistry {
		$registry = $this->createMock(PrincipalResolverRegistry::class);
		$registry->method('types')->willReturn($types);

		return $registry;
	}//end registry()

	/**
	 * Every performer field offers exactly the types the registry holds,
	 * including one an app contributed.
	 *
	 * @return void
	 */
	public function testEveryPerformerFieldOffersTheRegisteredTypes(): void {
		$types = ['agent', 'group', 'position', 'user'];
		$form  = $this->form(principals: $this->registry(types: $types));

		foreach (self::PERFORMER_FIELDS as $key) {
			$this->assertArrayHasKey($key, $form, $key);
			$this->assertSame('principal', $form[$key]['type'], $key);
			$this->assertSame($types, $form[$key]['principalTypes'], $key);
		}
	}//end testEveryPerformerFieldOffersTheRegisteredTypes()

	/**
	 * A type that is not registered is not offered: the editor holds no list
	 * of its own.
	 *
	 * @return void
	 */
	public function testAnUninstalledTypeIsNotOffered(): void {
		$form = $this->form(principals: $this->registry(types: ['group', 'user']));

		$this->assertNotContains('position', $form['assignee']['principalTypes']);
		$this->assertSame(['group', 'user'], $form['candidates']['principalTypes']);
	}//end testAnUninstalledTypeIsNotOffered()

	/**
	 * Only performer fields carry the list; a plain field does not.
	 *
	 * @return void
	 */
	public function testOnlyPerformerFieldsCarryTheList(): void {
		$form = $this->form(principals: $this->registry(types: ['user']));

		foreach ($form as $key => $field) {
			if (in_array($key, self::PERFORMER_FIELDS, true) === true) {
				continue;
			}

			$this->assertArrayNotHasKey('principalTypes', $field, $key);
		}
	}//end testOnlyPerformerFieldsCarryTheList()

	/**
	 * Without a registry the field declares no list, rather than an empty one
	 * that would tell the editor no type exists.
	 *
	 * @return void
	 */
	public function testWithoutARegistryNoListIsDeclared(): void {
		$form = $this->form(principals: null);

		$this->assertSame('principal', $form['assignee']['type']);
		$this->assertArrayNotHasKey('principalTypes', $form['assignee']);
	}//end testWithoutARegistryNoListIsDeclared()

	/**
	 * No performer field is a text box, and `performerType` is not asked for:
	 * the type travels with each reference.
	 *
	 * @return void
	 */
	public function testNoPerformerFieldIsTextAndPerformerTypeIsNotAsked(): void {
		$form = $this->form(principals: null);

		foreach (self::PERFORMER_FIELDS as $key) {
			$this->assertNotSame('text', $form[$key]['type'], $key);
		}

		$this->assertArrayNotHasKey('performerType', $form);
	}//end testNoPerformerFieldIsTextAndPerformerTypeIsNotAsked()
}//end class
