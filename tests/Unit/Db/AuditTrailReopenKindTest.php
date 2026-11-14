<?php

/**
 * A declared reopen out of a terminal state is audited as a reopen.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-over-reference-field/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Service\WriteCause;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditFlowAttribution
 * @uses \OCA\OpenRegister\Db\AuditTrailPayloadHelper
 * @uses \OCA\OpenRegister\Service\Audit\PurposeAttribution
 * @uses \OCA\OpenRegister\Service\Audit\TokenAttribution
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Service\ObjectAction\ObjectActionElevationScope
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedChange
 * @uses \OCA\OpenRegister\Service\Audit\AuditRowAttribution
 * @uses \OCA\OpenRegister\Service\Audit\AuditTrailCollaborators
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext
 */
final class AuditTrailReopenKindTest extends TestCase {

	/**
	 * The named-transition frame the mapper reads.
	 *
	 * @var LifecycleActionContext
	 */
	private LifecycleActionContext $actions;

	/**
	 * Start from an empty ambient cause stack.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		WriteCause::reset();
		$this->actions = new LifecycleActionContext();
	}//end setUp()

	/**
	 * And leave nothing behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		WriteCause::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * The mapper, whose container knows only the lifecycle action frame.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === LifecycleActionContext::class) {
					return $this->actions;
				}

				throw new \RuntimeException('not registered');
			}
		);

		return new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}//end mapper()

	/**
	 * A stored case.
	 *
	 * @param string $uuid   Its uuid.
	 * @param string $status Its status.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, string $status): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(7);
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject(['status' => $status]);

		return $object;
	}//end object()

	/**
	 * The audit row of a reopen.
	 *
	 * @param string $declaredFor The uuid the action is declared for.
	 * @param string $action      The declared action.
	 *
	 * @return array<string, mixed> The row's changed payload.
	 */
	private function changedWhileDeclared(string $declaredFor, string $action): array {
		$this->actions->declare(uuid: $declaredFor, action: $action);
		try {
			$trail = $this->mapper()->buildEntry(
				change: new AuditedChange(old: $this->object(uuid: 'u-7', status: 'closed'), new: $this->object(uuid: 'u-7', status: 'open'))
			);
		} finally {
			$this->actions->release(uuid: $declaredFor);
		}

		return (array)$trail->getChanged();
	}//end changedWhileDeclared()

	/**
	 * A reopen is recorded as a reopen, beside the status change itself.
	 *
	 * @return void
	 */
	public function testAReopenIsAuditedAsAReopen(): void {
		$changed = $this->changedWhileDeclared(declaredFor: 'u-7', action: 'reopen');

		$this->assertSame('reopen', $changed['transitionKind'] ?? null);
		$this->assertArrayHasKey('status', $changed);
	}//end testAReopenIsAuditedAsAReopen()

	/**
	 * An ordinary forward move carries no reopen kind.
	 *
	 * @return void
	 */
	public function testAForwardMoveIsNotAuditedAsAReopen(): void {
		$changed = $this->changedWhileDeclared(declaredFor: 'u-7', action: 'move-to-open');

		$this->assertArrayNotHasKey('transitionKind', $changed);
	}//end testAForwardMoveIsNotAuditedAsAReopen()

	/**
	 * A reopen declared for another object does not mark this one's row.
	 *
	 * @return void
	 */
	public function testAReopenOfAnotherObjectLeavesThisRowUnmarked(): void {
		$changed = $this->changedWhileDeclared(declaredFor: 'u-8', action: 'reopen');

		$this->assertArrayNotHasKey('transitionKind', $changed);
	}//end testAReopenOfAnotherObjectLeavesThisRowUnmarked()

	/**
	 * Without the lifecycle frame wired, the row is built unmarked rather than failing.
	 *
	 * @return void
	 */
	public function testAnUnwiredFrameLeavesTheRowUnmarked(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));
		$mapper = new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);

		$trail = $mapper->buildEntry(
			change: new AuditedChange(old: $this->object(uuid: 'u-7', status: 'closed'), new: $this->object(uuid: 'u-7', status: 'open'))
		);

		$this->assertArrayNotHasKey('transitionKind', (array)$trail->getChanged());
	}//end testAnUnwiredFrameLeavesTheRowUnmarked()
}//end class
