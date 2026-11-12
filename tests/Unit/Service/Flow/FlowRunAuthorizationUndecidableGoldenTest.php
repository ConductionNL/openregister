<?php

/**
 * Golden coverage for the undecidable outcomes of FlowRunAuthorization.
 *
 * A collaborator that throws while the caller is being judged must refuse
 * as UNDECIDABLE — never allow — at each of the three places it is asked.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowRunAuthorization;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Locks that a throwing collaborator refuses, and where.
 */
final class FlowRunAuthorizationUndecidableGoldenTest extends TestCase {

	/**
	 * A flow owned by anja.
	 *
	 * @return Flow The flow.
	 */
	private function flow(): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setOwner('anja');

		return $flow;
	}//end flow()

	/**
	 * Access for bert, throwing at the named point.
	 *
	 * @param string $throws Which call throws: user, admin or right.
	 *
	 * @return FlowAccess The access double.
	 */
	private function access(string $throws): FlowAccess {
		$access = $this->createMock(FlowAccess::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bert');

		if ($throws === 'user') {
			$access->method('currentUser')->willThrowException(new RuntimeException('session'));
		} else {
			$access->method('currentUser')->willReturn($user);
		}

		if ($throws === 'admin') {
			$access->method('callerIsAdmin')->willThrowException(new RuntimeException('groups'));
		} else {
			$access->method('callerIsAdmin')->willReturn(false);
		}

		if ($throws === 'right') {
			$access->method('may')->willThrowException(new RuntimeException('rights'));
		} else {
			$access->method('may')->willReturn(true);
		}

		return $access;
	}//end access()

	/**
	 * A throwing session, admin check or right check refuses as undecidable.
	 *
	 * @return void
	 */
	public function testAThrowingCollaboratorIsUndecidable(): void {
		foreach (['user', 'admin', 'right'] as $throws) {
			$this->assertSame(
				FlowRunAuthorization::UNDECIDABLE,
				(new FlowRunAuthorization(access: $this->access($throws)))->verdictFor(flow: $this->flow()),
				$throws
			);
		}
	}//end testAThrowingCollaboratorIsUndecidable()

	/**
	 * The control: with nothing throwing, the right lets bert run anja's flow.
	 *
	 * @return void
	 */
	public function testWithNothingThrowingTheRightAllows(): void {
		$this->assertSame(
			FlowRunAuthorization::ALLOWED,
			(new FlowRunAuthorization(access: $this->access('none')))->verdictFor(flow: $this->flow())
		);
	}//end testWithNothingThrowingTheRightAllows()
}//end class
