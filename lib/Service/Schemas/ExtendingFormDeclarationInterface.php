<?php

/**
 * ExtendingFormDeclarationInterface: what callers outside the Service component ask of ExtendingFormDeclaration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The extends-form declaration vocabulary, as the property-vocabulary
 * controller and the schema declaration checks use it. Only the methods and
 * constants those callers use.
 */
interface ExtendingFormDeclarationInterface {

	/**
	 * The annotation key, in OpenRegister's own namespace.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-extends-form';

	/**
	 * Roles that are not vocabulary keys, and the key each one supplies.
	 *
	 * Exactly one today. `propertiesFromDefinitions` reads
	 * `mapped($record, $map, 'definition')` as the fallback source for a
	 * description, so `definition` is a second source for `description`
	 * rather than a property key of its own. It is declared here because a
	 * shipped role the platform refuses by name is the same breakage as a
	 * mis-read map, one release later.
	 *
	 * @var array<string, string>
	 */
	public const SOURCE_ALIASES = ['definition' => 'description'];

	/**
	 * Roles the consumer reads to steer the form, not to fill a property key.
	 *
	 * `@conduction/nextcloud-vue` 2.79.0 added `hideOnCreate` to the
	 * `DEFAULT_MAP` of `propertiesFromDefinitions`: a definition whose mapped
	 * field is `true` gets no field on a create form, because the app fills
	 * that answer in itself. It forwards no vocabulary key, so it is neither
	 * counted as forwarded nor written onto a property built from a form.
	 * Refusing it by name refused dossiq's whole case schema at import.
	 *
	 * @var array<string, string>
	 */
	public const FORM_ROLES = [
		'hideOnCreate' => 'The create form leaves the field out, because the app fills it in itself. Edit forms still show it.',
	];	/**
	 * Every extends-form declaration in a schema, keyed by where it sits.
	 *
	 * @param array $configuration The schema configuration.
	 * @param array $properties    The schema properties.
	 *
	 * @return array<string, array<string, mixed>> Path to declaration.
	 */
	public function fromSchema(array $configuration, array $properties): array;

	/**
	 * Describe one declaration: the app, the form, what it forwards and leaves out.
	 *
	 * @param mixed  $annotation The declaration as stored.
	 * @param string $path       Where the declaration sits.
	 *
	 * @return array<string, mixed> The app, the form, what it forwards, what it leaves out and the counts.
	 */
	public function describe(mixed $annotation, string $path = self::ANNOTATION): array;
}//end interface
