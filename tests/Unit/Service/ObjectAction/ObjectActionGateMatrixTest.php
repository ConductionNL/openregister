<?php

/**
 * The gate's reading of matrix rows for a self-assign action (malformed rows
 * grant nothing, a scalar row is skipped), and the refusal reason it gives
 * when an action declares no message of its own.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationEntries;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionGate;
use OCA\OpenRegister\Service\ObjectAction\RightCheck;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixValidator;
use OCA\OpenRegister\Service\Rbac\MatrixRowsFromApps;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Matrix and reason tests for {@see ObjectActionGate}.
 */
class ObjectActionGateMatrixTest extends TestCase {

	/**
	 * The permission handler.
	 *
	 * @var PermissionHandlerInterface&MockObject
	 */
	private PermissionHandlerInterface&MockObject $permissions;

	/**
	 * The localiser.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * The gate under test.
	 *
	 * @var ObjectActionGate
	 */
	private ObjectActionGate $gate;

	/**
	 * Build the gate for signed-in user `anna`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->permissions->method('matrixRowsFromApps')->willReturn(
			new MatrixRowsFromApps(dispatcher: null, validator: new DepartmentMatrixValidator(), logger: new NullLogger())
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->gate = new ObjectActionGate(
			permissions: $this->permissions,
			userSession: $session,
			l10n: $this->l10n,
			elevation: $this->createMock(ObjectActionElevationEntries::class)
		);
	}//end setUp()

	/**
	 * A schema whose authorization carries only the given matrix.
	 *
	 * @param array<string, mixed> $matrix The matrix block.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(array $matrix): Schema {
		$schema = new Schema();
		$schema->setAuthorization(['read' => ['team'], 'matrix' => $matrix]);

		return $schema;
	}//end schema()

	/**
	 * Whether `anna` may run the self-assign action `take` on the schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return bool The answer.
	 */
	private function mayTake(Schema $schema): bool {
		$definition = ObjectActionDefinition::fromDeclaration(key: 'take', definition: ['selfAssign' => ['field' => 'assignee']]);

		return $this->gate->mayRun(check: new RightCheck(schema: $schema, right: 'update', object: new ObjectEntity()), definition: $definition);
	}//end mayTake()

	/**
	 * Matrix rows that are not a list grant nothing, and the permission
	 * handler is never asked.
	 *
	 * @return void
	 */
	public function testMalformedMatrixRowsGrantNothing(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->mayTake(schema: self::schema(matrix: ['field' => 'group', 'rows' => 'take'])));
	}//end testMalformedMatrixRowsGrantNothing()

	/**
	 * A scalar row is skipped while a later row naming the action (trimmed)
	 * grants it, subject to the permission handler.
	 *
	 * @return void
	 */
	public function testAScalarRowIsSkippedAndALaterRowGrants(): void {
		$this->permissions->expects($this->once())
			->method('hasPermission')
			->with($this->isInstanceOf(Schema::class), 'take', 'anna')
			->willReturn(true);

		$this->assertTrue($this->mayTake(schema: self::schema(matrix: ['field' => 'group', 'rows' => ['take', ['group' => 'team', 'actions' => [' take ']]]])));
	}//end testAScalarRowIsSkippedAndALaterRowGrants()

	/**
	 * Rows that never name the action grant nothing.
	 *
	 * @return void
	 */
	public function testRowsNotNamingTheActionGrantNothing(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->mayTake(schema: self::schema(matrix: ['field' => 'group', 'rows' => [['group' => 'team', 'actions' => ['read']]]])));
	}//end testRowsNotNamingTheActionGrantNothing()

	/**
	 * Without a declared message the reason is the generic one; an empty
	 * localised map reads as no message.
	 *
	 * @return void
	 */
	public function testTheReasonFallsBackToTheGenericMessage(): void {
		$plain = ObjectActionDefinition::fromDeclaration(key: 'close', definition: ['transition' => 'close']);
		$emptyMap = ObjectActionDefinition::fromDeclaration(key: 'close', definition: ['transition' => 'close', 'whenMessage' => []]);
		$own = ObjectActionDefinition::fromDeclaration(key: 'close', definition: ['transition' => 'close', 'whenMessage' => 'Closed already.']);

		$this->assertSame('This action is not available for this object right now.', $this->gate->reason(definition: $plain));
		$this->assertSame('This action is not available for this object right now.', $this->gate->reason(definition: $emptyMap));
		$this->assertSame('Closed already.', $this->gate->reason(definition: $own));
		$this->assertSame('', $this->gate->localised(value: []));
	}//end testTheReasonFallsBackToTheGenericMessage()
}//end class
