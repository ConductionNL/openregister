<?php

/**
 * K25 interface bindings: every interface lane K25 added is implemented as promised.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Push\PushChannels;
use OCA\OpenRegister\Push\PushEvents;
use PHPUnit\Framework\TestCase;

/**
 * PushChannels is a constants-only interface, so it has no container binding:
 * the promise is that PushEvents implements it and still exposes the same names.
 */
final class K25InterfaceBindingsTest extends TestCase {

	/**
	 * PushEvents implements PushChannels.
	 *
	 * @return void
	 */
	public function testPushEventsImplementsPushChannels(): void {
		$this->assertContains(PushChannels::class, class_implements(PushEvents::class));
	}//end testPushEventsImplementsPushChannels()

	/**
	 * The channel names did not change for consumers of either name.
	 *
	 * @return void
	 */
	public function testChannelNamesAreUnchanged(): void {
		$this->assertSame('or-object', PushChannels::OR_OBJECT);
		$this->assertSame('or-collection', PushChannels::OR_COLLECTION);
		$this->assertSame(PushChannels::OR_OBJECT, PushEvents::OR_OBJECT);
		$this->assertSame(PushChannels::OR_COLLECTION, PushEvents::OR_COLLECTION);
	}//end testChannelNamesAreUnchanged()
}//end class
