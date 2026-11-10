<?php

/**
 * Golden allowed/refused outcomes of ProcessingActivityVisibility.
 *
 * Admins see every activity; a non-admin sees only the activities bound to an
 * organisation in the accessible list, and never an unbound one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Support
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ProcessingActivityViewer;
use OCA\OpenRegister\Controller\Support\ProcessingActivityVisibility;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use PHPUnit\Framework\TestCase;

class ProcessingActivityVisibilityGoldenTest extends TestCase {
	private function activity(?string $organisationId): Verwerkingsactiviteit {
		$activity = new Verwerkingsactiviteit();
		$activity->setOrganisationId($organisationId);
		return $activity;
	}

	private function visibility(?string $activeUuid): ProcessingActivityVisibility {
		$organisations = $this->createMock(OrganisationServiceInterface::class);
		$organisation  = null;
		if ($activeUuid !== null) {
			$organisation = new Organisation();
			$organisation->setUuid($activeUuid);
		}

		$organisations->method('getActiveOrganisation')->willReturn($organisation);
		return new ProcessingActivityVisibility(organisationService: $organisations);
	}

	public function testAnAdminSeesEveryActivityIncludingUnboundOnes(): void {
		$visibility = $this->visibility('org-a');
		$this->assertSame([], $visibility->accessibleOrgs(true));
		foreach ([null, '', 'org-a', 'org-b'] as $org) {
			$this->assertTrue($visibility->maySee($this->activity($org), [], true));
		}
	}

	public function testANonAdminSeesOnlyTheActiveOrganisation(): void {
		$visibility = $this->visibility('org-a');
		$orgs       = $visibility->accessibleOrgs(false);
		$this->assertSame(['org-a'], $orgs);
		$this->assertTrue($visibility->maySee($this->activity('org-a'), $orgs, false));
		$this->assertFalse($visibility->maySee($this->activity('org-b'), $orgs, false));
		$this->assertFalse($visibility->maySee($this->activity(null), $orgs, false));
		$this->assertFalse($visibility->maySee($this->activity(''), $orgs, false));
	}

	public function testANonAdminWithoutAnActiveOrganisationSeesNothing(): void {
		$visibility = $this->visibility(null);
		$orgs       = $visibility->accessibleOrgs(false);
		$this->assertSame([], $orgs);
		$this->assertFalse($visibility->maySee($this->activity('org-a'), $orgs, false));
		$this->assertFalse($visibility->maySee($this->activity(''), $orgs, false));
	}

	public function testFilterKeepsOnlyVisibleActivitiesInOrder(): void {
		$visibility = $this->visibility('org-a');
		$a1 = $this->activity('org-a');
		$b  = $this->activity('org-b');
		$n  = $this->activity(null);
		$a2 = $this->activity('org-a');

		$this->assertSame([$a1, $a2], $visibility->filter([$a1, $b, $n, $a2], ['org-a'], false));
		$this->assertSame([$a1, $b, $n, $a2], $visibility->filter([$a1, $b, $n, $a2], [], true));
	}

	public function testVisibleToKeepsWhatTheViewerSees(): void {
		$visibility = $this->visibility('org-a');
		$a = $this->activity('org-a');
		$b = $this->activity('org-b');
		$this->assertSame([$a], $visibility->visibleTo([$b, $a], new ProcessingActivityViewer(false, ['org-a'])));
		$this->assertSame([$b, $a], $visibility->visibleTo([$b, $a], new ProcessingActivityViewer(true, [])));
	}

	public function testTheViewerOfACallerCarriesItsScope(): void {
		$visibility = $this->visibility('org-a');
		$this->assertTrue($visibility->viewer(true)->sees($this->activity(null)));
		$this->assertSame(['org-a'], $visibility->viewer(false)->accessibleOrgs);
		$this->assertTrue($visibility->viewer(false)->sees($this->activity('org-a')));
		$this->assertFalse($visibility->viewer(false)->sees($this->activity('org-b')));
		$this->assertFalse($this->visibility(null)->viewer(false)->sees($this->activity('org-a')));
	}
}//end class
