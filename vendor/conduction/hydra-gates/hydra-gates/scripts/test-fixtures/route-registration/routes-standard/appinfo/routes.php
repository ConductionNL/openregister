<?php
// SPDX-License-Identifier: EUPL-1.2
//
// ADR-040 route table. The ten canonical entries — dashboard#page,
// dashboard#catchAll, settings#index/create/update/load,
// preferences#getPreference/setPreference, metrics#index, health#index —
// are PREPENDED by Routes::standard() and appear nowhere in this file.
// That is the whole point of #223: gate-14 invariant 1 was grepping here
// for names that live in openregister.
return \OCA\OpenRegister\AppHost\Routes::standard([
    ['name' => 'widget#show', 'url' => '/api/widget', 'verb' => 'GET'],
]);
