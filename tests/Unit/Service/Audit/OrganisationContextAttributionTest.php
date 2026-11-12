<?php

/**
 * Unit tests for OrganisationContextAttribution.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Audit
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\Audit\OrganisationContextAttribution;
use OCA\OpenRegister\Service\OrganisationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Audit\OrganisationContextAttribution
 * @uses   \OCA\OpenRegister\Service\OrganisationContext
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 */
class OrganisationContextAttributionTest extends TestCase {

	public function testARowInsideAContextNamesTheOrganisation(): void {
		$row = new AuditTrail();
		$row->setResultSummary(['token' => ['id' => 't1']]);

		OrganisationContext::run('org-a', static fn () => (new OrganisationContextAttribution())->apply(auditTrail: $row));

		$this->assertSame(['token' => ['id' => 't1'], 'organisationContext' => 'org-a'], $row->getResultSummary());
	}//end testARowInsideAContextNamesTheOrganisation()

	public function testARowOnItsOwnGetsASummary(): void {
		$row = new AuditTrail();

		OrganisationContext::run('org-b', static fn () => (new OrganisationContextAttribution())->apply(auditTrail: $row));

		$this->assertSame(['organisationContext' => 'org-b'], $row->getResultSummary());
	}//end testARowOnItsOwnGetsASummary()

	public function testARowOutsideAContextIsUntouched(): void {
		$row = new AuditTrail();

		(new OrganisationContextAttribution())->apply(auditTrail: $row);

		$this->assertNull($row->getResultSummary());
	}//end testARowOutsideAContextIsUntouched()
}//end class
