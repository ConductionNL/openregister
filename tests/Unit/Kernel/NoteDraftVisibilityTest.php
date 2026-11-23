<?php

/**
 * The public note visibility is owned by the Kernel note draft and keeps its stored value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class NoteDraftVisibilityTest extends TestCase {

	/**
	 * The value written on a public note is still the string `public`.
	 *
	 * @return void
	 */
	public function testThePublicVisibilityIsStoredAsPublic(): void {
		$this->assertSame('public', NoteDraft::PUBLIC_VISIBILITY);
	}//end testThePublicVisibilityIsStoredAsPublic()

	/**
	 * The timeline's public flag is the Kernel value, so a link's note and the timeline filter agree.
	 *
	 * @return void
	 */
	public function testTheTimelinePublicFlagIsTheKernelValue(): void {
		$this->assertSame(NoteDraft::PUBLIC_VISIBILITY, TimelineVisibilityServiceInterface::PUBLIC_ENTRY);
	}//end testTheTimelinePublicFlagIsTheKernelValue()
}//end class
