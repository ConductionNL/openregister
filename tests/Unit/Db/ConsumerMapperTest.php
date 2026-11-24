<?php

/**
 * Unit tests for ConsumerMapper: secrets in the authorization configuration
 * are sealed on the way to the table and opened on the way back, and the
 * reads, filters and counts it builds.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ColumnFiltersTrait;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\Support\SecretAtRest;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the consumer mapper.
 */
#[CoversClass(ConsumerMapper::class)]
#[UsesClass(Consumer::class)]
#[UsesClass(SecretAtRest::class)]
#[UsesClass(ColumnFiltersTrait::class)]
#[UsesClass(ListQuery::class)]
class ConsumerMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper with or without secrets at rest.
	 *
	 * @param bool $withSecrets Whether secrets are sealed.
	 *
	 * @return ConsumerMapper The mapper.
	 */
	private function mapper(bool $withSecrets=true): ConsumerMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function (): IQueryBuilder {
				$qb = $this->recordingBuilder();
				$qb->method('getLastInsertId')->willReturn(11);
				return $qb;
			}
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'E(' . $plain . ')');
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => substr($cipher, 2, -1));

		$secrets = null;
		if ($withSecrets === true) {
			$secrets = new SecretAtRest($crypto);
		}

		return new ConsumerMapper($db, $secrets);
	}//end mapper()

	/**
	 * The query log lines that bind a JSON value.
	 *
	 * @return string[] The lines.
	 */
	private function jsonParams(): array {
		return array_values(array_filter($this->queryLog, static fn (string $line): bool => str_contains($line, 'apiKey')));
	}//end jsonParams()

	/**
	 * A created consumer's secret is sealed in the write and open again on the entity.
	 *
	 * @return void
	 */
	public function testCreateSealsTheSecretForTheWriteOnly(): void {
		$consumer = $this->mapper()->createFromArray(
			['name' => 'Portaal', 'authorizationConfiguration' => ['apiKey' => 'k-1', 'header' => 'X-Key']]
		);

		$this->assertSame(11, $consumer->getId());
		$this->assertNotNull($consumer->getUuid());
		$this->assertSame(['apiKey' => 'k-1', 'header' => 'X-Key'], $consumer->getAuthorizationConfiguration());
		$this->assertCount(1, $this->jsonParams());
		$this->assertStringContainsString('"apiKey":"orenc:v1:E(k-1)"', $this->jsonParams()[0]);
		$this->assertStringContainsString('"header":"X-Key"', $this->jsonParams()[0]);
	}//end testCreateSealsTheSecretForTheWriteOnly()

	/**
	 * Without secrets at rest the configuration is written as it is.
	 *
	 * @return void
	 */
	public function testWithoutSecretsNothingIsSealed(): void {
		$this->mapper(withSecrets: false)->createFromArray(['authorizationConfiguration' => ['apiKey' => 'k-1']]);

		$this->assertStringContainsString('"apiKey":"k-1"', $this->jsonParams()[0]);
	}//end testWithoutSecretsNothingIsSealed()

	/**
	 * A stored sealed secret reads back open, and an update seals it again.
	 *
	 * @return void
	 */
	public function testReadOpensAndUpdateSealsAgain(): void {
		$this->queuedRows[] = [['id' => 3, 'name' => 'Portaal', 'authorization_configuration' => '{"apiKey":"orenc:v1:E(k-9)"}']];

		$consumer = $this->mapper()->updateFromArray(3, ['description' => 'Burgerportaal']);

		$this->assertSame(['apiKey' => 'k-9'], $consumer->getAuthorizationConfiguration());
		$this->assertSame('Burgerportaal', $consumer->getDescription());
		$this->assertContains('#2 update(openregister_consumers, NULL)', $this->queryLog);
		$this->assertCount(1, $this->jsonParams());
		$this->assertStringContainsString('"apiKey":"orenc:v1:E(k-9)"', $this->jsonParams()[0]);
	}//end testReadOpensAndUpdateSealsAgain()

	/**
	 * Filters become null checks or equality, and an unbounded list sets no limit.
	 *
	 * @return void
	 */
	public function testFindAllAppliesTheFilters(): void {
		$this->mapper()->findAll(null, 5, ['user_id' => 'IS NULL', 'domains' => 'IS NOT NULL', 'name' => 'Portaal']);

		$this->assertContains('#1 setMaxResults(NULL)', $this->queryLog);
		$this->assertContains('#1 setFirstResult(5)', $this->queryLog);
		$this->assertContains('#1 andWhere(isNull(user_id))', $this->queryLog);
		$this->assertContains('#1 andWhere(isNotNull(domains))', $this->queryLog);
		$this->assertContains('#1 andWhere(eq(name, :p1, NULL))', $this->queryLog);
	}//end testFindAllAppliesTheFilters()

	/**
	 * The call count is read from the COUNT row.
	 *
	 * @return void
	 */
	public function testTotalCallCount(): void {
		$this->queuedRows[] = [['count' => '7']];

		$this->assertSame(7, $this->mapper()->getTotalCallCount());
		$this->assertContains('#1 from(openregister_consumers, NULL)', $this->queryLog);
	}//end testTotalCallCount()
}//end class
