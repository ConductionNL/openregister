<?php

/**
 * PropertyRbacHandler state-rule tests.
 *
 * The strip and the write refusal live on this handler on purpose: GraphQL and
 * the export both call these two methods, so putting the rule here is what
 * makes them inherit it with no call-site change. That inheritance is the
 * property asserted below, together with the two boundaries it has:
 *  - a state rule is applied BEFORE the admin short-circuit, because it is not
 *    a privilege grant;
 *  - `readOnly` is deliberately NOT refused here, because this method's caller
 *    turns its answer into "you are not authorized to modify", which is the
 *    wrong sentence for a field the user can see and normally edits.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/field-rules-by-state/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\PropertyRbacHandler
 */
class PropertyRbacHandlerStateRulesTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private IUserSession&MockObject $userSession;

	private IGroupManager&MockObject $groupManager;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
	}

	/**
	 * A handler whose caller sits in the given groups.
	 *
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return PropertyRbacHandler The handler.
	 */
	private function handlerFor(array $groups): PropertyRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);

		return new PropertyRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class),
			self::stateFieldRuleResolver($this->userSession, $this->groupManager)
		);
	}

	/**
	 * A schema hiding `internalNote` from the front desk while in `intake`,
	 * and freezing `decision` for handlers while `closed`.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setSlug('zaak');
		$schema->setProperties([
			'status' => ['type' => 'string', 'enum' => ['intake', 'closed']],
			'internalNote' => ['type' => 'string'],
			'decision' => ['type' => 'string'],
		]);
		$schema->setConfiguration([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => 'intake',
				'states' => [
					'intake' => [
						'fields' => [
							'hidden' => [['fields' => ['internalNote'], 'groups' => ['frontdesk']]],
						],
					],
					'closed' => [
						'fields' => [
							'readOnly' => [['fields' => ['decision'], 'groups' => ['handlers']]],
						],
					],
				],
			],
		]);

		return $schema;
	}

	/**
	 * @return void
	 */
	public function testAHiddenFieldIsAbsentForItsGroup(): void {
		$filtered = $this->handlerFor(['frontdesk'])->filterReadableProperties(
			schema: $this->schema(),
			object: ['status' => 'intake', 'internalNote' => 'niet delen', 'decision' => 'x']
		);

		$this->assertArrayNotHasKey('internalNote', $filtered);
		$this->assertArrayHasKey('decision', $filtered);
	}

	/**
	 * @return void
	 */
	public function testTheSameFieldIsPresentForAnotherGroup(): void {
		$filtered = $this->handlerFor(['handlers'])->filterReadableProperties(
			schema: $this->schema(),
			object: ['status' => 'intake', 'internalNote' => 'niet delen']
		);

		$this->assertSame('niet delen', $filtered['internalNote']);
	}

	/**
	 * An administrator is not exempt from a state rule, unlike from property
	 * authorization. The rule is a case-type contract, not a privilege grant.
	 *
	 * @return void
	 */
	public function testAnAdministratorInTheRulesGroupIsNotExempt(): void {
		$filtered = $this->handlerFor(['admin', 'frontdesk'])->filterReadableProperties(
			schema: $this->schema(),
			object: ['status' => 'intake', 'internalNote' => 'niet delen']
		);

		$this->assertArrayNotHasKey('internalNote', $filtered);
	}

	/**
	 * @return void
	 */
	public function testAnotherStateLeavesTheFieldAlone(): void {
		$filtered = $this->handlerFor(['frontdesk'])->filterReadableProperties(
			schema: $this->schema(),
			object: ['status' => 'closed', 'internalNote' => 'niet delen']
		);

		$this->assertSame('niet delen', $filtered['internalNote']);
	}

	/**
	 * @return void
	 */
	public function testWritingAHiddenFieldIsRefused(): void {
		$unauthorized = $this->handlerFor(['frontdesk'])->getUnauthorizedProperties(
			schema: $this->schema(),
			object: ['status' => 'intake', 'internalNote' => 'oud'],
			incomingData: ['status' => 'intake', 'internalNote' => 'nieuw']
		);

		$this->assertSame(['internalNote'], $unauthorized);
	}

	/**
	 * @return void
	 */
	public function testResubmittingAHiddenFieldUnchangedIsNotAWrite(): void {
		$unauthorized = $this->handlerFor(['frontdesk'])->getUnauthorizedProperties(
			schema: $this->schema(),
			object: ['status' => 'intake', 'internalNote' => 'oud'],
			incomingData: ['status' => 'intake', 'internalNote' => 'oud']
		);

		$this->assertSame([], $unauthorized);
	}

	/**
	 * A frozen field is not an authorization refusal, so this method stays
	 * silent about it and StateFieldRuleListener answers with 422 instead.
	 *
	 * @return void
	 */
	public function testAReadOnlyFieldIsNotReportedAsUnauthorizedHere(): void {
		$unauthorized = $this->handlerFor(['handlers'])->getUnauthorizedProperties(
			schema: $this->schema(),
			object: ['status' => 'closed', 'decision' => 'toegekend'],
			incomingData: ['status' => 'closed', 'decision' => 'afgewezen']
		);

		$this->assertSame([], $unauthorized);
	}

	/**
	 * @return void
	 */
	public function testASchemaWithoutStateRulesIsUntouched(): void {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('plain');
		$schema->setProperties(['note' => ['type' => 'string']]);
		$schema->setConfiguration([]);

		$handler = $this->handlerFor(['frontdesk']);
		$object = ['note' => 'blijft staan'];

		$this->assertSame($object, $handler->filterReadableProperties(schema: $schema, object: $object));
		$this->assertSame(
			[],
			$handler->getUnauthorizedProperties(
				schema: $schema,
				object: $object,
				incomingData: ['note' => 'gewijzigd']
			)
		);
	}
}
