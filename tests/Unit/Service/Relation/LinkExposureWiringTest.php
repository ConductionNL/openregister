<?php

declare(strict_types=1);

/*
 * The link exposure, where it is actually called from.
 *
 * `LinkExposure` has had its rule and its tests since the change opened, and
 * no caller. A rule with no caller is a control that silently does nothing:
 * every test of it passes, every schema that declares `exposes` is accepted,
 * and every field it was written to withhold travels anyway. These tests are
 * about the two call sites that end that, and they assert the WIRING rather
 * than the rule, because the rule already has its own suite.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Relation\LinkExposure;
use OCA\OpenRegister\Service\Relation\RelationTypeResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Relation\RelationTypeResolver
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @covers \OCA\OpenRegister\Db\SchemaMapper
 */
final class LinkExposureWiringTest extends TestCase {

	/**
	 * A schema with one reference property and a relation vocabulary.
	 *
	 * @param array<string, mixed>|null $exposes What the type declares it exposes, or null for a type that declares none.
	 * @param string                    $ref     The reference the property carries.
	 *
	 * @return Schema The schema.
	 */
	private function schemaLinkingTo(?array $exposes, string $ref = '#/components/schemas/besluit'): Schema {
		$type = ['key' => 'gerelateerd', 'label' => 'related to'];
		if ($exposes !== null) {
			$type[LinkExposure::KEY] = $exposes;
		}

		$schema = new Schema();
		$schema->setId(7);
		$schema->setProperties(
			[
				'besluit' => [
					'$ref' => $ref,
					'x-openregister-relation' => ['type' => 'gerelateerd'],
				],
			]
		);
		$schema->setConfiguration(['x-openregister-relation-types' => [$type]]);

		return $schema;
	}//end schemaLinkingTo()

	/**
	 * The far schema, which declares three properties and not the fourth.
	 *
	 * @return Schema The schema.
	 */
	private function farSchema(): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setSlug('besluit');
		$schema->setProperties(
			[
				'zaaknummer' => ['type' => 'string'],
				'status' => ['type' => 'string'],
				'toelichting' => ['type' => 'string'],
			]
		);

		return $schema;
	}//end farSchema()

	/**
	 * The descriptor carries what the link exposes.
	 *
	 * Without this the render path would have to read
	 * `x-openregister-relation-types` for itself, which is a second reader of
	 * one vocabulary.
	 *
	 * @return void
	 */
	public function testTheDescriptorCarriesTheExposedSet(): void {
		$descriptor = (new RelationTypeResolver())->descriptorFor(
			schema: $this->schemaLinkingTo(['zaaknummer', 'status']),
			property: 'besluit'
		);

		$this->assertSame(['zaaknummer', 'status'], $descriptor[LinkExposure::KEY]);
	}//end testTheDescriptorCarriesTheExposedSet()

	/**
	 * 🔴 An undeclared set is ABSENT from the descriptor, not empty.
	 *
	 * Undeclared means the link narrows nothing, which is what every relation
	 * type does today. Present-but-empty means it exposes nothing. Carrying an
	 * empty list for the first would turn every existing link into one that
	 * hands over no fields at all.
	 *
	 * @return void
	 */
	public function testAnUndeclaredSetIsAbsentRatherThanEmpty(): void {
		$descriptor = (new RelationTypeResolver())->descriptorFor(
			schema: $this->schemaLinkingTo(null),
			property: 'besluit'
		);

		$this->assertArrayNotHasKey(LinkExposure::KEY, $descriptor);
		$this->assertFalse((new LinkExposure())->declaresExposure(relationType: $descriptor));
	}//end testAnUndeclaredSetIsAbsentRatherThanEmpty()

	/**
	 * A present-but-empty set survives as an empty set.
	 *
	 * @return void
	 */
	public function testAnEmptySetSurvivesAsADeclaration(): void {
		$descriptor = (new RelationTypeResolver())->descriptorFor(
			schema: $this->schemaLinkingTo([]),
			property: 'besluit'
		);

		$this->assertSame([], $descriptor[LinkExposure::KEY]);
		$this->assertTrue((new LinkExposure())->declaresExposure(relationType: $descriptor));
	}//end testAnEmptySetSurvivesAsADeclaration()

	/**
	 * A render path with no constructor run, for the two private methods that
	 * read nothing but their arguments and their own lazy collaborators.
	 *
	 * @return RenderObject The renderer.
	 */
	private function renderer(): RenderObject {
		return (new ReflectionClass(objectOrClass: RenderObject::class))->newInstanceWithoutConstructor();
	}//end renderer()

	/**
	 * Call one of the renderer's private methods.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return mixed The answer.
	 */
	private function callRenderer(string $method, array $args): mixed {
		$reflected = new ReflectionMethod(objectOrMethod: RenderObject::class, method: $method);
		$reflected->setAccessible(true);

		return $reflected->invokeArgs($this->renderer(), $args);
	}//end callRenderer()

	/**
	 * The renderer collects the properties whose links declare a set.
	 *
	 * @return void
	 */
	public function testTheRendererCollectsTheLinksThatDeclareASet(): void {
		$exposures = $this->callRenderer('exposuresFor', [$this->schemaLinkingTo(['zaaknummer'])]);

		$this->assertArrayHasKey('besluit', $exposures);
		$this->assertSame(['zaaknummer'], $exposures['besluit'][LinkExposure::KEY]);
	}//end testTheRendererCollectsTheLinksThatDeclareASet()

	/**
	 * CONTROL: a link that declares nothing is not collected, so nothing on an
	 * existing schema is narrowed.
	 *
	 * @return void
	 */
	public function testALinkThatDeclaresNothingIsNotCollected(): void {
		$this->assertSame([], $this->callRenderer('exposuresFor', [$this->schemaLinkingTo(null)]));
	}//end testALinkThatDeclaresNothingIsNotCollected()

	/**
	 * The far record is narrowed to the declared set, and the rest is marked.
	 *
	 * @return void
	 */
	public function testTheExtendedRecordIsNarrowedToTheDeclaredSet(): void {
		$rendered = [
			'zaaknummer' => 'Z-1',
			'status' => 'open',
			'toelichting' => 'gevoelig',
			'@self' => ['id' => 'uuid-1', 'schema' => 9],
			'id' => 'uuid-1',
		];

		$projected = $this->callRenderer(
			'applyLinkExposure',
			[$rendered, ['property' => 'besluit', LinkExposure::KEY => ['zaaknummer', 'status']]]
		);

		$this->assertSame('Z-1', $projected['zaaknummer']);
		$this->assertSame('open', $projected['status']);
		$this->assertSame(
			LinkExposure::WITHHELD,
			$projected['toelichting'],
			'withheld, not absent: the two send a reader to different places'
		);
	}//end testTheExtendedRecordIsNarrowedToTheDeclaredSet()

	/**
	 * 🔴 The envelope is never withheld.
	 *
	 * `id` and `@self` say WHICH record the link points at. Withholding them
	 * would break every client that follows the link it was handed, and it
	 * would answer "you may not see this record's identity" about a record the
	 * link exists to name. The exposure decides which fields travel.
	 *
	 * @return void
	 */
	public function testTheEnvelopeIsNeverWithheld(): void {
		$projected = $this->callRenderer(
			'applyLinkExposure',
			[
				['zaaknummer' => 'Z-1', '@self' => ['id' => 'uuid-1'], 'id' => 'uuid-1'],
				['property' => 'besluit', LinkExposure::KEY => []],
			]
		);

		$this->assertSame('uuid-1', $projected['id']);
		$this->assertSame(['id' => 'uuid-1'], $projected['@self']);
		$this->assertSame(LinkExposure::WITHHELD, $projected['zaaknummer']);
	}//end testTheEnvelopeIsNeverWithheld()

	/**
	 * A field the reader's own rules already removed stays absent.
	 *
	 * The readable set is whatever survived the far schema's property rules,
	 * so a stripped field is not a key any more and must not reappear as a
	 * withheld marker: "you may not see this" and "this was never here for
	 * you" are the same answer here, and the weaker one leaks the field's
	 * existence.
	 *
	 * @return void
	 */
	public function testAFieldTheReadersOwnRulesRemovedStaysAbsent(): void {
		$projected = $this->callRenderer(
			'applyLinkExposure',
			[
				['zaaknummer' => 'Z-1', 'id' => 'uuid-1'],
				['property' => 'besluit', LinkExposure::KEY => ['zaaknummer', 'bsn']],
			]
		);

		$this->assertArrayNotHasKey('bsn', $projected);
	}//end testAFieldTheReadersOwnRulesRemovedStaysAbsent()

	/**
	 * CONTROL: with no descriptor the record is handed back untouched.
	 *
	 * Without this a projector that withheld everything unconditionally would
	 * pass the tests above.
	 *
	 * @return void
	 */
	public function testAnUndeclaredLinkChangesNothing(): void {
		$rendered = ['zaaknummer' => 'Z-1', 'toelichting' => 'gevoelig', 'id' => 'uuid-1'];

		$this->assertSame($rendered, $this->callRenderer('applyLinkExposure', [$rendered, null]));
	}//end testAnUndeclaredLinkChangesNothing()

	/**
	 * Project twice and the answer does not change.
	 *
	 * The wildcard extend path can hand an already-rendered record to the
	 * projector a second time, and a projection that degraded on the second
	 * pass would withhold fields it had just allowed.
	 *
	 * @return void
	 */
	public function testProjectingTwiceIsTheSameAsProjectingOnce(): void {
		$descriptor = ['property' => 'besluit', LinkExposure::KEY => ['zaaknummer']];
		$once = $this->callRenderer(
			'applyLinkExposure',
			[['zaaknummer' => 'Z-1', 'toelichting' => 'x', 'id' => 'u'], $descriptor]
		);
		$twice = $this->callRenderer('applyLinkExposure', [$once, $descriptor]);

		$this->assertSame($once, $twice);
	}//end testProjectingTwiceIsTheSameAsProjectingOnce()

	/**
	 * A mapper whose only stubbed method is the schema lookup.
	 *
	 * `createPartialMock` stubs by `onlyMethods`, so a lookup the real mapper
	 * does not declare could not be stubbed here at all.
	 *
	 * @param Schema|null $far The schema a reference resolves to, or null for one that resolves to nothing.
	 *
	 * @return SchemaMapper The mapper.
	 */
	private function mapperResolving(?Schema $far): SchemaMapper {
		$mapper = $this->createPartialMock(SchemaMapper::class, ['find']);

		if ($far === null) {
			$mapper->method('find')->willThrowException(new \RuntimeException('no such schema'));

			return $mapper;
		}

		$mapper->method('find')->willReturn($far);

		return $mapper;
	}//end mapperResolving()

	/**
	 * The refusals a schema save produces for its exposed sets.
	 *
	 * @param SchemaMapper $mapper The mapper.
	 * @param Schema       $schema The schema being saved.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private function refusalsFor(SchemaMapper $mapper, Schema $schema): array {
		$reflected = new ReflectionMethod(objectOrMethod: SchemaMapper::class, method: 'exposureRefusals');
		$reflected->setAccessible(true);

		return $reflected->invoke($mapper, $schema);
	}//end refusalsFor()

	/**
	 * 🔴 A save is refused when the exposed set names a property the linked
	 * schema does not declare.
	 *
	 * Left unrefused this is silent: the name is simply absent from every
	 * projection afterwards, and the author reads a 200.
	 *
	 * @return void
	 */
	public function testASaveIsRefusedForAnUndeclaredExposedProperty(): void {
		$refusals = $this->refusalsFor(
			$this->mapperResolving($this->farSchema()),
			$this->schemaLinkingTo(['zaaknummer', 'zaknummer'])
		);

		$this->assertCount(1, $refusals);
		$this->assertSame('relation-exposes-unknown-property', $refusals[0]['code']);
		$this->assertStringContainsString('zaknummer', $refusals[0]['message']);
	}//end testASaveIsRefusedForAnUndeclaredExposedProperty()

	/**
	 * CONTROL: a set that names only declared properties is accepted.
	 *
	 * @return void
	 */
	public function testACorrectExposedSetIsAccepted(): void {
		$this->assertSame(
			[],
			$this->refusalsFor(
				$this->mapperResolving($this->farSchema()),
				$this->schemaLinkingTo(['zaaknummer', 'status'])
			)
		);
	}//end testACorrectExposedSetIsAccepted()

	/**
	 * A far schema that cannot be resolved is not a refusal.
	 *
	 * Schemas arrive in whatever order an import walks them, so the schema a
	 * `$ref` names may genuinely not exist yet. Refusing there would fail a
	 * valid import on ordering alone.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFarSchemaIsNotARefusal(): void {
		$this->assertSame(
			[],
			$this->refusalsFor(
				$this->mapperResolving(null),
				$this->schemaLinkingTo(['anything-at-all'])
			)
		);
	}//end testAnUnresolvableFarSchemaIsNotARefusal()

	/**
	 * CONTROL: a link that declares no set is never asked about.
	 *
	 * @return void
	 */
	public function testALinkWithNoDeclaredSetIsNotRefused(): void {
		$this->assertSame(
			[],
			$this->refusalsFor($this->mapperResolving($this->farSchema()), $this->schemaLinkingTo(null))
		);
	}//end testALinkWithNoDeclaredSetIsNotRefused()
}//end class
