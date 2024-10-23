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
    public $system_context_persona;
    public $system_context_steps;
    public $system_context_rules;

    private $primary_field;

    public function __construct() {
        parent::__construct();
    }

    public function initSystemContexts(){
        $this->system_context_persona = $this->getProjectSetting('chatbot_system_context_persona');
        $this->system_context_steps = $this->getProjectSetting('chatbot_system_context_steps');
        $this->system_context_rules = $this->getProjectSetting('chatbot_system_context_rules');

        $initial_system_context = $this->appendSystemContext([], $this->system_context_persona);
        $initial_system_context = $this->appendSystemContext($initial_system_context, $this->system_context_steps);
        $initial_system_context = $this->appendSystemContext($initial_system_context, $this->system_context_rules);
        return $initial_system_context;
    }

    public function getIntroText(){
        return $this->getProjectSetting('chatbot_intro_text');
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
        $content = $this->getSecureChatInstance()->extractResponseText($response);
        $role = $response['choices'][0]['message']['role'] ?? 'assistant';
        $id = $response['id'] ?? null;
        $model = $response['model'] ?? null;
        $usage = $response['usage'] ?? null;

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
        $hasSystemContext = false;
        for ($i = 0; $i < count($chatMlArray); $i++) {
            if ($chatMlArray[$i]['role'] == 'system' && !empty($chatMlArray[$i]['content'])) {
                $chatMlArray[$i]['content'] .= '\n\n ' . $newContext;
                $hasSystemContext = true;
                break;
            }
        }

        if (!$hasSystemContext) {
            array_unshift($chatMlArray, array("role" => "system", "content" => $newContext));
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
            "max_tokens" => "gpt-max-tokens"
        ];

        foreach ($settings as $key => $setting) {
            if ($value = $this->getProjectSetting($setting)) {
                $params[$key] = is_numeric($value) ? (float) $value : intval($value);
            }
        }
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        try {
            switch ($action) {
                case "callAI":
                    $messages = $this->handleUserInput($payload);
                    //FIND AND INJECT RAG
//                $relevantDocs = $this->getRelevantDocuments($messages);
//                if (!empty($relevantDocs)) {
//                    $ragContext = implode("\n\n", array_column($relevantDocs, 'raw_content'));
//                    $messages = $this->appendSystemContext($messages, $ragContext);
//                }
                    $this->emDebug("chatml Messages array to API", $messages);

                    // Add most recent message to database
                    $recent_query = $messages[sizeof($messages) - 1];
                    $this->logMICAQuery(json_encode($recent_query), $recent_query['user_id']);

                    //CALL API ENDPOINT WITH AUGMENTED CHATML
                    $model  = "gpt-4o";
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

                    $this->emDebug("Result of SecureChatAI.callAI()", $result);
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
                    $existing_chat = $this->fetchSavedQueries($data);
                    if(!empty($existing_chat)){
                        $return_payload["current_session"] = $existing_chat;
                    }
                    return json_encode($return_payload);

                case "completeSession":
                    // expecting {participant_id : participant_id}
                    $data = $this->sanitizeInput($payload);
                    return json_encode($this->completeSession($payload));

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
     * @param $payload
     * @return array
     * @throws \Exception
     */
    public function fetchSavedQueries($payload): array
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
        if($check['participant_id'] === $participant_id && $check['participant_name'] === $name) {
            return MICAQuery::getLogsFor($this, PROJECT_ID, $participant_id);
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
//            $body = "Your MICA Verification code is $code";
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
        if($check['two_factor_code'] === $code)
            return [
                "success" => true,
                "user" => [
                    $primary_field => $check[$primary_field] ?? null,
                    "name" => $check['participant_name'] ?? null
                ]
            ];
        else // In case of invalid number, don't notifiy the user
            throw new \Exception('Invalid OTP code');
    }

    /**
     * @param $content
     * @param $session_id
     * @return void
     */
    private function logMICAQuery($content, $id){
        if (!isset($content) || !isset($id))
            throw new \Exception('No content passed to addAction, unable to save message');

        $this->emDebug("Adding action for MICA");
        $action = new MICAQuery($this);
        $action->setValue('mica_id', $id);
        $action->setValue('message', $content);
        $action->save();
        $this->emDebug("Added MICA query " . $action->getId());
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
        ['participant_id' => $participant_id] = $payload;

        // Correct the typo in the if statement
        if (empty($participant_id)) {
            throw new \Exception("Error with completing session: No participant ID provided");
        }

        $primary_field = $this->getPrimaryField();
        $params = array(
            "return_format" => "json",
            "filterLogic" => "[$primary_field] = '$participant_id'",
        );

        // Find user and determine validity
        $current_data = json_decode(\REDCap::getData($params), true);

        $check = reset($current_data);
        $logs = MICAQuery::getLogsFor($this, PROJECT_ID, $participant_id);
        $this->emDebug("completeSessions" , $logs, $current_data);


        $save = array(
            "user_complete" => "2",
            "completion_timestamp" => date("Y-m-d H:i:s"),
            "raw_chat_logs" => json_encode($logs)
        );

        $this->emDebug("completeSessions!", $save);
        $save = array(array_merge($check, $save));
        $response = \REDCap::saveData('json', json_encode($save), 'overwrite');

        if(! $response['errors']) {
            return ["success" => true];
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
