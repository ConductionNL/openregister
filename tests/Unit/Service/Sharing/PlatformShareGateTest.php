<?php

/**
 * Unit tests for PlatformShareGate: the schema's opt-in and the bit mapping in front of the one object share model.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\ObjectSharingServiceInterface;
use OCA\OpenRegister\Service\Sharing\PlatformShareGate;
use OCP\Constants;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sharing\PlatformShareGate
 * @uses   \OCA\OpenRegister\Service\Sharing\PlatformSharePermissions
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantRequest
 * @uses   \OCA\OpenRegister\Service\Rbac\GrantTerms
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses   \OCA\OpenRegister\Db\Schema\LinkedTypesRule
 * @uses   \OCA\OpenRegister\Db\Schema\VocabularyEntries
 * @uses   \OCA\OpenRegister\Db\Schema\WriteOnlyPathsDeclaration
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration
 * @uses   \OCA\OpenRegister\Service\Calendar\CalendarDateDeclarationCheck
 */
final class PlatformShareGateTest extends TestCase {

	private function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('zaken');
		$schema->setConfiguration($configuration);

		return $schema;
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('710d44a4-0000-4000-8000-000000000002');

		return $object;
	}

	/**
	 * @return array<string, array{array<string, mixed>, bool}>
	 */
	public static function declarations(): array {
		return [
			'declared' => [['platformSharing' => true], true],
			'declared off' => [['platformSharing' => false], false],
			'declaring nothing' => [[], false],
		];
	}

	/**
	 * @dataProvider declarations
	 */
	public function testOnlyASchemaDeclaringItOffersPlatformSharing(array $configuration, bool $offered): void {
		$gate = new PlatformShareGate(sharing: $this->createMock(ObjectSharingServiceInterface::class));

		$this->assertSame($offered, $gate->isOffered(schema: $this->schema(configuration: $configuration)));
	}

	public function testANonBooleanDeclarationDoesNotOptIn(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['platformSharing' => 'yes']);

		$this->assertFalse((new PlatformShareGate(sharing: $this->createMock(ObjectSharingServiceInterface::class)))->isOffered(schema: $schema));
	}

	public function testAnOptedOutSchemaIsRefusedAndNothingIsWritten(): void {
		$sharing = $this->createMock(ObjectSharingServiceInterface::class);
		$sharing->expects($this->never())->method('grantWithTerms');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Schema "zaken" does not offer its objects for platform sharing');

		(new PlatformShareGate(sharing: $sharing))->share(
			object: $this->object(),
			schema: $this->schema(configuration: []),
			request: new GrantRequest(type: 'user', shareWith: 'tvisser', terms: new GrantTerms(permissions: Constants::PERMISSION_READ))
		);
	}

	public function testAnUnmappedBitIsRefusedBeforeAnythingIsWritten(): void {
		$sharing = $this->createMock(ObjectSharingServiceInterface::class);
		$sharing->expects($this->never())->method('grantWithTerms');

		$this->expectExceptionMessage('Permission bit 8 (delete) maps to no object verb');

		(new PlatformShareGate(sharing: $sharing))->share(
			object: $this->object(),
			schema: $this->schema(configuration: ['platformSharing' => true]),
			request: new GrantRequest(type: 'user', shareWith: 'tvisser', terms: new GrantTerms(permissions: Constants::PERMISSION_READ | Constants::PERMISSION_DELETE))
		);
	}

	public function testAnAcceptedShareIsWrittenByTheObjectShareModelWithItsTerms(): void {
		$object = $this->object();
		$expires = new DateTime('2026-12-31');
		$request = new GrantRequest(
			type: 'user',
			shareWith: 'tvisser',
			terms: new GrantTerms(permissions: Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE, expiresAt: $expires, note: 'Graag voor vrijdag')
		);
		$sharing = $this->createMock(ObjectSharingServiceInterface::class);
		$sharing->expects($this->once())->method('grantWithTerms')->with($object, $request)->willReturn(['id' => 'ocinternal:41', 'permissions' => 3]);

		$grant = (new PlatformShareGate(sharing: $sharing))->share(object: $object, schema: $this->schema(configuration: ['platformSharing' => true]), request: $request);

		$this->assertSame(
			['id' => 'ocinternal:41', 'permissions' => 3, 'grantedVerbs' => ['read', 'update'], 'note' => 'Graag voor vrijdag'],
			$grant
		);
	}
}
