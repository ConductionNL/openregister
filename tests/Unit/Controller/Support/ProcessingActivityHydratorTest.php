<?php

/**
 * Unit test for ProcessingActivityHydrator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ProcessingActivityHydrator;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use PHPUnit\Framework\TestCase;

/**
 * How a request payload lands on a processing activity.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ProcessingActivityHydrator
 * @uses   \OCA\OpenRegister\Db\Verwerkingsactiviteit
 */
class ProcessingActivityHydratorTest extends TestCase {
	public function testEveryKnownFieldIsWrittenAndTheSameEntityReturned(): void {
		$entity = new Verwerkingsactiviteit();

		$result = (new ProcessingActivityHydrator())->hydrate($entity, [
			'code' => 'VA-1',
			'name' => 'Payroll',
			'legalBasis' => 'contract',
			'status' => 'published',
			'organisationId' => 'org-1',
			'dataSubjectCategories' => ['employees'],
			'recipients' => ['tax office'],
			'controller' => ['name' => 'City'],
		]);

		$this->assertSame($entity, $result);
		$this->assertSame('VA-1', $entity->getCode());
		$this->assertSame('Payroll', $entity->getName());
		$this->assertSame('contract', $entity->getLegalBasis());
		$this->assertSame('published', $entity->getStatus());
		$this->assertSame('org-1', $entity->getOrganisationId());
		$this->assertSame(['employees'], $entity->getDataSubjectCategories());
		$this->assertSame(['tax office'], $entity->getRecipients());
		$this->assertSame(['name' => 'City'], $entity->getController());
	}

	public function testAbsentFieldsAreLeftAlone(): void {
		$entity = new Verwerkingsactiviteit();
		$entity->setName('Kept');
		$entity->setRecipients(['kept']);

		(new ProcessingActivityHydrator())->hydrate($entity, ['code' => 'X', 'unknown' => 'ignored']);

		$this->assertSame('Kept', $entity->getName());
		$this->assertSame(['kept'], $entity->getRecipients());
		$this->assertSame('concept', $entity->getStatus());
		$this->assertSame('X', $entity->getCode());
	}

	public function testScalarsAreWrittenAsTextAndNullClears(): void {
		$entity = new Verwerkingsactiviteit();
		$entity->setDescription('old');

		(new ProcessingActivityHydrator())->hydrate($entity, ['code' => 42, 'retentionPeriod' => 7, 'description' => null]);

		$this->assertSame('42', $entity->getCode());
		$this->assertSame('7', $entity->getRetentionPeriod());
		$this->assertNull($entity->getDescription());
	}

	public function testANonArrayForAListFieldClearsIt(): void {
		$entity = new Verwerkingsactiviteit();
		$entity->setPersonalDataCategories(['bsn']);
		$entity->setDpoContactDetails(['email' => 'dpo@x']);

		(new ProcessingActivityHydrator())->hydrate($entity, ['personalDataCategories' => 'bsn', 'dpoContactDetails' => null]);

		$this->assertNull($entity->getPersonalDataCategories());
		$this->assertNull($entity->getDpoContactDetails());
	}
}
