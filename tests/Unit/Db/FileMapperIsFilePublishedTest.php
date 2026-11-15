<?php

/**
 * A file counts as published only through an open link share, as Nextcloud's share manager loads it.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Db\FileMapper::isFilePublished
 * @covers \OCA\OpenRegister\Files\Service\File\OpenLinkShare
 */
class FileMapperIsFilePublishedTest extends TestCase {
	use RecordingQueryBuilderTrait;

	public function testAnOpenLinkShareMakesTheFilePublished(): void {
		$this->assertTrue($this->mapper(['open' => $this->share()])->isFilePublished(42));
	}//end testAnOpenLinkShareMakesTheFilePublished()

	public function testAPasswordProtectedShareDoesNotPublish(): void {
		$this->assertFalse($this->mapper(['locked' => $this->share(password: 'hashed')])->isFilePublished(42));
	}//end testAPasswordProtectedShareDoesNotPublish()

	public function testAShareTheManagerRefusesAsExpiredDoesNotPublish(): void {
		$this->assertFalse($this->mapper(['gone' => null])->isFilePublished(42));
	}//end testAShareTheManagerRefusesAsExpiredDoesNotPublish()

	public function testAPassedExpirationDateDoesNotPublish(): void {
		$this->assertFalse($this->mapper(['late' => $this->share(expires: new DateTime('-1 day'))])->isFilePublished(42));
	}//end testAPassedExpirationDateDoesNotPublish()

	public function testAShareWithHiddenDownloadDoesNotPublish(): void {
		$this->assertFalse($this->mapper(['hidden' => $this->share(hideDownload: true)])->isFilePublished(42));
	}//end testAShareWithHiddenDownloadDoesNotPublish()

	public function testOneOpenShareBesideAProtectedOnePublishes(): void {
		$this->assertTrue($this->mapper(['locked' => $this->share(password: 'x'), 'open' => $this->share(expires: new DateTime('+1 day'))])->isFilePublished(42));
	}//end testOneOpenShareBesideAProtectedOnePublishes()

	public function testWithoutTheShareManagerNothingIsPublished(): void {
		$this->queuedRows = [[['token' => 'open']]];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$this->assertFalse((new FileMapper(db: $db, shareUrls: $this->createMock(ShareUrlBuilder::class)))->isFilePublished(42));
	}//end testWithoutTheShareManagerNothingIsPublished()

	/**
	 * @param array<string, IShare|null> $byToken Token => the share the manager loads, null when it refuses it.
	 */
	private function mapper(array $byToken): FileMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = [array_map(static fn (string $token): array => ['token' => $token], array_keys($byToken))];

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$manager = $this->createMock(IManager::class);
		$manager->method('getShareByToken')->willReturnCallback(
			static function (string $token) use ($byToken): IShare {
				if ($byToken[$token] === null) {
					throw new ShareNotFound();
				}

				return $byToken[$token];
			}
		);

		return new FileMapper(db: $db, shareUrls: $this->createMock(ShareUrlBuilder::class), shareManager: $manager);
	}//end mapper()

	private function share(?string $password = null, ?DateTime $expires = null, bool $hideDownload = false): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getPassword')->willReturn($password);
		$share->method('getExpirationDate')->willReturn($expires);
		$share->method('getHideDownload')->willReturn($hideDownload);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		return $share;
	}//end share()
}//end class
