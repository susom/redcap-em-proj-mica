<?php

namespace Stanford\MICA;

/**
 * The RA review dashboard.
 *
 * An **authenticated** module page - deliberately not in `no-auth-pages`, unlike `pages/chatbot.php`
 * which serves participants. REDCap has therefore already established who the caller is before this
 * file runs.
 *
 * That is not the same as entitled, so the page asks RoleService and renders a refusal rather than
 * an empty dashboard for a user with no MICA role. An empty dashboard would be read as "no findings"
 * by exactly the person least able to tell the difference.
 *
 * The SPA lives in `mica-review/` and mounts on the div below. This file's whole job is: check the
 * role, hand the client the minimum it needs to start, and get out of the way.
 *
 * @var \Stanford\MICA\MICA $module
 */

$username = \ExternalModules\ExternalModules::getUsername();
$roles = RoleService::fromModule($module);

if (!$roles->hasAnyRole($username)) {
    // 403, and say what to do about it. A bare "access denied" sends the reviewer to IT when the
    // fix is a project setting their own PI can change.
    http_response_code(403);
    ?>
    <div class="container my-4" style="max-width: 40rem;">
        <div class="alert alert-warning">
            <h5 class="alert-heading">You do not have a MICA review role</h5>
            <p class="mb-2">
                This dashboard is limited to users assigned a MICA role on this project. Your
                account (<code><?= $module->escape($username) ?></code>) is not one of them.
            </p>
            <p class="mb-0 small text-muted">
                A project administrator can assign it under
                <strong>External Modules &rarr; MICA &rarr; Configure</strong>, in the
                <em>Research assistants</em>, <em>PI / protocol lead</em> or <em>Auditors</em>
                setting.
            </p>
        </div>
    </div>
    <?php
    return;
}

$assets = $module->reviewAssetFiles();

if ($assets === []) {
    // The build step has not run. Said plainly, because the alternative is a blank page that looks
    // like "no findings" - and this dashboard's entire purpose is that an empty screen must never be
    // ambiguous.
    ?>
    <div class="container my-4" style="max-width: 40rem;">
        <div class="alert alert-danger">
            <h5 class="alert-heading">The review dashboard has not been built</h5>
            <p class="mb-0">
                No compiled assets were found in <code>mica-review/dist/assets</code>. Run
                <code>npm install &amp;&amp; npm run build</code> in <code>mica-review/</code>
                and redeploy. This is a deployment problem, not an absence of findings.
            </p>
        </div>
    </div>
    <?php
    return;
}
?>

<?php
// Defines the JavaScript Module Object. Without this the SPA has nothing to call: the framework's
// ajax() helper, the CSRF token and the signed request verification all live on it, and its absence
// is what made the dashboard render "could not reach REDCap" on its first live run.
echo $module->initializeJavascriptModuleObject();
?>

<div id="mica-review-root" class="mica-review-root"></div>

<script>
    // Assigned to a known global rather than left for the client to find. The framework names the
    // object after the module's namespace (ExternalModules.Stanford.MICA), which a client can only
    // discover by guessing at a nested path - and a wrong guess is an undefined function at the
    // first call, with nothing on screen to say why. mica-chatbot does the same thing for the same
    // reason.
    window.mica_review_jsmo = <?= $module->getJavascriptModuleObjectName() ?>;

    // Everything the SPA needs to start, and nothing more. No findings and no transcript: those
    // come from the audited endpoints, so reading them is a recorded act rather than a page load.
    window.mica_review = <?= json_encode([
        'ajaxUrl'      => $module->getUrl('pages/review.php', true, true),
        'username'     => $username,
        'roles'        => $roles->rolesFor($username),
        'canDisposition' => $roles->can($username, 'submitDisposition'),
        'deidentified' => $roles->isDeidentifiedOnly($username),
        'projectId'    => (string) PROJECT_ID,
        'redcapVersion' => defined('REDCAP_VERSION') ? REDCAP_VERSION : '',
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?= implode("\n", $assets) ?>
