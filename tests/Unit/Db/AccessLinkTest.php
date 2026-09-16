<?php

/**
 * Unit tests for the AccessLink entity.
 *
 * The row is where a link's refusals start, so the tests are the refusals: a
 * revoked, switched-off or expired link is not live, a row with no expiry is
 * not live either, and a capability the row did not declare is not allowed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.WrongStyle -- the section banners above tests are banners, not doc comments.
// phpcs:disable PEAR.Commenting.FunctionComment.MissingReturn -- PHPUnit fixtures and tests; the signature IS the contract.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixtures; the declaration IS the description.

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkTest extends TestCase {

	private function link(): AccessLink {
		$link = new AccessLink();
		$link->setUuid('7a1f0f2e-0000-4000-8000-000000000001');
		$link->setAnchor('anchor-value');
		$link->setSubjectType(AccessLink::SUBJECT_OBJECT);
		$link->setSubjectId('object-uuid');
		$link->setCapabilities('read');
		$link->setCreatedBy('owner');
		$link->setCreated(new DateTime('-1 day'));
		$link->setExpiresAt(new DateTime('+1 day'));
		$link->setDisabled(false);
		$link->setUseCount(0);

		return $link;
	}

	public function testALinkWithAFutureExpiryIsLive(): void {
		$this->assertTrue($this->link()->isLive());
	}

	public function testARevokedLinkIsNotLive(): void {
		$link = $this->link();
		$link->setRevokedAt(new DateTime('-1 hour'));

		$this->assertFalse($link->isLive());
	}

	public function testASwitchedOffLinkIsNotLive(): void {
		$link = $this->link();
		$link->setDisabled(true);

		$this->assertFalse($link->isLive());
	}

	public function testAnExpiredLinkIsNotLive(): void {
		$link = $this->link();
		$link->setExpiresAt(new DateTime('-1 second'));

		$this->assertFalse($link->isLive());
	}

	/**
	 * A row with no expiry should not exist, because the mint refuses one. If a
	 * row ever gets there anyway, reading it as live would make the requirement
	 * advisory, so the row reads dead.
	 */
	public function testALinkWithNoExpiryIsNotLive(): void {
		$link = $this->link();
		$link->setExpiresAt(null);

		$this->assertFalse($link->isLive());
	}

	public function testDeclaredCapabilitiesAreParsedAndDeduplicated(): void {
		$link = $this->link();
		$link->setCapabilities('read, comment ,READ');

		$this->assertSame(['read', 'comment'], $link->declaredCapabilities());
	}

	public function testAnUnknownStoredCapabilityIsNotDeclared(): void {
		$link = $this->link();
		$link->setCapabilities('read,delete');

		$this->assertSame(['read'], $link->declaredCapabilities());
		$this->assertFalse($link->allows('delete'));
	}

	public function testAnUndeclaredCapabilityIsRefused(): void {
		$link = $this->link();
		$link->setCapabilities('read,comment');

		$this->assertTrue($link->allows(AccessLink::CAP_READ));
		$this->assertTrue($link->allows(AccessLink::CAP_COMMENT));
		$this->assertFalse($link->allows(AccessLink::CAP_UPLOAD));
	}

	public function testThePrincipalIsTheLinkAndNotAUser(): void {
		$link = $this->link();

		$this->assertSame('link:7a1f0f2e-0000-4000-8000-000000000001', $link->principalId());
		$this->assertStringStartsWith(AccessLink::PRINCIPAL_PREFIX, $link->principalId());
	}

	public function testThePrincipalNameFallsBackToThePrincipalId(): void {
		$link = $this->link();
		$this->assertSame($link->principalId(), $link->principalName());

		$link->setLabel('Advice request, omgevingsdienst');
		$this->assertSame('Advice request, omgevingsdienst', $link->principalName());
	}

	public function testTheOwnerViewNeverCarriesThePasswordHash(): void {
		$link = $this->link();
		$link->setPasswordHash('1|$argon2id$fake');

		$serialised = $link->jsonSerialize();

		$this->assertArrayNotHasKey('passwordHash', $serialised);
		$this->assertTrue($serialised['hasPassword']);
		$this->assertSame('anchor-value', $serialised['anchor']);
	}

	public function testTheHolderViewCarriesNeitherTheAnchorNorTheMinter(): void {
		$link = $this->link();
		$link->setLabel('Advice request');

		$descriptor = $link->publicDescriptor();

		$this->assertArrayNotHasKey('anchor', $descriptor);
		$this->assertArrayNotHasKey('createdBy', $descriptor);
		$this->assertArrayNotHasKey('useCount', $descriptor);
		$this->assertSame(['read'], $descriptor['capabilities']);
		$this->assertSame('Advice request', $descriptor['label']);
	}
}
