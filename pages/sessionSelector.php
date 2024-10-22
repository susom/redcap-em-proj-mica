<?php
/** @var \Stanford\MICA\MICA $module */

// Fetch all incomplete mica sessions
$resp = json_decode($module->fetchIncompleteSessions(), true);
$a = 1;
// Note: this page will have to be regular javascript / jquery as otherwise we'd have to have two react-app


?>
