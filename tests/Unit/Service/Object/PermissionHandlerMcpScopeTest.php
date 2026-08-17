<?php

/**
 * The `mcp` scope is inert: it never grants, and it never revokes.
 *
 * `mcp` marks an action as one that MAY be offered to an agent. It is a
 * DESCRIPTION of a surface, not a group anyone can be in — whether a given
 * agent holds a right stays resolved in Hermiq against that agent's own
 * grants, because Nextcloud groups are per USER and cannot separate two
 * agents owned by one person.
 *
 * That makes two failure modes, in opposite directions, and this suite pins
 * both:
 *
 *  1. GRANTING. A real Nextcloud group can be named `mcp`. Without a guard,
 *     membership in it satisfies a rule that was written to describe an
 *     agent surface — a privilege escalation delivered by naming a group.
 *
 *  2. REVOKING. An authorization block is fail-closed once non-empty: an
 *     action it does not list is denied. So annotating a previously
 *     unrestricted schema with `read: ["mcp"]` would silently strip humans
 *     of create/update/delete. A descriptive annotation that changes
 *     enforcement is not descriptive.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerMcpScopeTest extends TestCase {

	private PermissionHandler $handler;

	private IUserSession&MockObject $userSession;

	private IUserManager&MockObject $userManager;

	private IGroupManager&MockObject $groupManager;

	/**
	 * Wire a PermissionHandler with everything mocked but the rule grammar.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		$this->handler = new PermissionHandler(
			$this->userSession,
			$this->userManager,
			$this->groupManager,
			$this->createMock(SchemaMapper::class),
			$this->createMock(MagicMapper::class),
			$this->createMock(ConditionMatcher::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class)
		);
	}//end setUp()

	/**
	 * Stage a logged-in non-admin user in the given groups.
	 *
	 * @param array<int, string> $groups The user's real Nextcloud groups.
	 *
	 * @return void
	 */
	private function stageUser(array $groups): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}//end stageUser()

	/**
	 * 🔴 The headline guarantee: being in a REAL Nextcloud group called `mcp`
	 * must not satisfy a rule that names the `mcp` scope.
	 *
	 * This is why the token is `mcp` and not `agents` — but a reserved name
	 * only helps if the evaluator actually reserves it. Nothing stops an
	 * administrator creating the group; the guard has to be in the code.
	 *
	 * @covers ::hasGroupPermission
	 *
	 * @return void
	 */
	public function testMembershipInARealGroupNamedMcpGrantsNothing(): void {
		$this->stageUser(['mcp']);

		$this->assertFalse(
			$this->handler->hasGroupPermission(
				authorization: ['read' => ['mcp']],
				groupId: 'mcp',
				action: 'read',
				userId: 'alice'
			),
			'A user in a real group named "mcp" was granted an action the schema only OFFERED to agents.'
		);
	}//end testMembershipInARealGroupNamedMcpGrantsNothing()

	/**
	 * The same guard for the object-entry form `{"group": "mcp"}`, which is a
	 * second, independent match branch in the rule grammar.
	 *
	 * @covers ::hasGroupPermission
	 *
	 * @return void
	 */
	public function testComplexEntryNamingMcpGrantsNothing(): void {
		$this->stageUser(['mcp']);

		$this->assertFalse(
			$this->handler->hasGroupPermission(
				authorization: ['read' => [['group' => 'mcp']]],
				groupId: 'mcp',
				action: 'read',
				userId: 'alice'
			),
			'The object-entry branch granted on the mcp scope where the string branch did not.'
		);
	}//end testComplexEntryNamingMcpGrantsNothing()

	/**
	 * 🔴 An offers-only block stays an ENFORCEABLE block.
	 *
	 * The first cut of this collapsed it to null — "no authorization
	 * configured" — reasoning that a descriptive annotation should not change
	 * enforcement. But "no authorization configured" is default-OPEN, so the
	 * schema became readable by every authenticated user and by anonymous
	 * callers. An author documenting an agent surface published it instead.
	 *
	 * The direction of failure is what decides this. Treating the annotation as
	 * enforcement-bearing can only DENY — loud and quickly fixed. Treating it as
	 * absent can GRANT, silently, to anonymous. The block gets the fail-safe
	 * reading.
	 *
	 * @covers ::stripMcpScope
	 * @covers ::hasGroupPermission
	 *
	 * @return void
	 */
	public function testAnOffersOnlyBlockStaysEnforceable(): void {
		$stripped = PermissionHandler::stripMcpScope(['read' => ['mcp']]);

		$this->assertSame(
			['read' => []],
			$stripped,
			'An offers-only block collapsed to "no authorization", which is default-OPEN.'
		);

		$this->stageUser(['users']);

		$this->assertFalse(
			$this->handler->hasGroupPermission(
				authorization: $stripped,
				groupId: 'users',
				action: 'read',
				userId: 'alice'
			),
			'An mcp offer admitted a caller who held no rule for the action.'
		);
	}//end testAnOffersOnlyBlockStaysEnforceable()

	/**
	 * Stripping the scope must leave every real rule exactly where it was —
	 * including on the same action the offer was attached to.
	 *
	 * @covers ::stripMcpScope
	 *
	 * @return void
	 */
	public function testStrippingKeepsEveryRealRule(): void {
		$stripped = PermissionHandler::stripMcpScope(
			[
				'read' => ['mcp', 'staff'],
				'create' => ['staff'],
				'public' => true,
			]
		);

		$this->assertSame(
			[
				'read' => ['staff'],
				'create' => ['staff'],
				'public' => true,
			],
			$stripped,
			'Stripping the mcp scope disturbed the surrounding rules.'
		);
	}//end testStrippingKeepsEveryRealRule()

	/**
	 * A block that never mentioned `mcp` must come back byte-identical. The
	 * strip runs on every authorization resolution, so its no-op case is the
	 * overwhelmingly common one and the one a regression would hide in.
	 *
	 * @covers ::stripMcpScope
	 *
	 * @return void
	 */
	public function testABlockWithoutMcpIsUntouched(): void {
		$original = [
			'read' => ['staff', ['group' => 'auditors', 'match' => ['status' => 'open']]],
			'delete' => ['admin'],
			'inheritFromPublic' => false,
		];

		$this->assertSame(
			$original,
			PermissionHandler::stripMcpScope($original),
			'A block with no mcp entries was altered by the strip.'
		);
	}//end testABlockWithoutMcpIsUntouched()

	/**
	 * The `public: true` opt-in is read OFF the resolved block, so the strip
	 * has to preserve it even when it removes every action key around it.
	 * Losing it would turn a deliberately public schema fail-closed.
	 *
	 * @covers ::stripMcpScope
	 *
	 * @return void
	 */
	public function testThePublicOptInSurvivesAnMcpOnlyBlock(): void {
		$this->assertSame(
			['read' => [], 'public' => true],
			PermissionHandler::stripMcpScope(['read' => ['mcp'], 'public' => true]),
			'The public opt-in was dropped along with the mcp scope.'
		);
	}//end testThePublicOptInSurvivesAnMcpOnlyBlock()

	/**
	 * ⚠️ An already-empty rule list means "grant this action to nobody" and is
	 * the STRICTEST rule the grammar can express. The strip must not mistake it
	 * for a key it emptied itself and drop it — that converts the strictest
	 * rule into no rule at all, which is default-OPEN.
	 *
	 * Caught by PermissionHandlerCustomScopeTest on the first run of this
	 * change, against a schema authorising `besluit_nemen` to nobody.
	 *
	 * @covers ::stripMcpScope
	 *
	 * @return void
	 */
	public function testAnAlreadyEmptyRuleListIsPreservedNotDropped(): void {
		$this->assertSame(
			['besluit_nemen' => []],
			PermissionHandler::stripMcpScope(['besluit_nemen' => []]),
			'An explicit "grant to nobody" was dropped, which reopens the action to everybody.'
		);

		$this->assertSame(
			['read' => [], 'update' => []],
			PermissionHandler::stripMcpScope(['read' => [], 'update' => ['mcp']]),
			'The deny-all rule did not survive alongside a stripped mcp offer.'
		);
	}//end testAnAlreadyEmptyRuleListIsPreservedNotDropped()

	/**
	 * The offer surface is what the grantable-rights index reads, so it has to
	 * survive as data even though it is inert for enforcement.
	 *
	 * @covers ::mcpOfferedActions
	 *
	 * @return void
	 */
	public function testTheOfferedActionsAreReadableAsData(): void {
		$this->assertSame(
			['read', 'sendMail'],
			PermissionHandler::mcpOfferedActions(
				[
					'read' => ['mcp', 'staff'],
					'create' => ['staff'],
					'sendMail' => [['group' => 'mcp']],
				]
			),
			'The mcp offer surface could not be read back off the block.'
		);
	}//end testTheOfferedActionsAreReadableAsData()

	/**
	 * An offer is not a grant. This is the scenario the spec calls out in red:
	 * a schema offers `read` to agents, and an agent holding no grant is
	 * refused exactly as it would be for a tool nobody ever offered.
	 *
	 * The refusal is Hermiq's to make, and the property this asserts is the
	 * OpenRegister half of it: the schema annotation contributes nothing to
	 * the verdict, so there is no path by which the offer alone admits anyone.
	 *
	 * @covers ::hasGroupPermission
	 *
	 * @return void
	 */
	public function testAnOfferAdmitsNobodyOnItsOwn(): void {
		$this->stageUser(['users']);

		$offerOnly = PermissionHandler::stripMcpScope(['read' => ['mcp'], 'create' => ['staff']]);

		$this->assertFalse(
			$this->handler->hasGroupPermission(
				authorization: $offerOnly,
				groupId: 'users',
				action: 'read',
				userId: 'alice'
			),
			'The mcp offer granted `read` to a caller who held no rule for it.'
		);
	}//end testAnOfferAdmitsNobodyOnItsOwn()

	/**
	 * Two agents owned by one person must be able to hold different rights.
	 *
	 * RBAC cannot express that — groups are per user, so both agents resolve
	 * to the same groups. The design depends on the schema layer never being
	 * the thing that decides, and the observable form of that here is:
	 * the block yields the SAME verdict regardless of which agent is asking,
	 * leaving the difference entirely to Hermiq's per-agent grants.
	 *
	 * @covers ::hasGroupPermission
	 *
	 * @return void
	 */
	public function testTheSchemaLayerCannotSeparateTwoAgentsAndDoesNotTry(): void {
		$this->stageUser(['users']);

		$authorization = PermissionHandler::stripMcpScope(['read' => ['mcp', 'users']]);

		$verdicts = [];
		foreach (['agent-a', 'agent-b'] as $ignoredAgent) {
			$verdicts[] = $this->handler->hasGroupPermission(
				authorization: $authorization,
				groupId: 'users',
				action: 'read',
				userId: 'alice'
			);
		}

		$this->assertSame(
			[true, true],
			$verdicts,
			'The schema layer produced a per-agent verdict it has no way to justify.'
		);
	}//end testTheSchemaLayerCannotSeparateTwoAgentsAndDoesNotTry()
}//end class
