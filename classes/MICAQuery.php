<?php

namespace Stanford\MICA;
require_once "ASEMLO.php";


/**
 * The Conversation State extends the Simple EM Log Object to provide a data store for all conversations
 *
 */
class MICAQuery extends ASEMLO
{
    /** @var MICA $this ->module */

    /**
     * @param $module
     * @param $type
     * @param $log_id
     * @param $limit_params //used if you want to obtain a specific log_id and then only pull certain parameters
     * @throws \Exception
     */
    public function __construct($module, $log_id = null, $limit_params = [])
    {
        parent::__construct($module, $log_id, $limit_params);
    }

    // public function payloadCheck() {
    //     if (! isset($this->payload)) {
    //         $this->payload = $this->getPayload();
    //     }
    // }

    /** GETTERS */
    public function getPayload()
    {
        // The payload is in the settings table and has the json object
        // TODO:? Should we json_decode here?
        return $this->getValue('payload');

    }

    public function getMICAQuery()
    {
        $message = $this->getValue('message');
        $decoded = json_decode($message, true);
        $decoded['timestamp'] = $this->getValue('timestamp');
        $decoded['id'] = $this->getId();
        return $decoded;
    }


    /** SETTERS */


    /** STATIC METHODS */

    /**
     * Load the active conversation after action_id
     * @param MICA $module
     * @param int $project_id
     * @param int $action_id
     * @return array Action
     * @throws \Exception
     */
    public static function getLogsFor($module, $project_id, $mica_id)
    {

        $filter_clause = "project_id = ? and mica_id = ? order by log_id asc";
        $objs = self::queryObjects(
            $module, $filter_clause, [$project_id, $mica_id]
        );

        $chatSession = [];

        foreach ($objs as $action) {
            $messageJson = $action->getValue('message');
            $timestamp = $action->getValue('timestamp');
            
            // Decode the JSON message
            $messageData = json_decode($messageJson, true);

            // Handle potential double encoding
            if (is_string($messageData)) {
                $messageData = json_decode($messageData, true);
            }

            // Check for JSON decoding errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                $module->emDebug('JSON decoding error: ' . json_last_error_msg(), $messageJson);
                continue; // Skip this iteration if decoding failed
            }

            // Extract data
            $assistantContent = $messageData['response']['content'] ?? null;
            $userContent = $messageData['query']['content'] ?? null;

            if (empty($assistantContent)) {
//                $module->emDebug("only return for completed Q + A");
                continue; // Skip this iteration
            }

            $id = $messageData['id'] ?? null;
            $model = $messageData['model'] ?? null;
            $usage = $messageData['usage'] ?? [];
            $inputTokens = $usage['prompt_tokens'] ?? null;
            $outputTokens = $usage['completion_tokens'] ?? null;

            // Build the chat session entry
            $chatSession[] = [
                'assistant_content' => $assistantContent,
                'user_content'      => $userContent,
                'id'                => $id,
                'model'             => $model,
                'input_tokens'      => $inputTokens,
                'output_tokens'     => $outputTokens,
                'input_cost'        => null, // Or calculate if available
                'output_cost'       => null, // Or calculate if available
                'timestamp'         => strtotime($timestamp) * 1000, // Convert timestamp to milliseconds
            ];
        }

        return $chatSession;
    }
}
