<?php

/**
 * ObjectQuotaRefusal: one wording for a create refused by a schema's object quota.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts\Core;

use OCA\OpenRegister\Kernel\Contracts\Core\ObjectQuotaRefusal;
use OCA\OpenRegister\Listener\ObjectQuotaListener;
use PHPUnit\Framework\TestCase;

/**
 * The codes and texts are the ones the quota listener has always sent, and the listener now reads them from here.
 *
 * @covers \OCA\OpenRegister\Kernel\Contracts\Core\ObjectQuotaRefusal
 * @uses   \OCA\OpenRegister\Listener\ObjectQuotaListener
 */
final class ObjectQuotaRefusalTest extends TestCase {

	/**
	 * A refusal at the limit names the held count, the cap and the schema.
	 *
	 * @return void
	 */
	public function testARefusalAtTheLimit(): void {
		$refusal = new ObjectQuotaRefusal(schema: 'melding', held: 10, limit: 10);

		$this->assertSame('object-quota-exceeded', $refusal->code());
		$this->assertSame('This organisation already holds 10 of at most 10 objects of schema "melding".', $refusal->message());
	}//end testARefusalAtTheLimit()

	/**
	 * Without a held count the quota could not be checked.
	 *
	 * @return void
	 */
	public function testARefusalThatCouldNotBeChecked(): void {
		$refusal = new ObjectQuotaRefusal(schema: '12');

		$this->assertSame('object-quota-unchecked', $refusal->code());
		$this->assertSame(
			'Schema "12" limits how many objects one organisation holds, and that limit could not be checked just now, so nothing was created. Try again.',
			$refusal->message()
		);
	}//end testARefusalThatCouldNotBeChecked()

	/**
	 * The listener's public codes and message helpers answer exactly the same.
	 *
	 * @return void
	 */
	public function testTheListenerKeepsItsCodesAndMessages(): void {
		$this->assertSame(ObjectQuotaRefusal::ERROR_CODE, ObjectQuotaListener::ERROR_CODE);
		$this->assertSame(ObjectQuotaRefusal::ERROR_CODE_UNCHECKED, ObjectQuotaListener::ERROR_CODE_UNCHECKED);
		$this->assertSame((new ObjectQuotaRefusal(schema: 'melding', held: 3, limit: 5))->message(), ObjectQuotaListener::exceededMessage(count: 3, limit: 5, schema: 'melding'));
		$this->assertSame((new ObjectQuotaRefusal(schema: 'melding'))->message(), ObjectQuotaListener::uncheckedMessage(schema: 'melding'));
	}//end testTheListenerKeepsItsCodesAndMessages()
}//end class
