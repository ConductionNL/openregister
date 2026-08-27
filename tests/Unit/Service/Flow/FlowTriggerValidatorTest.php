<?php

/**
 * Unit coverage for FlowTriggerValidator — the save-time trigger check.
 *
 * This class exists because `TriggerScheduleNode::validateConfig()` was an
 * ORPHANED CAPABILITY: written, unit-tested, and never called on the save path,
 * so a schedule trigger with no cron and no identity stored with HTTP 201.
 *
 * These tests therefore assert two different things, and the second is the one
 * that matters. That the validator REFUSES a bad trigger is easy and was already
 * true. That it is REACHED — that a node's own verdict actually propagates out of
 * `validate()` to the caller — is what was missing, and every refusal case here
 * is paired with a positive control so a validator that rejects everything cannot
 * pass this file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowTriggerValidator;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Locks the reachability and the refusal behaviour of the trigger validator.
 */
class FlowTriggerValidatorTest extends TestCase {

	/**
	 * Node ids the registry resolves, keyed by type.
	 *
	 * @var array<string, object>
	 */
	private array $nodes = [];

	/**
	 * Build a validator whose registry resolves $this->nodes.
	 *
	 * @param boolean                 $registryResolves Whether the container can supply a registry.
	 * @param string|null             $savedBy          The uid the session reports, or null for none.
	 * @param DelegationVerdict|null  $verdict          The verdict the delegation service returns.
	 * @param boolean                 $delegationBreaks Whether resolving the delegation service throws.
	 *
	 * @return FlowTriggerValidator The validator under test.
	 */
	private function validator(
		bool $registryResolves = true,
		?string $savedBy = null,
		?DelegationVerdict $verdict = null,
		bool $delegationBreaks = false,
	): FlowTriggerValidator {
		$registry = new class($this->nodes) {
			/**
			 * @param array<string, object> $nodes The resolvable nodes.
			 */
			public function __construct(private readonly array $nodes) {
			}

			/**
			 * @param string $type The node type.
			 *
			 * @return object The node.
			 */
			public function get(string $type): object {
				if (isset($this->nodes[$type]) === false) {
					throw new UnexpectedValueException('Unknown node type: ' . $type);
				}

				return $this->nodes[$type];
			}
		};

		$session = $this->createMock(IUserSession::class);
		if ($savedBy === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($savedBy);
			$session->method('getUser')->willReturn($user);
		}

		$delegation = $this->createMock(DelegationService::class);
		$delegation->method('verdictFor')->willReturn(
			($verdict ?? DelegationVerdict::refused(DelegationVerdict::REASON_NONE, 'no grant'))
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			// Routed by id, not "whatever was asked for". The earlier blanket
			// return handed the REGISTRY back for every lookup, which meant the
			// session probe silently got a non-session and the delegation check
			// was skipped in every test in this file — passing for the wrong
			// reason, which is the failure mode this whole class exists about.
			static function (string $id) use ($registry, $registryResolves, $session, $delegation, $delegationBreaks): object {
				if ($id === IUserSession::class) {
					return $session;
				}

				if ($id === DelegationService::class) {
					if ($delegationBreaks === true) {
						throw new RuntimeException('delegation store unavailable');
					}

					return $delegation;
				}

				if ($registryResolves === false) {
					throw new RuntimeException('registry unavailable');
				}

				return $registry;
			}
		);

		return new FlowTriggerValidator($container, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A flow carrying the given nodes.
	 *
	 * @param array $nodes The node list.
	 *
	 * @return Flow The flow.
	 */
	private function flowWith(array $nodes): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes($nodes);
		$flow->setEdges([]);

		return $flow;
	}

	/**
	 * Register a trigger node double that accepts or refuses its config.
	 *
	 * @param string       $type    The node type id.
	 * @param string|null  $refusal The message to throw, or null to accept.
	 *
	 * @return void
	 */
	private function givenTriggerNode(string $type, ?string $refusal = null): void {
		$this->nodes[$type] = new class($refusal) implements IFlowNode, IFlowTriggerNode {
			/**
			 * @param string|null $refusal The refusal message, or null to accept.
			 */
			public function __construct(private readonly ?string $refusal) {
			}

			public function getId(): string {
				return 'double';
			}

			public function getDisplayName(): string {
				return 'Double';
			}

			public function getDescription(): string {
				return 'A trigger node double.';
			}

			public function getIcon(): string {
				return '/icon.svg';
			}

			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			public function validateConfig(array $config): void {
				if ($this->refusal !== null) {
					throw new InvalidArgumentException($this->refusal);
				}
			}

			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
	}

	/**
	 * Register a NON-trigger node double that would refuse if it were asked.
	 *
	 * @param string $type The node type id.
	 *
	 * @return void
	 */
	private function givenStepNode(string $type): void {
		$this->nodes[$type] = new class implements IFlowNode {
			public function getId(): string {
				return 'step';
			}

			public function getDisplayName(): string {
				return 'Step';
			}

			public function getDescription(): string {
				return 'A step node double.';
			}

			public function getIcon(): string {
				return '/icon.svg';
			}

			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			public function validateConfig(array $config): void {
				throw new InvalidArgumentException('a step was asked, which it should not be');
			}

			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
	}

	/**
	 * POSITIVE CONTROL: an accepted trigger passes through silently.
	 *
	 * Without this, every refusal assertion below is satisfied by a validator
	 * that rejects everything — which would look like a fix and be an outage.
	 *
	 * @return void
	 */
	public function testAnAcceptedTriggerIsNotRefused(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$this->validator()->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '*/5 * * * *']]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * A trigger node's own refusal REACHES the caller.
	 *
	 * This is the whole point of the class. `validateConfig()` refusing was
	 * already true and always had been; nothing called it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testATriggersRefusalPropagates(): void {
		$this->givenTriggerNode('openregister.trigger-schedule', 'A schedule trigger must carry a "runAs".');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/runAs/');

		$this->validator()->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []]])
		);
	}

	/**
	 * A NON-trigger node is never asked.
	 *
	 * Connectivity and step config stay the preflight's business: `flow-engine`
	 * requires that saving a half-wired flow succeeds and warns, and asking every
	 * node here would refuse flows mid-authoring.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAStepNodeIsNotAsked(): void {
		$this->givenStepNode('openregister.set-fields');

		$this->validator()->validate(
			$this->flowWith([['id' => 'step', 'type' => 'openregister.set-fields', 'config' => []]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * An unknown node type is SKIPPED, not refused.
	 *
	 * A leaf app's trigger is not OpenRegister's to validate. Refusing would make
	 * this instance unable to store a flow authored against a fuller one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAnUnknownNodeTypeIsSkipped(): void {
		$this->validator()->validate(
			$this->flowWith([['id' => 'x', 'type' => 'someleafapp.exotic-trigger', 'config' => []]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * A registry that will not build does not refuse the save.
	 *
	 * A resolution failure is not a validation verdict. Refusing every save
	 * because the container was unhealthy turns an infrastructure fault into data
	 * loss for whoever was editing.
	 *
	 * @return void
	 */
	public function testAnUnavailableRegistryDoesNotRefuseTheSave(): void {
		$this->givenTriggerNode('openregister.trigger-schedule', 'would refuse');

		$this->validator(registryResolves: false)->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => []]])
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * Malformed node entries are stepped over rather than fataling.
	 *
	 * `nodes` is stored JSON. A row written by an older build, or by hand, can
	 * hold a scalar or a typeless object, and a save path that fatals on one
	 * cannot be used to repair it.
	 *
	 * @return void
	 */
	public function testMalformedNodesAreSteppedOver(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$this->validator()->validate(
			$this->flowWith(
				[
					'not-an-array',
					['id' => 'no-type'],
					['id' => 'blank-type', 'type' => '   '],
					['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => '*/5 * * * *']],
				]
			)
		);

		$this->addToAssertionCount(1);
	}

	/**
	 * A node whose `config` is not an array is normalised, not skipped.
	 *
	 * The mid-cutover shape on this instance is `config: []`, and a scalar is the
	 * same class of malformation. Both must still reach the node — a trigger that
	 * declares nothing is exactly the case the validator exists to catch, so
	 * skipping it would be the defect.
	 *
	 * @return void
	 */
	public function testANonArrayConfigStillReachesTheNode(): void {
		$this->givenTriggerNode('openregister.trigger-schedule', 'no cron');

		$this->expectException(InvalidArgumentException::class);

		$this->validator()->validate(
			$this->flowWith([['id' => 'start', 'type' => 'openregister.trigger-schedule', 'config' => 'nonsense']])
		);
	}

	/**
	 * A flow with no nodes at all validates cleanly.
	 *
	 * @return void
	 */
	public function testAnEmptyFlowIsAccepted(): void {
		$this->validator()->validate($this->flowWith([]));

		$this->addToAssertionCount(1);
	}

	/**
	 * The delegation stamp the validator writes, read back off a flow.
	 *
	 * @param Flow $flow The validated flow.
	 *
	 * @return mixed The stamp, or null when absent.
	 */
	private function stampOn(Flow $flow) {
		$nodes = ($flow->getNodes() ?? []);

		return (($nodes[0]['config'] ?? [])[FlowTriggerValidator::CONFIG_DECLARED_BY] ?? null);
	}

	/**
	 * A trigger naming SOMEBODY ELSE is refused when no grant covers it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testANamedThirdPartyIsRefusedWithoutAGrant(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/may not act as them/');

		$this->validator(savedBy: 'alice')->validate(
			$this->flowWith(
				[
					[
						'id' => 'start',
						'type' => 'openregister.trigger-schedule',
						'config' => ['cron' => '*/5 * * * *', 'runAs' => 'mayor'],
					],
				]
			)
		);
	}

	/**
	 * POSITIVE CONTROL: naming YOURSELF needs no grant.
	 *
	 * Without this the refusal above is satisfied by a validator that rejects
	 * every `runAs`, which would break the ordinary case — a person scheduling
	 * their own flow — while looking like a security fix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testNamingYourselfNeedsNoGrant(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$flow = $this->flowWith(
			[
				[
					'id' => 'start',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '*/5 * * * *', 'runAs' => 'alice'],
				],
			]
		);

		$this->validator(savedBy: 'alice')->validate($flow);

		$this->assertNull(
			$this->stampOn($flow),
			'a trigger naming its own saver delegates nothing, so it must carry no delegation stamp'
		);
	}

	/**
	 * POSITIVE CONTROL: a granted delegation saves, and is STAMPED.
	 *
	 * The stamp is the half that makes the fire-time re-check possible. A
	 * validator that permitted the save without recording who asserted the
	 * delegation would pass a "does it save" assertion and leave the schedule
	 * unrecheckable at 03:00.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAGrantedDelegationSavesAndRecordsWhoAssertedIt(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');

		$flow = $this->flowWith(
			[
				[
					'id' => 'start',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '*/5 * * * *', 'runAs' => 'mayor'],
				],
			]
		);

		$this->validator(savedBy: 'alice', verdict: DelegationVerdict::granted($grant))->validate($flow);

		$this->assertSame('alice', $this->stampOn($flow));
	}

	/**
	 * 🔴 A `runAsDeclaredBy` supplied by the CLIENT is overwritten, not trusted.
	 *
	 * The stamp is what the fire path checks a grant against. If a request body
	 * could set it, anyone able to save a flow could name a principal who does
	 * hold a grant and have their schedule run as somebody else — the exact
	 * widening the save-time check exists to stop, laundered through a field.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAClientSuppliedStampIsOverwritten(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');

		$flow = $this->flowWith(
			[
				[
					'id' => 'start',
					'type' => 'openregister.trigger-schedule',
					'config' => [
						'cron' => '*/5 * * * *',
						'runAs' => 'mayor',
						FlowTriggerValidator::CONFIG_DECLARED_BY => 'someone-who-does-hold-a-grant',
					],
				],
			]
		);

		$this->validator(savedBy: 'alice', verdict: DelegationVerdict::granted($grant))->validate($flow);

		$this->assertSame(
			'alice',
			$this->stampOn($flow),
			'the stamp must come from the session, never from the payload'
		);
	}

	/**
	 * 🔴 A forged stamp on a SELF-named trigger is stripped.
	 *
	 * The nastier variant of the case above, because this path never consults the
	 * delegation service at all: `runAs === savedBy` short-circuits. Leaving a
	 * payload-supplied stamp in place there would hand the fire path an assertion
	 * that no save-time check ever examined.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAForgedStampOnASelfNamedTriggerIsStripped(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$flow = $this->flowWith(
			[
				[
					'id' => 'start',
					'type' => 'openregister.trigger-schedule',
					'config' => [
						'cron' => '*/5 * * * *',
						'runAs' => 'alice',
						FlowTriggerValidator::CONFIG_DECLARED_BY => 'mayor',
					],
				],
			]
		);

		$this->validator(savedBy: 'alice')->validate($flow);

		$this->assertNull($this->stampOn($flow));
	}

	/**
	 * A code-initiated save — no session — is not subjected to the grant check.
	 *
	 * Migrations, repair steps and installation seeds run with no session at all.
	 * There is no principal for a grant to be checked against, and the tempting
	 * substitute (`flow.owner`) answers a different question.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testACodeInitiatedSaveIsNotGrantChecked(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$flow = $this->flowWith(
			[
				[
					'id' => 'start',
					'type' => 'openregister.trigger-schedule',
					'config' => ['cron' => '*/5 * * * *', 'runAs' => 'mayor'],
				],
			]
		);

		$this->validator(savedBy: null)->validate($flow);

		$this->assertNull($this->stampOn($flow), 'nobody asserted the delegation, so nothing may be stamped');
	}

	/**
	 * An unreachable delegation store REFUSES the delegating save.
	 *
	 * Fail-closed, and bounded: this branch is only reached by a save that is
	 * actually asserting a delegation, so an infrastructure fault costs exactly
	 * the saves whose authorization cannot be established — not every edit.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnreachableDelegationStoreRefusesTheSave(): void {
		$this->givenTriggerNode('openregister.trigger-schedule');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/unavailable/');

		$this->validator(savedBy: 'alice', delegationBreaks: true)->validate(
			$this->flowWith(
				[
					[
						'id' => 'start',
						'type' => 'openregister.trigger-schedule',
						'config' => ['cron' => '*/5 * * * *', 'runAs' => 'mayor'],
					],
				]
			)
		);
	}
}
