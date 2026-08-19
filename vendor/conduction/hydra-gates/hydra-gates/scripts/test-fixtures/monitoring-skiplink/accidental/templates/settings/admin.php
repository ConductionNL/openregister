<?php
// SPDX-License-Identifier: EUPL-1.2
//
// A MOUNT POINT, verbatim in the shape measured across the fleet
// (procest/templates/settings/admin.php is literally this). Nextcloud's
// Template renderer substitutes this into core's Settings page; core emitted
// that page's <html>, its landmarks and its bypass mechanism long before this
// file's first byte.
//
// It owns no document, so it cannot own a skip link, and adding one here
// would announce a second "skip to content" ahead of core's real one.
// gate-38 must NOT report it. Its sibling `standalone.php` differs by owning
// the document, and must still be reported.
script('fixture', 'admin');
style('fixture', 'admin');
?>
<div id="fixture-settings"></div>
