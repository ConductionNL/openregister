<?php

/**
 * Integration providers in the released (register, schema, objectId) form and in the ObjectRef form both load and run.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\IntegrationProviderInvoker;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\RefFormIntegrationProvider;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\ReleasedFormIntegrationProvider;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\ReleasedFormSubclassProvider;
use PHPUnit\Framework\TestCase;

class IntegrationProviderInvokerTest extends TestCase {

	private function ref(): ObjectRef {
		return new ObjectRef(register: 5, schema: 'case', id: 'uuid-1');
	}

	public function testReleasedFormProviderReceivesRegisterSchemaAndObjectIdAsStrings(): void {
		$provider = new ReleasedFormIntegrationProvider();
		$invoker  = new IntegrationProviderInvoker();

		$this->assertSame([['form' => 'released']], $invoker->list($provider, $this->ref(), ['f' => 1]));
		$this->assertSame(['form' => 'released'], $invoker->get($provider, $this->ref(), 'e1'));
		$this->assertSame(['form' => 'released'], $invoker->create($provider, $this->ref(), ['p' => 1]));
		$this->assertSame(['form' => 'released'], $invoker->update($provider, $this->ref(), 'e1', ['p' => 2]));
		$invoker->delete($provider, $this->ref(), 'e1');

		$this->assertSame(
			[
				'list'   => ['5', 'case', 'uuid-1', ['f' => 1]],
				'get'    => ['5', 'case', 'uuid-1', 'e1'],
				'create' => ['5', 'case', 'uuid-1', ['p' => 1]],
				'update' => ['5', 'case', 'uuid-1', 'e1', ['p' => 2]],
				'delete' => ['5', 'case', 'uuid-1', 'e1'],
			],
			$provider->received
		);
	}

	public function testRefFormProviderReceivesTheObjectRef(): void {
		$provider = new RefFormIntegrationProvider();
		$invoker  = new IntegrationProviderInvoker();
		$ref      = $this->ref();

		$this->assertSame([['form' => 'ref']], $invoker->list($provider, $ref, ['f' => 1]));
		$this->assertSame(['form' => 'ref'], $invoker->get($provider, $ref, 'e1'));
		$this->assertSame(['form' => 'ref'], $invoker->create($provider, $ref, ['p' => 1]));
		$this->assertSame(['form' => 'ref'], $invoker->update($provider, $ref, 'e1', ['p' => 2]));
		$invoker->delete($provider, $ref, 'e1');

		$this->assertSame(
			[
				'list'   => [$ref, ['f' => 1]],
				'get'    => [$ref, 'e1'],
				'create' => [$ref, ['p' => 1]],
				'update' => [$ref, 'e1', ['p' => 2]],
				'delete' => [$ref, 'e1'],
			],
			$provider->received
		);
	}

	public function testReleasedFormSubclassOfTheAbstractProviderLoadsAndLists(): void {
		$provider = new ReleasedFormSubclassProvider();

		$this->assertSame(
			[['5', 'case', 'uuid-1', []]],
			(new IntegrationProviderInvoker())->list($provider, $this->ref())
		);
	}

	public function testAnOperationTheProviderDoesNotDeclareAnswersNotImplemented(): void {
		$this->expectException(NotImplementedException::class);
		$this->expectExceptionMessage('Provider fixture-subclass does not support get()');

		(new IntegrationProviderInvoker())->get(new ReleasedFormSubclassProvider(), $this->ref(), 'e1');
	}

	public function testADirectCallToAnUndeclaredOperationAnswersNotImplemented(): void {
		$this->expectException(NotImplementedException::class);
		$this->expectExceptionMessage('Provider fixture-subclass does not support create()');

		(new ReleasedFormSubclassProvider())->create('5', 'case', 'uuid-1', []);
	}
}
