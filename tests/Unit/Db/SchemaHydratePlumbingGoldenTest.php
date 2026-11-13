<?php

/**
 * SchemaHydratePlumbingGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Pins the entity's column types and how hydrate() folds top-level annotations.
 *
 * Written against the entity before its field-type list and annotation fold
 * moved into collaborators: the exact types and their order, and the
 * configuration a hydrated schema ends up with for each shape of payload.
 */
class SchemaHydratePlumbingGoldenTest extends TestCase {

	public function testTheColumnTypesAndTheirOrderAreUnchanged(): void {
		$this->assertSame(
			[
				'id'                 => 'integer',
				'uuid'               => 'string',
				'uri'                => 'string',
				'slug'               => 'string',
				'title'              => 'string',
				'description'        => 'string',
				'version'            => 'string',
				'summary'            => 'string',
				'icon'               => 'string',
				'required'           => 'json',
				'properties'         => 'json',
				'archive'            => 'json',
				'facets'             => 'json',
				'allOf'              => 'json',
				'oneOf'              => 'json',
				'anyOf'              => 'json',
				'source'             => 'string',
				'hardValidation'     => 'boolean',
				'immutable'          => 'boolean',
				'appendOnly'         => 'boolean',
				'searchable'         => 'boolean',
				'smartPickerEnabled' => 'boolean',
				'updated'            => 'datetime',
				'created'            => 'datetime',
				'maxDepth'           => 'integer',
				'owner'              => 'string',
				'application'        => 'string',
				'organisation'       => 'string',
				'authorization'      => 'json',
				'sharedWith'         => 'json',
				'deleted'            => 'datetime',
				'configuration'      => 'json',
				'groups'             => 'json',
				'hooks'              => 'json',
				'mail'               => 'json',
				'contacts'           => 'json',
				'notes'              => 'json',
				'todos'              => 'json',
				'calendar'           => 'json',
				'talk'               => 'json',
				'deck'               => 'json',
				'draft'              => 'json',
				'formerSlugs'        => 'json',
			],
			(new Schema())->getFieldTypes()
		);
	}

	public function testTopLevelAnnotationsFoldIntoTheConfiguration(): void {
		$lifecycle = ['field' => 'status', 'states' => ['open' => []]];
		$cases = [
			'nothing to fold'          => [
				['title' => 'T', 'configuration' => ['objectNameField' => 'title']],
				['objectNameField' => 'title'],
			],
			'annotation'               => [
				['title' => 'T', 'x-openregister-lifecycle' => $lifecycle],
				['x-openregister-lifecycle' => $lifecycle],
			],
			'annotation and config'    => [
				['configuration' => ['objectNameField' => 'title'], 'x-openregister-mcp' => ['tools' => []]],
				['objectNameField' => 'title', 'x-openregister-mcp' => ['tools' => []]],
			],
			'annotation overrides'     => [
				['configuration' => ['x-openregister-mcp' => ['tools' => ['a']]], 'x-openregister-mcp' => ['tools' => ['b']]],
				['x-openregister-mcp' => ['tools' => ['b']]],
			],
			'config not an array'      => [
				['configuration' => 'text', 'x-openregister-mcp' => ['tools' => []]],
				['x-openregister-mcp' => ['tools' => []]],
			],
			'semantic marker'          => [
				['x-schema-org' => 'schema:Organization'],
				['x-schema-org' => 'schema:Organization'],
			],
			'explicit marker wins'     => [
				['configuration' => ['x-schema-org' => 'schema:Person'], 'x-schema-org' => 'schema:Organization'],
				['x-schema-org' => 'schema:Person'],
			],
			'marker and annotation'    => [
				['x-schema-org' => 'schema:Place', 'x-openregister-contextchat' => true],
				['x-openregister-contextchat' => true, 'x-schema-org' => 'schema:Place'],
			],
			'unknown annotation'       => [
				['x-openregister-lifecycl' => $lifecycle],
				null,
			],
			'not an annotation prefix' => [
				['x-openregister' => true, 'X-openregister-mcp' => []],
				null,
			],
		];
		foreach ($cases as $name => [$payload, $configuration]) {
			$schema = (new Schema())->hydrate($payload);
			$this->assertSame($configuration, $schema->getConfiguration(), $name);
		}
	}

	public function testHydrateMarksOnlyTheFieldsItSets(): void {
		$cases = [
			'nothing to fold'     => [['title' => 'T'], ['title', 'required', 'hardValidation']],
			'config not an array' => [['configuration' => 'text'], ['configuration', 'required', 'hardValidation']],
			'annotation'          => [['x-openregister-mcp' => ['tools' => []]], ['required', 'hardValidation', 'configuration']],
			'marker'              => [['x-schema-org' => 'schema:Place'], ['required', 'hardValidation', 'configuration']],
			'empty'               => [[], ['required', 'hardValidation']],
		];
		foreach ($cases as $name => [$payload, $updated]) {
			$schema = (new Schema())->hydrate($payload);
			$this->assertSame($updated, array_keys($schema->getUpdatedFields()), $name);
		}
	}
}
