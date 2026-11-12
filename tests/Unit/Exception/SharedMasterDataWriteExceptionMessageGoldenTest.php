<?php

/**
 * Characterisation tests for the shared-master-data write refusal message.
 *
 * Pins the exact sentence for every combination of holder name (set, null,
 * empty — falling back to the holder's uuid) and resource title (set, null,
 * empty — falling back to the bare resource type), and the 403 status.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\SharedMasterDataWriteException;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the refusal sentence.
 */
class SharedMasterDataWriteExceptionMessageGoldenTest extends TestCase {

	/**
	 * Every holder / title combination composes the same sentence as before.
	 *
	 * @return void
	 */
	public function testRefusalSentence(): void {
		$cases = [
			['org-1', 'Gemeente A', 'register', 'Zaken', 'This register "Zaken" is shared master data held by Gemeente A. Your organisation reads it and cannot change it. Ask Gemeente A to make the change.'],
			['org-1', null, 'register', 'Zaken', 'This register "Zaken" is shared master data held by org-1. Your organisation reads it and cannot change it. Ask org-1 to make the change.'],
			['org-1', '', 'schema', null, 'This schema is shared master data held by org-1. Your organisation reads it and cannot change it. Ask org-1 to make the change.'],
			['org-1', 'Gemeente A', 'schema', '', 'This schema is shared master data held by Gemeente A. Your organisation reads it and cannot change it. Ask Gemeente A to make the change.'],
		];

		foreach ($cases as [$uuid, $name, $type, $title, $expected]) {
			$refusal = new SharedMasterDataWriteException($uuid, $name, $type, $title);
			$this->assertSame($expected, $refusal->getMessage());
			$this->assertSame(403, $refusal->getCode());
		}

		$this->assertSame(
			'This register is shared master data held by org-9. Your organisation reads it and cannot change it. Ask org-9 to make the change.',
			(new SharedMasterDataWriteException('org-9'))->getMessage()
		);
	}//end testRefusalSentence()
}//end class
