<?php

/**
 * Pins the two-part construction of the AppHost store descriptor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Service\StoreListing;
use OCA\OpenRegister\AppHost\Service\StoreSharing;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\AppHost\Service\StoreDescriptor
 * @covers \OCA\OpenRegister\AppHost\Service\StoreListing
 * @covers \OCA\OpenRegister\AppHost\Service\StoreSharing
 */
final class StoreDescriptorConstructionTest extends TestCase {
	/**
	 * The constructor takes two parts, so it stays far under the parameter limit.
	 *
	 * @return void
	 */
	public function testConstructorTakesAListingAndASharingPart(): void {
		$params = (new \ReflectionMethod(StoreDescriptor::class, '__construct'))->getParameters();

		self::assertSame(['listing', 'sharing'], array_map(static fn ($p): string => $p->getName(), $params));
		self::assertTrue($params[1]->isOptional());
		self::assertLessThanOrEqual(4, count((new \ReflectionMethod(StoreListing::class, '__construct'))->getParameters()));
		self::assertLessThanOrEqual(4, count((new \ReflectionMethod(StoreSharing::class, '__construct'))->getParameters()));
	}//end testConstructorTakesAListingAndASharingPart()

	/**
	 * A listing-only descriptor answers exactly as the old three-argument form did.
	 *
	 * @return void
	 */
	public function testListingOnlyDescriptorKeepsTheOldDefaults(): void {
		$descriptor = new StoreDescriptor(
			listing: new StoreListing(appId: 'buildiq', schema: 'application-template', defaultRegister: 'buildiq')
		);

		self::assertSame('buildiq', $descriptor->appId);
		self::assertSame('application-template', $descriptor->schema);
		self::assertSame('buildiq', $descriptor->defaultRegister);
		self::assertSame(
			[
				'slug' => 'slug',
				'title' => 'title',
				'description' => 'description',
				'category' => 'category',
				'version' => 'version',
			],
			$descriptor->cardFields
		);
		self::assertSame([], $descriptor->types);
		self::assertSame([], $descriptor->publishFields);
		self::assertSame([], $descriptor->publishGroups);
		self::assertFalse($descriptor->isFederated());
		self::assertFalse($descriptor->isPublishable());
		self::assertSame([], $descriptor->namedPublishGroups());
	}//end testListingOnlyDescriptorKeepsTheOldDefaults()

	/**
	 * Every field set through the two parts reads back through the flat properties.
	 *
	 * @return void
	 */
	public function testEveryPartIsExposedThroughTheFlatProperties(): void {
		$listing  = new StoreListing(
			appId: 'pipelinq',
			schema: 'commercial-template',
			defaultRegister: 'pipelinq',
			cardFields: ['slug' => 'slug', 'icon' => 'icon']
		);
		$sharing = new StoreSharing(
			types: ['openregister.configset'],
			publishFields: ['title'],
			publishGroups: [' sales ', '', 'sales', 'admin']
		);

		$descriptor = new StoreDescriptor(listing: $listing, sharing: $sharing);

		self::assertSame($listing, $descriptor->listing);
		self::assertSame($sharing, $descriptor->sharing);
		self::assertSame('pipelinq', $descriptor->appId);
		self::assertSame('commercial-template', $descriptor->schema);
		self::assertSame('pipelinq', $descriptor->defaultRegister);
		self::assertSame(['slug' => 'slug', 'icon' => 'icon'], $descriptor->cardFields);
		self::assertSame(['openregister.configset'], $descriptor->types);
		self::assertSame(['title'], $descriptor->publishFields);
		self::assertSame([' sales ', '', 'sales', 'admin'], $descriptor->publishGroups);
		self::assertTrue($descriptor->isFederated());
		self::assertTrue($descriptor->isPublishable());
		self::assertSame(['sales', 'admin'], $descriptor->namedPublishGroups());
	}//end testEveryPartIsExposedThroughTheFlatProperties()

	/**
	 * Fields without groups, or blank-only groups, keep the descriptor read-only.
	 *
	 * @return void
	 */
	public function testPublishingStillNeedsFieldsAndANamedGroup(): void {
		$listing = new StoreListing(appId: 'learniq', schema: 'shared-course-package', defaultRegister: 'learniq');

		self::assertFalse((new StoreDescriptor(listing: $listing, sharing: new StoreSharing(publishFields: ['title'])))->isPublishable());
		self::assertFalse(
			(new StoreDescriptor(listing: $listing, sharing: new StoreSharing(publishFields: ['title'], publishGroups: ['', '  '])))->isPublishable()
		);
		self::assertFalse((new StoreDescriptor(listing: $listing, sharing: new StoreSharing(publishGroups: ['admin'])))->isPublishable());
	}//end testPublishingStillNeedsFieldsAndANamedGroup()
}//end class
