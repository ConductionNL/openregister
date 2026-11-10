<?php

/**
 * Pins how a ResolvedConsumer is built and what it answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Consumer;

use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Service\Consumer\ConsumerAuthorization;
use OCA\OpenRegister\Service\Consumer\ConsumerIdentity;
use OCA\OpenRegister\Service\Consumer\ConsumerMapperSource;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use PHPUnit\Framework\TestCase;

final class ResolvedConsumerTest extends TestCase {

	public function testItIsBuiltFromAnIdentityAndAnAuthorization(): void {
		$record   = ['from' => 'an app schema'];
		$consumer = new ResolvedConsumer(
			identity: new ConsumerIdentity(source: 'integriq', uuid: 'app-consumer-1', name: 'portaal'),
			authorization: new ConsumerAuthorization(
				userId: 'api-user',
				authorizationType: 'jwt',
				configuration: ['algorithm' => 'HS256']
			),
			record: $record
		);

		$this->assertSame('integriq', $consumer->source);
		$this->assertSame('app-consumer-1', $consumer->uuid);
		$this->assertSame('portaal', $consumer->name);
		$this->assertSame('api-user', $consumer->userId);
		$this->assertSame('jwt', $consumer->authorizationType);
		$this->assertSame(['algorithm' => 'HS256'], $consumer->configuration);
		$this->assertSame($record, $consumer->record);
		$this->assertSame('portaal', $consumer->identity->name);
		$this->assertSame('jwt', $consumer->authorization->authorizationType);
	}//end testItIsBuiltFromAnIdentityAndAnAuthorization()

	public function testNullableFieldsStayNull(): void {
		$consumer = new ResolvedConsumer(
			identity: new ConsumerIdentity(source: 'openregister', uuid: null, name: 'anoniem'),
			authorization: new ConsumerAuthorization(userId: null, authorizationType: null)
		);

		$this->assertNull($consumer->uuid);
		$this->assertNull($consumer->userId);
		$this->assertNull($consumer->authorizationType);
		$this->assertSame([], $consumer->configuration);
		$this->assertNull($consumer->record);
	}//end testNullableFieldsStayNull()

	public function testAConsumerEntityResolvesAsBefore(): void {
		$entity = new Consumer();
		$entity->setUuid('c0ffee00-0000-4000-8000-000000000001');
		$entity->setName('leverancier');
		$entity->setUserId('leverancier');
		$entity->setAuthorizationType('apiKey');
		$entity->setAuthorizationConfiguration(['apiKey' => 'k']);

		$consumer = ConsumerMapperSource::resolve($entity);

		$this->assertSame('openregister', $consumer->source);
		$this->assertSame('c0ffee00-0000-4000-8000-000000000001', $consumer->uuid);
		$this->assertSame('leverancier', $consumer->name);
		$this->assertSame('leverancier', $consumer->userId);
		$this->assertSame('apiKey', $consumer->authorizationType);
		$this->assertSame(['apiKey' => 'k'], $consumer->configuration);
		$this->assertSame($entity, $consumer->record);
	}//end testAConsumerEntityResolvesAsBefore()
}//end class
