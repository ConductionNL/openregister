<?php

/**
 * Which erasure buckets the vocabulary knows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Erasure
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Erasure;

use OCA\OpenRegister\Service\Gdpr\Erasure\ErasureBucket;
use PHPUnit\Framework\TestCase;

/**
 * Only the three named buckets are members; case, null and kinds do not count.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Erasure\ErasureBucket
 */
final class ErasureBucketMembershipTest extends TestCase {

	/**
	 * The three buckets are members.
	 *
	 * @return void
	 */
	public function testTheThreeBucketsAreMembers(): void {
		$this->assertTrue(ErasureBucket::isMember(bucket: 'erasable'));
		$this->assertTrue(ErasureBucket::isMember(bucket: 'pseudonymised'));
		$this->assertTrue(ErasureBucket::isMember(bucket: 'protected'));
	}//end testTheThreeBucketsAreMembers()

	/**
	 * Anything else, including another case, null or a kind, is not.
	 *
	 * @return void
	 */
	public function testAnythingElseIsNot(): void {
		$this->assertFalse(ErasureBucket::isMember(bucket: 'Erasable'));
		$this->assertFalse(ErasureBucket::isMember(bucket: null));
		$this->assertFalse(ErasureBucket::isMember(bucket: ''));
		$this->assertFalse(ErasureBucket::isMember(bucket: 'objects'));
	}//end testAnythingElseIsNot()
}//end class
