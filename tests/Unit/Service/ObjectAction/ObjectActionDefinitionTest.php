<?php

/**
 * Unit tests for the declared-action shape: one body, inputs, when, surfaces, elevate.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use PHPUnit\Framework\TestCase;

class ObjectActionDefinitionTest extends TestCase {

	/**
	 * A configuration declaring one action, with a static lifecycle.
	 *
	 * @param array $extra Keys on the declaration.
	 *
	 * @return array The configuration.
	 */
	private function configuration(array $extra): array {
		return [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'transitions' => ['finish' => ['from' => ['open'], 'to' => 'done']],
			],
			'x-openregister-action' => [
				'publish' => array_merge(['name' => 'Publish', 'description' => 'Make it public.'], $extra),
			],
		];
	}//end configuration()

	/**
	 * The refusals one declaration earns, with a granting authorization block.
	 *
	 * @param array $extra Keys on the declaration.
	 *
	 * @return array The refusals.
	 */
	private function refusals(array $extra): array {
		return ObjectActionDefinition::refusals($this->configuration($extra), ['publish' => [['group' => 'redactie']]]);
	}//end refusals()

	public function testTwoBodiesAreRefusedNamingTheAction(): void {
		$refusals = $this->refusals(['transition' => 'finish', 'steps' => [['type' => 'openregister.set-fields']]]);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"publish"', $refusals[0]);
		$this->assertStringContainsString('one body', $refusals[0]);
	}//end testTwoBodiesAreRefusedNamingTheAction()

	public function testATransitionTheLifecycleDoesNotDeclareIsRefused(): void {
		$refusals = $this->refusals(['transition' => 'fulfil']);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('no transition "fulfil"', $refusals[0]);
	}//end testATransitionTheLifecycleDoesNotDeclareIsRefused()

	public function testADeclaredTransitionIsAccepted(): void {
		$this->assertSame([], $this->refusals(['transition' => 'finish']));
	}//end testADeclaredTransitionIsAccepted()

	public function testAProviderLifecycleAcceptsAnyTransitionKey(): void {
		$configuration = $this->configuration(['transition' => 'abort']);
		$configuration['x-openregister-lifecycle'] = ['field' => 'status', 'provider' => 'dossiq.case'];

		$this->assertSame([], ObjectActionDefinition::refusals($configuration, []));
	}//end testAProviderLifecycleAcceptsAnyTransitionKey()

	public function testInputsThatAreNotAnObjectSchemaAreRefused(): void {
		$refusals = $this->refusals(['inputs' => ['type' => 'string']]);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"inputs"', $refusals[0]);
	}//end testInputsThatAreNotAnObjectSchemaAreRefused()

	public function testAWhenThatIsNotJsonLogicIsRefused(): void {
		$refusals = $this->refusals(['when' => ['no-such-operator' => [1]]]);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"when"', $refusals[0]);
	}//end testAWhenThatIsNotJsonLogicIsRefused()

	public function testASurfaceOutsideTheVocabularyIsRefused(): void {
		$refusals = $this->refusals(['surfaces' => ['header', 'sidebar']]);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"surfaces"', $refusals[0]);
	}//end testASurfaceOutsideTheVocabularyIsRefused()

	public function testBulkWithAnObjectDefaultIsRefused(): void {
		$refusals = $this->refusals(
			[
				'surfaces' => ['bulk'],
				'inputs' => ['type' => 'object', 'properties' => ['to' => ['type' => 'string', 'default' => '@object.applicant']]],
			]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('"@object"', $refusals[0]);
	}//end testBulkWithAnObjectDefaultIsRefused()

	public function testAnAsyncTransitionIsRefused(): void {
		$refusals = $this->refusals(['transition' => 'finish', 'mode' => 'async']);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('always runs sync', $refusals[0]);
	}//end testAnAsyncTransitionIsRefused()

	public function testAnAsyncActionWithAWriteOnlyInputIsRefused(): void {
		$refusals = $this->refusals(
			[
				'mode' => 'async',
				'steps' => [['type' => 'openregister.set-fields']],
				'inputs' => ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'writeOnly' => true]]],
			]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('writeOnly', $refusals[0]);
	}//end testAnAsyncActionWithAWriteOnlyInputIsRefused()

	public function testAnUnknownNextHintIsRefused(): void {
		$this->assertCount(1, $this->refusals(['next' => 'elsewhere']));
	}//end testAnUnknownNextHintIsRefused()

	public function testStepsThatAreNotAListOfTypedNodesAreRefused(): void {
		$this->assertCount(1, $this->refusals(['steps' => []]));
		$this->assertCount(1, $this->refusals(['steps' => [['config' => []]]]));
		$this->assertCount(1, $this->refusals(['steps' => [['type' => 'openregister.set-fields', 'config' => 'x']]]));
	}//end testStepsThatAreNotAListOfTypedNodesAreRefused()

	public function testAWildcardElevationIsRefused(): void {
		$refusals = $this->refusals(
			['steps' => [['type' => 'openregister.set-fields']], 'elevate' => [['register' => '*', 'schema' => '*', 'verbs' => ['create']]]]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('wildcard', $refusals[0]);
	}//end testAWildcardElevationIsRefused()

	public function testAnElevationNoRuleGrantsIsRefused(): void {
		$refusals = ObjectActionDefinition::refusals(
			$this->configuration(['steps' => [['type' => 'x.y']], 'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create']]]]),
			[]
		);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('privilege escalation', $refusals[0]);
	}//end testAnElevationNoRuleGrantsIsRefused()

	public function testAnElevationWithAnUnknownVerbIsRefused(): void {
		$refusals = $this->refusals(['steps' => [['type' => 'x.y']], 'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['read']]]]);

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('verbs', $refusals[0]);
	}//end testAnElevationWithAnUnknownVerbIsRefused()

	public function testAScopedGrantedElevationIsAccepted(): void {
		$this->assertSame(
			[],
			$this->refusals(['steps' => [['type' => 'x.y']], 'elevate' => [['register' => 'pub', 'schema' => 'publication', 'verbs' => ['create', 'update']]]])
		);
	}//end testAScopedGrantedElevationIsAccepted()

	public function testABodilessActionIsValidButNotRunnable(): void {
		$configuration = ['x-openregister-action' => ['sendMail' => ['name' => 'Send mail', 'description' => 'Send it.']]];

		$this->assertSame([], ObjectActionDefinition::refusals($configuration, []));
		$definitions = ObjectActionDefinition::parse($configuration);
		$this->assertFalse($definitions['sendMail']->isRunnable());
	}//end testABodilessActionIsValidButNotRunnable()

	public function testAnExistingMacroDeclarationReadsAsAFlowAction(): void {
		$configuration = [
			'x-openregister-action' => [
				'close-and-notify' => ['name' => 'Close and notify', 'description' => 'Close it.', 'macro' => true, 'flow' => 'flow-1'],
			],
		];

		$this->assertSame([], ObjectActionDefinition::refusals($configuration, []));
		$definition = ObjectActionDefinition::parse($configuration)['close-and-notify'];
		$this->assertSame(ObjectActionDefinition::KIND_FLOW, $definition->kind);
		$this->assertSame('flow-1', $definition->body);
		$this->assertSame(['header', 'row'], $definition->surfaces);
		$this->assertSame('Close and notify', $definition->label);
		$this->assertSame('sync', $definition->mode);
	}//end testAnExistingMacroDeclarationReadsAsAFlowAction()

	public function testParseLeavesOutAMalformedEntry(): void {
		$configuration = $this->configuration(['transition' => 'finish', 'flow' => 'x']);

		$this->assertSame([], ObjectActionDefinition::parse($configuration));
	}//end testParseLeavesOutAMalformedEntry()

	public function testWriteOnlyInputsAreNamed(): void {
		$definition = ObjectActionDefinition::fromDeclaration(
			'sign',
			[
				'steps' => [['type' => 'x.y']],
				'inputs' => ['type' => 'object', 'properties' => ['pin' => ['type' => 'string', 'writeOnly' => true], 'note' => ['type' => 'string']]],
			]
		);

		$this->assertSame(['pin'], $definition->writeOnlyInputs());
	}//end testWriteOnlyInputsAreNamed()
}//end class
