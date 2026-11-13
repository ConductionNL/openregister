<?php

/**
 * The bundle bindings: which bundle each subject follows.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Reads and writes the bindings between a bundle and the subjects that follow it.
 */
interface ConfigurationBindingMapperInterface {

	/**
	 * The binding of one subject; a subject follows at most one bundle.
	 *
	 * @param string $subject The subject.
	 *
	 * @return ConfigurationBinding|null The binding, or null when the subject follows no bundle.
	 */
	public function findBySubject(string $subject): ?ConfigurationBinding;

	/**
	 * Every binding of one bundle, by subject.
	 *
	 * @param string $bundle The bundle name.
	 *
	 * @return ConfigurationBinding[] The bindings.
	 */
	public function findByBundle(string $bundle): array;

	/**
	 * Every binding, by bundle then subject.
	 *
	 * @return ConfigurationBinding[] The bindings.
	 */
	public function findAllBindings(): array;

	/**
	 * Create and persist a binding.
	 *
	 * @param array<string, mixed> $data The binding fields.
	 *
	 * @return ConfigurationBinding The persisted binding.
	 */
	public function createFromArray(array $data): ConfigurationBinding;

	/**
	 * Remove a binding.
	 *
	 * @param ConfigurationBinding $binding The binding to remove.
	 *
	 * @return ConfigurationBinding The removed binding.
	 */
	public function remove(ConfigurationBinding $binding): ConfigurationBinding;
}//end interface
