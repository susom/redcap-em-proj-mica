<?php
/** @var \Stanford\MICA\MICA $module */

// Check if the form was submitted (when "Complete Session" is clicked)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participant_id'])) {
    $participant_id = $_POST['participant_id'];

    // Create the payload as expected by completeSession() function
    $payload = ['participant_id' => $participant_id];
    $module->completeSession($payload);
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
                        <input type="hidden" name="participant_id" value="<?php echo $session['participant_id']; ?>">
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
