<?php
declare(strict_types=1);

// The public entry point is the commuter welcome experience. Driver, admin,
// and passenger account pages remain available through their direct routes.
header('Cache-Control: no-store, private');
header('Location: commuter/user-login.html', true, 302);
exit;
