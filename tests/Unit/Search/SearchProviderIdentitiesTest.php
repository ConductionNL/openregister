<?php

/**
 * Unit tests for SearchProviderIdentities and SearchProviderIdentity.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-claiming-app-has-its-own-search-provider-identity-req-psp-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Search\SearchProviderIdentities;
use OCA\OpenRegister\Search\SearchProviderIdentity;
use OCA\OpenRegister\Search\SearchScopeCatalog;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The identity of a pair is read from its deep link claim, and an unclaimed pair is OpenRegister's.
 *
 * @covers \OCA\OpenRegister\Search\SearchProviderIdentities
 * @covers \OCA\OpenRegister\Search\SearchProviderIdentity
 * @uses   \OCA\OpenRegister\Dto\DeepLinkRegistration
 * @uses   \OCA\OpenRegister\Search\SearchScopeCatalog
 */
class SearchProviderIdentitiesTest extends TestCase {

	/**
	 * The translator: returns the text unchanged.
	 *
	 * @return IL10N The translator.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;
	}

	/**
	 * The scope catalog the claims are read through.
	 *
	 * @param DeepLinkRegistryServiceInterface $deepLink The registry.
	 *
	 * @return SearchScopeCatalog The catalog.
	 */
	private function claims(DeepLinkRegistryServiceInterface $deepLink): SearchScopeCatalog {
		return new SearchScopeCatalog(
			catalog: $this->createMock(SchemaCatalogInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			deepLink: $deepLink
		);
	}

	/**
	 * Identities over the given registrations.
	 *
	 * @param array<int, DeepLinkRegistration> $registrations The claims.
	 * @param LoggerInterface|null             $logger        The logger, or a silent one.
	 *
	 * @return SearchProviderIdentities The identities.
	 */
	private function identities(array $registrations, ?LoggerInterface $logger = null): SearchProviderIdentities {
		$deepLink = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$deepLink->method('registrations')->willReturn($registrations);

		return new SearchProviderIdentities(
			l10n: $this->l10n(),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			claims: $this->claims($deepLink)
		);
	}

	/**
	 * One identity per claiming app, OpenRegister first, apps in first-claim order with the next orders.
	 *
	 * @return void
	 */
	public function testOneIdentityPerClaimingAppInClaimOrder(): void {
		$identities = $this->identities(
			[
				new DeepLinkRegistration(appId: 'Pipelinq', registerSlug: 'crm', schemaSlug: 'Contact', urlTemplate: '/p'),
				new DeepLinkRegistration(appId: 'dossiq', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/c', displayName: 'Dossiers'),
				new DeepLinkRegistration(appId: 'pipelinq', registerSlug: 'crm', schemaSlug: 'lead', urlTemplate: '/l', displayName: 'Pipeline'),
			]
		);

		$all = $identities->all();

		self::assertSame(
			[
				['id' => 'openregister', 'name' => 'Open Register Objects', 'order' => 10],
				['id' => 'pipelinq', 'name' => 'Pipeline', 'order' => 11],
				['id' => 'dossiq', 'name' => 'Dossiers', 'order' => 12],
			],
			array_map(static fn (SearchProviderIdentity $i): array => $i->jsonSerialize(), $all)
		);
		self::assertSame(['crm::contact', 'crm::lead'], $all[1]->pairs, 'both claims of the app, in lower case');
		self::assertSame([], $all[0]->pairs, 'OpenRegister claims nothing itself');
		self::assertTrue($all[0]->isOpenRegister());
		self::assertFalse($all[1]->isOpenRegister());
	}

	/**
	 * An app that gives no display name is named by its id.
	 *
	 * @return void
	 */
	public function testAnAppWithoutADisplayNameIsNamedByItsId(): void {
		$identities = $this->identities(
			[new DeepLinkRegistration(appId: 'dossiq', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/c', displayName: '  ')]
		);

		self::assertSame('dossiq', $identities->byId(id: 'dossiq')?->name);
	}

	/**
	 * A pair belongs to the app that claimed it; an unclaimed pair to OpenRegister.
	 *
	 * @return void
	 */
	public function testAPairBelongsToItsClaimantOrToOpenRegister(): void {
		$identities = $this->identities(
			[new DeepLinkRegistration(appId: 'dossiq', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/c')]
		);

		self::assertSame('dossiq', $identities->forPair(registerSlug: 'Zaken', schemaSlug: 'CASE')->id);
		self::assertSame('openregister', $identities->forPair(registerSlug: 'zaken', schemaSlug: 'note')->id);
		self::assertSame('openregister', $identities->forPair(registerSlug: 'other', schemaSlug: 'case')->id, 'the register is part of the pair');
	}

	/**
	 * An id is looked up case-insensitively, and an id that names nothing answers null.
	 *
	 * @return void
	 */
	public function testByIdIsCaseInsensitiveAndUnknownIsNull(): void {
		$identities = $this->identities(
			[new DeepLinkRegistration(appId: 'dossiq', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/c')]
		);

		self::assertSame('dossiq', $identities->byId(id: ' DossIQ ')?->id);
		self::assertSame('openregister', $identities->byId(id: 'OpenRegister')?->id);
		self::assertNull($identities->byId(id: 'keepiq'));
	}

	/**
	 * A claim by an app calling itself openregister, or with no id, makes no second OpenRegister identity.
	 *
	 * @return void
	 */
	public function testAClaimCannotTakeTheOpenRegisterIdentity(): void {
		$identities = $this->identities(
			[
				new DeepLinkRegistration(appId: 'openregister', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/c', displayName: 'Mine'),
				new DeepLinkRegistration(appId: '', registerSlug: 'zaken', schemaSlug: 'note', urlTemplate: '/n'),
			]
		);

		self::assertCount(1, $identities->all());
		self::assertSame('Open Register Objects', $identities->all()[0]->name);
	}

	/**
	 * Without a registry, or with one that throws, only the OpenRegister identity exists; the failure is logged.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegistryLeavesOnlyOpenRegister(): void {
		$none = new SearchProviderIdentities(l10n: $this->l10n(), logger: $this->createMock(LoggerInterface::class));
		self::assertSame(['openregister'], array_map(static fn (SearchProviderIdentity $i): string => $i->id, $none->all()));

		$deepLink = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$deepLink->method('registrations')->willThrowException(new \RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(self::stringContains('Failed to read the deep-link registry'));

		$broken = new SearchProviderIdentities(l10n: $this->l10n(), logger: $logger, claims: $this->claims($deepLink));

		self::assertSame(['openregister'], array_map(static fn (SearchProviderIdentity $i): string => $i->id, $broken->all()));
		self::assertSame('openregister', $broken->forPair(registerSlug: 'zaken', schemaSlug: 'case')->id);
	}

	/**
	 * The claims are read once per request, however often the identities are asked.
	 *
	 * @return void
	 */
	public function testTheClaimsAreReadOnce(): void {
		$deepLink = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$deepLink->expects(self::once())->method('registrations')->willReturn(
			[new DeepLinkRegistration(appId: 'dossiq', registerSlug: 'zaken', schemaSlug: 'case', urlTemplate: '/c')]
		);
		$identities = new SearchProviderIdentities(l10n: $this->l10n(), logger: $this->createMock(LoggerInterface::class), claims: $this->claims($deepLink));

		$identities->all();
		$identities->byId(id: 'dossiq');
		$identities->forPair(registerSlug: 'zaken', schemaSlug: 'case');

		self::assertCount(2, $identities->all());
	}
}
