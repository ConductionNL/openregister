<?php

/**
 * Webhook and consumer secrets are stored encrypted, and the repair step brings old rows under it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\Support\SecretAtRest;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCA\OpenRegister\Db\Webhook;
use OCA\OpenRegister\Db\WebhookMapper;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Repair\ProtectStoredSecrets;
use OCA\OpenRegister\Service\WebhookRequestOptions;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Migration\IOutput;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class SecretsAtRestTest extends TestCase {

	private function crypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'C(' . base64_encode($plain) . ')');
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $cipher): string {
				if (preg_match('/^C\((.*)\)$/', $cipher, $m) !== 1) {
					throw new RuntimeException('HMAC does not match.');
				}

				return base64_decode($m[1]);
			}
		);

		return $crypto;
	}//end crypto()

	private function secrets(): SecretAtRest {
		return new SecretAtRest($this->crypto());
	}//end secrets()

	public function testASecretIsSealedOnceAndOpensToItself(): void {
		$sealed = $this->secrets()->seal('s3cret');

		$this->assertTrue(SecretAtRest::isSealed($sealed));
		$this->assertStringNotContainsString('s3cret', $sealed);
		$this->assertSame($sealed, $this->secrets()->seal($sealed));
		$this->assertSame('s3cret', $this->secrets()->open($sealed));
		$this->assertSame('plain', $this->secrets()->open('plain'), 'A value from before encryption still reads');
		$this->assertNull($this->secrets()->seal(null));
	}//end testASecretIsSealedOnceAndOpensToItself()

	public function testAValueThatCannotBeOpenedNeverReadsAsItsCiphertext(): void {
		$broken = SecretAtRest::PREFIX . 'garbage';
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->assertNull((new SecretAtRest($this->crypto(), $logger))->open($broken));
	}//end testAValueThatCannotBeOpenedNeverReadsAsItsCiphertext()

	public function testAConsumerSecretThatCannotBeOpenedIsAbsentNotItsCiphertext(): void {
		$mapper = new ConsumerMapper($this->createMock(IDBConnection::class), $this->secrets());
		$broken = SecretAtRest::PREFIX . 'garbage';

		$read = new ReflectionMethod(ConsumerMapper::class, 'mapRowToEntity');
		$stored = json_encode(['apiKey' => $broken, 'publicKey' => $broken, 'algorithm' => 'HS256']);
		$loaded = $read->invoke($mapper, ['id' => 1, 'authorization_configuration' => $stored]);

		$this->assertSame(['apiKey' => null, 'publicKey' => null, 'algorithm' => 'HS256'], $loaded->getAuthorizationConfiguration());
	}//end testAConsumerSecretThatCannotBeOpenedIsAbsentNotItsCiphertext()

	public function testAStoredWebhookSecretThatCannotBeOpenedRefusesTheDelivery(): void {
		$read = new ReflectionMethod(WebhookMapper::class, 'mapRowToEntity');
		$loaded = $read->invoke($this->webhookMapper(), ['id' => 1, 'secret' => SecretAtRest::PREFIX . 'garbage']);

		$this->assertSame(SecretAtRest::UNREADABLE, $loaded->getSecret());
		$this->expectException(RuntimeException::class);
		(new WebhookRequestOptions(webhook: $loaded))->headers(payload: ['a' => 1]);
	}//end testAStoredWebhookSecretThatCannotBeOpenedRefusesTheDelivery()

	public function testOnlyTheSecretKeysOfAConsumerConfigurationAreSealed(): void {
		$sealed = $this->secrets()->sealConfiguration(['apiKey' => 'k', 'algorithm' => 'HS256', 'publicKey' => 'shared', 'grants' => ['a']]);

		$this->assertTrue(SecretAtRest::isSealed($sealed['apiKey']));
		$this->assertTrue(SecretAtRest::isSealed($sealed['publicKey']));
		$this->assertSame('HS256', $sealed['algorithm']);
		$this->assertSame(['a'], $sealed['grants']);
		$plain = ['apiKey' => 'k', 'algorithm' => 'HS256', 'publicKey' => 'shared', 'grants' => ['a']];
		$this->assertSame($plain, $this->secrets()->openConfiguration($sealed));
	}//end testOnlyTheSecretKeysOfAConsumerConfigurationAreSealed()

	private function webhookMapper(): WebhookMapper {
		$tenancy = new TenancyServices(
			organisationMapper: $this->createMock(OrganisationMapperInterface::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class)
		);

		return new WebhookMapper($this->createMock(IDBConnection::class), $tenancy, $this->createMock(IAppConfig::class), null, $this->secrets());
	}//end webhookMapper()

	public function testAWebhookIsWrittenWithItsSecretSealedAndReadBackOpen(): void {
		$mapper = $this->webhookMapper();
		$webhook = new Webhook();
		$webhook->setSecret('hook-secret');

		(new ReflectionMethod(WebhookMapper::class, 'sealSecret'))->invoke($mapper, $webhook);
		$written = $webhook->getSecret();
		(new ReflectionMethod(WebhookMapper::class, 'openSecret'))->invoke($mapper, $webhook);

		$this->assertTrue(SecretAtRest::isSealed($written), 'The store receives the sealed secret');
		$this->assertSame('hook-secret', $webhook->getSecret(), 'The caller keeps the secret open');

		$read = new ReflectionMethod(WebhookMapper::class, 'mapRowToEntity');
		$loaded = $read->invoke($mapper, ['id' => 1, 'secret' => $written]);
		$this->assertSame('hook-secret', $loaded->getSecret());
	}//end testAWebhookIsWrittenWithItsSecretSealedAndReadBackOpen()

	public function testAConsumerIsWrittenWithItsSecretsSealedAndReadBackOpen(): void {
		$mapper = new ConsumerMapper($this->createMock(IDBConnection::class), $this->secrets());
		$consumer = new Consumer();
		$consumer->setAuthorizationConfiguration(['apiKey' => 'api-key-1']);

		(new ReflectionMethod(ConsumerMapper::class, 'sealConfiguration'))->invoke($mapper, $consumer);
		$written = $consumer->getAuthorizationConfiguration();
		(new ReflectionMethod(ConsumerMapper::class, 'openConfiguration'))->invoke($mapper, $consumer);

		$this->assertTrue(SecretAtRest::isSealed($written['apiKey']));
		$this->assertSame(['apiKey' => 'api-key-1'], $consumer->getAuthorizationConfiguration());

		$read = new ReflectionMethod(ConsumerMapper::class, 'mapRowToEntity');
		$loaded = $read->invoke($mapper, ['id' => 1, 'authorization_configuration' => json_encode($written)]);
		$this->assertSame(['apiKey' => 'api-key-1'], $loaded->getAuthorizationConfiguration());
	}//end testAConsumerIsWrittenWithItsSecretsSealedAndReadBackOpen()

	public function testAWebhookWhoseSecretCannotBeOpenedIsNotSentUnsigned(): void {
		$webhook = new Webhook();
		$webhook->setSecret(SecretAtRest::PREFIX . 'garbage');

		$this->expectException(RuntimeException::class);
		(new WebhookRequestOptions(webhook: $webhook))->headers(payload: ['a' => 1]);
	}//end testAWebhookWhoseSecretCannotBeOpenedIsNotSentUnsigned()

	public function testTheRepairStepSealsAndHashesOnlyWhatIsStillInClear(): void {
		$tables = [
			'openregister_webhooks' => [
				['id' => 1, 'secret' => 'plain'],
				['id' => 2, 'secret' => SecretAtRest::PREFIX . 'C(eA==)'],
				['id' => 3, 'secret' => null],
			],
			'openregister_consumers' => [
				['id' => 4, 'authorization_configuration' => json_encode(['apiKey' => 'k'])],
				['id' => 5, 'authorization_configuration' => json_encode(['grants' => []])],
			],
			'openregister_calendar_feed_tokens' => [
				['id' => 6, 'token' => str_repeat('a', 43), 'expires_at' => null],
				['id' => 7, 'token' => hash('sha256', 'x'), 'expires_at' => null],
			],
		];
		$updates = [];
		$db = $this->recordingDb(tables: $tables, updates: $updates);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnArgument(2);

		$step = new ProtectStoredSecrets($db, $this->crypto(), $config);
		$step->run($this->createMock(IOutput::class));
		$step2 = new ProtectStoredSecrets($this->recordingDb(tables: $this->applied($tables, $updates), updates: $second), $this->crypto(), $config);
		$step2->run($this->createMock(IOutput::class));

		$this->assertSame([1, 4, 6], array_column($updates, 'id'));
		$this->assertTrue(SecretAtRest::isSealed($updates[0]['values']['secret']));
		$this->assertTrue(SecretAtRest::isSealed(json_decode($updates[1]['values']['authorization_configuration'], true)['apiKey']));
		$this->assertSame(hash('sha256', str_repeat('a', 43)), $updates[2]['values']['token'], 'The feed keeps answering the URL the holder subscribed to');
		$this->assertArrayHasKey('expires_at', $updates[2]['values'], 'A token that never expired gets the default lifetime');
		$this->assertSame([], $second, 'A second run changes nothing');
	}//end testTheRepairStepSealsAndHashesOnlyWhatIsStillInClear()

	private function applied(array $tables, array $updates): array {
		foreach ($updates as $update) {
			foreach ($tables[$update['table']] as $i => $row) {
				if ($row['id'] === $update['id']) {
					$tables[$update['table']][$i] = array_merge($row, $update['values']);
				}
			}
		}

		return $tables;
	}//end applied()

	private function recordingDb(array $tables, ?array &$updates): IDBConnection {
		$updates = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($tables, &$updates): IQueryBuilder {
				$state = ['table' => null, 'values' => [], 'id' => null, 'update' => false];
				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('select')->willReturnSelf();
				$qb->method('from')->willReturnCallback(function (string $table) use ($qb, &$state): IQueryBuilder {
					$state['table'] = $table;
					return $qb;
				});
				$qb->method('update')->willReturnCallback(function (string $table) use ($qb, &$state): IQueryBuilder {
					$state['table'] = $table;
					$state['update'] = true;
					return $qb;
				});
				$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => $value);
				$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb, &$state): IQueryBuilder {
					$state['values'][$column] = $value;
					return $qb;
				});
				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('eq')->willReturnCallback(function (string $column, $value) use (&$state): string {
					$state['id'] = $value;
					return 'eq';
				});
				$qb->method('expr')->willReturn($expr);
				$qb->method('where')->willReturnSelf();
				$qb->method('executeQuery')->willReturnCallback(function () use ($tables, &$state): IResult {
					$result = $this->createMock(IResult::class);
					$result->method('fetchAll')->willReturn($tables[$state['table']]);
					return $result;
				});
				$qb->method('executeStatement')->willReturnCallback(function () use (&$updates, &$state): int {
					$updates[] = ['table' => $state['table'], 'id' => $state['id'], 'values' => $state['values']];
					return 1;
				});

				return $qb;
			}
		);

		return $db;
	}//end recordingDb()
}//end class
