<?php

/**
 * Characterisation test for FileMapper::publishFile().
 *
 * Pins the public share a publication writes (type, owner, initiator,
 * read-only permissions, expiration) and that an existing public share is
 * answered as it is, without a second insert. The token and timestamps are
 * random or clock-bound and are not pinned.
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FileMapperPublishFileCharacterisationTest extends TestCase {
	use RecordingQueryBuilderTrait;

	public function testANewPublicationWritesAReadOnlyPublicLinkShare(): void {
		$this->queuedRows = [[], []];
		$result = $this->mapper()->publishFile(42, 'alice', 'bob');

		$params = $this->insertedParameters();
		$this->assertSame(self::param('3', IQueryBuilder::PARAM_INT), $params[0], 'share_type: public link');
		$this->assertSame(self::param('bob', IQueryBuilder::PARAM_STR), $params[3], 'uid_owner');
		$this->assertSame(self::param('alice', IQueryBuilder::PARAM_STR), $params[4], 'uid_initiator');
		$this->assertSame(self::param('42', IQueryBuilder::PARAM_INT), $params[7], 'item_source');
		$this->assertSame(self::param('/42', IQueryBuilder::PARAM_STR), $params[8], 'item_target');
		$this->assertSame(self::param('1', IQueryBuilder::PARAM_INT), $params[11], 'permissions: read only');
		$this->assertSame(self::param('NULL', IQueryBuilder::PARAM_STR), $params[14], 'expiration: none');
		$this->assertSame(self::param('file', IQueryBuilder::PARAM_STR), $params[6], 'item_type');
		$this->assertSame(self::param('42', IQueryBuilder::PARAM_INT), $params[9], 'file_source');
		$this->assertCount(18, $params);
		$this->assertSame(15, strlen($result['token']));
		$this->assertSame('share:' . $result['token'], $result['accessUrl']);
		$this->assertSame('share:' . $result['token'] . '/download', $result['downloadUrl']);
	}//end testANewPublicationWritesAReadOnlyPublicLinkShare()

	public function testADepublicationDateBecomesTheShareExpiration(): void {
		$this->queuedRows = [[], []];
		$this->mapper()->publishFile(42, 'alice', 'alice', depublished: new DateTime('2031-02-03 04:05:06'));

		$params = $this->insertedParameters();
		$this->assertSame(self::param('1', IQueryBuilder::PARAM_INT), $params[11], 'permissions: read only');
		$this->assertSame(self::param('2031-02-03 04:05:06', IQueryBuilder::PARAM_STR), $params[14], 'expiration');
	}//end testADepublicationDateBecomesTheShareExpiration()

	public function testAnExistingPublicShareIsAnsweredWithoutASecondInsert(): void {
		$this->queuedRows = [[['id' => 9, 'token' => 'tok9', 'stime' => 1700000000]]];
		$result = $this->mapper()->publishFile(42, 'alice', 'bob');

		$this->assertSame(9, $result['id']);
		$this->assertSame('tok9', $result['token']);
		$this->assertSame('share:tok9', $result['accessUrl']);
		$this->assertSame('share:tok9/download', $result['downloadUrl']);
		$this->assertSame([], array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'insert('))));
	}//end testAnExistingPublicShareIsAnsweredWithoutASecondInsert()

	private function mapper(): FileMapper {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());

		$urls = $this->createMock(ShareUrlBuilder::class);
		$urls->method('shareLink')->willReturnCallback(fn (string $token): string => 'share:' . $token);
		$urls->method('absolute')->willReturnCallback(fn (string $path): string => 'abs:' . $path);

		return new FileMapper(db: $db, shareUrls: $urls);
	}//end mapper()

	/**
	 * One bound parameter as the recording builder renders it.
	 *
	 * @param string $value The rendered value.
	 * @param int    $type  The parameter type.
	 *
	 * @return string The rendered parameter.
	 */
	private static function param(string $value, int $type): string {
		return $value . ', ' . var_export($type, true);
	}//end param()

	/**
	 * The named parameters bound on the query builder that ran the insert, in order.
	 *
	 * @return list<string>
	 */
	private function insertedParameters(): array {
		$insert = null;
		foreach ($this->queryLog as $line) {
			if (preg_match('/^(\S+) insert\(/', $line, $match) === 1) {
				$insert = $match[1];
			}
		}

		$this->assertNotNull($insert, 'an insert ran');
		$params = [];
		foreach ($this->queryLog as $line) {
			if (preg_match('/^' . preg_quote($insert, '/') . ' param \S+ = (.*)$/', $line, $match) === 1) {
				$params[] = $match[1];
			}
		}

		return $params;
	}//end insertedParameters()
}//end class
