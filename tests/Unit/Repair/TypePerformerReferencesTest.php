<?php

/**
 * Typing what is certain, and reporting what is not.
 *
 * 🔴 THE AMBIGUOUS CASE IS THE WHOLE POINT. An instance may hold a user AND a
 * group of one name, and the guard these strings were written against accepts
 * BOTH — so today's behaviour is genuinely the union, and any rewrite NARROWS
 * it. Narrowing who may answer an approval is a decision for a person, not for
 * a repair step running unattended during an upgrade.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace Unit\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Repair\TypePerformerReferences;
use OCA\OpenRegister\Service\Flow\Principal\IPrincipalResolver;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A resolver that answers for a fixed set of names.
 */
class NamedResolver implements IPrincipalResolver {

	/**
	 * Constructor.
	 *
	 * @param string             $type  The type it answers for.
	 * @param array<int, string> $names The names that exist under it.
	 */
	public function __construct(
		private readonly string $type,
		private readonly array $names = [],
	) {

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
	 * Whether this name exists under this type.
	 *
	 * @param string $id The name.
	 *
	 * @return array<int, string> The one uid, or nothing.
	 */
	public function resolve(string $id): array {
		return in_array($id, $this->names, true) ? [$id] : [];
	}//end resolve()
}//end class

/**
 * Tests for {@see TypePerformerReferences}.
 *
 * @covers \OCA\OpenRegister\Repair\TypePerformerReferences
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalReference
 * @uses \OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry
 * @uses \OCA\OpenRegister\Service\Flow\Principal\RegisterPrincipalResolversEvent
 * @uses \OCA\OpenRegister\Db\Flow
 */
final class TypePerformerReferencesTest extends TestCase {

	/**
	 * The flows the fixture instance holds.
	 *
	 * @var array<int, Flow>
	 */
	private array $flows = [];

	/**
	 * Flows handed back to the mapper.
	 *
	 * @var array<int, Flow>
	 */
	private array $stored = [];

	/**
	 * Everything the step printed as a warning.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A flow with one step carrying this config.
	 *
	 * @param array<string, mixed> $config The step configuration.
	 * @param string               $uuid   The flow uuid.
	 *
	 * @return Flow The flow.
	 */
	private function flowWith(array $config, string $uuid = 'flow-1'): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setNodes([['id' => 'ask', 'type' => 'openregister.user-task', 'config' => $config]]);

		return $flow;
	}//end flowWith()

	/**
	 * Run the step over the fixture.
	 *
	 * @param array<int, IPrincipalResolver> $resolvers What this instance understands.
	 *
	 * @return void
	 */
	private function runStep(array $resolvers): void {
		$mapper = $this->createMock(FlowMapper::class);
		$mapper->method('findAllFlows')->willReturnCallback(
			function (
				?string $app = null,
				?string $applicationSlug = null,
				?string $organisation = null,
				?bool $enabled = null,
				int $limit = 100,
				int $offset = 0,
			): array {
				return array_slice($this->flows, $offset, $limit);
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (Flow $flow): Flow {
				$this->stored[] = $flow;

				return $flow;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($resolvers): void {
				if (($event instanceof RegisterPrincipalResolversEvent) === true) {
					foreach ($resolvers as $resolver) {
						$event->registerResolver($resolver);
					}
				}
			}
		);
		$registry = new PrincipalResolverRegistry($dispatcher, $this->createMock(LoggerInterface::class));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mapper, $registry) {
				return ($id === PrincipalResolverRegistry::class) ? $registry : $mapper;
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		(new TypePerformerReferences($container, $this->createMock(LoggerInterface::class)))->run($output);
	}//end runStep()

	/**
	 * The config of the first stored flow's step.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function storedConfig(): array {
		$this->assertNotSame([], $this->stored, 'nothing was written back');

		return $this->stored[0]->getNodes()[0]['config'];
	}//end storedConfig()

	/**
	 * A string only one kind answers to is rewritten to that kind.
	 *
	 * @return void
	 */
	public function testAnUnambiguousStringIsTyped(): void {
		$this->flows = [$this->flowWith(['assignee' => 'bezwaar'])];

		$this->runStep([new NamedResolver('user', ['alice']), new NamedResolver('group', ['bezwaar'])]);

		$this->assertSame(['type' => 'group', 'id' => 'bezwaar'], $this->storedConfig()['assignee']);
		$this->assertSame([], $this->warnings);
	}//end testAnUnambiguousStringIsTyped()

	/**
	 * 🔴 AN AMBIGUOUS STRING IS LEFT ALONE AND REPORTED, NAMING BOTH TYPES.
	 *
	 * The scenario the requirement is written against. Today both may answer,
	 * so choosing one would silently narrow who can.
	 *
	 * @return void
	 */
	public function testAnAmbiguousStringIsReportedRatherThanGuessed(): void {
		$this->flows = [$this->flowWith(['assignee' => 'support'])];

		$this->runStep([new NamedResolver('user', ['support']), new NamedResolver('group', ['support'])]);

		$this->assertSame([], $this->stored, 'an ambiguous string must not be rewritten');

		$said = implode(' ', $this->warnings);
		$this->assertStringContainsString('AMBIGUOUS', $said);
		$this->assertStringContainsString('support', $said);
		// BOTH types named: "it is ambiguous" without saying between what
		// leaves the reader exactly where they started.
		$this->assertStringContainsString('user', $said);
		$this->assertStringContainsString('group', $said);
	}//end testAnAmbiguousStringIsReportedRatherThanGuessed()

	/**
	 * A string nothing answers to is left alone and reported.
	 *
	 * @return void
	 */
	public function testAnUnresolvableStringIsReportedAndLeft(): void {
		$this->flows = [$this->flowWith(['assignee' => 'ghost'])];

		$this->runStep([new NamedResolver('user', ['alice']), new NamedResolver('group', ['bezwaar'])]);

		$this->assertSame([], $this->stored);
		$said = implode(' ', $this->warnings);
		$this->assertStringContainsString('UNRESOLVABLE', $said);
		$this->assertStringContainsString('ghost', $said);
		// It names WHERE, or nobody can find the flow to fix.
		$this->assertStringContainsString('flow-1', $said);
	}//end testAnUnresolvableStringIsReportedAndLeft()

	/**
	 * 🔴 AN UNRESOLVABLE ENTRY SURVIVES A WRITE-BACK, RATHER THAN BEING DROPPED.
	 *
	 * The spec says the repair SHALL NOT delete it, and "nothing was stored" is
	 * too weak a test to prove that: an implementation that drops the entry
	 * also writes nothing, because the result is then empty. This flow carries
	 * one entry that DOES get typed beside the broken one, so the flow really
	 * is written back — and the broken entry has to still be in it.
	 *
	 * @return void
	 */
	public function testAnUnresolvableEntrySurvivesAWriteBack(): void {
		$this->flows = [$this->flowWith(['assignee' => ['bezwaar', 'ghost']])];

		$this->runStep([new NamedResolver('group', ['bezwaar'])]);

		$assignee = $this->storedConfig()['assignee'];
		$this->assertSame(['type' => 'group', 'id' => 'bezwaar'], $assignee[0], 'the known one is typed');
		$this->assertSame('ghost', $assignee[1], 'the unknown one is left EXACTLY as it was, not dropped');
		$this->assertStringContainsString('ghost', implode(' ', $this->warnings));
	}//end testAnUnresolvableEntrySurvivesAWriteBack()

	/**
	 * 🔴 TWENTY-SEVEN BROKEN FLOWS DO NOT FAIL THE UPGRADE.
	 *
	 * The measured number. Those flows were broken before this step existed,
	 * and a repair that turns pre-existing breakage into a failed `occ upgrade`
	 * makes it everybody's problem instead of their owner's.
	 *
	 * @return void
	 */
	public function testManyBrokenFlowsDoNotFailTheUpgrade(): void {
		$this->flows = [];
		for ($i = 0; $i < 27; $i++) {
			$this->flows[] = $this->flowWith(['assignee' => 'ghost-' . $i], 'flow-' . $i);
		}

		$this->runStep([new NamedResolver('user', ['alice'])]);

		$this->assertCount(27, $this->warnings, 'every one is reported by name');
		$this->assertSame([], $this->stored);
	}//end testManyBrokenFlowsDoNotFailTheUpgrade()

	/**
	 * 🔑 A FIELD WHOSE NAME SAYS THE TYPE IS NOT AMBIGUOUS AT ALL.
	 *
	 * `candidateGroups` holds group names and always did, so its entries need
	 * no resolution and no guess — and typing them cannot be wrong.
	 *
	 * @return void
	 */
	public function testAFieldWhoseNameSaysTheTypeNeedsNoGuess(): void {
		$this->flows = [
			$this->flowWith(
				[
					'candidateGroups' => ['bezwaar'],
					'candidateUsers' => ['alice'],
					'candidateRole' => 'college',
				]
			),
		];

		// Nothing resolves at all, and it still types them: the field name is
		// the evidence, not the roster.
		$this->runStep([new NamedResolver('user', []), new NamedResolver('group', [])]);

		$config = $this->storedConfig();
		$this->assertSame([['type' => 'group', 'id' => 'bezwaar']], $config['candidateGroups']);
		$this->assertSame([['type' => 'user', 'id' => 'alice']], $config['candidateUsers']);
		$this->assertSame(['type' => 'group', 'id' => 'college'], $config['candidateRole']);
		$this->assertSame([], $this->warnings);
	}//end testAFieldWhoseNameSaysTheTypeNeedsNoGuess()

	/**
	 * 🔴 A TEMPLATED ASSIGNEE IS NOT REPORTED AS BROKEN.
	 *
	 * `{{ case.assignee }}` is resolved per item when the step runs, so there
	 * is nothing to look up and nothing wrong with it. Found on the dev
	 * instance: the first draft reported it as "already broken", which would
	 * have sent somebody looking for a flow that works correctly.
	 *
	 * @return void
	 */
	public function testATemplatedAssigneeIsNotCalledBroken(): void {
		$this->flows = [$this->flowWith(['assignee' => '{{ case.assignee }}'])];

		$this->runStep([new NamedResolver('user', ['alice'])]);

		$this->assertSame([], $this->stored, 'a template is left exactly as it is');
		$this->assertSame([], $this->warnings, 'and it is not reported as broken');
	}//end testATemplatedAssigneeIsNotCalledBroken()

	/**
	 * An already-typed reference is left exactly as it is.
	 *
	 * @return void
	 */
	public function testAnAlreadyTypedReferenceIsUntouched(): void {
		$this->flows = [$this->flowWith(['assignee' => ['type' => 'position', 'id' => 'chair']])];

		$this->runStep([new NamedResolver('user', ['chair'])]);

		$this->assertSame([], $this->stored, 'a typed reference is already done');
		$this->assertSame([], $this->warnings);
	}//end testAnAlreadyTypedReferenceIsUntouched()

	/**
	 * One unreadable flow does not stop the rest.
	 *
	 * @return void
	 */
	public function testOneUnreadableFlowDoesNotStopTheRest(): void {
		$bad = new Flow();
		$bad->setUuid('flow-bad');
		$bad->setNodes('not an array');

		$this->flows = [$bad, $this->flowWith(['assignee' => 'bezwaar'], 'flow-good')];

		$this->runStep([new NamedResolver('group', ['bezwaar'])]);

		$this->assertCount(1, $this->stored);
		$this->assertSame('flow-good', (string)$this->stored[0]->getUuid());
	}//end testOneUnreadableFlowDoesNotStopTheRest()

	/**
	 * Missing tables are a skip, not a failure.
	 *
	 * @return void
	 */
	public function testMissingTablesAreASkip(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such table'));

		(new TypePerformerReferences($container, $this->createMock(LoggerInterface::class)))
			->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->stored);
	}//end testMissingTablesAreASkip()

	/**
	 * The step names itself for `occ upgrade`.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$step = new TypePerformerReferences(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNotSame('', $step->getName());
	}//end testTheStepNamesItself()
}//end class
