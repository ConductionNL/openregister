<?php

/**
 * Schema: a lifecycle's createStates survives a save, an export and an update.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleDeclarationForms
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionOptionsValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleMessageValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleErrorBuilding
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleGraphValidator
 * @uses \OCA\OpenRegister\Service\Lifecycle\LifecycleStateValidator
 * @uses \OCA\OpenRegister\Db\Schema\AuthorizationRulesValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\DeclaredActions
 * @uses \OCA\OpenRegister\Db\Schema\TopLevelAnnotationFold
 * @uses \OCA\OpenRegister\Service\Lifecycle\StateDeclarationScope
 *
 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md
 */
class SchemaLifecycleCreateStatesRoundTripTest extends TestCase {

	private const LIFECYCLE = [
		'field' => 'status',
		'initial' => 'draft',
		'createStates' => ['published'],
		'transitions' => ['publish' => ['from' => 'draft', 'to' => 'published']],
	];

	/**
	 * A schema as an app's register JSON declares it: the lifecycle beside its properties.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		return [
			'slug' => 'application-version',
			'title' => 'Application version',
			'properties' => ['status' => ['type' => 'string', 'enum' => ['draft', 'published']]],
			'x-openregister-lifecycle' => self::LIFECYCLE,
		];
	}//end declaration()

	public function testCreateStatesSurvivesASaveAnExportAndAnUpdate(): void {
		$schema = new Schema();
		$schema->hydrate($this->declaration());
		$this->assertSame(['published'], $schema->getConfiguration()['x-openregister-lifecycle']['createStates']);

		$exported = $schema->jsonSerialize();
		$this->assertSame(['published'], $exported['configuration']['x-openregister-lifecycle']['createStates']);

		$reimported = new Schema();
		$reimported->hydrate(json_decode(json_encode($exported), true));
		$this->assertSame(['published'], $reimported->getConfiguration()['x-openregister-lifecycle']['createStates']);

		$reimported->hydrate(['title' => 'Application version, renamed']);
		$this->assertSame(['published'], $reimported->getConfiguration()['x-openregister-lifecycle']['createStates']);
	}//end testCreateStatesSurvivesASaveAnExportAndAnUpdate()

	public function testTheValidatorAcceptsCreateStatesFromTheEnum(): void {
		$errors = (new LifecycleAnnotationValidator())->validate(['x-openregister-lifecycle' => self::LIFECYCLE] + $this->declaration());

		$this->assertSame([], array_values(array_filter($errors, static fn (array $e): bool => str_contains((string)$e['code'], 'create-state'))));
	}//end testTheValidatorAcceptsCreateStatesFromTheEnum()

	public function testTheValidatorNamesACreateStateOutsideTheEnum(): void {
		$lifecycle = self::LIFECYCLE;
		$lifecycle['createStates'] = ['published', 'gone'];
		$errors = (new LifecycleAnnotationValidator())->validate(['x-openregister-lifecycle' => $lifecycle] + $this->declaration());

		$codes = array_column($errors, 'code');
		$this->assertContains('lifecycle-create-state-not-in-enum', $codes);
	}//end testTheValidatorNamesACreateStateOutsideTheEnum()
}//end class
