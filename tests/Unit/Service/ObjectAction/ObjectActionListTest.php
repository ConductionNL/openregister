<?php

/**
 * Unit tests for the one list of actions on an object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputs;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ObjectActionListTest extends TestCase {

	private TransitionEngine&MockObject $transitions;

	/**
	 * Rights the caller holds, by action.
	 *
	 * @var array<string, bool>
	 */
	private array $rights = [];

	protected function setUp(): void {
		parent::setUp();

		$this->transitions = $this->createMock(TransitionEngine::class);
	}//end setUp()

	/**
	 * The list over real definitions, gate and inputs.
	 *
	 * @return ObjectActionList
	 */
	private function list(): ObjectActionList {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			fn ($schema, string $action): bool => ($this->rights[$action] ?? true)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('en');

		return new ObjectActionList(
			new ObjectActionDefinitions($this->createMock(RegisterMapper::class)),
			new ObjectActionGate($permissions, $session, $l10n, $this->createMock(ObjectActionElevationEntries::class)),
			new ObjectActionInputs(new OasRequestValidator()),
			$this->transitions,
			$l10n
		);
	}//end list()

	/**
	 * A case schema with `claim`, `publish`, a bodiless `sendMail` and `finish` over a transition.
	 *
	 * @param array $lifecycle The lifecycle.
	 *
	 * @return Schema
	 */
	private function schema(array $lifecycle = ['field' => 'status', 'transitions' => ['finish' => ['from' => ['in-behandeling'], 'to' => 'done']]]): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => $lifecycle,
				'x-openregister-action' => [
					'claim' => [
						'name' => 'Claim',
						'description' => 'Take it.',
						'when' => ['!' => ['var' => 'object.assignee']],
						'whenMessage' => 'Someone already handles this case.',
						'surfaces' => ['header', 'bulk'],
						'steps' => [['type' => 'openregister.set-fields', 'config' => ['set' => ['assignee' => '{{inputs.who}}']]]],
					],
					'publish' => [
						'name' => 'Publish',
						'description' => 'Make it public.',
						'when' => ['==' => [['var' => 'object.status'], 'decided']],
						'whenMessage' => 'Only a decided case.',
						'steps' => [['type' => 'openregister.set-fields', 'config' => ['set' => ['public' => true]]]],
					],
					'sendMail' => ['name' => 'Send mail', 'description' => 'Grant only.'],
				],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * A claimed case being handled.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setOwner('bob');
		$object->setObject(['status' => 'in-behandeling', 'assignee' => 'anna']);
		return $object;
	}//end object()

	/**
	 * The list entries keyed by action.
	 *
	 * @param array $entries The entries.
	 *
	 * @return array The keyed entries.
	 */
	private function keyed(array $entries): array {
		return array_column($entries, null, 'key');
	}//end keyed()

	public function testAHandlerSeesWhatTheyCanDoAndWhyOneIsGreyedOut(): void {
		$this->transitions->method('availableActions')->willReturn(
			[['action' => 'finish', 'to' => 'done', 'requires' => null, 'description' => null, 'inputs' => [['field' => 'reason', 'required' => true]]]]
		);

		$entries = $this->keyed($this->list()->forObject($this->object(), $this->schema()));

		$this->assertFalse($entries['claim']['available']);
		$this->assertSame('Someone already handles this case.', $entries['claim']['reason']);
		$this->assertFalse($entries['publish']['available']);
		$this->assertSame('Only a decided case.', $entries['publish']['reason']);
		$this->assertSame('transition', $entries['finish']['kind']);
		$this->assertTrue($entries['finish']['available']);
		$this->assertSame(['reason'], $entries['finish']['inputs']['required']);
	}//end testAHandlerSeesWhatTheyCanDoAndWhyOneIsGreyedOut()

	public function testAnActionTheUserMayNotRunIsAbsent(): void {
		$this->transitions->method('availableActions')->willReturn([]);
		$this->rights['publish'] = false;

		$this->assertArrayNotHasKey('publish', $this->keyed($this->list()->forObject($this->object(), $this->schema())));
	}//end testAnActionTheUserMayNotRunIsAbsent()

	public function testABodilessActionIsNeverListed(): void {
		$this->transitions->method('availableActions')->willReturn([]);

		$this->assertArrayNotHasKey('sendMail', $this->keyed($this->list()->forObject($this->object(), $this->schema())));
	}//end testABodilessActionIsNeverListed()

	public function testProviderTransitionsAreListedWithTheirInputsAsJsonSchema(): void {
		$this->transitions->method('availableActions')->willReturn(
			[['action' => 'abort', 'to' => 'aborted', 'requires' => null, 'description' => 'Stop', 'label' => 'Afbreken', 'inputs' => [['field' => 'grond', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A']]]]]]
		);

		$entry = $this->keyed($this->list()->forObject($this->object(), $this->schema(['field' => 'status', 'provider' => 'dossiq.case'])))['abort'];

		$this->assertSame('transition', $entry['kind']);
		$this->assertSame('Afbreken', $entry['label']);
		$this->assertSame('object', $entry['inputs']['type']);
		$this->assertSame(['a'], $entry['inputs']['properties']->grond->enum);
	}//end testProviderTransitionsAreListedWithTheirInputsAsJsonSchema()

	public function testATransitionNamedByADeclaredActionIsListedOnceUnderItsKey(): void {
		$schema = $this->schema();
		$configuration = $schema->getConfiguration();
		$configuration['x-openregister-action']['close'] = ['name' => 'Close', 'description' => 'Close it.', 'label' => 'Sluiten', 'transition' => 'finish'];
		$schema->setConfiguration($configuration);
		$this->transitions->method('availableActions')->willReturn(
			[['action' => 'finish', 'to' => 'done', 'requires' => null, 'description' => null, 'inputs' => []]]
		);

		$entries = $this->keyed($this->list()->forObject($this->object(), $schema));

		$this->assertArrayNotHasKey('finish', $entries);
		$this->assertSame('Sluiten', $entries['close']['label']);
		$this->assertTrue($entries['close']['available']);
	}//end testATransitionNamedByADeclaredActionIsListedOnceUnderItsKey()

	public function testADeclaredTransitionNotAvailableNowIsListedDisabled(): void {
		$schema = $this->schema();
		$configuration = $schema->getConfiguration();
		$configuration['x-openregister-action']['close'] = ['name' => 'Close', 'description' => 'Close it.', 'transition' => 'finish'];
		$schema->setConfiguration($configuration);
		$this->transitions->method('availableActions')->willReturn([]);

		$entry = $this->keyed($this->list()->forObject($this->object(), $schema))['close'];

		$this->assertFalse($entry['available']);
		$this->assertArrayHasKey('reason', $entry);
	}//end testADeclaredTransitionNotAvailableNowIsListedDisabled()

	public function testListingNeedsRead(): void {
		$this->rights['read'] = false;
		$this->transitions->expects($this->never())->method('availableActions');

		$this->expectException(NotAuthorizedException::class);

		$this->list()->forObject($this->object(), $this->schema());
	}//end testListingNeedsRead()

	public function testASelectionOffersOnlyBulkActions(): void {
		$entries = $this->keyed($this->list()->forSelection($this->schema(), null));

		$this->assertSame(['claim'], array_keys($entries));
	}//end testASelectionOffersOnlyBulkActions()
}//end class
