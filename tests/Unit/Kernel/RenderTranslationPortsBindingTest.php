<?php

/**
 * The render path's translation and language ports are answered by the
 * translation store, the translation status and the language service, each
 * bound in the bindings of the area that owns the implementation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ConfigurationMapperBindings;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Kernel\Contracts\Core\RequestLanguagesInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\TranslationCompletenessInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\TranslationRowStatusInterface;
use OCA\OpenRegister\Service\CoreServiceBindings;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Service\Object\RenderTranslations;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\TranslationStatusService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RenderTranslationPortsBindingTest extends TestCase {

	public function testTheStatusServiceAnswersTheCompletenessPort(): void {
		self::assertTrue(is_subclass_of(TranslationStatusService::class, TranslationCompletenessInterface::class));
		self::assertSame(TranslationStatusService::class, CoreServiceBindings::BINDINGS[TranslationCompletenessInterface::class] ?? null);
	}

	public function testTheTranslationStoreAnswersTheRowStatusPort(): void {
		self::assertTrue(is_subclass_of(TranslationMapperInterface::class, TranslationRowStatusInterface::class));
		self::assertTrue(is_subclass_of(TranslationMapper::class, TranslationRowStatusInterface::class));
		self::assertSame(TranslationMapper::class, ConfigurationMapperBindings::BINDINGS[TranslationRowStatusInterface::class] ?? null);
	}

	public function testTheLanguageServiceAnswersTheRequestLanguagesPort(): void {
		self::assertTrue(is_subclass_of(LanguageService::class, RequestLanguagesInterface::class));
		self::assertSame(LanguageService::class, CoreServiceBindings::BINDINGS[RequestLanguagesInterface::class] ?? null);
	}

	public function testTheRenderPathTypesAgainstThePorts(): void {
		self::assertSame(
			[
				'translationHandler'       => TranslationHandler::class,
				'translationStatusService' => TranslationCompletenessInterface::class,
				'translationMapper'        => TranslationRowStatusInterface::class,
				'languageService'          => RequestLanguagesInterface::class,
			],
			$this->constructorTypes(RenderTranslations::class)
		);
		self::assertSame(RequestLanguagesInterface::class, $this->constructorTypes(TranslationHandler::class)['languageService'] ?? null);
	}

	public function testRowStatusIsNullWithoutARow(): void {
		self::assertNull($this->mapperFinding(null)->rowStatus(new TranslationSlot(objectUuid: 'u-1', property: 'title', language: 'en')));
	}

	public function testRowStatusIsDraftWhenTheRowHasNone(): void {
		self::assertSame(Translation::STATUS_DRAFT, $this->mapperFinding(new Translation())->rowStatus(new TranslationSlot(objectUuid: 'u-1', property: 'title', language: 'en')));
	}

	public function testRowStatusIsTheRowsOwnStatus(): void {
		$row = new Translation();
		$row->setStatus('approved');

		self::assertSame('approved', $this->mapperFinding($row)->rowStatus(new TranslationSlot(objectUuid: 'u-1', property: 'title', language: 'en')));
	}

	public function testRowStatusReadsTheSlotItIsGiven(): void {
		$slot   = new TranslationSlot(objectUuid: 'u-1', property: 'title', language: 'en');
		$mapper = $this->createPartialMock(TranslationMapper::class, ['findOne']);
		$mapper->expects($this->once())->method('findOne')->with($slot)->willReturn(null);

		$mapper->rowStatus($slot);
	}

	public function testRowStatusLetsAStoreFailureThrough(): void {
		$mapper = $this->createPartialMock(TranslationMapper::class, ['findOne']);
		$mapper->method('findOne')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(\RuntimeException::class);
		$mapper->rowStatus(new TranslationSlot(objectUuid: 'u-1', property: 'title', language: 'en'));
	}

	/**
	 * A translation store whose lookup returns the given row.
	 *
	 * @param Translation|null $row The row the lookup returns.
	 *
	 * @return TranslationMapper The store.
	 */
	private function mapperFinding(?Translation $row): TranslationMapper {
		$mapper = $this->createPartialMock(TranslationMapper::class, ['findOne']);
		$mapper->method('findOne')->willReturn($row);
		return $mapper;
	}

	/**
	 * The declared type of each constructor parameter, by name.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string> Type per parameter name.
	 */
	private function constructorTypes(string $class): array {
		$types = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$types[$parameter->getName()] = (string) $parameter->getType();
		}

		return $types;
	}
}
