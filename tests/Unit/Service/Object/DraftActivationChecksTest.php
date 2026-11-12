<?php

declare(strict_types=1);

/**
 * DraftActivationChecks bundle test
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Form\FormPayloadFindings;
use OCA\OpenRegister\Service\Object\DraftActivationChecks;
use OCA\OpenRegister\Service\Object\ObjectValidatorInterface;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The bundle hands back exactly the services it was given.
 */
final class DraftActivationChecksTest extends TestCase {
	/**
	 * Every service is carried as given.
	 *
	 * @return void
	 */
	public function testCarriesTheServicesAsGiven(): void {
		$validator = $this->createMock(ObjectValidatorInterface::class);
		$findings = new FormPayloadFindings();
		$l10n = $this->createMock(IL10N::class);
		$bundle = new DraftActivationChecks(validator: $validator, findings: $findings, l10n: $l10n);

		$this->assertSame($validator, $bundle->validator);
		$this->assertSame($findings, $bundle->findings);
		$this->assertSame($l10n, $bundle->l10n);
	}//end testCarriesTheServicesAsGiven()
}//end class
