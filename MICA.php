<?php
namespace Stanford\MICA;

require_once "emLoggerTrait.php";
require_once "classes/Sanitizer.php";
require_once "classes/MICAQuery.php";
require_once "classes/UserRightsCheck.php";
// Required explicitly rather than left to the composer autoloader below: the integrity gate on the
// hash-pinned handoff artifacts must not become unreachable just because vendor/ is absent.
require_once "classes/ArtifactRegistry.php";
// Same reason, plus one of its own: redcap_module_system_enable() runs while the module is being
// enabled, and an unloadable class there is reported as a bare fatal with no cause attached.
require_once "classes/EntitySchemaManager.php";
require_once "classes/RedcapEntityPlatform.php";
// The post-session pipeline. Required explicitly for the same reason as the rest: this module has
// to work when vendor/ is absent, and a class that cannot load is reported as an unrelated fatal.
require_once "classes/RedcapScanQueueStore.php";
require_once "classes/RedcapTranscriptStore.php";
require_once "classes/ScanWorker.php";
require_once "classes/SessionHostMap.php";
require_once "classes/TranscriptFinalizer.php";
// Stage 4: the scan itself.
require_once "classes/FindingThresholds.php";
require_once "classes/FindingWriter.php";
require_once "classes/FixtureSafetyScanCaller.php";
require_once "classes/RedcapScanResultStore.php";
require_once "classes/ScanRunner.php";
require_once "classes/SecureChatSafetyScanCaller.php";
// Stage 5: the review dashboard's services.
require_once "classes/AuditLogger.php";
require_once "classes/DispositionService.php";
require_once "classes/RedcapAuditStore.php";
require_once "classes/RedcapFindingReviewStore.php";
require_once "classes/RoleService.php";
require_once "classes/RedcapReviewQueryStore.php";
require_once "classes/ReviewEndpoints.php";
// Stage 6: launch gates and everything addressed to a human.
require_once "classes/LaunchReadiness.php";
require_once "classes/NotificationPolicy.php";
require_once "classes/NotificationService.php";
require_once "classes/RedcapActionFieldWriter.php";
require_once "classes/RedcapEmailChannel.php";
require_once "classes/RedcapLaunchEnvironment.php";
require_once "classes/RedcapNotificationStore.php";
require_once "classes/RedcapRecipientDirectory.php";

// vendor/ is committed and deploys with the module, and opis/json-schema is a runtime dependency
// of the turn contract, so this is a hard require again. It was briefly conditional because a
// missing vendor/ made this class file unloadable, which REDCap surfaces as an unrelated fatal when
// enabling the module - hence the explicit message rather than a bare require, so the next person
// to hit it reads the cause instead of guessing.
if (!file_exists(__DIR__ . "/vendor/autoload.php")) {
    throw new \Exception(
        'MICA: vendor/autoload.php is missing. This directory was deployed incomplete - '
        . 'restore it from the repository, or run `composer install --no-dev` in the module directory.'
    );
}
require_once __DIR__ . "/vendor/autoload.php";
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

    /**
     * REDCap Entity type declarations. Not a REDCap hook - the redcap_entity module discovers this
     * by method_exists() (EntityFactory::loadModuleEntityTypes), so there is nothing to declare in
     * config.json. Definitions and the reasoning behind them live in classes/EntityTypes.php.
     */
    public function redcap_entity_types(): array
    {
        return EntityTypes::all();
    }

    /**
     * Build the entity schema when the module is enabled system-wide.
     *
     * Forced rather than version-guarded: enabling is the one moment an admin is present, watching,
     * and able to act on a failure, so it is the right place to pay for a full verify-and-migrate.
     * It also recovers a table dropped by hand in the Entity DB Manager, which the version guard
     * cannot detect on its own.
     *
     * The throw is deliberate. REDCap surfaces it as an enable failure, which is the honest outcome:
     * a MICA without its scan queue would accept sessions and then have nowhere to put the
     * transcript, and a study would rather find that out here than after enrollment.
     */
    public function redcap_module_system_enable($version)
    {
        $this->entitySchemaManager()->ensureSchema(true);
    }

    /**
     * Idempotent, and cheap when there is nothing to do: ensureSchema() short-circuits on a
     * matching `schema-version` system setting without touching the database. Safe to call from a
     * cron entry point or before the first entity write of a request.
     */
    public function entitySchemaManager(): EntitySchemaManager
    {
        return new EntitySchemaManager(new RedcapEntityPlatform($this));
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
     * Asset tags for the review SPA.
     *
     * Its own method rather than a parameter on generateAssetFiles() because the two builds fail
     * differently and the callers want different things: the chat SPA's absence is a broken
     * participant session, while this one's is a staff page that must say "not built" rather than
     * render an empty dashboard. An empty dashboard reads as "no findings" to exactly the person
     * least able to tell the difference.
     *
     * Returns [] when the directory is absent, so pages/review.php can say so plainly.
     *
     * @return string[]
     */
    public function reviewAssetFiles(): array
    {
        $dir = 'mica-review/dist/assets';
        $path = $this->getModulePath() . $dir . '/';

        if (!is_dir($path)) {
            return [];
        }

        $files = scandir($path);
        if ($files === false) {
            $this->emError("Failed to open the review asset directory: $path");
            return [];
        }

        $assets = [];
        foreach (array_diff($files, ['.', '..']) as $file) {
            $url = $this->getUrl("$dir/$file");

            // str_ends_with, not str_contains: a Vite build emits `index-<hash>.js` alongside
            // `index-<hash>.js.map`, and a substring match would load the source map as a script.
            if (str_ends_with($file, '.js')) {
                // No `crossorigin`. Vite's template emits it and it buys nothing here - the bundle is
                // always same-origin with the module - while turning any host mismatch into a hard
                // CORS failure: REDCap builds asset URLs from its configured base URL, so reaching
                // the page by IP, by an alternate hostname, or through a proxy that rewrites Host
                // makes the browser refuse the script and the dashboard renders blank. Found by an
                // E2E run against 127.0.0.1 on an instance configured as redcap.local.
                $assets[] = "<script type='module' src='{$url}'></script>";
            } elseif (str_ends_with($file, '.css')) {
                $assets[] = "<link rel='stylesheet' href='{$url}'>";
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
        // This hook is not just cosmetic: ExternalModules/index.php calls it before including the
        // page file and exits when it returns null, so it gates page *access*, not only the sidebar
        // entry. The previous version returned $link unconditionally and never called the parent,
        // which removed the framework's design-rights default for BOTH links - so any user with
        // access to the project could open "Mica Session Admin". Combined with the missing rights
        // check in pages/sessionSelector.php, that let them close any participant's session.
        // See docs/phase-3-handoff/14-live-defects.md D4.
        // The review dashboard is gated by the user's REDCap ROLE, not by design rights.
        //
        // The framework's default is design rights, and applying it here meant only a project
        // DESIGNER could open the safety-review dashboard. That is the wrong control: a research
        // assistant reviewing findings is not a project designer, and the two ways out of it are
        // both bad - give every RA design rights over the study's data dictionary, or let only
        // designers review safety findings. Found by running the E2E as an ordinary reviewer with
        // design = 0, which is exactly the user the dashboard exists for.
        //
        // What replaces it is REDCap's own role management (see RoleService): the module configures
        // which REDCap role counts as a reviewer, and people are added by being put in that role.
        // So this is not a looser control than design rights, it is a different and more
        // appropriate one - governed and logged by the same mechanism as project access itself.
        //
        // Checked BEFORE the parent call, so the role grants access rather than merely surviving a
        // check it would fail. The page re-checks it and renders a specific refusal for anyone
        // without one, so this is not the only gate.
        if (array_key_exists('url', $link) && str_contains($link['url'], 'review')) {
            return RoleService::fromModule($this, (int) $project_id)
                ->hasAnyRole(\ExternalModules\ExternalModules::getUsername())
                ? $link
                : null;
        }

        // Everything else keeps the framework's default. In particular "Mica Session Admin" must
        // stay behind design rights - relaxing that is the D4 defect this method was fixed for.
        $link = parent::redcap_module_link_check_display($project_id, $link);
        if (empty($link)) {
            return null;
        }

        // Participants never use this sidebar entry - they reach the chatbot through the survey
        // page - but pages/chatbot.php is declared in no-auth-pages, so staff opening it from here
        // need NOAUTH on the URL for the page's own bootstrap to behave the same way.
        if (array_key_exists('url', $link) && str_contains($link['url'], 'chatbot')) {
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

            // `!== null` was not enough. A REDCap number field that has been saved BLANK comes back
            // as '', which is neither null nor numeric - so it fell through to the else branch below
            // and was forwarded verbatim, sending the provider `temperature: ""`. Dormant on a
            // project whose rows were never created, and armed by the first save of the config form.
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            // Cast before strpos(): a numeric setting can come back as an int, and strpos() on an
            // int is a TypeError in PHP 8 rather than the silent coercion it used to be.
            $params[$key] = is_numeric($value)
                ? (str_contains((string) $value, '.') ? (float) $value : (int) $value)
                : $value;
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
            $ctx = $this->getSystemContextForRecord($record, (string) $instrument);
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

        // The launch gates, before anything else can go wrong. A misconfigured production project
        // must not run a real session: an unregistered SafetyScan alias or mock mode left on means
        // the session happens and is never screened, and nothing about the participant's experience
        // would say so. Checked here rather than at finalization because refusing afterwards would
        // be refusing after the harm.
        $gateRefusal = null;
        $launchBanner = null;
        try {
            $gates = $this->launchReadinessFor($pid);

            if (!$gates->mayStartSession()) {
                $gateRefusal = $gates->participantRefusal();
                $this->emError('MICA refused to start a session: launch gates fail', [
                    'project_id'  => $pid,
                    'record'      => $record,
                    'explanation' => $gates->staffExplanation(),
                ]);
            }

            // A development project runs the session anyway - that is what development status is for
            // - but says so, so nobody mistakes an unready configuration for a ready one. The banner
            // is built by LaunchReadiness rather than assembled here: the chatbot has no unit-test
            // harness, so a derivation written inline would be untested on both sides.
            $launchBanner = $gates->developmentBanner();
        } catch (\Throwable $e) {
            // A broken gate check is not a licence to proceed. It is also not a reason to invent a
            // participant-facing error out of an internal fault, so the refusal text is the study's
            // approved fallback either way.
            $gateRefusal = 'This session cannot start right now. Please let the study team know.';
            $this->emError('MICA launch gate evaluation failed', [
                'project_id' => $pid,
                'error'      => $e->getMessage(),
            ]);
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
            // chat instead of the reason (docs 14 D7). A launch-gate refusal takes precedence over a
            // scheduling one: there is no point telling someone to come back in three days if the
            // project could not screen them when they did.
            'error'                  => $gateRefusal ?? $error ?? null,
            // Null on a production project and on a fully configured one. Titles and a count only -
            // this page is in no-auth-pages, so gate *details* stay on the dashboard.
            'launch_banner'          => $launchBanner,
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

            // The review dashboard is dispatched FIRST, before any participant is resolved.
            //
            // resolveParticipantId() throws when it cannot work out whose session a request is for,
            // which is correct for the chat and wrong here: a staff request has no participant, so
            // every review action failed with "Unable to determine which participant this request is
            // for" - a message about the wrong thing entirely.
            //
            // It also returns an ARRAY rather than a JSON string. The framework puts the hook's
            // return value straight into the response's `payload`, so encoding here would nest a
            // JSON string inside JSON and the client would read a string where it expected an
            // object. The participant actions below keep their existing json_encode() shape; the
            // chatbot depends on it.
            if (in_array($action, $this->getConfig()['auth-ajax-actions'] ?? [], true)) {
                return $this->handleReviewAction($action, $payload);
            }

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
                    // $instrument comes from the framework, not the payload - see
                    // resolveSessionHostInstrument() on why that distinction matters.
                    return json_encode($this->completeSession(
                        $data,
                        (string) $instrument,
                        (int) $event_id,
                        (int) $repeat_instance
                    ));

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
     * Dispatch one review-dashboard action.
     *
     * Requires a real logged-in user, explicitly, rather than relying on the outer guard. That guard
     * accepts EITHER a survey hash or a session user, because the participant chat runs on a survey
     * - so a review action arriving with a survey hash would satisfy it. RoleService would then
     * refuse it anyway (an empty username holds no roles), but "refused for the right reason" is
     * worth more than "refused as a side effect": the participant path and the staff path have
     * different authentication, and saying so here means a future change to one cannot quietly
     * weaken the other.
     *
     * @return array<string,mixed>
     */
    private function handleReviewAction(string $action, $payload): array
    {
        $username = \ExternalModules\ExternalModules::getUsername();

        if (empty($username)) {
            $this->emError("review action $action attempted with no authenticated user");
            http_response_code(403);

            return [
                'ok'     => false,
                'status' => 403,
                'error'  => 'The review dashboard requires a signed-in REDCap user.',
            ];
        }

        $response = $this->reviewEndpoints()->handle(
            $action,
            $username,
            is_array($payload) ? $payload : []
        );

        // The status is carried in the body as well as the header: REDCap's AJAX helper does not
        // surface a non-200 body to the caller reliably, and the SPA needs the 409's payload (the
        // current lock version) more than it needs the status line.
        if (isset($response['status']) && $response['status'] !== 200) {
            http_response_code((int) $response['status']);
        }

        return $response;
    }

    /** Constructed in one place, so every review action shares the same wiring. */
    public function reviewEndpoints(): ReviewEndpoints
    {
        $projectId = (int) PROJECT_ID;
        $roles = RoleService::fromModule($this);
        $audit = new AuditLogger(
            new RedcapAuditStore($this),
            $roles,
            (string) $projectId,
            fn(string $m) => $this->emError("audit: $m")
        );

        return new ReviewEndpoints(
            new RedcapReviewQueryStore($this),
            new DispositionService(new RedcapFindingReviewStore($this), $roles, $audit),
            $roles,
            $audit,
            (string) $projectId,
            $this->notificationServiceFor($projectId, $roles, $audit),
            new RedcapActionFieldWriter($this),
            $this->launchReadinessFor($projectId, $roles)
        );
    }

    /**
     * The notification stack for one project.
     *
     * Assembled here rather than inside NotificationService so that the class holding the RA-first
     * rule has no idea what REDCap is - which is what lets the rule be tested exhaustively without a
     * database, and why the suite can assert "every gated action is gated" as a data provider.
     */
    public function notificationServiceFor(
        int $projectId,
        ?RoleService $roles = null,
        ?AuditLogger $audit = null
    ): NotificationService {
        $roles ??= RoleService::fromModule($this, $projectId);
        $audit ??= new AuditLogger(
            new RedcapAuditStore($this),
            $roles,
            (string) $projectId,
            fn(string $m) => $this->emError("audit: $m")
        );

        $artifacts = new ArtifactRegistry();

        return new NotificationService(
            NotificationPolicy::fromJson(
                (string) $this->getProjectSetting('notification-policy-json', $projectId),
                $artifacts,
                new SchemaValidator($artifacts)
            ),
            new RedcapEmailChannel(
                (string) $this->getProjectSetting('notification-from-email', $projectId)
            ),
            new RedcapNotificationStore($this, new RedcapReviewQueryStore($this)),
            new RedcapRecipientDirectory($this, $roles, $projectId),
            new RedcapFindingReviewStore($this),
            $audit,
            (string) $projectId,
            $this->reviewDashboardUrl($projectId)
        );
    }

    /**
     * The review dashboard link that goes in every notification body.
     *
     * `getUrl()` derives the project from `PROJECT_ID`, which is **undefined in cron** - and the
     * digest, the acknowledgment monitor and the scan worker's "findings ready" notice all run there.
     * Left alone, every notice those send would carry a link with no project on it, which is the only
     * actionable thing in the body. The link is where a reviewer goes; a broken one makes the whole
     * message decorative.
     *
     * The pid is *overwritten* rather than appended, because a cron iterating projects must not
     * inherit whichever project a surrounding request happened to be in.
     */
    private function reviewDashboardUrl(int $projectId): string
    {
        $url = (string) $this->getUrl('pages/review.php', false, false);
        $url = rtrim((string) preg_replace('/([?&])pid=[^&]*/', '$1', $url), '?&');

        return $url . (str_contains($url, '?') ? '&' : '?') . 'pid=' . $projectId;
    }

    public function launchReadinessFor(int $projectId, ?RoleService $roles = null): LaunchReadiness
    {
        $roles ??= RoleService::fromModule($this, $projectId);
        $artifacts = new ArtifactRegistry();

        return new LaunchReadiness(new RedcapLaunchEnvironment(
            $this,
            $projectId,
            $artifacts,
            new SchemaValidator($artifacts),
            $roles,
            new RedcapRecipientDirectory($this, $roles, $projectId)
        ));
    }

    /**
     * Chase findings nobody has acknowledged inside the policy's window.
     *
     * Every 5 minutes, because the window can be as short as a minute and a monitor that runs less
     * often than the deadline it enforces cannot enforce it. Does nothing at all until the target is
     * set - which is a launch blocker, so the silence is visible rather than mistaken for quiet.
     */
    public function micaAckMonitorCron($cronInfo = []): string
    {
        return $this->notificationCronPass(
            'mica_ack_monitor',
            fn(NotificationService $n): array => $n->notifyOverdueAcknowledgments()
        );
    }

    /**
     * Send the digests whose window has closed.
     *
     * Runs hourly and works out for itself whether a window is due, rather than trusting the cron to
     * fire at the right hour. The idempotency key is `digest_id:since:until`, so every run inside the
     * same window resolves to the same key and only the first one sends - which means a digest is not
     * lost because the server was busy at 07:00.
     */
    public function micaDigestCron($cronInfo = []): string
    {
        $now = time();

        // Local midnight and this week's Monday, in REDCap's configured timezone. The policy's
        // per-digest `timezone` is not honoured yet; a study spanning timezones would need it, and
        // until then using the server's is at least consistent rather than arbitrary.
        //
        // `monday this week`, NOT `last monday`: PHP's "last X" excludes today, so on a Monday it
        // returns the Monday before - which made the weekly digest report a window a full week stale,
        // then send again on Tuesday for the correct one. Two emails, one of them wrong.
        $dailyUntil  = strtotime('today midnight', $now);
        $weeklyUntil = strtotime('monday this week 00:00', $now);

        return $this->notificationCronPass(
            'mica_digest',
            static function (NotificationService $n) use ($dailyUntil, $weeklyUntil): array {
                return array_merge(
                    $n->sendDigests('daily', $dailyUntil - 86400, $dailyUntil),
                    $n->sendDigests('weekly', $weeklyUntil - 604800, $weeklyUntil)
                );
            }
        );
    }

    /**
     * One notification cron pass over every project with the module enabled.
     *
     * Shared, and each project is in its own try/catch: one project with a broken policy or an
     * unreachable mail server must not stop the others being notified. A cron that dies on the first
     * bad project is a cron that silently only serves the alphabetically-first study.
     *
     * @param callable(NotificationService): list<NotificationResult> $pass
     */
    private function notificationCronPass(string $cronName, callable $pass): string
    {
        $summary = [];

        foreach ($this->getProjectsWithModuleEnabled() as $projectId) {
            try {
                $results = $pass($this->notificationServiceFor((int) $projectId));

                $sent = array_filter($results, static fn(NotificationResult $r): bool => $r->wasSent());
                $failed = array_filter(
                    $results,
                    static fn(NotificationResult $r): bool => $r->outcome === NotificationResult::FAILED
                );

                if ($sent !== [] || $failed !== []) {
                    $summary[] = sprintf(
                        'pid %d: %d sent, %d failed',
                        $projectId,
                        count($sent),
                        count($failed)
                    );
                }

                foreach ($failed as $failure) {
                    $this->emError("$cronName could not deliver", [
                        'project_id' => $projectId,
                        'type'       => $failure->notificationType,
                        'reason'     => $failure->reason,
                    ]);
                }
            } catch (\Throwable $e) {
                $summary[] = "pid $projectId: FAILED - " . $e->getMessage();
                $this->emError("$cronName failed for one project", [
                    'project_id' => $projectId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $summary === [] ? 'nothing to do' : implode('; ', $summary);
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

    public function getSystemContextForRecord($recordId, ?string $hostInstrument = null): ?array {
        /**
         * The pilot's session resolution gates the SYSTEM PROMPT, which is not obvious from here.
         *
         * `calculateSessionInfo()` returns null without a `baseline_arm_1` event, and this method used
         * to return null with it - before reading a single one of the
         * `chatbot_system_context_*` settings. `redcap_survey_page()` then normalises null to `[]`,
         * the SPA seeds an empty context, and the model is called with **no system prompt at all**.
         *
         * The visible symptom is the chatbot introducing itself as Claude while
         * `chatbot_system_context_general` sits configured and ignored. The client already warns
         * "no initial system context was provided for this session" in the console; nothing on the
         * server said anything.
         *
         * So on a project without the pilot scaffolding, build the context anyway. Everything
         * `initSystemContexts()` needs is a record, a session key and a look-back count - none of it
         * pilot-specific. What is lost is only the pilot's gating (des_mica, month3_fu_complete,
         * session_info_complete), and those fields do not exist on such a project to gate on.
         */
        $pilot = $this->hasPilotSessionScaffolding();
        $calc = $pilot ? $this->calculateSessionInfo($recordId) : null;

        if (!$pilot) {
            return $this->systemContextWithoutPilotScaffolding($recordId, $hostInstrument);
        }

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

    
    /**
     * The system context for a project that was never a pilot project.
     *
     * The session key comes from the chat host via SessionHostMap - the same mapping that decides
     * baseline-vs-booster for a SafetyScan - rather than from the pilot's day-count arithmetic.
     *
     * Deliberately NOT a `session_N` key. `initSystemContexts()` treats those as the pilot's 14-day
     * cadence and appends a catch-up summary built from `baseline_arm_1` / `session_N_arm_1` events,
     * which do not exist here: asking for one would fail while trying to add context. `baseline` and
     * `booster` both fall outside that regex, so the general context and the session context are all
     * that is assembled.
     */
    private function systemContextWithoutPilotScaffolding($recordId, ?string $hostInstrument): array
    {
        $sessionKey = 'baseline';

        try {
            if ($hostInstrument !== null && $hostInstrument !== '') {
                $hosts = SessionHostMap::fromSetting($this->getProjectSetting('session-host-map'));
                $sessionKey = $hosts->resolve($hostInstrument)['session_type'];
            }
        } catch (\Throwable $e) {
            // An unmappable host is not a reason to send the model no persona at all. The general
            // context is the part that matters and it does not depend on the session type.
            $this->emDebug(
                'getSystemContextForRecord: host instrument did not map to a session type, so the '
                . 'baseline session context is used. The general context is unaffected.',
                ['instrument' => $hostInstrument, 'error' => $e->getMessage()]
            );
        }

        $backN = $this->getProjectSetting('number_session_callback') ?? 1;

        return [
            'system_context'     => $this->initSystemContexts($recordId, $sessionKey, $backN),
            'currentSession'     => $sessionKey,
            'session_start_time' => time(),
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
    /**
     * Does this project have the pilot's session scaffolding at all?
     *
     * Both halves are required, and each was independently fatal on PID 257:
     *
     *   1. an event whose unique name is literally `baseline_arm_1` - `calculateSessionInfo()` does
     *      `array_search('baseline_arm_1', REDCap::getEventNames(true, false))` and returns null
     *      without it;
     *   2. the two real fields the save below writes.
     *
     * Two things deliberately NOT checked. `consent_date`, because an absent or blank one does not
     * fail: `new DateTime('')` returns *now* rather than throwing, so the pilot treats it as a
     * day-zero session. And `session_info_complete`, because REDCap generates `<form>_complete`
     * fields rather than storing them in `redcap_metadata` - requiring it would have returned false
     * on the pilot project itself and skipped a save that works there, which is the one regression
     * this method exists to avoid.
     */
    private function hasPilotSessionScaffolding(): bool
    {
        if (!array_search('baseline_arm_1', \REDCap::getEventNames(true, false), true)) {
            return false;
        }

        $fields = $this->getProjectFieldNames();

        foreach (['raw_chat_logs', 'session_timestamp'] as $required) {
            if (!in_array($required, $fields, true)) {
                return false;
            }
        }

        return true;
    }

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
    public function completeSession(
        $payload,
        ?string $hostInstrument = null,
        ?int $hostEventId = null,
        ?int $hostInstance = null
    ) {
        ['participant_id' => $participant_id] = $payload;

        if (empty($participant_id)) {
            throw new \Exception("Error with completing session: No participant ID provided");
        }

        // calculateSessionInfo() returns null whenever it cannot resolve the pilot session events
        // or the participant's consent_date. This used to fall straight through to
        // ->getTimestamp() on null - a fatal that fired *before* saveData(), so the session was
        // never finalized and the participant was signed out with no explanation (docs 14 D16).
        /**
         * A project without the pilot's scaffolding skips the pilot save rather than failing.
         *
         * The block below writes `raw_chat_logs` to a `baseline_arm_1` event. On an R01 project none
         * of that exists - PID 257's arm-1 events are `day_1_ed_arm_1`, `month_3_arm_1`, and the three
         * pilot fields are absent from the dictionary - so it used to throw here and take the
         * transcript finalization at the bottom of this method down with it. The result was a project
         * where a participant could talk to MICA and **no session could ever be screened**, reported
         * as "the project is not configured for MICA sessions".
         *
         * Two different failures were being treated as one. A pilot project whose session cannot be
         * resolved is a real fault and still throws - that is docs 14 D16, and swallowing it would
         * sign the participant out with no explanation again. A project that was never a pilot project
         * has nothing to resolve, and the honest thing is to skip a save that does not apply and get
         * on with the part that does.
         *
         * Stage 2 deletes the pilot half outright. This is what makes the scan pipeline usable before
         * then.
         */
        $pilotScaffolding = $this->hasPilotSessionScaffolding();
        $calc = $pilotScaffolding ? $this->calculateSessionInfo($participant_id) : null;
        $pilotResolved = is_array($calc)
            && !empty($calc['sessionStart'])
            && $calc['sessionStart'] instanceof \DateTime;

        if ($pilotScaffolding && !$pilotResolved) {
            $this->emError('completeSession: could not resolve session info', [
                'participant_id' => $participant_id,
                'project_id'     => PROJECT_ID,
            ]);
            throw new \Exception(
                'This session could not be finalized because the project is not configured for MICA '
                . 'sessions. Your messages have been recorded - please contact the study team.'
            );
        }

        if (!$pilotScaffolding) {
            $this->emDebug(
                'completeSession: no pilot session scaffolding on this project, so the raw_chat_logs '
                . 'save is skipped. The transcript is still finalized and the safety scan still '
                . 'queued.',
                ['participant_id' => $participant_id, 'project_id' => PROJECT_ID]
            );

            $surveys = ['success' => true];
            $override = trim((string) $this->getProjectSetting('chatbot_end_session_url_override'));

            // Without a link the SPA signs the participant out with no message, which is the shape of
            // docs 14 D16. An R01 project has no `posttest` survey to send them to, so the override is
            // the only honest source of one.
            if ($override !== '') {
                $surveys['survey_link'] = $override;
            }

            return array_merge($surveys, $this->finalizeSessionTranscript(
            $participant_id,
            $payload,
            $hostInstrument,
            $hostEventId,
            $hostInstance
        ));
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

        // Finalize the transcript and queue the safety scan. Additive rather than a rewrite of the
        // pilot path above: Stage 3.4 calls for replacing this method's session resolution with the
        // R01 engine, but that engine is Stage 2 and its fields do not exist on PID 257 yet (audit
        // G4). Doing it this way makes the post-session scan pipeline work today without breaking
        // the pilot flow that still runs from this branch. Stage 2 deletes the half above.
        $surveys = array_merge($surveys, $this->finalizeSessionTranscript(
            $participant_id,
            $payload,
            $hostInstrument,
            $hostEventId,
            $hostInstance
        ));

        return $surveys;
    }

    /**
     * Snapshot the finished conversation and queue its safety scan.
     *
     * Deliberately cannot fail the session. The participant has already finished talking and their
     * messages are already stored; refusing to close the session because a scan could not be queued
     * would strand them on a screen they cannot leave, and would not make the scan happen. So this
     * reports the problem to staff and returns - the failure is visible in the module log and, once
     * Stage 5 exists, in the RA dashboard's queue counts.
     *
     * The one thing it must never do is report success it did not achieve, because "no scan queued"
     * that looks like "scan queued" is a participant whose disclosure is never read.
     *
     * @return array<string,mixed> keys merged into the completeSession response
     */
    private function finalizeSessionTranscript(
        $participant_id,
        $payload,
        ?string $hostInstrument = null,
        ?int $hostEventId = null,
        ?int $hostInstance = null
    ): array {
        if (!$this->getProjectSetting('enable-transcript-finalization')) {
            return [];
        }

        try {
            $instrument = $this->resolveSessionHostInstrument($payload, $hostInstrument);
            $hosts = SessionHostMap::fromSetting($this->getProjectSetting('session-host-map'));
            $resolved = $hosts->resolve($instrument);

            $result = $this->transcriptFinalizer()->finalize(
                (string) PROJECT_ID,
                (string) $participant_id,
                (string) $participant_id,
                // Both from the framework, falling back to the payload only if it did not supply
                // them. The SPA never sends `event_name`, so this used to resolve to event 0 - and a
                // longitudinal project refuses a save with an empty `redcap_event_name`, so a scan
                // that had already produced verified findings failed at the last step with "The
                // record event name is missing". The framework knew the event all along.
                $hostInstance ?: (int) ($payload['repeat_instance'] ?? 1),
                $hostEventId
                    ?: (int) (\REDCap::getEventIdFromUniqueEvent($payload['event_name'] ?? '') ?: 0),
                $resolved['session_type'],
                $resolved['setting']
            );

            foreach ($result->warnings as $warning) {
                $this->emError('completeSession: transcript finalized with a warning', $warning);
            }

            return ['transcript' => $result->toArray()];
        } catch (\Throwable $e) {
            // Never rethrown - see the docblock. Logged as an error, not a debug line: a session
            // whose transcript was not queued for scanning is a safety-relevant gap, and the study
            // team needs to find it without turning debug logging on first.
            $this->emError('completeSession: transcript finalization FAILED - no scan is queued', [
                'participant_id' => $participant_id,
                'project_id'     => PROJECT_ID,
                'error'          => $e->getMessage(),
            ]);

            // Also to the EM log, because emError routes through emLogger and emLogger is an
            // optional module that is disabled by default - so on a stock install the one message
            // explaining why a session was never screened went nowhere at all.
            try {
                $this->log('mica_finalize_failed', [
                    'record'     => (string) $participant_id,
                    'project_id' => (int) PROJECT_ID,
                    'reason'     => substr($e->getMessage(), 0, 1000),
                ]);
            } catch (\Throwable $ignored) {
                // A logging failure must not become the participant's problem.
            }

            return ['transcript' => [
                'queued' => false,
                'error'  => 'This session was recorded but its safety review could not be queued. '
                          . 'The study team has been notified.',
            ]];
        }
    }

    /**
     * Which instrument hosted this chat, which is what decides baseline-vs-booster and the clinical
     * setting (see classes/SessionHostMap.php).
     *
     * Taken from the request rather than the payload where possible: the payload is client-supplied
     * on a no-auth action, and letting a caller name its own instrument would let them label an ED
     * baseline as a remote booster - which changes how a SafetyScan finding reads clinically.
     */
    private function resolveSessionHostInstrument($payload, ?string $hostInstrument = null): string
    {
        // The framework tells the module which instrument the request came from, and it is as
        // server-derived as $_GET['page'] - it just was not being passed in. Without it, a project
        // with TWO configured chat hosts (which the R01 has: an ED session and a booster) could
        // never finalize a real participant session at all: the inference below only works for a
        // single host, so every End Session threw and no session was ever screened.
        //
        // Validated against the configured hosts rather than trusted outright. That costs nothing
        // and means a future caller passing something from a payload cannot widen this.
        if ($hostInstrument !== null && $hostInstrument !== '' && $this->isChatHostInstrument($hostInstrument)) {
            return $hostInstrument;
        }

        // REDCap sets this on a survey request; it is server-derived and not spoofable.
        if (isset($_GET['page']) && is_string($_GET['page']) && $_GET['page'] !== '') {
            return (string) $_GET['page'];
        }

        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->getProjectSetting('chat_host_instruments'))
        )));

        // A single configured host is unambiguous, so an admin-initiated finalize does not need to
        // guess. More than one and there is nothing to infer from.
        if (count($configured) === 1) {
            return $configured[0];
        }

        throw new TranscriptException(sprintf(
            'The chat host instrument could not be determined, so the session type and clinical '
            . 'setting are unknown and nothing was finalized. Tried: framework instrument "%s", '
            . '$_GET[page] "%s", and the %d configured host(s) [%s] - which can only be inferred '
            . 'from when there is exactly one.',
            (string) $hostInstrument,
            isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '',
            count($configured),
            implode(', ', $configured)
        ));
    }

    /** Wired here so the finalizer's dependencies are constructed in exactly one place. */
    public function transcriptFinalizer(): TranscriptFinalizer
    {
        $this->entitySchemaManager()->assertSchemaCurrent();

        $registry = new ArtifactRegistry();
        $states = new ScanJobStateMachine(
            (int) ($this->getProjectSetting('safetyscan-max-attempts') ?: ScanJobStateMachine::DEFAULT_MAX_ATTEMPTS)
        );

        return new TranscriptFinalizer(
            new RedcapTranscriptStore($this),
            new TranscriptBuilder(),
            new SchemaValidator($registry),
            new ScanQueue(
                new RedcapScanQueueStore($this),
                $states,
                null,
                fn(string $m) => $this->emDebug("scan queue: $m")
            ),
            $this->sessionPseudoIdSalt(),
            null,
            fn(string $m) => $this->emDebug("finalizer: $m")
        );
    }

    /**
     * The pseudo-id salt, generated once on first use and never rotated.
     *
     * Lazily created rather than required as a setup step, because a study whose first session is
     * refused for a missing salt is worse than one that generates it - but it is generated *once*,
     * with a hard refusal to overwrite: rotating it re-pseudonymises every id already issued and
     * severs the link between existing findings and the sessions they came from.
     */
    private function sessionPseudoIdSalt(): string
    {
        $salt = (string) $this->getSystemSetting(SessionPseudoId::SALT_SETTING);

        if ($salt !== '') {
            return $salt;
        }

        $salt = SessionPseudoId::generateSalt();
        $this->setSystemSetting(SessionPseudoId::SALT_SETTING, $salt);
        $this->emDebug('generated the session pseudo-id salt (one time only; never rotated)');

        return $salt;
    }

    /**
     * Cron entry point: run one scan-worker pass per project that has MICA enabled.
     *
     * Each project is isolated in its own try/catch. One project with a broken configuration must
     * not stop every other project's scans - which is exactly what an uncaught throw here would do,
     * silently, since nobody reads a cron that appears to have run.
     */
    public function micaScanWorkerCron($cronInfo = []): string
    {
        $summary = [];

        foreach ($this->getProjectsWithModuleEnabled() as $projectId) {
            try {
                $result = $this->scanWorkerFor((int) $projectId)->runPass();

                if ($result['claimed'] > 0 || $result['reaped'] > 0) {
                    $summary[] = sprintf(
                        'pid %d: %d claimed, %d reaped, %s',
                        $projectId,
                        $result['claimed'],
                        $result['reaped'],
                        json_encode($result['outcomes'])
                    );
                }
            } catch (\Throwable $e) {
                $summary[] = "pid $projectId: FAILED - " . $e->getMessage();
                $this->emError('mica_scan_worker cron failed for one project', [
                    'project_id' => $projectId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $summary === [] ? 'nothing to do' : implode('; ', $summary);
    }

    private function scanWorkerFor(int $projectId): ScanWorker
    {
        $this->entitySchemaManager()->ensureSchema();

        $states = new ScanJobStateMachine(
            (int) ($this->getProjectSetting('safetyscan-max-attempts', $projectId)
                ?: ScanJobStateMachine::DEFAULT_MAX_ATTEMPTS)
        );

        $queue = new ScanQueue(
            new RedcapScanQueueStore($this),
            $states,
            null,
            fn(string $m) => $this->emDebug("scan queue (pid $projectId): $m")
        );

        $runner = $this->scanRunnerFor($projectId);
        $results = new RedcapScanResultStore($this);
        $findings = new FindingWriter($results);

        return new ScanWorker(
            $queue,
            function (array $job) use ($runner, $findings, $states, $projectId): array {
                $outcome = $runner->run($job);

                // A job about to be given up on gets a visible review task, not silence. Written
                // here rather than in ScanRunner because only the queue's state machine knows
                // whether this attempt was the last one - the runner sees one attempt at a time.
                $attempts = ((int) ($job['attempts'] ?? 0)) + 1;
                // Same call ScanQueue::finishAttempt() will make, terminal flag included, so the
                // placeholder, the notification and the persisted job status cannot disagree about
                // what happened to this attempt.
                $next = $states->afterAttempt(
                    (string) $job['status'],
                    $outcome->runStatus,
                    $attempts,
                    $outcome->terminal
                );

                if ($next['status'] === ScanJobStateMachine::MANUAL_REVIEW_REQUIRED) {
                    $this->writeScanFailurePlaceholder($findings, $job, $outcome, $attempts, $projectId);
                }

                $this->notifyReviewersIfSettled($job, $outcome, $next['status'], $projectId);

                return [
                    'runStatus' => $outcome->runStatus,
                    'error'     => $outcome->error,
                    'terminal'  => $outcome->terminal,
                ];
            },
            null,
            fn(string $m) => $this->emDebug("scan worker (pid $projectId): $m")
        );
    }

    /**
     * Tell the reviewers, once a job has stopped moving.
     *
     * Only for the two states that mean a human is now needed. A job that failed transiently and is
     * going to retry in four minutes is not something to email anybody about - and notifying on every
     * attempt is how a reviewer learns to filter the alerts, which is the failure that costs the most
     * later.
     *
     * Its own try/catch for the same reason as the placeholder write: the job's state transition has
     * already been decided, and losing it to a mail server being down would be strictly worse than an
     * un-notified finding sitting in a queue somebody will still see.
     */
    private function notifyReviewersIfSettled(
        array $job,
        ScanOutcome $outcome,
        string $nextStatus,
        int $projectId
    ): void {
        $manual = $nextStatus === ScanJobStateMachine::MANUAL_REVIEW_REQUIRED;

        if (!$manual && $nextStatus !== ScanJobStateMachine::READY_FOR_REVIEW) {
            return;
        }

        // A clean scan with no findings is not worth an email: there is nothing to review, and the
        // session still appears in the history view. A failed one always is - "not screened" is not
        // the same as "nothing found", and that distinction is the whole point of the manual path.
        if (!$manual && $outcome->findingsWritten === 0) {
            return;
        }

        try {
            $result = $this->notificationServiceFor($projectId)->notifyReviewersReady(
                (int) ($job['id'] ?? 0),
                (string) ($job['record'] ?? ''),
                (int) ($job['event_id'] ?? 0),
                (int) ($job['instance'] ?? 1),
                (string) ($job['session_type'] ?? ''),
                $outcome->findingsWritten,
                (string) ($outcome->overallUrgency ?? 'none'),
                $manual
            );

            if ($result->outcome === NotificationResult::FAILED) {
                $this->emError('SafetyScan findings are ready but the reviewers could not be told', [
                    'project_id' => $projectId,
                    'job_id'     => $job['id'] ?? null,
                    'reason'     => $result->reason,
                ]);
            }
        } catch (\Throwable $e) {
            $this->emError('Notifying reviewers failed; the finding is still in the queue', [
                'project_id' => $projectId,
                'job_id'     => $job['id'] ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * The `scan_failure` placeholder instance for a session the scanner gave up on.
     *
     * Its own method, and its own try/catch, because it must not be able to turn a
     * `manual_review_required` into an exception: the job is already going to a human, and losing
     * that transition to a failed placeholder write would be strictly worse than a missing
     * placeholder. Both outcomes are logged as errors.
     */
    private function writeScanFailurePlaceholder(
        FindingWriter $findings,
        array $job,
        ScanOutcome $outcome,
        int $attempts,
        int $projectId
    ): void {
        try {
            $findings->writeScanFailure(
                (string) $job['project_id'],
                (string) $job['record'],
                (int) ($job['event_id'] ?? 0),
                $outcome->scanRunId,
                $outcome->runStatus,
                $attempts,
                $outcome->error
            );

            $this->emError('SafetyScan gave up on a session; a manual-review task was created', [
                'project_id' => $projectId,
                'job_id'     => $job['id'] ?? null,
                'run_status' => $outcome->runStatus,
                'attempts'   => $attempts,
            ]);
        } catch (\Throwable $e) {
            // Most likely cause on PID 257 today: the mica_safety_finding instrument does not exist
            // (audit G5). The job still lands in manual_review_required, so the session is not lost
            // - but nobody will see it in a REDCap queue until the instrument is built.
            $this->emError(
                'SafetyScan gave up on a session AND its manual-review task could not be created. '
                . 'The job is in manual_review_required but will not appear in the review '
                . 'instrument. Reason: ' . $e->getMessage(),
                [
                    'project_id' => $projectId,
                    'job_id'     => $job['id'] ?? null,
                    'run_status' => $outcome->runStatus,
                ]
            );
        }
    }

    /**
     * Wires the SafetyScan runner. `scan-mock-mode` swaps only the caller, so a mock-mode scan goes
     * through exactly the same validation and quote verification as a real one.
     */
    private function scanRunnerFor(int $projectId): ScanRunner
    {
        $registry = new ArtifactRegistry();
        $mock = (bool) $this->getProjectSetting('scan-mock-mode', $projectId);

        $caller = $mock
            ? new FixtureSafetyScanCaller(
                null,
                'no_supported_concern',
                fn(string $m) => $this->emDebug("scan runner (pid $projectId): $m")
            )
            : new SecureChatSafetyScanCaller($this, $projectId);

        if ($mock) {
            $this->emDebug("scan-mock-mode is ON for pid $projectId - no model will be called");
        }

        return new ScanRunner(
            $registry,
            new SchemaValidator($registry),
            new RedcapTranscriptStore($this),
            $caller,
            new RedcapScanResultStore($this),
            new FindingWriter(new RedcapScanResultStore($this)),
            (string) ($this->getProjectSetting('safetyscan-model-alias', $projectId) ?: 'gemini-2.5-flash'),
            $this->appVersion(),
            null,
            fn(string $m) => $this->emDebug("scan runner (pid $projectId): $m"),
            // The project's post-scan filter. Unconfigured means it admits everything, so this is
            // inert until a PI narrows the queue - and even then it only decides what becomes a
            // review item: the model's full answer is still stored on the run row.
            FindingThresholds::fromModule($this, $projectId)
        );
    }

    /**
     * Module version plus the deployed git short SHA, recorded on every scan run and counselor turn.
     *
     * The handoff requires knowing which build produced a given result; a version alone cannot tell
     * two deployments of `9.9.9` apart. The SHA is read from a `VERSION` file if the build writes
     * one and is otherwise omitted rather than guessed.
     */
    public function appVersion(): string
    {
        $version = (string) ($this->getConfig()['version'] ?? $this->VERSION ?? 'unknown');
        $shaFile = $this->getModulePath() . 'VERSION';

        if (is_file($shaFile)) {
            $sha = trim((string) file_get_contents($shaFile));
            if ($sha !== '') {
                return $version . '+' . substr($sha, 0, 12);
            }
        }

        return $version;
    }

    /**
     * Whether the *current* user may administer MICA sessions.
     *
     * The decision itself lives in UserRightsCheck (unit-tested); this method only supplies the
     * REDCap facts. It used to read `current(getPrivileges(PROJECT_ID)[PROJECT_ID])`, which returns
     * the alphabetically-first user in the project rather than the caller - see UserRightsCheck's
     * docblock and docs/phase-3-handoff/14-live-defects.md D4.
     *
     * @return bool
     */
    public function validatePermissions(): bool {
        $username = \ExternalModules\ExternalModules::getUsername();

        return UserRightsCheck::hasSessionAdminRights(
            UserRights::getPrivileges(PROJECT_ID, $username)[PROJECT_ID] ?? [],
            $username,
            \ExternalModules\ExternalModules::isSuperUser()
        );
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
