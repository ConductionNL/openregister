<?php

/**
 * Unit tests for InstanceSetSerialiser: an instance as one portable set, without secrets.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use OCA\OpenRegister\Service\Import\InstanceAppConfigReader;
use OCA\OpenRegister\Service\Import\InstanceSecretFilter;
use OCA\OpenRegister\Service\Import\InstanceSetSerialiser;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(InstanceSetSerialiser::class)]
#[UsesClass(InstanceAppConfigReader::class)]
#[UsesClass(InstanceSecretFilter::class)]
#[UsesClass(Register::class)]
final class InstanceSetSerialiserTest extends TestCase {

	private function register(?string $slug, int $id): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSlug($slug);

		return $register;
	}

	/**
	 * A serialiser over the given registers, their exports and app config.
	 *
	 * @param list<Register>                        $registers The registers the mapper lists.
	 * @param array<int, array<string, mixed>|null> $exports   Export by register id; null throws.
	 * @param array<string, mixed>                  $config    Stored app config.
	 *
	 * @return InstanceSetSerialiser
	 */
	private function serialiser(array $registers, array $exports, array $config=[]): InstanceSetSerialiser {
		$mapper = $this->createMock(RegisterMapperInterface::class);
		$mapper->expects($this->once())->method('findAll')->with()->willReturn($registers);

		$handler = $this->createMock(ExportHandler::class);
		$handler->expects($this->exactly(count($registers)))->method('exportConfig')->willReturnCallback(
			static function (Register $input, bool $includeObjects) use ($exports): array {
				if ($includeObjects !== true) {
					throw new RuntimeException('objects not requested');
				}

				$export = $exports[$input->getId()];
				if ($export === null) {
					throw new RuntimeException('export broke');
				}

				return $export;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAllValues')->with('openregister')->willReturn($config);
		$appConfig->method('isSensitive')->willReturn(false);

		$filter = new InstanceSecretFilter();

		return new InstanceSetSerialiser($mapper, $handler, new InstanceAppConfigReader($appConfig, $filter), $filter);
	}

	public function testAnInstanceWithCredentialsIsSerialisedWithoutThemAndSaysSo(): void {
		$export = [
			'openapi'    => '3.0.0',
			'components' => [
				'registers' => ['zaken' => ['slug' => 'zaken', 'title' => 'Zaken']],
				'schemas'   => ['account' => ['properties' => ['password' => ['type' => 'string']]]],
				'sources'   => [['name' => 'Bron', 'apikey' => 'KEY-123']],
				'objects'   => [['@self' => ['schema' => 'account'], 'name' => 'a', 'password' => 'hunter2']],
			],
		];

		$set = $this->serialiser([$this->register('zaken', 1)], [1 => $export], ['smtpPassword' => 'pw-9', 'theme' => 'dark'])->serialise();

		$encoded = (string) json_encode($set);
		foreach (['KEY-123', 'hunter2', 'pw-9'] as $credential) {
			$this->assertStringNotContainsString($credential, $encoded);
		}

		$this->assertTrue($set['secrets']['excluded']);
		$this->assertSame(
			[
				'registers.zaken.components.sources.0.apikey',
				'registers.zaken.components.objects.0.password',
				'configuration.smtpPassword',
			],
			$set['secrets']['paths']
		);
		$this->assertSame(['theme' => 'dark'], $set['configuration']);
	}

	public function testSchemaDefinitionsAndSectionOrderAreKeptWhole(): void {
		$export = [
			'openapi'    => '3.0.0',
			'components' => [
				'registers' => [],
				'schemas'   => ['account' => ['properties' => ['password' => ['type' => 'string'], 'token' => ['type' => 'string']]]],
				'objects'   => [],
			],
		];

		$set = $this->serialiser([$this->register('zaken', 1)], [1 => $export])->serialise();

		$this->assertSame($export, $set['registers']['zaken']);
		$this->assertSame([], $set['secrets']['paths']);
		$this->assertTrue($set['secrets']['excluded']);
	}

	public function testTheSetNamesItsFormatAndReportsEachRegister(): void {
		$withObjects = ['components' => ['objects' => [['a' => 1], ['a' => 2]]]];
		$registers   = [$this->register('zaken', 1), $this->register(null, 7), $this->register('kapot', 3)];

		$set = $this->serialiser($registers, [1 => $withObjects, 7 => ['components' => []], 3 => null])->serialise();

		$this->assertSame('openregister-instance-set', $set['format']);
		$this->assertSame(1, $set['version']);
		$this->assertSame(['zaken', 7], array_keys($set['registers']));
		$this->assertSame(
			[
				['register' => 'zaken', 'status' => 'exported', 'objects' => 2],
				['register' => '7', 'status' => 'exported', 'objects' => 0],
				['register' => 'kapot', 'status' => 'failed', 'error' => 'export broke'],
			],
			$set['outcome']
		);
	}

	public function testAnEmptyInstanceStillRecordsTheExclusion(): void {
		$set = $this->serialiser([], [])->serialise();

		$this->assertSame([], $set['registers']);
		$this->assertSame([], $set['outcome']);
		$this->assertSame([], $set['configuration']);
		$this->assertTrue($set['secrets']['excluded']);
		$this->assertNotSame('', $set['secrets']['note']);
	}
}
