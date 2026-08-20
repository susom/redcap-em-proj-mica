<?php
/** @var \Stanford\MICA\MICA $module */

// Authorization comes first, before any state-changing branch. Until 2026-08-19 the POST handler
// below ran *before* any rights check at all - validatePermissions() was only consulted inside
// fetchIncompleteSessions(), further down the page - so anyone who could open this page could close
// an arbitrary participant's session. See docs/phase-3-handoff/14-live-defects.md D4.
//
// CSRF is already handled upstream and does not need a token here: the framework calls
// checkCSRFToken() in ExternalModules/index.php before this file is included (framework 14 >=
// CSRF_MIN_FRAMEWORK_VERSION, and this module declares no no-csrf-pages), and REDCap core's
// appendCsrfTokenToForm() adds redcap_csrf_token to every form on the page, which
// ExternalModules/redcap_connect.php maps onto the token the framework checks.
if (!$module->validatePermissions()) {
    ?>
    <h2>MICA Session Admin</h2>
    <div class="yellow" style="max-width:40rem;">
        You do not have permission to administer MICA sessions. This page requires the
        <strong>User Rights</strong> privilege on this project.
    </div>
    <?php
    return;
}

$notice = null;

// Handle "Complete Session".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participant_id'])) {
    $participant_id = $module->sanitizeInput($_POST['participant_id']);

    try {
        // completeSession() throws on failure. Left uncaught, that rendered a bare REDCap error
        // page and lost the admin's place in the table.
        $module->completeSession(['participant_id' => $participant_id]);
        $notice = ['class' => 'green', 'text' => "Session completed for $participant_id."];
    } catch (\Throwable $e) {
        $module->emError('sessionSelector: completeSession failed', $e->getMessage());
        $notice = ['class' => 'red', 'text' => 'Could not complete that session: ' . $e->getMessage()];
    }
}

// Fetch all incomplete mica sessions
$resp = json_decode($module->fetchIncompleteSessions(), true);
$open_sessions = $resp['sessions'] ?? [];
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
<style>
    #sessionsTable_wrapper {
        width: 100%;  /* Ensure full width */
        padding-right: 20px;  /* Add margin on the right */
    }
</style>
<h2>MICA Session Admin</h2>

<?php if ($notice !== null): ?>
    <div class="<?php echo $notice['class']; ?>" style="max-width:40rem;margin-bottom:1rem;">
        <?php echo htmlspecialchars($notice['text'], ENT_QUOTES); ?>
    </div>
<?php endif; ?>

<?php if (!empty($resp['error'])): ?>
    <div class="red" style="max-width:40rem;margin-bottom:1rem;">
        <?php echo htmlspecialchars($resp['error'], ENT_QUOTES); ?>
    </div>
<?php endif; ?>

<table id="sessionsTable" class="display">
    <thead>
    <tr>
        <th>Participant ID</th>
        <th>Participant Name</th>
        <th>Baseline Complete</th>
        <th>Posttest Complete</th>
        <th>Complete Study</th>
        <th>Withdraw Date</th>
        <th>Study Comments</th>
        <th>Session Time</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($open_sessions as $session): ?>
        <tr>
            <td><?php echo htmlspecialchars($session['participant_id']); ?></td>
            <td>
                <?php echo htmlspecialchars($session['participant_name']); ?><br>
                <small><?php echo htmlspecialchars($session['participant_email']); ?></small>
            </td>
            <td><?php echo $session['baseline_complete'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $session['posttest_complete'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $session['complete_study'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo !empty($session['withdraw_date']) ? htmlspecialchars($session['withdraw_date']) : 'N/A'; ?></td>
            <td><?php echo !empty($session['study_comments']) ? htmlspecialchars($session['study_comments']) : ''; ?></td>
            <td><?php echo htmlspecialchars($session['two_factor_code_ts']); ?></td>
            <td>
                <?php if (!empty($session['two_factor_code_ts'])): ?>
                    <form method="POST" style="display:inline;">
                        <?php // Escaped: this lands in an HTML attribute, and every sibling cell already escapes. ?>
                        <input type="hidden" name="participant_id"
                               value="<?php echo htmlspecialchars($session['participant_id'], ENT_QUOTES); ?>">
                        <button type="submit" class="complete-session-btn">
                            Complete Session
                        </button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- DataTables initialization -->
<script>
    $(document).ready(function() {
        // Initialize DataTables
        $('#sessionsTable').DataTable();
    });
</script>
