<?php

/**
 * A vetoed delete is answered as a 409 conflict and stops the chain.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\Support\ConflictVeto;
use PHPUnit\Framework\TestCase;

/**
 * The trait merges the refusal, the 409 status and the detail, then stops propagation.
 */
class ConflictVetoTest extends TestCase {
	/**
	 * A minimal host for the trait.
	 *
	 * @return object A host exposing the veto.
	 */
	private function host(): object {
		return new class {
			use ConflictVeto;

			public function veto(ObjectDeletingEvent $event, array $refusal, array $detail): void {
				$this->vetoAsConflict($event, $refusal, $detail);
			}
		};
	}

	public function testVetoSetsMergedErrorsWithConflictStatusAndStopsPropagation(): void {
		$event = new ObjectDeletingEvent(new ObjectEntity());
		$this->assertFalse($event->isPropagationStopped());

		$this->host()->veto($event, ['message' => 'Referenced', 'status' => 400], ['referencedBy' => ['a-1']]);

		$this->assertSame(['message' => 'Referenced', 'status' => 409, 'referencedBy' => ['a-1']], $event->getErrors());
		$this->assertTrue($event->isPropagationStopped());
	}

	public function testRefusalWithoutStatusOrDetailStillAnswersConflict(): void {
		$event = new ObjectDeletingEvent(new ObjectEntity());

		$this->host()->veto($event, ['message' => 'Locked'], []);

		$this->assertSame(['message' => 'Locked', 'status' => 409], $event->getErrors());
		$this->assertTrue($event->isPropagationStopped());
	}
}
