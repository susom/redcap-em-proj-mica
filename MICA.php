<?php
namespace Stanford\MICA;

require_once "emLoggerTrait.php";
require_once "classes/Sanitizer.php";
require_once "classes/MICAQuery.php";
require_once "vendor/autoload.php";
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
                    $sanitizedPayload[] = array(
                        'role' => $data['role'],
                        'content' => $data['content'],
                        'user_id' => $data['user_id']
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
            "presence_penalty" => "presence_penalty",
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

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        try {
            switch ($action) {
                case "callAI":
                    $messages = $this->handleUserInput($payload);

                    // Add most recent message to database
                    $recent_query = $messages[sizeof($messages) - 1];
                    $this->logMICAQuery(json_encode($recent_query), $recent_query['user_id']);

                    // Add user baseline AFTER logging to leave it out of the logs
                    $participant_id = current($payload)["user_id"];
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

                    $response = $this->getSecureChatInstance()->callAI($model, $params, PROJECT_ID );
                    $result = $this->formatResponse($response);

                    if(isset($recent_query['user_id'])) {
                        $result['user_id'] = $recent_query['user_id'];
                        unset($recent_query['user_id']);
                        $result['query'] = $recent_query;
                    }

                    // Add response to database
                    if($result)
                        $this->logMICAQuery(json_encode($result), $result['user_id']);

                    return json_encode($result);
                case "login":
                    $data = $this->sanitizeInput($payload);
                    return json_encode($this->loginUser($data));

                case "verifyEmail":
                    $data = $this->sanitizeInput($payload);
                    $verify_phone_data = $this->verifyEmail($data);

                    $return_payload = $verify_phone_data;
                    $return_payload["current_session"] = [];
                    return json_encode($return_payload);

                case "fetchSavedQueries":
                    $data = $this->sanitizeInput($payload);
                    $return_payload = [];
                    $return_payload["current_session"] = [];
                    $existing_chat = $this->fetchSavedQueries($data, $data['session_start_time'] ?? null);
                    if(!empty($existing_chat)){
                        $return_payload["current_session"] = $existing_chat;
                    }
                    return json_encode($return_payload);

                case "completeSession":
                    // expecting {participant_id : participant_id}
                    $data = $this->sanitizeInput($payload);
                    return json_encode($this->completeSession($data));
                    break;

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
        ['name' => $name, 'participant_id' => $participant_id] = $payload;

        // Correct the typo in the if statement
        if (empty($participant_id) || empty($name)) {
            throw new \Exception("Error with fetching queries: Participant ID / name combination not provided");
        }

        $primary_field = $this->getPrimaryField();
        $params = array(
            "return_format" => "json",
            "filterLogic" => "[$primary_field] = '$participant_id'",
            "fields" => array($primary_field, "participant_name"),
        );
        // Find user and determine validity
        $json = json_decode(\REDCap::getData($params), true);
        $check = reset($json);

        // Check across participant name and id
        if($check['record_id'] === $participant_id && $check['participant_name'] === $name) {
            return MICAQuery::getLogsFor($this, PROJECT_ID, $participant_id, $sessionStart);
        }
        return [];
    }

    public function getPrimaryField(){
        //TODO CLEAN THIS UP
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

        ['name' => $name, 'email' => $email] = $payload;

        // Fetch user information
        $params = array(
            "return_format" => "json",
            "filterLogic" => "[participant_name] = '$name' AND [participant_email] = '$email'",
            "fields" => array($primary_field, "participant_name", "participant_email", "participant_phone", "user_complete", "completion_timestamp"),
        );

        // Find user and determine validity
        $json = json_decode(\REDCap::getData($params), true);
        if(count($json) > 1)
            throw new \Exception("Error logging in user, duplicate entries for $name, $email");

        $check = reset($json);

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

        if (empty($response['errors'])) {
            $body = "<html><p>Your MICA Verification code is: <strong>$code</strong></p></html>";
            $res = \REDCap::email($email, 'redcap@stanford.edu', 'Your MICA verification code', $body);
            if(!$res){
                $this->emError('Email hook failure');
                throw new \Exception('Verification email could not be sent, please contact your administrator');
            }

        } else {
            $this->emError('Save data failure, ', json_encode($response['errors']));
            throw new \Exception('Save data failure in generating one time password');
        }
    }

    /**
     * @param $payload
     * @return false[]|true[]
     * @throws \Exception
     */
    private function verifyEmail($payload) {
        $primary_field = $this->getPrimaryField();

        if(empty($payload['code']))
            throw new \Exception("Error verifying email, no code provided");

        ['code' => $code] = $payload;

        // Fetch user information
        $params = array(
            "return_format" => "json",
            "fields" => array($primary_field, "two_factor_code", "participant_name"),
            "filterLogic" => "[two_factor_code] = '$code'"
        );

        // Find user and determine validity
        $json = json_decode(\REDCap::getData($params), true);
        if(count($json) > 1)
            throw new \Exception("Error logging in user, duplicate entries for $code ");

        $check = reset($json);
        if ($check['two_factor_code'] === $code) {
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
            $days = $consentDate->diff($today)->days;
        } catch (\Exception $e) {
            $this->emError("DateTime crash", [
                'message' => $e->getMessage(),
                'raw_value' => $consentDateStr,
                'record' => $recordId,
                'event' => $baselineEventId
            ]);
            return null;
        }
        $session_length_days = 14;
        $sessionNum = (int) min(7, floor($days / $session_length_days));
        
        $sessionStart = clone $consentDate;
        $sessionStart->modify("+".($sessionNum * $session_length_days)." days");

        $contextKey = $sessionNum === 0
            ? 'baseline'
            : "session_{$sessionNum}";
    
        $currentSession = $sessionNum === 0 ? 'baseline' : $sessionNum;
        $sys_ctx = $this->initSystemContexts($recordId, $contextKey, 3);

        // check is session already complete
        $eventName = ($currentSession === 'baseline')
            ? 'baseline_arm_1'
            : "session_{$currentSession}_arm_1";

        $completionData = \REDCap::getData([
            'project_id' => $this->getProjectId(),
            'records' => [$recordId],
            'fields' => ['session_info_complete'],
            'events' => [$eventName],
            'return_format' => 'array'
        ]);
        
        $eventId = array_search($eventName, $events); // gives you the key '100' in your debug
        $sessionComplete = $completionData[$recordId][$eventId]['session_info_complete'] ?? null;
            
        if ((int)$sessionComplete === 2) {
            $nextSessionStart = clone $sessionStart;
            $nextSessionStart->modify("+$session_length_days days");
            $today = new \DateTime();
            $daysUntilNext = max(0, $today->diff($nextSessionStart)->days) + 1;
            throw new \Exception("Session already completed. Return in $daysUntilNext day(s) for your next session!");
        }

        return [
            'system_context' => $sys_ctx,
            'currentSession' => $currentSession,
            'session_start_time' => $sessionStart->getTimestamp() 
        ];
    }
    
    public function initSystemContexts($record_id, $session_key = 'baseline', $backN= 1) {

        $setting_key = "chatbot_system_context_" . $session_key;
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
            if ($i === 1) continue; // skip session_1_arm_1 (doesn't exist)
        
            $event = $i === 0 ? 'baseline_arm_1' : "session_{$i}_arm_1";
        
            $eventId = \REDCap::getEventIdFromUniqueEvent($event);
            $data = \REDCap::getData([
                'project_id' => $this->getProjectId(),
                'records' => [$recordId],
                'fields' => ['raw_chat_logs'],
                'events' => [$event],
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
     * @param $payload
     * @return true[]|void
     * @throws \Exception
     */
    public function completeSession($payload) {
        ['participant_id' => $participant_id, 
        'session' => $session,
        'session_start_time' => $sessionStart] = $payload;

        if (empty($participant_id)) {
            throw new \Exception("Error with completing session: No participant ID provided");
        }
    
        $primary_field = $this->getPrimaryField();
        $logs = MICAQuery::getLogsFor($this, PROJECT_ID, $participant_id, $sessionStart);
    
        // Target uniform field names
        $logField = 'raw_chat_logs';
        $timestampField = 'session_timestamp';
        $completeField = 'session_info_complete';
    
        // Determine target event
        $eventName = ($session === 'baseline' || empty($session))
            ? 'baseline_arm_1'
            : "session_{$session}_arm_1";
    
        // Prepare data to save
        $save = [
            $primary_field => $participant_id,
            'redcap_event_name' => $eventName,
            $logField => is_string($logs) ? $logs : json_encode($logs),
            $timestampField => date("Y-m-d H:i:s"),
            $completeField => '2'
        ];
    
        
        // Use targeted event for saving
        $response = \REDCap::saveData([
            'dataFormat' => 'json',
            'data' => json_encode([ $save ]),
            'overwriteBehavior' => 'overwrite',
            'returnFormat' => 'json'
        ]);
    
        if (!$response['errors']) {
            $override = $this->getProjectSetting('chatbot_end_session_url_override');
            $event_id =  \REDCap::getEventIdFromUniqueEvent($eventName);

            $survey_link = $override ?: \REDCap::getSurveyLink($participant_id, "posttest", $event_id);
    
            $surveys = [
                "success" => true,
                "survey_link" => $survey_link
            ];
    
            if ($session == 7) {
                $surveys["follow_up_link"] = $this->getProjectSetting('chatbot_end_session_url_override')
                    ?: \REDCap::getSurveyLink($participant_id, "month3_fu", "follow_up_arm_1");
            }
    
            $this->emDebug("ok saved the chat session to session_info, now here is the survey links", $surveys);
            return $surveys;
        } else {
            throw new \Exception($response['errors']);
        }
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
