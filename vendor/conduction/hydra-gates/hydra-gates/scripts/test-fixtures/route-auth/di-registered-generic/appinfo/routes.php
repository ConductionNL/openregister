<?php
// SPDX-License-Identifier: EUPL-1.2
//
// Fixture mirroring openconnector (2026-08-07): an AppHost generic reaching a
// route WITHOUT Bootstrap::register() aliasing it.
//
// This app needs OpenRegister's GenericPreferencesController constructed with
// its OWN appName, so that the `pref_` user-value namespace stays scoped here
// rather than to OpenRegister. It therefore registers the generic itself, in
// lib/AppInfo/Application.php, under the standard controller class name — the
// name NC's App::main synthesises from a plain route slug. The file does not
// exist in this repository, by design, exactly as with a Bootstrap alias.
//
// The slug is `genericPreferences`, NOT `preferences`, because the service key
// must be the class name the router builds. That is why the five-slug
// _apphost_serves() list cannot recognise it, and why gate-14 called a working
// endpoint unreachable.
//
// `gadget#run` is the control half, kept in this same fixture so it cannot
// drift away from the case it guards: a class that is neither on disk nor
// registered anywhere is still a reachability defect. If
// _di_binds_controller() ever loosens into "this app registers services, so
// absences are fine", this route goes quiet and the suite goes red.
return [
    'routes' => [
        ['name' => 'widget#show', 'url' => '/api/widgets/{id}', 'verb' => 'GET'],
        ['name' => 'gadget#run',  'url' => '/api/gadgets/run',  'verb' => 'POST'],

        ['name' => 'genericPreferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'genericPreferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
    ],
];
