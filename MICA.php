<?php
namespace Stanford\MICA;

require_once "emLoggerTrait.php";
require_once "classes/Sanitizer.php";
require_once "classes/MICAQuery.php";

// Composer deps are optional (vendor/ is gitignored and nothing in the module currently uses
// php-ml or the Twilio SDK) - a hard require here makes the module class file unloadable,
// which surfaces as an unrelated fatal error when REDCap tries to enable the module.
if (file_exists(__DIR__ . "/vendor/autoload.php")) {
    require_once __DIR__ . "/vendor/autoload.php";
}
use Exception;
use UserRights;

class MICA extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    const BUILD_FILE_DIR = 'mica-chatbot/dist/assets';
    const SecureChatInstanceModuleName = 'secure_chat_ai';

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;
    public $system_context_session;
    public $system_context_global;

    private $primary_field;

    public function __construct() {
        parent::__construct();
    }

    public function getIntroText(){
        return $this->getProjectSetting('chatbot_intro_text');
    }

    public function getEndSessionText(){
        return $this->getProjectSetting('chatbot_end_session_text');
    }

    public function generateAssetFiles(): array {
        $cwd = $this->getModulePath();
        $assets = [];

        $full_path = $cwd . self::BUILD_FILE_DIR . '/';
        $dir_files = scandir($full_path);

        // Check if scandir failed
        if ($dir_files === false) {
            $this->emError("Failed to open directory: $full_path");
            return $assets; // Return an empty array or handle the error as needed
        }

        $dir_files = array_diff($dir_files, array('..', '.'));

        foreach ($dir_files as $file) {
            $url = $this->getUrl(self::BUILD_FILE_DIR . '/' . $file);
            $html = '';
            if (str_contains($file, '.js')) {
                $html = "<script type='module' crossorigin src='{$url}'></script>";
            } elseif (str_contains($file, '.css')) {
                $html = "<link rel='stylesheet' href='{$url}'>";
            }
            if ($html !== '') {
                $assets[] = $html;
            }
        }

        return $assets;
    }

    /**
     * @param $project_id
     * @param $link
     * @return mixed|null
     */
    function redcap_module_link_check_display($project_id, $link)
    {
        //Replace web link on sidebar with direct noauth link
        if (isset($link) && array_key_exists('url', $link) && str_contains($link['url'], 'chatbot')) {
            $link['url'] = $link['url'] . '&NOAUTH';
        }
        return $link;
    }

    /**
     * @param $data
     * @return mixed
     */
    public function sanitizeInput($data): mixed
    {
        $sanitizer = new Sanitizer();
        return $sanitizer->sanitize($data);
    }

    public function handleUserInput($payload): array|string {
        $sanitizedPayload = array();

        if (is_array($payload)) {
            foreach ($payload as $message) {
                if (
                    isset($message['role']) && is_string($message['role']) &&
                    isset($message['content']) && is_string($message['content'])
                ) {
                    $data = $this->sanitizeInput($message);
                    // Keep only the fields the model API accepts. The client's per-message `user_id`
                    // used to be forwarded verbatim into the request AND used as the participant's
                    // identity; identity is now resolved server-side (docs 14 D2), and dropping the
                    // key here also stops a nonstandard field reaching the provider.
                    $sanitizedPayload[] = array(
                        'role' => $data['role'],
                        'content' => $data['content'],
                    );
                }
            }
        }
        return $sanitizedPayload;
    }

    public function formatResponse($response) {
        // Check if the response is normalized (has `content`)
        if (isset($response['content'])) {
            $content = $response['content'];
            $role = $response['role'] ?? 'assistant';
        } else {
            // Handle raw responses (e.g., GPT-4o, Ada-002 pass-through)
            $content = $this->getSecureChatInstance()->extractResponseText($response);
            $role = $response['choices'][0]['message']['role'] ?? 'assistant';
        }

        // Common fields
        $id = $response['id'] ?? null;
        $model = $response['model'] ?? null;
        $usage = $response['usage'] ?? null;

        // Return in required structure
        $formattedResponse = [
            'response' => [
                'role' => $role,
                'content' => $content
            ],
            'id' => $id,
            'model' => $model,
            'usage' => $usage
        ];

        return $formattedResponse;
    }

    public function appendSystemContext($chatMlArray, $newContext) {
        // Normalize to array of system messages
        if (isset($newContext['role']) && isset($newContext['content'])) {
            $newContext = [ $newContext ];
        } elseif (!is_array($newContext)) {
            $newContext = [ [ 'role' => 'system', 'content' => (string)$newContext ] ];
        }
    
        foreach ($newContext as $ctx) {
            $hasSystemContext = false;
            for ($i = 0; $i < count($chatMlArray); $i++) {
                if ($chatMlArray[$i]['role'] == 'system' && !empty($chatMlArray[$i]['content'])) {
                    $chatMlArray[$i]['content'] .= "\n\n" . $ctx['content'];
                    $hasSystemContext = true;
                    break;
                }
            }
    
            if (!$hasSystemContext) {
                array_unshift($chatMlArray, $ctx);
            }
        }
    
        return $chatMlArray;
    }
    
    /**
     * Set em config parameters for model usage
     * @param $params
     * @return void
     */
    private function setModelParameters(&$params){
        $settings = [
            "temperature" => "gpt-temperature",
            "top_p" => "gpt-top-p",
            "frequency_penalty" => "gpt-frequency-penalty",
            "presence_penalty" => "gpt-presence-penalty",
            "max_tokens" => "gpt-max-tokens",
            "reasoning_effort" => "reasoning-effort"
        ];

        foreach ($settings as $key => $setting) {
            $value = $this->getProjectSetting($setting);
            if ($value !== null) { // Ensure the value exists
                if (is_numeric($value)) {
                    $params[$key] = strpos($value, '.') !== false ? (float) $value : (int) $value; // Keep floats as float
                } else {
                    $params[$key] = $value; // Preserve non-numeric strings
                }
            }
        }
    }

    /**
     * Fail loudly when `llm-model` names an alias SecureChatAI has not registered.
     *
     * Without this the failure is invisible: SecureChatAI throws internally, retries, and then
     * converts the error into a friendly assistant message ("...experiencing network difficulties")
     * that carries a `content` key - so formatResponse() treats it as a successful answer and
     * logMICAQuery() stores it in the participant's transcript as a counselor turn.
     *
     * Only enforced when the registry is readable and non-empty, so a SecureChatAI
     * misconfiguration cannot turn this guard into a second outage.
     *
     * @param $model
     * @return void
     * @throws \Exception
     */
    /**
     * Determine which participant this AJAX request is actually for.
     *
     * All five ajax actions are declared no-auth, and the guard in redcap_module_ajax() only checks
     * that *some* survey hash or *some* logged-in session exists. Taking the participant id from the
     * payload therefore let any caller act as any record: write another participant's transcript,
     * pull their baseline instrument data into the prompt, or close their session (docs 14 D2).
     *
     * On the survey path the framework hands us the record the participant is authenticated for
     * (verified: `$record` is populated alongside a survey hash), so that is authoritative and the
     * payload is ignored. Only an authenticated REDCap user - who already has data access - may
     * name a record explicitly.
     *
     * @param $record   the framework-supplied record for this request
     * @param $payload  the client payload (only consulted for authenticated users)
     * @return string
     * @throws \Exception
     */
    private function resolveParticipantId($record, $payload): string
    {
        if (!empty($record)) {
            return (string) $record;
        }

        if (!empty($_SESSION['username'])) {
            $candidate = null;
            if (is_array($payload)) {
                if (isset($payload['participant_id'])) {
                    $candidate = $payload['participant_id'];
                } else {
                    $first = current($payload);
                    if (is_array($first) && isset($first['user_id'])) $candidate = $first['user_id'];
                }
            }
            if (!empty($candidate)) {
                return (string) $this->sanitizeInput($candidate);
            }
        }

        throw new \Exception('Unable to determine which participant this request is for');
    }

    private function assertModelIsRegistered($model): void
    {
        if (empty($model)) {
            throw new \Exception('No LLM model configured for this project (llm-model project setting is empty)');
        }

        $available = $this->getSecureChatInstance()->getAvailableModels();
        if (empty($available) || !is_array($available)) {
            $this->emError('Could not read the SecureChatAI model registry; skipping alias validation', $model);
            return;
        }

        if (!in_array($model, $available, true)) {
            $this->emError('Configured llm-model is not registered in SecureChatAI', $model, $available);
            throw new \Exception("The configured AI model \"$model\" is not available. Please contact your administrator.");
        }
    }

    /**
     * Is this instrument one of the instruments configured to host the chat UI?
     * Reads the comma-delimited `chat_host_instruments` project setting and falls back to the
     * original pilot instrument when the setting is empty/unset so existing projects are unchanged.
     * @param $instrument
     * @return bool
     */
    private function isChatHostInstrument($instrument): bool {
        $setting = trim((string) $this->getProjectSetting('chat_host_instruments'));
        if ($setting === '') {
            $setting = 'ui_hosting_instrument';
        }
        $hosts = array_filter(array_map('trim', explode(',', $setting)), fn($host) => $host !== '');
        return in_array((string) $instrument, $hosts, true);
    }

    /**
     * All field names present in this project's data dictionary (empty array if unavailable).
     * Used to avoid asking getData() for fields a project does not have.
     * @return array
     */
    private function getProjectFieldNames(): array {
        try {
            $pro        = new \Project(PROJECT_ID);
            $metadata   = $pro->getMetadata();
            return is_array($metadata) ? array_keys($metadata) : [];
        } catch (\Throwable $e) {
            $this->emError("Unable to read project metadata", $e->getMessage());
            return [];
        }
    }

    // In your EM class
    public function redcap_survey_page_top($pid,$record,$instrument,$event_id,$group_id,$survey_hash,$response_id,$repeat_instance){
        if (!$this->isChatHostInstrument($instrument)) return;
        echo <<<HTML
        <style id="mica-hide-native">
        html,body { background:#E6E7ED !important; }
        /* Hide only REDCap's native chrome, not the content wrapper */
        #surveytitle, #surveyinstructions,
        #return_instructions, #footer, .rc-footer {
            display:none !important;
        }
        </style>
        <style id="mica-hide-submit">
        /* REDCap's submit chrome must never be reachable on a chat host survey. On a
           repeating host (Repeat Survey enabled) this block also renders a "Take this
           survey again" button, which would let a participant mint their own session
           instance, and a plain Submit, which completes the response. The SPA paints over
           them, but they stay in the DOM, focusable by keyboard and exposed to screen
           readers - so hide them outright.
           Deliberately a SEPARATE style block from #mica-hide-native above, because that
           one is removed by unmask() once the app mounts; this must survive that. */
        .surveysubmit { display:none !important; }
        </style>
        <noscript>
        <div style="padding:16px;font-family:sans-serif">
            JavaScript is required to use this chatbot. Please enable JavaScript and reload.
        </div>
        </noscript>
        HTML;
    }

    public function redcap_survey_page($pid,$record,$instrument,$event_id,$group_id,$survey_hash,$response_id,$repeat_instance){
        if (!$this->isChatHostInstrument($instrument)) return;

        // 1) Build bootstrap (same fields your app expects)
        $ctx = null;
        try {
            $ctx = $this->getSystemContextForRecord($record);
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $this->emDebug("Unable to build system context for record", $record, $error);
        }
        // Projects without the pilot's session scaffolding get null back - normalize to safe defaults
        if (!is_array($ctx)) $ctx = [];

        $primary = $this->getPrimaryField();

        // Only ask for fields this project actually has (participant_name/participant_email are pilot-only)
        $projectFields  = $this->getProjectFieldNames();
        $requestFields  = [$primary];
        foreach (['participant_name','participant_email'] as $optionalField) {
            if (in_array($optionalField, $projectFields, true)) $requestFields[] = $optionalField;
        }

        $row = [];
        if (!empty($record)) {
            $data = json_decode((string) \REDCap::getData([
                'records' => [$record],
                'fields'  => $requestFields,
                'return_format' => 'json'
            ]), true);
            $first = is_array($data) ? current($data) : false;
            if (is_array($first)) $row = $first;
        }

        $bootstrap = [
            'participant_id'         => $record,
            'name'                   => $row['participant_name'] ?? null,
            'email'                  => $row['participant_email'] ?? null,
            'current_session'        => $ctx['currentSession'] ?? null,
            'session_start_time'     => $ctx['session_start_time'] ?? null,
            'initial_system_context' => $ctx['system_context'] ?? [],
            // The session gates ("Session already completed", "Return in N day(s)...") were raised
            // as exceptions, caught here, and then dropped - the participant saw a normal, unusable
            // chat instead of the reason (docs 14 D7).
            'error'                  => $error ?? null,
            'login_url' => $this->getUrl('pages/chatbot.php', true, true)
        ];
        $json = json_encode($bootstrap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?: '{}';

        $this->emDebug("survey detail", $pid,$record,$instrument,$event_id, $json);

        // 2) Define JSMO and a mount point
        echo $this->initializeJavascriptModuleObject();
        echo "<div id='chatbot_ui_container' data-bootstrap='$json'></div>";

        // 3) Load JSMO helper + your built assets (CSS/JS from dist)
        echo "<script src='".$this->getUrl('assets/jsmo.js', true, true)."'></script>";
        foreach ($this->generateAssetFiles() as $f) echo $f;

        // 4) Minimal inline bootstrap + mount + block submit
        //    (If CSP blocks inline, move this into a small file later.)
        $js = <<<JS
        (function(){
        window.mica_jsmo_module = window.mica_jsmo_module || %s;

        var root = document.getElementById('chatbot_ui_container');
        var b = {};
        try { b = JSON.parse(root.dataset.bootstrap || '{}'); } catch(e){ b = {}; }
        window.mica_bootstrap = b;

        window.mica_jsmo_module.data = b.initial_system_context || [];
        window.mica_jsmo_module.this_session = b.current_session || null;

        function blockSubmit(){
            var form = document.querySelector('form#form');
            if (!form) return;
            form.setAttribute('novalidate','novalidate');
            form.addEventListener('submit', function(e){ e.preventDefault(); e.stopImmediatePropagation(); }, true);
            document.addEventListener('keydown', function(e){
            if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') e.preventDefault();
            }, true);
            document.querySelectorAll('button:not([type])').forEach(function(btn){ btn.setAttribute('type','button'); });
        }

        function unmask(){
            var tag = document.getElementById('mica-hide-native');
            if (tag) tag.remove();
        }

        function tryMount(){
            blockSubmit();
            if (window.renderMicaApp && typeof window.renderMicaApp === 'function') {
            window.renderMicaApp('#chatbot_ui_container');
            unmask();  // reveal after app mounts
            return true;
            }
            return false;
        }

        function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
        ready(function(){
            var attempts = 0;
            var id = setInterval(function(){
            attempts++;
            if (tryMount() || attempts > 100) clearInterval(id); // ~10s
            }, 100);
            window.addEventListener('load', tryMount, { once:true });
        });
        })();
        JS;

        printf("<script>%s</script>", sprintf($js, $this->getJavascriptModuleObjectName()));
    }

    /**
     * Put a record into the arm it was randomized to, so a CRC never has to create it there by hand.
     *
     * A REDCap record spans arms under the same record_id - there is nothing to "copy". A record
     * simply *appears* in an arm once it has at least one saved value in an event of that arm, which
     * is also what `REDCap::getSurveyLink()` checks before it will mint a link. So this writes the
     * allocation value into the first event of the assigned arm and lets REDCap do the rest.
     *
     * Deliberately only the ASSIGNED arm. Materializing every record in every arm would give a
     * Standard Care participant a valid `mica_ed_session` link (those host instruments are
     * designated to arms 2 and 3), i.e. a control participant could receive the intervention.
     *
     * By convention the `study_group` value IS the arm number - see
     * docs/phase-3-handoff/scripts/apply-study-group-field.php.
     */
    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                                      $survey_hash, $response_id, $repeat_instance)
    {
        // Our own saveData() re-enters this hook; the idempotency check would stop it anyway, but
        // guard explicitly so the intent is not left to ordering.
        static $running = false;
        if ($running) return;

        $running = true;
        try {
            $this->ensureRecordInAssignedArm($project_id, $record);
        } catch (\Throwable $t) {
            // Never let this break the CRC's data entry.
            $this->log('arm materialization threw', [
                'record' => (string) $record, 'error' => $t->getMessage(),
            ]);
        } finally {
            $running = false;
        }
    }

    /**
     * Ensure a record exists in the arm its allocation field points at.
     *
     * Public because `redcap_save_record` only fires on data-entry/survey saves through the UI
     * (REDCap calls it from exactly one place: `Classes/DataEntry.php:6735`). Data imports, the API
     * and other modules' `REDCap::saveData()` calls do NOT fire it, so the same logic has to be
     * runnable on demand - see docs/phase-3-handoff/scripts/backfill-study-group-arms.php.
     *
     * @param $project_id
     * @param $record
     * @return string one of: disabled, no-field, not-randomized, bad-arm, already-present,
     *                materialized, save-failed
     */
    public function ensureRecordInAssignedArm($project_id, $record): string
    {
        if (empty($this->getProjectSetting('materialize-assigned-arm'))) return 'disabled';

        $groupField = trim((string) ($this->getProjectSetting('study-group-field') ?: 'study_group'));
        $proj       = new \Project($project_id);
        if (!isset($proj->metadata[$groupField])) {
            $this->log('arm materialization skipped: allocation field missing', [
                'field' => $groupField, 'record' => (string) $record,
            ]);
            return 'no-field';
        }

        // The allocation may be recorded at any event; take the first non-empty value.
        $data  = \REDCap::getData([
            'project_id' => $project_id, 'records' => [$record],
            'fields' => [$groupField], 'return_format' => 'array',
        ]);
        $group = null;
        foreach (($data[$record] ?? []) as $values) {
            if (isset($values[$groupField]) && $values[$groupField] !== '') {
                $group = $values[$groupField];
                break;
            }
        }
        if ($group === null) return 'not-randomized';

        $arm = (int) $group;
        $targetEventId = $this->getFirstEventIdForArm($proj, $arm);
        if (!$targetEventId) {
            $this->log('arm materialization failed: no such arm', [
                'record' => (string) $record, 'study_group' => (string) $group, 'arm' => $arm,
            ]);
            return 'bad-arm';
        }

        if ($this->recordExistsInArm($project_id, $record, $arm)) return 'already-present';

        $eventNames = \REDCap::getEventNames(true, false);
        $response   = \REDCap::saveData([
            'project_id'        => $project_id,
            'dataFormat'        => 'json',
            'data'              => json_encode([[
                $proj->table_pk     => $record,
                'redcap_event_name' => $eventNames[$targetEventId] ?? '',
                $groupField         => $group,
            ]]),
            'overwriteBehavior' => 'normal',
            'returnFormat'      => 'json',
        ]);

        $errors = $this->describeSaveDataErrors($response);
        if ($errors !== '') {
            $this->log('arm materialization failed: saveData reported errors', [
                'record' => (string) $record, 'arm' => $arm, 'errors' => $errors,
            ]);
            return 'save-failed';
        }

        // Trial-relevant enough to keep an audit trail of.
        $this->log('record added to its randomized arm', [
            'record' => (string) $record, 'study_group' => (string) $group,
            'arm' => $arm, 'event_id' => (string) $targetEventId,
        ]);
        return 'materialized';
    }

    /**
     * Lowest-day_offset event of an arm - `eventInfo` carries arm_num/day_offset but no unique name.
     *
     * @param \Project $proj
     * @param int $arm
     * @return int|null
     */
    private function getFirstEventIdForArm($proj, int $arm): ?int
    {
        $best = null; $bestOffset = null;
        foreach ($proj->eventInfo as $eventId => $info) {
            if ((int) ($info['arm_num'] ?? 0) !== $arm) continue;
            $offset = (int) ($info['day_offset'] ?? 0);
            if ($bestOffset === null || $offset < $bestOffset) {
                $bestOffset = $offset;
                $best = (int) $eventId;
            }
        }
        return $best;
    }

    /**
     * Does this record already have data in any event of the given arm?
     *
     * @param $project_id
     * @param $record
     * @param int $arm
     * @return bool
     */
    private function recordExistsInArm($project_id, $record, int $arm): bool
    {
        $dataTable = method_exists($this, 'getDataTable')
            ? $this->getDataTable($project_id)
            : 'redcap_data';

        $sql = "select 1 from $dataTable d
                  join redcap_events_metadata em on em.event_id = d.event_id
                  join redcap_events_arms ea on ea.arm_id = em.arm_id
                 where d.project_id = ? and d.record = ? and ea.project_id = ? and ea.arm_num = ?
                 limit 1";
        $result = $this->query($sql, [$project_id, $record, $project_id, $arm]);
        return (bool) $result->fetch_row();
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        try {
            $isSurvey = !empty($survey_hash);
            $isUser   = !empty($_SESSION['username']);
            if (!$isSurvey && !$isUser) {
                http_response_code(403);
                return json_encode(['error'=>'Forbidden']);
            }
            header('Content-Type: application/json; charset=utf-8');

            // Never take the participant's identity from the payload - see resolveParticipantId().
            $participant_id = $this->resolveParticipantId($record, $payload);

            switch ($action) {
                case "callAI":
                    $messages = $this->handleUserInput($payload);
                    if (empty($messages)) {
                        throw new \Exception('No usable messages were provided');
                    }

                    // Add most recent message to database
                    $recent_query = $messages[count($messages) - 1];
                    $this->logMICAQuery(json_encode($recent_query), $participant_id);

                    // Add user baseline AFTER logging to leave it out of the logs
                    $formattedBaseline = $this->getFormattedBaselineData($participant_id);
                    if (!empty($formattedBaseline)) {
                        $messages = $this->appendSystemContext($messages, $formattedBaseline);
                    }
                    $this->emDebug("chatml Messages array to API", $messages);

                    //CALL API ENDPOINT WITH AUGMENTED CHATML
                    $model  = $this->getProjectSetting("llm-model");
                    $params = array("messages" => $messages);

                    // Alter model parameters if set by user
                    $this->setModelParameters($params);

                    $this->assertModelIsRegistered($model);
                    $response = $this->getSecureChatInstance()->callAI($model, $params, PROJECT_ID );
                    $result = $this->formatResponse($response);

                    $result['user_id'] = $participant_id;
                    $result['query']   = $recent_query;

                    // Add response to database
                    $this->logMICAQuery(json_encode($result), $participant_id);

                    return json_encode($result);

                case "fetchSavedQueries":
                    $data = $this->sanitizeInput($payload);
                    $data['participant_id'] = $participant_id; // authoritative, not client-supplied
                    $return_payload = [];
                    $return_payload["current_session"] = [];
                    $existing_chat = $this->fetchSavedQueries($data, $data['session_start_time'] ?? null);
                    if(!empty($existing_chat)){
                        $return_payload["current_session"] = $existing_chat;
                    }
                    return json_encode($return_payload);

                case "completeSession":
                    $data = $this->sanitizeInput($payload);
                    $data['participant_id'] = $participant_id; // authoritative, not client-supplied
                    return json_encode($this->completeSession($data));

                default:
                    throw new Exception("Action $action is not defined");
            }
        } catch(\Exception $e) {
            $this->emError($e);
            return json_encode([
                "error" => $e->getMessage(),
                "success" => false
            ]);
        }
    }

    /**
     * Fetch and format REDCap instrument data for a participant based on config.
     *
     * @param string $participant_id The record_id of the participant.
     * @return string|null A concatenated formatted string or null if no data exists.
     */
    private function getFormattedBaselineData($participant_id) {
        // Get comma-delimited instruments from project settings
        $instrumentsString = $this->getProjectSetting("chatbot_redcap_inject");
        if (empty($instrumentsString)) {
            return null;
        }
        $instruments = array_map('trim', explode(',', $instrumentsString));

        // Get metadata once
        $metadata = \REDCap::getDataDictionary('array');

        // Helper to decode enumerated values
        $decodeChoice = function ($field, $value) use ($metadata) {
            $choices = $metadata[$field]['select_choices_or_calculations'] ?? null;
            if ($choices) {
                $choiceArray = array_map('trim', explode('|', $choices));
                foreach ($choiceArray as $choice) {
                    list($code, $label) = array_map('trim', explode(',', $choice, 2));
                    if ((string)$code === (string)$value) {
                        return $label;
                    }
                }
            }
            return $value;
        };

        $finalFormatted = "";
        foreach ($instruments as $instrument) {
            // Fetch field names and participant's data for the instrument
            $fields = \REDCap::getFieldNames($instrument);
            $recordData = \REDCap::getData([
                'records' => $participant_id,
                'fields' => $fields,
                'return_format' => 'json'
            ]);
            $record = current(json_decode($recordData, true));
            if (empty($record)) {
                continue;
            }
            // Start with a header indicating the instrument name
            $formatted = "## " . ucfirst($instrument) . " Data\n\n";
            foreach ($record as $field => $value) {
                $fieldLabel = $metadata[$field]['field_label'] ?? $field;
                $decodedValue = $decodeChoice($field, $value);
                if (!empty($decodedValue)) {
                    $formatted .= "{$fieldLabel}: {$decodedValue}\n";
                }
            }
            $finalFormatted .= $formatted . "\n";
        }
        return empty($finalFormatted) ? null : $finalFormatted;
    }

    /**
     * @param $payload
     * @return array
     * @throws \Exception
     */
    public function fetchSavedQueries($payload, $sessionStart = null): array
    {
        $participant_id = $payload['participant_id'] ?? null;
        $name           = $payload['name'] ?? null;

        if (empty($participant_id)) {
            throw new \Exception("Error with fetching queries: no participant ID provided");
        }

        $primary_field = $this->getPrimaryField();
        $hasNameField  = in_array('participant_name', $this->getProjectFieldNames(), true);

        // The pilot used participant_name as a second factor on this no-auth action. Keep that gate
        // wherever the field exists, but do not *require* a name in projects that have no such field
        // (the R01 structure): demanding one made restore impossible, so a reloaded session silently
        // came back empty (docs 14 D6).
        if ($hasNameField && empty($name)) {
            throw new \Exception("Error with fetching queries: Participant ID / name combination not provided");
        }

        $fields = [$primary_field];
        if ($hasNameField) {
            $fields[] = 'participant_name';
        }

        // Look the record up by `records` instead of interpolating user input into a filterLogic
        // string (docs 14 D3, same class of issue).
        $json  = json_decode((string) \REDCap::getData([
            'records'       => [$participant_id],
            'fields'        => $fields,
            'return_format' => 'json',
        ]), true);
        $check = is_array($json) ? current($json) : false;

        // Compare against the project's actual primary field, not a hardcoded 'record_id' (docs 14 D13).
        if (!is_array($check) || ($check[$primary_field] ?? null) !== (string) $participant_id) {
            return [];
        }
        if ($hasNameField && ($check['participant_name'] ?? null) !== $name) {
            return [];
        }

        return MICAQuery::getLogsFor($this, PROJECT_ID, $participant_id, $sessionStart);
    }

    public function getPrimaryField(){
        $pro                    = new \Project(PROJECT_ID);
        $this->primary_field    = $pro->table_pk;
        return $this->primary_field;
    }
    /**
     * @param $payload
     * @return true[]
     * @throws \Exception
     */
    public function loginUser($payload): array
    {
        $primary_field = $this->getPrimaryField();
        if(empty($payload['name']) || empty($payload['email']))
            throw new \Exception("Error logging in user, either name or email is empty");

        $name  = trim((string) $payload['name']);
        $email = trim((string) $payload['email']);

        // Only a validated e-mail is ever interpolated into filterLogic; the free-text name is
        // compared in PHP. Previously both went in raw from $_POST on a no-auth page (docs 14 D3).
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\'"\\\\\[\]]/', $email)) {
            throw new \Exception('Invalid Credentials');
        }

        $params = array(
            "return_format" => "json",
            "filterLogic" => "[participant_email] = '$email'",
            "fields" => array($primary_field, "participant_name", "participant_email", "participant_phone", "user_complete", "completion_timestamp"),
        );

        $json = json_decode((string) \REDCap::getData($params), true);
        $rows = is_array($json) ? $json : [];

        // Name match happens here rather than in the logic string.
        $matches = array_values(array_filter(
            $rows,
            fn($row) => is_array($row) && ($row['participant_name'] ?? null) === $name
        ));

        if (count($matches) > 1)
            throw new \Exception("Error logging in user, duplicate entries for $name, $email");

        $check = $matches[0] ?? false;
        if (!is_array($check)) {
            throw new \Exception('Invalid Credentials');
        }

        // Ensure completed users cannot login again
        if (isset($check["user_complete"]) && $check['user_complete'] == "2") {
            $time_completed = $check['completion_timestamp'];
            throw new \Exception("Your MICA session was completed on $time_completed, thank you for participating");
        }

        // TODO Change user complete to form name
        // Otherwise, login regularly
        if($check['participant_name'] === $name && $check['participant_email'] === $email) { //User Successfully matched
            $this->generateOneTimePassword($check[$primary_field], $check['participant_email']);
            return ["success" => true];
        } else {
            throw new \Exception('Invalid Credentials');
        }
    }

    /**
     * Generates OTP and saves to record
     * @param $record_id
     * @param $email
     * @return void
     * @throws \Exception
     */
    private function generateOneTimePassword($record_id, $email): void
    {
        $primary_field = $this->getPrimaryField();

        $code = bin2hex(random_bytes(3));
        $saveData = array(
            array(
                $primary_field => $record_id,
                "two_factor_code" => $code,
                "two_factor_code_ts" => date("Y-m-d H:i:s"),
            )
        );

        $response = \REDCap::saveData('json', json_encode($saveData), 'overwrite');

        // Check the save before sending anything. `empty($response['errors'])` used to be the
        // success condition, which fails OPEN: a non-array response makes the expression true, so
        // the participant would be emailed a code that was never stored, leaving them unable to
        // log in at all (docs 14 D11).
        $saveErrors = $this->describeSaveDataErrors($response);
        if ($saveErrors !== '') {
            $this->emError('generateOneTimePassword: saveData failed', $saveErrors);
            throw new \Exception('Save data failure in generating one time password');
        }

        $body = "<html><p>Your MICA Verification code is: <strong>$code</strong></p></html>";
        $res = \REDCap::email($email, 'redcap@stanford.edu', 'Your MICA verification code', $body);
        if (!$res) {
            $this->emError('Email hook failure');
            throw new \Exception('Verification email could not be sent, please contact your administrator');
        }
    }

    /**
     * Normalize a REDCap::saveData() result into an error description.
     *
     * The result is an array whose 'errors' key may be absent, a string, or an array - and with a
     * json returnFormat saveData can hand back a string instead. Callers used to subscript it
     * directly, which both mistook a non-array response for success and could pass an array into
     * Exception's string parameter (docs 14 D11). Unrecognized shapes are treated as failure so
     * this stays fail-closed.
     *
     * @param mixed $response
     * @return string '' when the save reported no errors, otherwise a loggable description
     */
    private function describeSaveDataErrors($response): string
    {
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            // A JSON body is the documented returnFormat=json shape; anything else is not a
            // result we can interpret, so treat it as an error rather than assume success.
            $response = is_array($decoded) ? $decoded : ['errors' => $response];
        }

        if (!is_array($response)) {
            return 'Unexpected REDCap::saveData() response of type ' . get_debug_type($response);
        }

        $errors = $response['errors'] ?? null;
        if (empty($errors)) {
            return '';
        }

        if (!is_array($errors)) {
            return is_scalar($errors) ? (string) $errors : json_encode($errors);
        }

        // REDCap can report errors as nested rows (e.g. [record, field, value, message]), so
        // json_encode anything that is not scalar rather than stringifying an array.
        return implode('; ', array_map(
            fn($error) => is_scalar($error) ? (string) $error : json_encode($error),
            $errors
        ));
    }

    /**
     * @param $payload
     * @return false[]|true[]
     * @throws \Exception
     */
    public function verifyEmail($payload) {
        $primary_field = $this->getPrimaryField();

        if(empty($payload['code']))
            throw new \Exception("Error verifying email, no code provided");

        // Codes are bin2hex(random_bytes(3)) - exactly six lowercase hex characters. Validating the
        // shape means no user-supplied text is interpolated into filterLogic (docs 14 D3), and it
        // rejects the malformed input that would otherwise scan every record.
        $code = strtolower(trim((string) $payload['code']));
        if (!preg_match('/^[0-9a-f]{6}$/', $code)) {
            throw new \Exception('Invalid OTP code');
        }

        // Fetch user information
        $params = array(
            "return_format" => "json",
            "fields" => array($primary_field, "two_factor_code", "participant_name"),
            "filterLogic" => "[two_factor_code] = '$code'"
        );

        // Find user and determine validity
        $json = json_decode((string) \REDCap::getData($params), true);
        $rows = is_array($json) ? $json : [];
        if(count($rows) > 1)
            throw new \Exception("Error logging in user, duplicate entries for $code ");

        $check = reset($rows);
        if (is_array($check) && ($check['two_factor_code'] ?? null) === $code) {
            $record_id = $check[$primary_field] ?? null;
            $session_stuff = $this->getSystemContextForRecord($record_id);
    
            $chat_info = [
                "success" => true,
                "user" => [
                    "participant_id" => $record_id,
                    "name" => $check['participant_name'] ?? null
                ],
                "initial_system_context" => $session_stuff["system_context"],
                "currentSession" => $session_stuff["currentSession"],
                "session_start_time" => $session_stuff["session_start_time"]
            ];
            return $chat_info;
        } else {
            throw new \Exception('Invalid OTP code');
        }
    }

    /**
     * @param $content
     * @param $session_id
     * @return void
     */
    private function logMICAQuery($content, $id){
        if (!isset($content) || !isset($id))
            throw new \Exception('No content passed to addAction, unable to save message');

        $action = new MICAQuery($this);
        $action->setValue('mica_id', $id);
        $action->setValue('message', $content);
        $action->save();
    }

    public function getSystemContextForRecord($recordId): ?array {
        $calc = $this->calculateSessionInfo($recordId);
        if (!$calc) return null;

        $currentSession = $calc['currentSession'];
        $sessionStart   = $calc['sessionStart'];
        $eventName      = $calc['eventName'];
        $events         = $calc['events'];

        $baselineEventId = array_search("baseline_arm_1", $events);
        $data = \REDCap::getData([
            'project_id' => $this->getProjectId(),
            'records' => [$recordId],
            'fields' => ['consent_date', 'des_mica', 'month3_fu_complete'],
            'events' => [$baselineEventId],
            'return_format' => 'array'
        ]);

        $desMica = (int)($data[$recordId][$baselineEventId]['des_mica'] ?? 1);
        $month3_fu_complete = (int)($data[$recordId][$baselineEventId]['month3_fu_complete'] ?? 0);

        if($month3_fu_complete == 2){
            throw new \Exception("Study already completed. Thank you.");
        }

        $contextKey = $currentSession == "baseline"
            ? $currentSession
            : "session_{$currentSession}";

        $backN = $this->getProjectSetting("number_session_callback") ?? 1;
        $sys_ctx = $this->initSystemContexts($recordId, $contextKey, $backN);

        $eventId = array_search($eventName, $events);
        $completionData = \REDCap::getData([
            'project_id' => $this->getProjectId(),
            'records' => [$recordId],
            'fields' => ['session_info_complete'],
            'events' => [$eventId],
            'return_format' => 'array'
        ]);
        $sessionComplete = $completionData[$recordId][$eventId]['session_info_complete'] ?? null;

        if ((int)$sessionComplete === 2) {
            if ($desMica === 0 || $month3_fu_complete == 2) {
                throw new \Exception("Session already completed. Thank you.");
            } else {
                $nextSessionStart = clone $sessionStart;
                $session_length_days = $this->getProjectSetting("session_length_days") ?? 14;
                $nextSessionStart->modify("+$session_length_days days");
                $today = new \DateTime();
                $daysUntilNext = max(0, $today->diff($nextSessionStart)->days) + 1;
                throw new \Exception("Session already completed. Return in $daysUntilNext day(s) for your next session!");
            }
        }

        return [
            'system_context' => $sys_ctx,
            'currentSession' => $currentSession,
            'session_start_time' => $sessionStart->getTimestamp()
        ];
    }

    
    public function initSystemContexts($record_id, $session_key = 'baseline', $backN= 1) {

        $setting_key = "chatbot_system_context_" . $session_key;
        $this->emDebug("using session context", $setting_key);
        $this->system_context_global =  $this->getProjectSetting("chatbot_system_context_general");
        $this->system_context_session = $this->getProjectSetting($setting_key);
        $general_system_context =  $this->appendSystemContext([], $this->system_context_global);

        if (preg_match('/session_(\d+)/', $session_key, $matches)) {
            $currentSession = (int) $matches[1];
            if ($currentSession >= 1) {
                $catchup = $this->summarizeCatchUp($record_id, $currentSession, $backN); 
                $general_system_context = $this->appendSystemContext($general_system_context, $catchup);
            }
        }
        return $this->appendSystemContext($general_system_context, $this->system_context_session);
    }

    public function summarizeCatchUp($recordId, $currentSession, $backN = 1) {
        $primary_field = $this->getPrimaryField();
        $logs = [];
        for ($i = max(0, $currentSession - $backN); $i < $currentSession; $i++) {
            $event = $i === 1 ? 'baseline_arm_1' : "session_{$i}_arm_1";
            $eventId =  \REDCap::getEventIdFromUniqueEvent($event);
            $data = \REDCap::getData([
                'project_id' => $this->getProjectId(),
                'records' => [$recordId],
                'fields' => ['raw_chat_logs'],
                'events' => [$eventId],
                'return_format' => 'json'
            ]);
        
            $data = json_decode($data, true);
            $data = current($data);
            $logStr = $data["raw_chat_logs"];

            if (!empty($logStr)) {
                $log = is_string($logStr) ? json_decode($logStr, true) : $logStr;
                if (is_array($log)) {
                    foreach ($log as $msg) {
                        $text = trim(($msg['user_content'] ?? '') . ' ' . ($msg['assistant_content'] ?? ''));
                        if ($text !== '') {
                            $logs[] = $text;
                        }
                    }
                }
            }
        }
        
        $summaryText = implode("\n", $logs);
        $flat_context = ['role' => 'system', 'content' => "Previous session summaries:\n" . $summaryText];
        return [$flat_context];
    }
    
 /**
 * Send SMS with body payload
 * @param $body
 * @param $phone_number
 * @return void
 */
//public function sendSMS($body, $phone_number): void
//{
//    $sid = $this->getSystemSetting('twilio-sid');
//    $auth = $this->getSystemSetting('twilio-auth-token');
//    $fromNumber = $this->getSystemSetting('twilio-from-number');
//
//    $twilio = new Client($sid, $auth);
//    $twilio->messages
//        ->create(
//            "$phone_number",
//            array(
//                'body' => $body,
//                'from' => $fromNumber
//            )
//        );
//}
    private function calculateSessionInfo($recordId): ?array {
        $events = \REDCap::getEventNames(true, false);
        $baselineEventId = array_search("baseline_arm_1", $events);
        if (!$baselineEventId) {
            $this->emError("Unable to find event ID for baseline_arm_1");
            return null;
        }

        $data = \REDCap::getData([
            'project_id' => $this->getProjectId(),
            'records' => [$recordId],
            'fields' => ['consent_date'],
            'events' => [$baselineEventId],
            'return_format' => 'array'
        ]);

        $consentDateStr = trim((string) $data[$recordId][$baselineEventId]['consent_date'] ?? '');
        try {
            $consentDate = new \DateTime($consentDateStr);
            $today = new \DateTime();
            $days_since_consent = $consentDate->diff($today)->days;
        } catch (\Exception $e) {
            $this->emError("DateTime crash", [
                'message' => $e->getMessage(),
                'raw_value' => $consentDateStr,
                'record' => $recordId,
                'event' => $baselineEventId
            ]);
            return null;
        }

        $session_length_days = $this->getProjectSetting("session_length_days") ?? 14;
        $sessionNum = (int) min(7, floor($days_since_consent / $session_length_days));

        $sessionStart = clone $consentDate;
        $sessionStart->modify("+".($sessionNum * $session_length_days)." days");

        $currentSession = $sessionNum === 0 ? 'baseline' : $sessionNum + 1;
        $eventName = ($currentSession === 'baseline')
            ? 'baseline_arm_1'
            : "session_{$currentSession}_arm_1";

        return [
            'currentSession' => $currentSession,
            'sessionStart'   => $sessionStart,
            'eventName'      => $eventName,
            'events'         => $events
        ];
    }


    /**
     * @param $payload
     * @return true[]|void
     * @throws \Exception
     */
    public function completeSession($payload) {
        ['participant_id' => $participant_id] = $payload;

        if (empty($participant_id)) {
            throw new \Exception("Error with completing session: No participant ID provided");
        }

        // calculateSessionInfo() returns null whenever it cannot resolve the pilot session events
        // or the participant's consent_date. This used to fall straight through to
        // ->getTimestamp() on null - a fatal that fired *before* saveData(), so the session was
        // never finalized and the participant was signed out with no explanation (docs 14 D16).
        $calc = $this->calculateSessionInfo($participant_id);
        if (!is_array($calc) || empty($calc['sessionStart']) || !($calc['sessionStart'] instanceof \DateTime)) {
            $this->emError('completeSession: could not resolve session info', [
                'participant_id' => $participant_id,
                'project_id'     => PROJECT_ID,
            ]);
            throw new \Exception(
                'This session could not be finalized because the project is not configured for MICA '
                . 'sessions. Your messages have been recorded - please contact the study team.'
            );
        }

        $session      = $calc['currentSession'];
        $sessionStart = $calc['sessionStart']->getTimestamp();

        $this->emDebug("payload and calc", $payload, $session, $sessionStart);

        $primary_field = $this->getPrimaryField();
        $logs = MICAQuery::getLogsFor($this, PROJECT_ID, $participant_id, $sessionStart);

        $logField = 'raw_chat_logs';
        $timestampField = 'session_timestamp';
        $completeField = 'session_info_complete';

        $eventName = ($session === 'baseline')
            ? 'baseline_arm_1'
            : "session_{$session}_arm_1";

        $save = [
            $primary_field => $participant_id,
            'redcap_event_name' => $eventName,
            $logField => is_string($logs) ? $logs : json_encode($logs),
            $timestampField => date("Y-m-d H:i:s"),
            $completeField => '2'
        ];

        $response = \REDCap::saveData([
            'dataFormat' => 'json',
            'data' => json_encode([$save]),
            'overwriteBehavior' => 'overwrite',
            'returnFormat' => 'json'
        ]);
        $saveErrors = $this->describeSaveDataErrors($response);
        if ($saveErrors !== '') {
            $this->emError('completeSession: saveData reported errors', $saveErrors);
            throw new \Exception('Your session could not be saved. Please contact the study team.');
        }

        $surveys = ["success" => true];
        $event_id = null; // sessions 2-6 take neither branch below; it is still logged (docs 14 D10)

        if ($session === 'baseline') {
            $event_id = \REDCap::getEventIdFromUniqueEvent($eventName);
            $surveys["survey_link"] = $this->getProjectSetting('chatbot_end_session_url_override')
                ?: \REDCap::getSurveyLink($participant_id, "posttest", $event_id);
        } elseif ($session == 7) {
            $event_id = \REDCap::getEventIdFromUniqueEvent("baseline_arm_1");
            $surveys["survey_link"] = $this->getProjectSetting('chatbot_end_session_url_override')
                ?: \REDCap::getSurveyLink($participant_id, "month3_fu", $event_id);
        }

        $this->emDebug("return surveys object ", $participant_id, $session, $event_id, $surveys);

        return $surveys;
    }

    /**
     * @return bool
     */
    public function validatePermissions() {
        $test = current(UserRights::getPrivileges(PROJECT_ID)[PROJECT_ID]);
        if($test['user_rights'] === '1')
            return true;
        return false;
    }

    /**
     * @return string
     */
    public function fetchIncompleteSessions() {
        try {
            if(!$this->validatePermissions())
                throw new \Exception("You do not have permissions to view this page");

            $params = array(
                "return_format" => "json",
                "filterLogic" => "[user_complete] != '2'",
            );

            // Find user and determine validity
            $json = json_decode(\REDCap::getData($params), true);
            $ind = $json ?? [];

            return json_encode([
                "sessions" => $ind,
                "success" => true
            ]);

        } catch (\Exception $e) {
            $this->emError($e);
            return json_encode([
                "error" => $e->getMessage(),
                "success" => false
            ]);
        }


    }

    /**
     * @return \Stanford\SecureChatAI\SecureChatAI
     * @throws \Exception
     */
    public function getSecureChatInstance(): \Stanford\SecureChatAI\SecureChatAI
    {
        if(empty($this->secureChatInstance)){
            $this->setSecureChatInstance(\ExternalModules\ExternalModules::getModuleInstance(self::SecureChatInstanceModuleName));
            return $this->secureChatInstance;
        }else{
            return $this->secureChatInstance;
        }
    }

    /**
     * @param \Stanford\SecureChatAI\SecureChatAI $secureChatInstance
     */
    public function setSecureChatInstance(\Stanford\SecureChatAI\SecureChatAI $secureChatInstance): void
    {
        $this->secureChatInstance = $secureChatInstance;
    }
}
?>
