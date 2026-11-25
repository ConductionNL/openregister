<?php

/**
 * MandateEvidence: the Core side of the task mandate check, reached by
 * Processes through the Kernel port MandateEvidenceInterface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\MandateEvidenceInterface;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Rbac\MandateEvidence;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Task\TaskMandateGuard;
use PHPUnit\Framework\TestCase;

/**
 * The port is bound to the Core class, and it fails closed without collaborators.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\MandateEvidence
 */
class MandateEvidenceTest extends TestCase {

	/**
	 * Core binds the Kernel port to MandateEvidence.
	 *
	 * @return void
	 */
	public function testThePortIsBoundToTheCoreClass(): void {
		$this->assertSame(MandateEvidence::class, CoreServiceBindings::BINDINGS[MandateEvidenceInterface::class] ?? null);
	}//end testThePortIsBoundToTheCoreClass()

	/**
	 * Grantability is the catalogue's answer, and false without a catalogue.
	 *
	 * @return void
	 */
	public function testGrantabilityIsTheCataloguesAnswer(): void {
		$catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$catalogue->method('isGrantable')->willReturnCallback(static fn (string $verb): bool => $verb === 'decide');

		$mandates = new MandateEvidence(catalogue: $catalogue);

		$this->assertTrue($mandates->isGrantable(verb: 'decide'));
		$this->assertFalse($mandates->isGrantable(verb: 'decid'));
		$this->assertFalse((new MandateEvidence())->isGrantable(verb: 'decide'));
	}//end testGrantabilityIsTheCataloguesAnswer()

	/**
	 * A missing collaborator or an unknown verb answers "not held" without asking.
	 *
	 * @return void
	 */
	public function testMissingCollaboratorsOrAnUnknownVerbAnswerNotHeld(): void {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->expects($this->never())->method('hasPermission');
		$catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$catalogue->method('isGrantable')->willReturn(false);
		$catalog = $this->createMock(SchemaCatalogInterface::class);

		$this->assertNull((new MandateEvidence(permissions: $permissions))->evidence(verb: 'decide', uid: 'chris', objectUuid: null, registerId: null, schemaId: 7));
		$this->assertNull(
			(new MandateEvidence(permissions: $permissions, catalog: $catalog, catalogue: $catalogue))
				->evidence(verb: 'decide', uid: 'chris', objectUuid: null, registerId: null, schemaId: 7)
		);
	}//end testMissingCollaboratorsOrAnUnknownVerbAnswerNotHeld()

	/**
	 * The guard's and the Core class's custom source are the same word.
	 *
	 * @return void
	 */
	public function testTheCustomSourceMatchesTheGuards(): void {
		$this->assertSame(TaskMandateGuard::SOURCE_CUSTOM, MandateEvidence::SOURCE_CUSTOM);
	}//end testTheCustomSourceMatchesTheGuards()
}//end class
