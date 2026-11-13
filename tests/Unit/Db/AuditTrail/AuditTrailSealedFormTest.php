<?php

/**
 * The sealed form of an audit trail row: the id, then exactly the sealed
 * fields in order, with dates as ISO 8601 and everything else unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\AuditTrail
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\AuditTrail;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTimeImmutable;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailFieldTypes;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailSealedForm;
use PHPUnit\Framework\TestCase;

/**
 * AuditTrailSealedForm.
 *
 * @covers \OCA\OpenRegister\Db\AuditTrail\AuditTrailSealedForm
 */
class AuditTrailSealedFormTest extends TestCase {

	private function values(): array {
		$values = ['id' => 12];
		foreach (array_keys(AuditTrailFieldTypes::TYPES) as $field) {
			$values[$field] = 'v-'.$field;
		}

		return $values;
	}//end values()

	public function testTheIdLeadsAndOnlySealedFieldsFollowInOrder(): void {
		$form = (new AuditTrailSealedForm())->serialise($this->values());

		$this->assertSame(array_merge(['id'], AuditTrailFieldTypes::SEALED_FIELDS), array_keys($form));
		$this->assertSame(12, $form['id']);
		$this->assertSame('v-cause', $form['cause']);
	}//end testTheIdLeadsAndOnlySealedFieldsFollowInOrder()

	public function testUnsealedFieldsAreLeftOut(): void {
		$form = (new AuditTrailSealedForm())->serialise($this->values());

		foreach (['purgedAt', 'importJobId', 'purpose', 'consumer'] as $unsealed) {
			$this->assertArrayNotHasKey($unsealed, $form);
		}
	}//end testUnsealedFieldsAreLeftOut()

	public function testDatesAreSealedAsIso8601AndOtherValuesUnchanged(): void {
		$values            = $this->values();
		$values['created'] = new DateTimeImmutable('2026-03-04T05:06:07+01:00');
		$values['expires'] = null;
		$values['changed'] = ['title' => ['old' => 'a', 'new' => 'b']];
		$values['size']    = 42;

		$form = (new AuditTrailSealedForm())->serialise($values);

		$this->assertSame('2026-03-04T05:06:07+01:00', $form['created']);
		$this->assertNull($form['expires']);
		$this->assertSame(['title' => ['old' => 'a', 'new' => 'b']], $form['changed']);
		$this->assertSame(42, $form['size']);
	}//end testDatesAreSealedAsIso8601AndOtherValuesUnchanged()
}//end class
