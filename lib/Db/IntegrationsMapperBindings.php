<?php

/**
 * IntegrationsMapperBindings: the map of the Integrations area's mapper interfaces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\Search\WebhookLogStatisticsInterface;

/**
 * Interface => mapper map of the Integrations area, behind IntegrationsMapperRegistrar.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class IntegrationsMapperBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AnalyticsLinkMapperInterface::class   => AnalyticsLinkMapper::class,
		AnalyticsSeriesMapperInterface::class => AnalyticsSeriesMapper::class,
		ApiCallRecordMapperInterface::class   => ApiCallRecordMapper::class,
		BookmarkLinkMapperInterface::class    => BookmarkLinkMapper::class,
		CalendarLinkMapperInterface::class    => CalendarLinkMapper::class,
		CollectiveLinkMapperInterface::class  => CollectiveLinkMapper::class,
		ConsumerMapperInterface::class        => ConsumerMapper::class,
		ContactLinkMapperInterface::class     => ContactLinkMapper::class,
		CospendLinkMapperInterface::class     => CospendLinkMapper::class,
		DeckLinkMapperInterface::class        => DeckLinkMapper::class,
		EmailLinkMapperInterface::class       => EmailLinkMapper::class,
		EndpointLogMapperInterface::class     => EndpointLogMapper::class,
		EndpointMapperInterface::class        => EndpointMapper::class,
		FederatedShareMapperInterface::class  => FederatedShareMapper::class,
		FlowLinkMapperInterface::class        => FlowLinkMapper::class,
		MapLinkMapperInterface::class         => MapLinkMapper::class,
		MappingMapperInterface::class         => MappingMapper::class,
		OpenProjectLinkMapperInterface::class => OpenProjectLinkMapper::class,
		PhotoLinkMapperInterface::class       => PhotoLinkMapper::class,
		PollLinkMapperInterface::class        => PollLinkMapper::class,
		SourceMapperInterface::class          => SourceMapper::class,
		SyncRecordMapperInterface::class      => SyncRecordMapper::class,
		TalkLinkMapperInterface::class        => TalkLinkMapper::class,
		TimeTrackerLinkMapperInterface::class => TimeTrackerLinkMapper::class,
		WebhookLogMapperInterface::class      => WebhookLogMapper::class,
		WebhookLogStatisticsInterface::class  => WebhookLogMapper::class,
		WebhookMapperInterface::class         => WebhookMapper::class,
		XwikiLinkMapperInterface::class       => XwikiLinkMapper::class,
	];
}//end class
