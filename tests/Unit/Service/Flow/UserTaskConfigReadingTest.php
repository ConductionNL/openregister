<?php

/**
 * What the step says, read the way the task is built from it.
 *
 * These are the readings a flow author trips over rather than the ones a
 * developer does: a list written as `approved, rejected` in one field and as a
 * real array in another, a title carrying a template, an item stream whose
 * first entry is not a record.
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
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Tests for {@see UserTaskConfig} and the performer-type reading.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 * @uses \OCA\OpenRegister\Service\Task\TaskFormReader
 * @uses \OCA\OpenRegister\Service\Task\TaskForm
 * @uses \OCA\OpenRegister\Service\Flow\FlowValueTemplate
 * @uses \OCA\OpenRegister\Service\Flow\FlowAdvanceBudget
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Db\Task
 */
final class UserTaskConfigReadingTest extends TestCase {

	/**
	 * Translations that render the string they are given.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $p = []): string => ($p === [] ? $text : vsprintf($text, $p))
		);

		return $l10n;
	}//end l10n()

	/**
	 * A form reader with no collaborators of consequence.
	 *
	 * ⚠️ The real reader, not a double: `TaskForm` is FINAL, so PHPUnit cannot
	 * generate a return value for `fromConfig()` and the auto-stub throws.
	 *
	 * @return TaskFormReader The reader.
	 */
	private function formReader(): TaskFormReader {
		return new TaskFormReader(
			$this->createMock(SchemaMapper::class),
			$this->createMock(TransitionEngine::class),
			$this->createMock(IAppManager::class),
			$this->l10n()
		);
	}//end formReader()

	/**
	 * A registry that knows the given types.
	 *
	 * @param array<int, string> $types The types it understands.
	 *
	 * @return PrincipalResolverRegistry The registry.
	 */
	private function registry(array $types): PrincipalResolverRegistry {
		$resolvers = [];
		foreach ($types as $type) {
			$resolvers[] = new class($type) implements IPrincipalResolver {

				/**
				 * Constructor.
				 *
				 * @param string $name The type it answers for.
				 */
				public function __construct(private readonly string $name) {
				}

				/**
				 * The type.
				 *
				 * @return string The type.
				 */
				public function type(): string {
					return $this->name;
				}

				/**
				 * Who holds it.
				 *
				 * @param string $id The id.
				 *
				 * @return array<int, string> The holders.
				 */
				public function resolve(string $id): array {
					return ['alice'];
				}
			};
		}

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($resolvers): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === false) {
					return;
				}

				foreach ($resolvers as $resolver) {
					$event->registerResolver($resolver);
				}
			}
		);

		return new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));
	}//end registry()

	/**
	 * The config reader, over a registry that knows the given types.
	 *
	 * @param array<int, string> $types The known types.
	 *
	 * @return UserTaskConfig The reader.
	 */
	private function config(array $types = ['user', 'group']): UserTaskConfig {
		return new UserTaskConfig(l10n: $this->l10n(), forms: $this->formReader());
	}//end config()

	/**
	 * The performers reader, over a registry that knows the given types.
	 *
	 * @param array<int, string>|null $types The known types, or null for no registry.
	 *
	 * @return UserTaskPerformers The reader.
	 */
	private function performers(?array $types): UserTaskPerformers {
		$registry = null;
		if ($types !== null) {
			$registry = $this->registry($types);
		}

		return new UserTaskPerformers(config: $this->config(), principals: $registry);
	}//end performers()

	/**
	 * A list written as a comma-separated string is read as a list.
	 *
	 * That is how an author types one into a text field, and reading it as a
	 * single name would create one outcome called "approved, rejected".
	 *
	 * @return void
	 */
	public function testACommaSeparatedStringIsAList(): void {
		$data = $this->config()->taskData(
			config: [
				'title' => 'Approve it',
				'assignee' => 'alice',
				'outcomes' => 'approved, rejected',
				'candidateUsers' => 'alice, bob',
			],
			items: [['json' => []]],
			nodeId: 'ask',
			nodeType: 'openregister.user-task'
		);

		$this->assertSame(['approved', 'rejected'], $data['metadata']['outcomes']);
		$this->assertSame(['alice', 'bob'], $data['candidateUsers']);
	}//end testACommaSeparatedStringIsAList()

	/**
	 * Empty and non-scalar entries are dropped from a list.
	 *
	 * A trailing comma is the ordinary way to produce an empty entry, and an
	 * empty candidate would be a performer nobody can be.
	 *
	 * @return void
	 */
	public function testEmptyAndNonScalarEntriesAreDropped(): void {
		$data = $this->config()->taskData(
			config: [
				'title' => 'Approve it',
				'assignee' => 'alice',
				'candidateUsers' => ['alice', '  ', ['nested'], 'bob'],
			],
			items: [['json' => []]],
			nodeId: 'ask',
			nodeType: 'openregister.user-task'
		);

		$this->assertSame(['alice', 'bob'], $data['candidateUsers']);
	}//end testEmptyAndNonScalarEntriesAreDropped()

	/**
	 * A list that ends up empty is stored as null, not as an empty list.
	 *
	 * @return void
	 */
	public function testAnEmptyListIsStoredAsNull(): void {
		$data = $this->config()->taskData(
			config: ['title' => 'Approve it', 'assignee' => 'alice', 'candidateGroups' => '  ,  '],
			items: [['json' => []]],
			nodeId: 'ask',
			nodeType: 'openregister.user-task'
		);

		$this->assertNull($data['candidateGroups']);
	}//end testAnEmptyListIsStoredAsNull()

	/**
	 * 🔑 THE FIRST REAL RECORD IS WHAT THE TEMPLATES RENDER AGAINST.
	 *
	 * A stream may carry something that is not a record, and skipping to the
	 * first one that is keeps a title from rendering blank for a reason the
	 * author cannot see.
	 *
	 * @return void
	 */
	public function testTemplatesRenderAgainstTheFirstRecord(): void {
		$data = $this->config()->taskData(
			config: [
				'title' => 'Approve {{ subject }}',
				'assignee' => 'alice',
				'description' => 'About {{ subject }}',
			],
			items: [['json' => ['subject' => 'the objection']]],
			nodeId: 'ask',
			nodeType: 'openregister.user-task'
		);

		$this->assertSame('Approve the objection', $data['title']);
		$this->assertSame('About the objection', $data['description']);
	}//end testTemplatesRenderAgainstTheFirstRecord()

	/**
	 * A field that is not configured stays null rather than becoming ''.
	 *
	 * An empty string in a date column is not a date, and it reads as "set" to
	 * everything downstream.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredFieldStaysNull(): void {
		$data = $this->config()->taskData(
			config: ['title' => 'Approve it', 'assignee' => 'alice', 'description' => '   '],
			items: [['json' => []]],
			nodeId: 'ask',
			nodeType: 'openregister.user-task'
		);

		$this->assertNull($data['description']);
		$this->assertNull($data['dueAt']);
		$this->assertNull($data['expiresAt']);
	}//end testAnUnconfiguredFieldStaysNull()

	/**
	 * An outcomes field that names nothing is refused, naming the spelling.
	 *
	 * @return void
	 */
	public function testAnOutcomesFieldThatNamesNothingIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->config()->validate(['title' => 'Approve it', 'assignee' => 'alice', 'outcomes' => '  ']);
	}//end testAnOutcomesFieldThatNamesNothingIsRefused()

	/**
	 * A heartbeat that is not a number is refused.
	 *
	 * @return void
	 */
	public function testANonNumericHeartbeatIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->config()->validate(
			['title' => 'Approve it', 'assignee' => 'alice', 'heartbeatMinutes' => 'often']
		);
	}//end testANonNumericHeartbeatIsRefused()

	/**
	 * A step naming everything correctly saves.
	 *
	 * @return void
	 */
	public function testACompleteStepSaves(): void {
		$this->expectNotToPerformAssertions();

		$config = [
			'title' => 'Approve it',
			'assignee' => ['type' => 'group', 'id' => 'bezwaar'],
			'outcomes' => 'approved, rejected',
			'heartbeatMinutes' => '15',
		];

		$this->config()->validate($config);
		$this->performers(['user', 'group'])->refuseUnknownTypes($config, $this->l10n());
	}//end testACompleteStepSaves()

	/**
	 * 🔴 A PERFORMER TYPE NOTHING ON THIS SERVER UNDERSTANDS IS REFUSED AT SAVE.
	 *
	 * An unknown type is a defect in the document: the author is at the
	 * keyboard, the field is on screen, and the fix is to pick a different
	 * type. The refusal names both the type and the id so the author can find
	 * the field it came from.
	 *
	 * @return void
	 */
	public function testAnUnknownPerformerTypeIsRefusedNamingTypeAndId(): void {
		try {
			$this->performers(['user', 'group'])->refuseUnknownTypes(
				['title' => 'Approve it', 'assignee' => ['type' => 'gremium', 'id' => 'raad']],
				$this->l10n()
			);
			$this->fail('an unknown performer type should be refused');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('gremium', $e->getMessage());
			$this->assertStringContainsString('raad', $e->getMessage());
		}
	}//end testAnUnknownPerformerTypeIsRefusedNamingTypeAndId()

	/**
	 * 🔑 AN INSTANCE THAT CANNOT SAY WHICH TYPES EXIST REFUSES NONE OF THEM.
	 *
	 * Without a registry, deciding that no type is known would make every
	 * typed step unauthorable for a reason that has nothing to do with the step.
	 *
	 * @return void
	 */
	public function testWithoutARegistryNoTypeIsRefused(): void {
		$this->expectNotToPerformAssertions();

		$this->performers(null)->refuseUnknownTypes(
			['title' => 'Approve it', 'assignee' => ['type' => 'gremium', 'id' => 'raad']],
			$this->l10n()
		);
	}//end testWithoutARegistryNoTypeIsRefused()

	/**
	 * The authored performer type wins over anything inferred.
	 *
	 * @return void
	 */
	public function testTheAuthoredPerformerTypeWins(): void {
		$this->assertSame(
			'group',
			UserTaskPerformers::kindFor(
				config: ['performerType' => 'group'],
				performers: [PrincipalReference::from(value: ['type' => 'user', 'id' => 'alice'])]
			)
		);
	}//end testTheAuthoredPerformerTypeWins()

	/**
	 * With nothing authored, the first performer whose type is a task
	 * performer kind decides, and a person is the fallback.
	 *
	 * @return void
	 */
	public function testThePerformerKindIsInferredThenDefaultsToUser(): void {
		$this->assertSame(
			'agent',
			UserTaskPerformers::kindFor(
				config: [],
				performers: [PrincipalReference::from(value: ['type' => 'agent', 'id' => 'scribe'])]
			)
		);

		$this->assertSame(
			'user',
			UserTaskPerformers::kindFor(
				config: [],
				performers: [PrincipalReference::from(value: ['type' => 'position', 'id' => 'chair'])]
			)
		);

		$this->assertSame('user', UserTaskPerformers::kindFor(config: [], performers: []));
	}//end testThePerformerKindIsInferredThenDefaultsToUser()
}//end class
