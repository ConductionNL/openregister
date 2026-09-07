<?php

/**
 * Who the step says it is asking, and when a wrong answer is refused.
 *
 * 🔴 THE LEGACY FIELDS MUST KEEP CONFIGURING A STEP. `candidateUsers`,
 * `candidateGroups` and `candidateRole` are a type system implemented as field
 * names, and they exist in stored flows across the fleet. Each is read as
 * candidates of its OWN type, so no stored document changes meaning and none
 * needs migrating.
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

use OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * A resolver that simply exists, so a type is known.
 */
class KnownTypeResolver implements IPrincipalResolver {

	/**
	 * Constructor.
	 *
	 * @param string $type The type it answers for.
	 */
	public function __construct(private readonly string $type) {

	}//end __construct()

	/**
	 * The type.
	 *
	 * @return string The type.
	 */
	public function type(): string {
		return $this->type;
	}//end type()

	/**
	 * Nobody in particular.
	 *
	 * @param string $id The id.
	 *
	 * @return array<int, string> Always empty.
	 */
	public function resolve(string $id): array {
		return [];
	}//end resolve()
}//end class

/**
 * The typed half of {@see UserTaskConfig}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 */
final class UserTaskTypedPerformersTest extends TestCase {

	/**
	 * A config reader that knows `user`, `group` and `position`.
	 *
	 * @param array<int, string> $types The known types.
	 *
	 * @return UserTaskConfig The reader.
	 */
	private function configReader(array $types = ['user', 'group', 'position']): UserTaskConfig {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $params = []): string {
				// vsprintf understands `%1$s` positionally, which is exactly
				// what IL10N::t() does; rewriting them to `%s` first turns two
				// references to one argument into two arguments.
				if ($params === []) {
					return $text;
				}

				return vsprintf($text, $params);
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($types): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === false) {
					return;
				}

				foreach ($types as $type) {
					$event->registerResolver(new KnownTypeResolver($type));
				}
			}
		);

		return new UserTaskConfig(
			l10n: $l10n,
			forms: $this->formReader(),
			principals: new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class))
		);
	}//end configReader()

	/**
	 * A form reader that reads a real form.
	 *
	 * ⚠️ `TaskForm` is FINAL, so PHPUnit cannot generate a return value for
	 * `fromConfig()` and the auto-stub throws. The real reader is used instead:
	 * it has no collaborators, and a step with no form declaration passes
	 * validation, which is exactly the shape these tests want.
	 *
	 * @return TaskFormReader The reader.
	 */
	private function formReader(): TaskFormReader {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new TaskFormReader(
			$this->createMock(SchemaMapper::class),
			$this->createMock(TransitionEngine::class),
			$this->createMock(IAppManager::class),
			$l10n
		);
	}//end formReader()

	/**
	 * The performers a config names, as `type:id` strings.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 *
	 * @return array<int, string> The references.
	 */
	private function performersOf(array $config): array {
		return array_map(
			static fn ($r): string => (string)$r,
			$this->configReader()->performers(config: $config)
		);
	}//end performersOf()

	/**
	 * 🔴 THE THREE LEGACY FIELDS STILL CONFIGURE A STEP, EACH AS ITS OWN TYPE.
	 *
	 * @return void
	 */
	public function testTheLegacyCandidateFieldsAreReadAsTheirOwnTypes(): void {
		$performers = $this->performersOf(
			[
				'candidateUsers' => ['alice'],
				'candidateGroups' => ['bezwaar'],
				'candidateRole' => 'college',
			]
		);

		// `candidateRole` is a GROUP: the pre-typed guard resolved it through
		// `isInGroup()`, so any other reading moves who may answer.
		$this->assertSame(['user:alice', 'group:bezwaar', 'group:college'], $performers);
	}//end testTheLegacyCandidateFieldsAreReadAsTheirOwnTypes()

	/**
	 * A bare assignee is a user, and a typed one keeps its type.
	 *
	 * @return void
	 */
	public function testAnAssigneeIsReadInBothSpellings(): void {
		$this->assertSame(['user:jdoe'], $this->performersOf(['assignee' => 'jdoe']));
		$this->assertSame(
			['position:chair'],
			$this->performersOf(['assignee' => ['type' => 'position', 'id' => 'chair']])
		);
	}//end testAnAssigneeIsReadInBothSpellings()

	/**
	 * One `candidates` field expresses what three fields did, and may mix.
	 *
	 * @return void
	 */
	public function testOneCandidatesFieldMixesTypes(): void {
		$this->assertSame(
			['user:alice', 'group:bezwaar', 'position:chair'],
			$this->performersOf(
				[
					'candidates' => [
						'alice',
						['type' => 'group', 'id' => 'bezwaar'],
						['type' => 'position', 'id' => 'chair'],
					],
				]
			)
		);
	}//end testOneCandidatesFieldMixesTypes()

	/**
	 * 🔴 AN UNKNOWN TYPE IS REFUSED WHEN THE STEP IS SAVED, NAMING BOTH.
	 *
	 * The author is at the keyboard and the field is on screen; this is the
	 * only moment the person who can fix it is looking.
	 *
	 * @return void
	 */
	public function testAnUnknownTypeIsRefusedAtSaveNamingTypeAndId(): void {
		try {
			$this->configReader()->validate(
				[
					'title' => 'Approve it',
					'assignee' => ['type' => 'gremium', 'id' => 'bezwaarcommissie'],
				]
			);
			$this->fail('an unknown principal type should be refused');
		} catch (UnexpectedValueException $e) {
			$this->assertStringContainsString('gremium', $e->getMessage());
			$this->assertStringContainsString('bezwaarcommissie', $e->getMessage());
		}
	}//end testAnUnknownTypeIsRefusedAtSaveNamingTypeAndId()

	/**
	 * 🔴 A KNOWN TYPE THAT RESOLVES TO NOBODY IS *NOT* REFUSED AT SAVE.
	 *
	 * An empty resolution is a fact about the instance and it changes: a
	 * committee with no members today has members next week. Refusing the SAVE
	 * would make the flow unauthorable for a reason that has nothing to do with
	 * the flow. It fails when the task is created, where somebody actually
	 * needs to be found.
	 *
	 * @return void
	 */
	public function testAKnownTypeThatResolvesToNobodyStillSaves(): void {
		$this->expectNotToPerformAssertions();

		// `KnownTypeResolver` deliberately resolves to nobody.
		$this->configReader()->validate(
			[
				'title' => 'Approve it',
				'assignee' => ['type' => 'position', 'id' => 'vacant'],
			]
		);
	}//end testAKnownTypeThatResolvesToNobodyStillSaves()

	/**
	 * With no registry, nothing is refused.
	 *
	 * An instance that cannot say which types exist must not decide that none
	 * of them do — that would refuse every typed flow on a container-less path.
	 *
	 * @return void
	 */
	public function testWithoutARegistryNoTypeIsRefused(): void {
		$this->expectNotToPerformAssertions();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$config = new UserTaskConfig(l10n: $l10n, forms: $this->formReader());

		$config->validate(
			['title' => 'Approve it', 'assignee' => ['type' => 'gremium', 'id' => 'bezwaar']]
		);
	}//end testWithoutARegistryNoTypeIsRefused()

	/**
	 * A typed assignee counts as naming a performer.
	 *
	 * A `{type, id}` map casts to the string "Array", which is non-empty — so a
	 * string test would call it named for the wrong reason, and would stop
	 * doing so the moment the shape changed.
	 *
	 * @return void
	 */
	public function testATypedAssigneeCountsAsAPerformer(): void {
		$this->expectNotToPerformAssertions();

		$this->configReader()->validate(
			['title' => 'Approve it', 'assignee' => ['type' => 'position', 'id' => 'chair']]
		);
	}//end testATypedAssigneeCountsAsAPerformer()

	/**
	 * A step naming nobody at all is still refused.
	 *
	 * @return void
	 */
	public function testAStepNamingNobodyIsStillRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->configReader()->validate(['title' => 'Approve it']);
	}//end testAStepNamingNobodyIsStillRefused()
}//end class
