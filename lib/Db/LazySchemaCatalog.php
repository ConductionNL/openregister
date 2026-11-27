<?php

/**
 * LazySchemaCatalog: the register, schema and object table mappers, each
 * built when it is first asked for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Psr\Container\ContainerInterface;

/**
 * The schema catalogue the container hands out: same mappers as SchemaCatalog,
 * resolved on first use.
 *
 * SchemaCatalog built its three mappers when it was constructed, so every
 * service that takes the catalogue built the MagicMapper (with its bulk,
 * search, table and RBAC handlers) as soon as it was itself constructed, even
 * when the request never reached a table. On a Nextcloud page render that
 * happened through the URN and semantic provider capabilities, whose blocks
 * need neither mapper: ~1.3 ms of every page on the reference stack. This
 * catalogue asks the container for a mapper only when its accessor is called,
 * and the container keeps handing out its one shared instance of each.
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */
final class LazySchemaCatalog implements SchemaCatalogInterface {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The app container, which holds the mappers.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The register mapper.
	 *
	 * @return RegisterMapperInterface
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function registers(): RegisterMapperInterface {
		return $this->container->get(RegisterMapperInterface::class);
	}//end registers()

	/**
	 * The schema mapper.
	 *
	 * @return SchemaMapperInterface
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function schemas(): SchemaMapperInterface {
		return $this->container->get(SchemaMapperInterface::class);
	}//end schemas()

	/**
	 * The object table mapper.
	 *
	 * @return MagicMapperInterface
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function tables(): MagicMapperInterface {
		return $this->container->get(MagicMapperInterface::class);
	}//end tables()
}//end class
