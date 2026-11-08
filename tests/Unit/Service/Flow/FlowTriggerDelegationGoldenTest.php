<?php

/**
 * Golden test for the delegation check a trigger save goes through.
 *
 * Pins, as one matrix, every allowed AND refused outcome of saving a trigger
 * node that names a `runAs`: the stamp that survives (or is stripped), the
 * exact refusal messages, the fail-closed refusal when the delegation store
 * cannot be reached, and that non-trigger and malformed nodes are left alone —
 * so the validator can be split into smaller units without moving a verdict.
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
 * Golden allowed/refused outcomes for a trigger's `runAs` delegation.
 */
class FlowTriggerDelegationGoldenTest extends TestCase {

	private const STAMP = FlowTriggerValidator::CONFIG_DECLARED_BY;

	/**
	 * How many times the delegation service was asked.
	 *
	 * @var int
	 */
	private int $asked = 0;

	/**
	 * How many errors were logged.
	 *
	 * @var int
	 */
	private int $errors = 0;

	/**
	 * A validator for a session user (or none), a delegation verdict (or an unreachable store).
	 *
	 * @param string|null            $savedBy The saving user, null for a code-initiated save.
	 * @param DelegationVerdict|null $verdict The verdict, null for an unreachable delegation store.
	 *
	 * @return FlowTriggerValidator The validator.
	 */
	private function validator(?string $savedBy, ?DelegationVerdict $verdict): FlowTriggerValidator {
		$trigger = new class implements IFlowNode, IFlowTriggerNode {
			/**
			 * The node id.
			 *
			 * @return string
			 */
			public function getId(): string {
				return 'trigger';
			}

			/**
			 * The display name.
			 *
			 * @return string
			 */
			public function getDisplayName(): string {
				return 'Trigger';
			}

			/**
			 * The description.
			 *
			 * @return string
			 */
			public function getDescription(): string {
				return 'A trigger.';
			}

			/**
			 * The icon.
			 *
			 * @return string
			 */
			public function getIcon(): string {
				return '/i.svg';
			}

			/**
			 * Always available.
			 *
			 * @param int $scope The scope.
			 *
			 * @return bool
			 */
			public function isAvailableForScope(int $scope): bool {
				return true;
			}

			/**
			 * Refuses a config carrying `bad`.
			 *
			 * @param array $config The config.
			 *
			 * @return void
			 */
			public function validateConfig(array $config): void {
				if (isset($config['bad']) === true) {
					throw new InvalidArgumentException('bad config');
				}
			}

			/**
			 * Pass-through.
			 *
			 * @param array $items   The items.
			 * @param array $config  The config.
			 * @param array $context The context.
			 *
			 * @return array
			 */
			public function execute(array $items, array $config, array $context): array {
				return $items;
			}
		};
		$step     = $this->createMock(IFlowNode::class);
		$registry = new class(['trigger' => $trigger, 'step' => $step]) {
			/**
			 * Constructor.
			 *
			 * @param array<string, object> $nodes The nodes by type.
			 */
			public function __construct(private readonly array $nodes) {
			}

			/**
			 * Resolve a node type.
			 *
			 * @param string $type The type.
			 *
			 * @return object The node.
			 */
			public function get(string $type): object {
				return ($this->nodes[$type] ?? throw new UnexpectedValueException('Unknown node type: '.$type));
			}
		};

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($savedBy !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($savedBy);
		}

		$session->method('getUser')->willReturn($user);

		$delegation = $this->createMock(DelegationService::class);
		$delegation->method('verdictFor')->willReturnCallback(
			function (string $principal, string $actingAs) use ($verdict): DelegationVerdict {
				$this->asked++;
				return $verdict;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registry, $session, $delegation, $verdict): object {
				if ($id === IUserSession::class) {
					return $session;
				}

				if ($id === DelegationService::class) {
					if ($verdict === null) {
						throw new RuntimeException('store down');
					}

					return $delegation;
				}

				return $registry;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (): void {
				$this->errors++;
			}
		);

		return new FlowTriggerValidator($container, $logger);

	}//end validator()

	/**
	 * A granted verdict.
	 *
	 * @return DelegationVerdict
	 */
	private static function granted(): DelegationVerdict {
		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');
		return DelegationVerdict::granted($grant);

	}//end granted()

	/**
	 * Save one node and return the stored nodes, or the refusal message.
	 *
	 * @param FlowTriggerValidator $validator The validator.
	 * @param mixed                $node      The node.
	 *
	 * @return array<int, mixed>|string The nodes after the save, or the refusal.
	 */
	private static function save(FlowTriggerValidator $validator, mixed $node): array|string {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes([$node]);
		$flow->setEdges([]);
		try {
			$validator->validate($flow);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return $flow->getNodes();

	}//end save()

	/**
	 * The allowed outcomes: what survives, and whether the delegation store was asked.
	 *
	 * @return void
	 */
	public function testAllowedOutcomes(): void {
		$cases = [
			'granted third party is stamped' => ['alice', ' mayor ', self::granted(), ['runAs' => ' mayor ', self::STAMP => 'alice'], 1],
			'granted overwrites a forged stamp' => ['alice', 'mayor', self::granted(), ['runAs' => 'mayor', self::STAMP => 'alice'], 1],
			'self needs no grant, stamp stripped' => ['alice', ' alice ', null, ['runAs' => ' alice '], 0],
			'no runAs, stamp stripped' => ['alice', '', null, ['runAs' => ''], 0],
			'code-initiated save, stamp stripped' => [null, 'mayor', null, ['runAs' => 'mayor'], 0],
		];
		foreach ($cases as $name => [$savedBy, $runAs, $verdict, $expected, $asked]) {
			$this->asked = 0;
			$node        = ['id' => 'start', 'type' => 'trigger', 'config' => ['runAs' => $runAs, self::STAMP => 'forged']];
			$result      = self::save(validator: $this->validator(savedBy: $savedBy, verdict: $verdict), node: $node);
			$this->assertSame([['id' => 'start', 'type' => 'trigger', 'config' => $expected]], $result, $name);
			$this->assertSame($asked, $this->asked, $name);
		}

		// Nothing to strip and nothing to stamp: the node is stored as it came.
		$plain = ['id' => 'start', 'type' => 'trigger', 'config' => ['cron' => '* * * * *']];
		$this->assertSame([$plain], self::save(validator: $this->validator(savedBy: 'alice', verdict: null), node: $plain));

	}//end testAllowedOutcomes()

	/**
	 * The refused outcomes, with their exact messages.
	 *
	 * @return void
	 */
	public function testRefusedOutcomes(): void {
		$refused = DelegationVerdict::refused(DelegationVerdict::REASON_NONE, 'Ask them for a grant.');
		$node    = ['id' => 'start', 'type' => 'trigger', 'config' => ['runAs' => 'mayor']];

		$this->assertSame(
			'The schedule trigger names "mayor", but "alice" may not act as them ('.DelegationVerdict::REASON_NONE.'). Ask them for a grant.',
			self::save(validator: $this->validator(savedBy: 'alice', verdict: $refused), node: $node)
		);

		$this->assertSame(
			'Cannot verify that "alice" may schedule runs as "mayor": the delegation store is unavailable. The trigger has not been saved.',
			self::save(validator: $this->validator(savedBy: 'alice', verdict: null), node: $node)
		);
		$this->assertSame(1, $this->errors, 'the unreachable store is logged once');

		// The node's own refusal comes first, before any delegation check.
		$this->asked = 0;
		$this->assertSame(
			'bad config',
			self::save(validator: $this->validator(savedBy: 'alice', verdict: self::granted()), node: ['type' => 'trigger', 'config' => ['bad' => 1, 'runAs' => 'mayor']])
		);
		$this->assertSame(0, $this->asked);

	}//end testRefusedOutcomes()

	/**
	 * Non-trigger, unknown and malformed nodes are never checked or changed.
	 *
	 * @return void
	 */
	public function testOtherNodesAreLeftAlone(): void {
		$forged = ['runAs' => 'mayor', self::STAMP => 'forged'];
		$nodes  = [
			'not-a-node',
			['type' => ''],
			['type' => 'unknown', 'config' => $forged],
			['type' => 'step', 'config' => $forged],
			['type' => 'trigger', 'config' => 'not-an-array'],
		];
		foreach ($nodes as $node) {
			$this->assertSame([$node], self::save(validator: $this->validator(savedBy: 'alice', verdict: null), node: $node));
		}

		$this->assertSame(0, $this->errors);

	}//end testOtherNodesAreLeftAlone()
}//end class
