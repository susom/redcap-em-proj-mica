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
    // 403, and say which of the three possible causes it is. A bare "access denied" sends the
    // reviewer to IT when the fix is usually a REDCap role assignment their own PI can make - and
    // the three causes have three different fixes.
    http_response_code(403);

    $redcapRole = $roles->redcapRoleFor($username);
    ?>
    <div class="container my-4" style="max-width: 42rem;">
        <div class="alert alert-warning">
            <h5 class="alert-heading">You do not have access to the MICA safety review</h5>

            <?php if ($roles->isUnconfigured()) { ?>
                <p class="mb-2">
                    No REDCap user role has been mapped to a MICA review role on this project yet, so
                    <em>nobody</em> can open this dashboard.
                </p>
                <p class="mb-0 small text-muted">
                    A project administrator sets the mapping under
                    <strong>External Modules &rarr; MICA &rarr; Configure</strong> &mdash; the
                    <em>Reviewer</em>, <em>PI / protocol lead</em> and <em>Auditor</em> settings each
                    take a REDCap user role.
                </p>
            <?php } elseif ($redcapRole === null) { ?>
                <p class="mb-2">
                    Your account (<code><?= $module->escape($username) ?></code>) has rights on this
                    project but is <strong>not assigned to a REDCap user role</strong>. MICA access
                    follows REDCap roles, so a user with no role has none here.
                </p>
                <p class="mb-0 small text-muted">
                    A project administrator can assign you a role under
                    <strong>User Rights</strong>. That is the same place study access is managed, on
                    purpose: access to participant transcripts should be governed by the same thing
                    that governs access to the project.
                </p>
            <?php } else { ?>
                <p class="mb-2">
                    Your REDCap role on this project is not one of the roles mapped to a MICA review
                    role.
                </p>
                <p class="mb-0 small text-muted">
                    Either your account should move to a role that is mapped, or that mapping should
                    include your role &mdash; a project administrator can do either, under
                    <strong>User Rights</strong> or
                    <strong>External Modules &rarr; MICA &rarr; Configure</strong>.
                </p>
            <?php } ?>
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
        // Which tabs exist at all. Asked of RoleService rather than derived from `roles` in the
        // client, so the matrix stays the single answer to "who may do what" - a second copy of it in
        // JavaScript is a second thing to keep in step.
        //
        // `canSeeQueue` matters more than it looks: a super user holds `sysadmin` and nothing else,
        // so they reach this dashboard (rolesFor() grants sysadmin unconditionally) but `reviewQueue`
        // refuses them. Without this the sysadmin - the person who owns the configuration the launch
        // checklist reports on - would land on a tab that 403s and conclude the dashboard is broken.
        'canSeeQueue'  => $roles->can($username, 'reviewQueue'),
        'canSeeAudit'  => $roles->can($username, 'auditTrail'),
        'canSeeLaunchGates' => $roles->can($username, 'launchReadiness'),
        'projectId'    => (string) PROJECT_ID,
        'redcapVersion' => defined('REDCAP_VERSION') ? REDCAP_VERSION : '',
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?= implode("\n", $assets) ?>
