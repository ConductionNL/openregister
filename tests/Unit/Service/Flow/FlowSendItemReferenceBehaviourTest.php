<?php

/**
 * FlowSendItem: the object reference a notification links to, by uuid or by id.
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
use OCA\OpenRegister\Service\Flow\FlowSendItem;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowSendItem
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class FlowSendItemReferenceBehaviourTest extends TestCase {

	public function testTheReferenceCarriesTheUuidFallingBackToTheId(): void {
		self::assertSame('u-1', (new FlowSendItem(json: ['uuid' => 'u-1', 'id' => 'i-1'], uids: [], addresses: []))->objectReference()->getUuid());
		self::assertSame('i-1', (new FlowSendItem(json: ['id' => 'i-1'], uids: [], addresses: []))->objectReference()->getUuid());
		self::assertNull((new FlowSendItem(json: ['uuid' => ''], uids: [], addresses: []))->objectReference()->getUuid());
	}//end testTheReferenceCarriesTheUuidFallingBackToTheId()
}//end class
