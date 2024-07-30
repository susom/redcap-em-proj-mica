<?php
namespace Stanford\MICA;

require_once "emLoggerTrait.php";
require_once "classes/Sanitizer.php";
require_once "classes/Action.php";

use \REDCapEntity\Entity;
use \REDCapEntity\EntityDB;
use \REDCapEntity\EntityFactory;
use Twilio\Rest\Client;

require_once "vendor/autoload.php";

class MICA extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;
    const BUILD_FILE_DIR = 'mica-chatbot/dist/assets';
    const SecureChatInstanceModuleName = 'secure_chat_ai';

    private \Stanford\SecureChatAI\SecureChatAI $secureChatInstance;
    public $system_context_persona;
    public $system_context_steps;
    public $system_context_rules;
    private $entityFactory;

    private $primary_field;

    public function __construct() {
        parent::__construct();
//        $this->entityFactory = new \REDCapEntity\EntityFactory();
//        $this->addAction(["Hello this is an example response to mica"], 1);
    }

    public function initSystemContexts(){
        //TODO CLEAN THIS UP
        $this->system_context_persona = $this->getProjectSetting('chatbot_system_context_persona');
        $this->system_context_steps = $this->getProjectSetting('chatbot_system_context_steps');
        $this->system_context_rules = $this->getProjectSetting('chatbot_system_context_rules');

        $initial_system_context = $this->appendSystemContext([], $this->system_context_persona);
        $initial_system_context = $this->appendSystemContext($initial_system_context, $this->system_context_steps);
        $initial_system_context = $this->appendSystemContext($initial_system_context, $this->system_context_rules);
//        $test = Action::getActionsFor($this,52,2);
        return $initial_system_context;
    }

    // Define entity types
    public function redcap_entity_types() {
        $types = [];

        $types['mica_contextdb'] = [
            'label' => 'MICA Chatbot Context',
            'label_plural' => 'MICA Chatbot Contexts',
            'icon' => 'file',
            'properties' => [
                'title' => [
                    'name' => 'Title',
                    'type' => 'text',
                    'required' => true,
                ],
                'raw_content' => [
                    'name' => 'Raw Content',
                    'type' => 'long_text',
                    'required' => true,
                ],
                'embedding_vector' => [
                    'name' => 'Embedding Vector',
                    'type' => 'long_text',
                    'required' => true,
                ],
            ],
        ];

        return $types;
    }

    // Hook to trigger entity initialization on module enablement
//    public function redcap_module_system_enable($version) {
//        \REDCapEntity\EntityDB::buildSchema($this->PREFIX);
//    }
//
//    public function redcap_every_page_top($project_id) {
//
//    }

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
//                    $this->addAction(json_encode($recent_query), $recent_query['id']);

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
                        $this->addAction(json_encode($result), $result['user_id']);

                    $this->emDebug("Result of SecureChatAI.callAI()", $result);
                    return json_encode($result);
                case "login":
                    $data = $this->sanitizeInput($payload);
                    return json_encode($this->loginUser($data));
                case "verifyPhone":
                    $data = $this->sanitizeInput($payload);
                    return json_encode($this->verifyPhone($data));

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
    public function loginUser($payload) {
        $primary_field = $this->getPrimaryField();
        if(empty($payload['name']) || empty($payload['email']))
            throw new \Exception("Error logging in user, either name or email is empty");

        ['name' => $name, 'email' => $email] = $payload;

        // Fetch user information
        $params = array(
            "return_format" => "json",
            "fields" => array($primary_field, "participant_name", "participant_email", "participant_phone"),
        );

        // Find user and determine validity
        $json = json_decode(\REDCap::getData($params), true);
        if(count($json) > 1)
            throw new \Exception("Error logging in user, duplicate entries for $name, $email");
        $check = reset($json);

        if($check['participant_name'] === $name && $check['participant_email'] === $email) { //User Successfully matched
            $this->generateOneTimePassword($check[$primary_field], $check['participant_phone']);
            return ["success" => true];
        } else {
            throw new \Exception('Invalid Credentials');
        }
    }

    /**
     * Generates OTP and saves to record
     * @param $record_id
     * @param $phone_number
     * @return void
     * @throws \Exception
     */
    private function generateOneTimePassword($record_id, $phone_number): void
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
            $body = "Your MICA Verification code is: $code";
            $this->sendSMS($body, $phone_number);

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
    private function verifyPhone($payload) {
        $primary_field = $this->getPrimaryField();

        if(empty($payload['code']))
            throw new \Exception("Error verifying phone number: no number provided");

        ['code' => $code] = $payload;

        // Fetch user information
        $params = array(
            "return_format" => "json",
            "fields" => array($primary_field,"participant_phone", "two_factor_code", "participant_name"),
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

    private function getEmbedding($text) {
        try {
            $result = $this->getSecureChatInstance()->callAI("ada-002", array("input" => $text) );
            return $result['data'][0]['embedding'];
        } catch (GuzzleException $e) {
            $this->emError("Embedding error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * @param $content
     * @param $session_id
     * @return void
     */
    private function addAction($content, $id){
        if (!isset($content) || !isset($id))
            throw new \Exception('No content passed to addAction, unable to save message');

        $this->emDebug("Adding action for MICA");
        $action = new Action($this);
        $action->setValue('mica_id', $id);
        $action->setValue('message', json_encode($content));
        $action->save();
        $this->emDebug("Added MICA action " . $action->getId());
    }

    private function getAllEntityIds($entityType) {
        $ids = [];
        $sql = 'SELECT id FROM `redcap_entity_' . db_escape($entityType) . '`';
        $result = db_query($sql);

        while ($row = db_fetch_assoc($result)) {
            $ids[] = $row['id'];
        }

        return $ids;
    }

 /**
 * Send SMS with body payload
 * @param $body
 * @param $phone_number
 * @return void
 */
public function sendSMS($body, $phone_number): void
{
    $sid = $this->getSystemSetting('twilio-sid');
    $auth = $this->getSystemSetting('twilio-auth-token');
    $fromNumber = $this->getSystemSetting('twilio-from-number');

    $twilio = new Client($sid, $auth);
    $twilio->messages
        ->create(
            "$phone_number",
            array(
                'body' => $body,
                'from' => $fromNumber
            )
        );
}

    // Retrieve relevant documents method
    public function getRelevantDocuments($queryArray) {
        if (!is_array($queryArray) || empty($queryArray)) {
            return null;
        }

        $lastElement = end($queryArray);

        if (!isset($lastElement['role']) || $lastElement['role'] !== 'user' || !isset($lastElement['content'])) {
            return null;
        }

        $query = $lastElement['content'];
        $queryEmbedding = $this->getEmbedding($query);

        if (!$queryEmbedding) {
            return null;
        }

        $entities = $this->entityFactory->loadInstances('mica_contextdb', $this->getAllEntityIds('chatbot_contextdb'));
        $documents = [];

        foreach ($entities as $entity) {
            $docEmbedding = json_decode($entity->getData()['embedding_vector'], true);
            $similarity = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

            $documents[] = [
                'id' => $entity->getId(),
                'title' => $entity->getData()['title'],
                'raw_content' => $entity->getData()['raw_content'],
                'similarity' => $similarity
            ];
        }

        usort($documents, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($documents, 0, 3);
    }

    // Store document method
    public function storeDocument($title, $content) {
        // Get the embedding for the content
        $embedding = $this->getEmbedding($content);
        $serialized_embedding = json_encode($embedding);

        // Store the new document with its embedding vector
        $entity = new \REDCapEntity\Entity($this->entityFactory, 'mica_contextdb');

        $entity->setData([
            'title' => $title,
            'raw_content' => $content,
            'embedding_vector' => $serialized_embedding
        ]);

        $entity->save();
    }

    // Cosine similarity calculation method
    private function cosineSimilarity($vec1, $vec2) {
        $dotProduct = 0;
        $normVec1 = 0;
        $normVec2 = 0;

        foreach ($vec1 as $key => $value) {
            $dotProduct += $value * ($vec2[$key] ?? 0);
            $normVec1 += $value ** 2;
        }

        foreach ($vec2 as $value) {
            $normVec2 += $value ** 2;
        }

        $normVec1 = sqrt($normVec1);
        $normVec2 = sqrt($normVec2);

        if ($normVec1 == 0 || $normVec2 == 0) {
            return 0; // Return zero if either vector norm is zero
        }

        return $dotProduct / ($normVec1 * $normVec2);
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
