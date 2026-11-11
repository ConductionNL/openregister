<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\MailingRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Notification\MailingRequest
 */
class MailingRequestTest extends TestCase {

	public function testTheRequestCarriesTheRulesDeclarationAndMessage(): void {
		$request = new MailingRequest([['kind' => 'email', 'field' => 'ref.mail']], 'Subject', 'Body', 'service');

		$this->assertSame([['kind' => 'email', 'field' => 'ref.mail']], $request->recipients);
		$this->assertSame('Subject', $request->subject);
		$this->assertSame('Body', $request->body);
		$this->assertSame('service', $request->category);
	}
}//end class
