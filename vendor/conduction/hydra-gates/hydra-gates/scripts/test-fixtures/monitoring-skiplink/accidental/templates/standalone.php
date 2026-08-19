<?php
// SPDX-License-Identifier: EUPL-1.2
//
// THE POSITIVE CONTROL for the PHP half of gate-38.
//
// This template OWNS THE DOCUMENT: it emits <html> and <body> itself, so it
// is rendered outside Nextcloud's shell and nothing above it emitted a
// bypass mechanism. It has no jump-to-content affordance. It is a genuine
// WCAG 2.4.1 failure and MUST be reported.
//
// Its sibling `settings/admin.php` differs by exactly the one thing the
// #214/#216 narrowing accepts — it is a fragment — and must NOT be reported.
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Fixture standalone page</title>
</head>
<body>
	<nav class="fixture-nav">
		<a href="/apps/fixture/things">Things</a>
	</nav>
	<div class="fixture-page">
		<?php print_unescaped($_['body'] ?? ''); ?>
	</div>
</body>
</html>
