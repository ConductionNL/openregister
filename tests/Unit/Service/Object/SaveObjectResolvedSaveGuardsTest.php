<?php

/**
 * Which guards SaveObject::saveResolvedObject runs before it looks for the stored object.
 *
 * The shared master data refusal runs only for a persisting multitenant save,
 * the object-source delegation only for a persisting save, the property
 * refusal only with RBAC on; the translations are always normalised first.
 * The flow is stopped at the stored-object lookup, so a guard that ran when
 * it must not shows up as a different outcome.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveCall;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSaveFlags;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\SharedMasterDataService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * saveResolvedObject's guard order, golden.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @uses \OCA\OpenRegister\Service\Object\SaveObject\ObjectWriteTarget
 * @uses \OCA\OpenRegister\Service\Object\TranslationScope
 * @uses \OCA\OpenRegister\Service\OrganisationContext
 * @uses \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses \OCA\OpenRegister\Kernel\Contracts\ListQuery
 */
final class SaveObjectResolvedSaveGuardsTest extends TestCase {

	/**
	 * Every normalisation the translation handler did, as its input data.
	 *
	 * @var array<int, array>
	 */
	private array $normalised = [];

	/**
	 * Every service the handler asked its container for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Run saveResolvedObject on a call with these flags and schema answers; answer what stopped it.
	 *
	 * @param ObjectSaveFlags $flags   The save flags.
	 * @param array|null      $source  What the schema answers for its object source.
	 * @param bool            $guarded Whether the schema guards properties.
	 *
	 * @return array{0: string, 1: array} The stop (`Class: message`) and the call data afterwards.
	 */
	private function stopOf(ObjectSaveFlags $flags, ?array $source, bool $guarded): array {
		$handler = $this->getMockBuilder(SaveObject::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		$reflection = new ReflectionClass(SaveObject::class);
		$translations = $this->createMock(TranslationHandler::class);
		$translations->method('normalizeTranslationsForSave')->willReturnCallback(
			function (array $objectData): array {
				$this->normalised[] = $objectData;

				return $objectData + ['normalised' => true];
			}
		);
		$reflection->getProperty('translationHandler')->setValue($handler, $translations);
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willThrowException(new RuntimeException('stopped at the stored-object lookup'));
		$reflection->getProperty('objectEntityMapper')->setValue($handler, $mapper);
		$reflection->getProperty('logger')->setValue($handler, new NullLogger());
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->asked[] = $id;

				return new \stdClass();
			}
		);
		$reflection->getProperty('container')->setValue($handler, $container);

		$schema = $this->createMock(Schema::class);
		$schema->method('getObjectSource')->willReturn($source);
		$schema->method('hasPropertyAuthorization')->willReturn($guarded);
		$call = new ObjectSaveCall();
		$call->schema = $schema;
		$call->data = ['title' => 'x'];
		$call->uuid = 'u-1';
		$call->flags = $flags;

		try {
			$reflection->getMethod('saveResolvedObject')->invoke($handler, $call);
			$stop = 'none';
		} catch (Throwable $e) {
			$stop = (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
		}

		return [$stop, $call->data];
	}//end stopOf()

	/**
	 * A non-persisting, RBAC-off save skips every guard but still normalises before the lookup.
	 *
	 * @return void
	 */
	public function testANonPersistingRbacOffSaveSkipsTheGuards(): void {
		$flags = new ObjectSaveFlags();
		$flags->persist = false;
		$flags->multitenancy = true;
		$flags->rbac = false;

		$this->assertSame(
			['RuntimeException: stopped at the stored-object lookup', ['title' => 'x', 'normalised' => true]],
			$this->stopOf(flags: $flags, source: ['provider' => 'view'], guarded: true)
		);
		$this->assertSame([['title' => 'x']], $this->normalised);
		$this->assertSame([], $this->asked);
	}//end testANonPersistingRbacOffSaveSkipsTheGuards()

	/**
	 * A persisting save without multitenancy, object source or guarded properties reaches the lookup.
	 *
	 * @return void
	 */
	public function testAPlainPersistingSaveReachesTheLookup(): void {
		$flags = new ObjectSaveFlags();
		$flags->persist = true;
		$flags->multitenancy = false;
		$flags->rbac = true;

		$this->assertSame(
			['RuntimeException: stopped at the stored-object lookup', ['title' => 'x', 'normalised' => true]],
			$this->stopOf(flags: $flags, source: null, guarded: false)
		);
		$this->assertSame([], $this->asked);
	}//end testAPlainPersistingSaveReachesTheLookup()

	/**
	 * A persisting multitenant save asks for the shared master data guard first.
	 *
	 * @return void
	 */
	public function testAPersistingMultitenantSaveAsksTheSharedMasterDataGuard(): void {
		$flags = new ObjectSaveFlags();
		$flags->persist = true;
		$flags->multitenancy = true;
		$flags->rbac = false;

		$this->assertSame(
			['RuntimeException: stopped at the stored-object lookup', ['title' => 'x', 'normalised' => true]],
			$this->stopOf(flags: $flags, source: null, guarded: true)
		);
		$this->assertSame([SharedMasterDataService::class], $this->asked);
	}//end testAPersistingMultitenantSaveAsksTheSharedMasterDataGuard()
}//end class
