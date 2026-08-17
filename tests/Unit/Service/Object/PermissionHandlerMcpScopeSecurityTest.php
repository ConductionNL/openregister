<?php

/**
 * Security probes for the `mcp` scope.
 *
 * The scope is inert by design, and "inert" is a claim about what CANNOT
 * happen — which is only worth as much as the attempts made to make it happen.
 * These are the attempts.
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
 * @spec openspec/specs/declared-actions/spec.md
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
class PermissionHandlerMcpScopeSecurityTest extends TestCase {

	private IUserSession&MockObject $userSession;

	private IUserManager&MockObject $userManager;

	private IGroupManager&MockObject $groupManager;

	/**
	 * Wire the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}//end setUp()

	/**
	 * Build a handler.
	 *
	 * @return PermissionHandler The subject.
	 */
	private function handler(): PermissionHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(false);

		return new PermissionHandler(
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
	}//end handler()

	/**
	 * Build a schema carrying the given block.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(101);
		$schema->setSlug('sensitive');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * 🔴 PROBE 1 — the anonymous reader.
	 *
	 * A schema whose ONLY rule is an mcp offer collapses to "no authorization
	 * configured", and an unconfigured schema is default-OPEN for reads. So the
	 * question is whether an author who wrote `{"read": ["mcp"]}` on a sensitive
	 * schema — plausibly believing they had RESTRICTED it to agents — has
	 * instead published it to the anonymous internet.
	 *
	 * @covers ::hasPermission
	 *
	 * @return void
	 */
	public function testAnonymousReadOfAnMcpOnlyBlock(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$verdict = $this->handler()->hasPermission(
			schema: $this->schemaWith(['read' => ['mcp']]),
			action: 'read'
		);

		$this->assertFalse(
			$verdict,
			'An mcp-only block granted READ to an ANONYMOUS caller. '
			. 'An author documenting an agent surface silently published the schema.'
		);
	}//end testAnonymousReadOfAnMcpOnlyBlock()

	/**
	 * 🔴 PROBE 2 — the same block, an authenticated stranger.
	 *
	 * @covers ::hasPermission
	 *
	 * @return void
	 */
	public function testUnrelatedUserReadOfAnMcpOnlyBlock(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);

		$verdict = $this->handler()->hasPermission(
			schema: $this->schemaWith(['read' => ['mcp']]),
			action: 'read',
			userId: 'mallory'
		);

		$this->assertFalse(
			$verdict,
			'An mcp-only block granted READ to an unrelated authenticated user.'
		);
	}//end testUnrelatedUserReadOfAnMcpOnlyBlock()

	/**
	 * PROBE 3 — the control. A schema that genuinely has no block IS
	 * default-open, and must stay that way. Without this, "fix" probe 1 by
	 * denying everything and the suite would look green while breaking every
	 * unconfigured schema on the instance.
	 *
	 * @covers ::hasPermission
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaStaysDefaultOpen(): void {
		// Asserted at the rule-grammar level, NOT through hasPermission(): with
		// a mocked SchemaMapper the register cascade cannot resolve and
		// fail-closes, so a hasPermission() control would report a denial that
		// says nothing about the default-open rule under test. A control that
		// fails for its own reasons is not a control.
		$this->assertTrue(
			$this->handler()->hasGroupPermission(
				authorization: PermissionHandler::stripMcpScope(authorization: null),
				groupId: 'users',
				action: 'read',
				userId: 'alice'
			),
			'CONTROL FAILED: a schema with no authorization block stopped being readable.'
		);
	}//end testAnUnconfiguredSchemaStaysDefaultOpen()

	/**
	 * PROBE 4 — an mcp offer must not widen a block that restricts other
	 * actions. `{"read": ["mcp"], "create": ["staff"]}` still has real rules, so
	 * it stays fail-closed and `read` is denied to a non-staff caller.
	 *
	 * @covers ::hasPermission
	 *
	 * @return void
	 */
	public function testAnOfferAlongsideRealRulesDoesNotWiden(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['users']);

		$this->assertFalse(
			$this->handler()->hasPermission(
				schema: $this->schemaWith(['read' => ['mcp'], 'create' => ['staff']]),
				action: 'read',
				userId: 'mallory'
			),
			'An mcp offer widened a block that still carried real rules.'
		);
	}//end testAnOfferAlongsideRealRulesDoesNotWiden()

	/**
	 * PROBE 5 — a delegation entry must survive the strip. The mcp guard sits
	 * in front of the user-override branch, so a bug there would silently
	 * revoke every delegated grant.
	 *
	 * @covers ::hasGroupPermission
	 *
	 * @return void
	 */
	public function testAUserOverrideSurvivesTheStrip(): void {
		$this->assertTrue(
			$this->handler()->hasGroupPermission(
				authorization: PermissionHandler::stripMcpScope(
					authorization: ['read' => ['mcp', 'user:alice']]
				),
				groupId: 'nobody',
				action: 'read',
				userId: 'alice'
			),
			'A delegated user grant was dropped by the mcp strip.'
		);
	}//end testAUserOverrideSurvivesTheStrip()
}//end class
