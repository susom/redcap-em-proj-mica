<?php

namespace Stanford\MICA;

use \Exception;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

/**
 * Abstract Simple EM Log Object
 * An abstract class that can be extended to make quick object-level storage as part of an EM
 */
class ASEMLO
{
    const OBJECT_NAME     = null;     // Object name (defaults to class name) - can be overwritten by parent object
    const NAME_COLUMN     = 'record'; // typically 'message' or 'record' - recommend using record unless you need
                                      // the built-in record logging functionality
    const LOG_OBJECT_NAME = null;     // Will default to class name+'_LOG' unless set in parent class
    const LOG_CHANGES = false;         // default log changes behavior

    /** @var AbstractExternalModule $module */
    private $module;

    private $object_name;

    protected const PRIMARY_FIXED_COLUMNS = [ 'log_id','ui_id','ip','external_module_id' ];
    protected const PRIMARY_UPDATABLE_COLUMNS = [ 'timestamp','project_id','record','message' ];

    private $data;                    // The data for this object
    private $dirty_keys = [];
    public $log_changes;           // Will log all changes to values
    public $change_log = [];



    /**
     * You can LOAD or CREATE a new object depending on whether you pass along the log_id
     *
     * @param AbstractExternalModule $module
     * @param integer $log_id leave Null for a new module
     * @param array $limit_params Leave blank for ALL parameters, otherwise specific array of desired
     * @param string $object_name Specify object name (if not using an extended class
     * @throws Exception
     */
    public function __construct($module, $log_id = null, $limit_params = [], $object_name = null, $log_changes = null) {
        // Other code to run when object is instantiated
        $this->module = $module;
        $this->object_name = $object_name;
        $this->log_changes = is_null($log_changes) ? static::LOG_CHANGES : (bool) $log_changes;

        if($log_id) {
            if (empty($limit_params)) {
                // Try to get all available EAV parameter entries for the log_id
                $sql = "select distinct name from redcap_external_modules_log_parameters where log_id=?";
                $result = $module->query($sql, $log_id);
                while ($row = $result->fetch_assoc()) {
                    $limit_params[] = $row['name'];
                }
            }

            // Query all data for primary columns and specified parameter columns
            $available_columns = array_unique(array_merge(self::PRIMARY_UPDATABLE_COLUMNS, self::PRIMARY_FIXED_COLUMNS, $limit_params));
            $sql = "select " . implode(", ", $available_columns) . " where log_id=? and " . static::NAME_COLUMN . "=?";
            // $module->emDebug("Load Sql: " . $sql);
            $q = $module->queryLogs($sql, [$log_id, self::getObjectName()]);
            if ($row = $q->fetch_assoc()) {
                $this->setValues($row,false);
                // foreach ($row as $key=>$val) {
                //     $this->setValue($key, $val, false);
                // }
            } else {
                $this->last_error = "Requested log_id $log_id not found for object " . self::getObjectName();
                $this->module->emDebug($this->last_error);
                throw new Exception ($this->last_error);
            }
        } else {
            // Create a new object - not yet saved
            $this->module->emDebug("Creating new " . self::getObjectName() . " using " . static::NAME_COLUMN);
            $this->setValue(static::NAME_COLUMN, self::getObjectName());
        }
    }


    /**
     * Set object value by key pair
     * If null, remove from object_properties
     * If unchanged, do not mark as dirty
     * @param string $name
     * @param $val
     * @param bool $mark_change_as_dirty if true, the change will be marked as a dirty column for updating on save
     * @return void
     * @throws Exception
     */
    public function setValue($name, $val, $mark_change_as_dirty = true) {
        if(is_array($val)) {
            $val = json_encode($val);
            $this->module->emDebug("Input $name is array - casting to json for storage");
        }
        if(is_object($val)) {
            $val = json_encode($val);
            $this->module->emDebug("Input $name is object - casting to json for storage");
        }

        if(in_array($name, self::PRIMARY_UPDATABLE_COLUMNS)) {
            // Is a primary updatable column
            if ($name == static::NAME_COLUMN && $val !== self::getObjectName() && $this->getId()) {
                // already saved and you are potentially trying to rename the object type
                $this->module->emError("You should not try to update the " . static::NAME_COLUMN . " column as it is being used as the NAME_COLUMN for the object.  Doing so would orphan your object type!  If you need to switch objects, use the rename_object method");
                // TODO: Maybe throw an exception here?
                throw new Exception("You cannot update the object name column: " . static::NAME_COLUMN);
            } else if ($this->getValue($name) !== $val) {
                $this->data[$name] = $val;
                if ($mark_change_as_dirty) $this->dirty_keys[] = $name;
            } else {
                $this->module->emDebug("No change to $name");
            }
        } elseif(in_array($name, self::PRIMARY_FIXED_COLUMNS)) {
            // Is a primary fixed column - only set its value from null on load
            if (empty($this->data[$name])) {
                $this->data[$name] = $val;
            } else {
                throw new Exception ("You cannot update a fixed log column, like $name in " . self::getObjectName());
            }
        } else {
            if (isset($this->data[$name])) {
                // Parameter already exists
                if ($this->data[$name] !== $val) {
                    $this->data[$name] = $val;
                    if ($mark_change_as_dirty) $this->dirty_keys[] = $name;
                }
            } else {
                // Parameter is new
                if ($this->validateParameter($name, $val)) {
                    $this->data[$name] = $val;
                    if ($mark_change_as_dirty) $this->dirty_keys[] = $name;
                } else {
                    $this->module->emDebug("Invalid parameter name [$name] or value: ", $val);
                }
            }
        }
    }

    /**
     * Rename Object (advanced!)
     * @param $new_name
     * @return void
     */
    public function renameObject($new_name) {
        $current_object_name = $this->data[static::NAME_COLUMN];
        $this->data[static::NAME_COLUMN] = $new_name;
        $this->dirty_keys[] = static::NAME_COLUMN;
        $this->logChange(['rename object', $current_object_name, $new_name]);
        $this->module->emDebug("Renaming " . $this->getId() . " from $current_object_name to $new_name.  This means you must have another object type to access this entry or it will be orphaned.");
    }

    /**
     * Set object values by an associative array
     * @param array $arr
     * @return bool
     * @throws Exception
     */
    public function setValues($arr, $mark_change_as_dirty = true) {
        if (!is_array($arr)) {
            $this->module->emDebug("Input is not an array");
            return false;
        }
        foreach ($arr as $k => $v) {
            $this->setValue($k, $v, $mark_change_as_dirty);
        }
        return true;
    }


    /**
     * Get a value by a key
     * If key doesn't exist, return null
     * @param string $k
     * @return mixed
     */
    public function getValue($k) {
        return $this->data[$k] ?? null;
    }


    /**
     * See if your object is changed
     * @return bool
     */
    public function isDirty() {
        return !empty($this->dirty_keys);
    }


    /**
     * Get the log_id for the object
     * @return mixed
     */
    public function getId() {
        return $this->getValue('log_id');
    }


    /**
     * Save the log_id after the initial save of an object
     * @param $log_id
     * @return void
     */
    private function setId($log_id) {
        $this->data['log_id'] = $log_id;
    }

    /**
     * Save the object, only modifying the object_parameters
     * @return void
     * @throws Exception
     */
    public function save() {
        if ($id = $this->getId()) {
            // Updating an existing log entry
            $this->dirty_keys = array_unique($this->dirty_keys);
            foreach ($this->dirty_keys as $k) {
                if (in_array($k, self::PRIMARY_UPDATABLE_COLUMNS)) {
                    // We can update this value
                    $v = $this->getValue($k);
                    $sql = "update redcap_external_modules_log set " . $k . "=? where log_id=?";
                    $params = [$v, $id];
                    $result = $this->module->query($sql, $params);
                    if ($result) {
                        // $this->module->emDebug("Updated $id: set $k to $v");
                        $this->logChange(["update", $k, $v]);
                    } else {
                        $this->module->emDebug("Primary update failed: ", $sql, $params, $result);
                    }
                } elseif (in_array($k, self::PRIMARY_FIXED_COLUMNS)) {
                    // We cannot update this kind of column
                    $this->module->emError("You cannot update column $k in this object");
                } else {
                    // It is a parameter
                    $v = $this->getValue($k);
                    if (is_null($v) or $v == "") {
                        // delete the parameter
                        $sql = "delete from redcap_external_modules_log_parameters where log_id=? and name=? limit 1";
                        $params = [$id, $k];
                        $result = $this->module->query($sql, $params);
                        if ($result) {
                            $this->logChange(["delete parameter", $k]);
                            $this->module->emDebug("Deleted parameter $k for log id $id");
                        } else {
                            $this->module->emError("Delete parameter $k failed for log it $id", $sql,$params,$result);
                        }
                    } else {
                        // upsert/insert parameter
                        $sql = "INSERT INTO redcap_external_modules_log_parameters (log_id,name,value) " .
                            " VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=?";
                        $params = [$id, $k, $v, $v];
                        $result = $this->module->query($sql, $params);
                        if ($result) {
                            $this->logChange(['update', $k, $v]);
                            // $this->module->emDebug("Updated $id - set $k to $v");
                        } else {
                            $this->module->emDebug("Parameter update failed: ", $sql, $params, $result);
                        }
                    }
                }
            }
            // Clear Dirty Keys - All Processed
            $this->dirty_keys = [];
        } else {
            // Create New Log Entry (merging columns and parameters)
            // You cannot include fixed external_module_log columns - so let's remove them before creation
            $updatable_data = array_filter(
                $this->data,
                function($item, $key) {
                    return !in_array($key,self::PRIMARY_FIXED_COLUMNS)
                        && !is_null($item)
                        && $item !== ''
                        && $key !== 'message';
                },
                ARRAY_FILTER_USE_BOTH
            );

            // $this->module->emDebug("Updatable Data", $updatable_data, $this->data);
            $message = $this->data['message'] ?? "-";
            $this->module->emDebug("About to save $message: " , $updatable_data);
            if ($log_id = $this->module->log($message, $updatable_data)) {
                $this->setId($log_id);
                $this->module->emDebug("Created log $log_id");
            } else {
                $this->module->emError("Error creating new $log_id");
            }
        }

        // Write to change logs
        $this->saveChangeLog();
    }


    /**
     * Delete from database
     * @return bool
     */
    public function delete() {
        // Remove this log_id
        if ($id = $this->getId()) {
            $result = $this->module->removeLogs("log_id = ?", [$id]);
            $this->module->emDebug("Removed log $id with result: " . json_encode($result));
            $this->logChange(["delete", $id]);
            $this->saveChangeLog();
            return true;
        } else {
            $this->module->emDebug("This object hasn't been saved.  Cannot delete.");
            return false;
        }
    }


    /**
     * Modified from Framework function
     * @param string $name
     * @param mixed $value
     * @return bool
     * @throws Exception
     */
    private function validateParameter($name, $value)
    {
        $type = gettype($value);
        if(!in_array($type, ['boolean', 'integer', 'double', 'string', 'NULL'])){
            throw new Exception("The type '$type' for the '$name' parameter is not supported.");
        }
        else if (isset(AbstractExternalModule::$RESERVED_LOG_PARAMETER_NAMES_FLIPPED[$name])) {
            throw new Exception("The '$name' parameter name is set automatically and cannot be overridden.");
        }
        else if($value === null){
            // There's no point in storing null values in the database.
            // If a parameter is missing, queries will return null for it anyway.
            // unset($parameters[$name]);
            return false;
        }
        else if(strpos($name, "'") !== false){
            throw new Exception("Single quotes are not allowed in parameter names.");
        }
        else if(mb_strlen($name, '8bit') > ExternalModules::LOG_PARAM_NAME_SIZE_LIMIT){
            throw new Exception(ExternalModules::tt('em_errors_160', ExternalModules::LOG_PARAM_NAME_SIZE_LIMIT));
        }
        else if(mb_strlen($value, '8bit') > ExternalModules::LOG_PARAM_VALUE_SIZE_LIMIT){
            throw new Exception(ExternalModules::tt('em_errors_161', ExternalModules::LOG_PARAM_VALUE_SIZE_LIMIT));
        }
        return true;
    }


    /**
     * Log a change to the SEMLO
     * @param $change
     * @return void
     */
    private function logChange($change) {
        $this->change_log[] = $change;
    }


    /**
     * Save Change Log
     * // The record property is used for the parent log_id
     * // The message property is used for the name of the log object
     * // The activity parameter is used for the log entry
     * @return void
     */
    private function saveChangeLog() {
        if (!empty($this->change_log) && $this->log_changes) {
            $params = [
                "record" => self::getLogObjectName(),
            ];
            // Add project_id if present in underlying object
            if (!empty($this->getValue('project_id'))) $params['project_id'] = $this->getValue('project_id');

            $log = [
                "parent_log_id" => $this->getId(),
                "object_name" => self::getObjectName(),
                "log" => $this->change_log
            ];

            // Save the log object
            if ($log_id = $this->module->log(json_encode($log), $params)) {
                $this->module->emDebug("Saved change log id " . $log_id);
            } else {
                $this->module->emDebug("Error saving change log", $params);
            };
            // Empty the change log
            $this->change_log = [];
        }
    }


    #### STATIC METHODS ####

    /**
     * Determine the name of the object for the name column
     * @return string|null
     */
    private static function getObjectName() {
        return is_null(static::OBJECT_NAME) ? substr(strrchr(static::class, '\\'), 1) : static::OBJECT_NAME;
    }

    /**
     * Determine the name of the object for storing logs
     * @return string|null
     */
    private static function getLogObjectName() {
        return is_null(static::LOG_OBJECT_NAME) ? self::getObjectName() . "_LOG" : static::LOG_OBJECT_NAME;
    }


    /**
     * This method will purge the redcap_external_module_logs table of all of the change_event
     * logs for this object that are older than the $age_in_days
     * @param integer $age_in_days
     * @param string $object_type  The type of object, e.g. "CS"
     * @return void
     */
    public static function purgeChangeLogs($module, $age_in_days = 30) {
        $dt = new \DateTime();
        $interval = new \DateInterval("P" . $age_in_days . "D");
        $timestamp = $dt->sub($interval)->format("Y-m-d H:i:s");
        $filter_clause = "timestamp < ? and record = ?";
        $affected_rows = $module->removeLogs($filter_clause, [$timestamp, self::getLogObjectName()]);
        $module->emDebug($affected_rows . " change logs of type " . self::getLogObjectName() . " older than $age_in_days days were purged");
    }


    /**
     * Get all of the matching log ids for the object
     * @param $module
     * @param $object_type
     * @param $filter_clause
     * @param $parameters
     * @return array
     * @throws Exception
     */
    public static function queryIds($module, $filter_clause = "", $parameters = []) {
        $framework = new \ExternalModules\Framework($module);

        // Trim leading where if it exists
        if (substr(trim(mb_strtolower($filter_clause)),0,5) === "where") {
            $filter_clause = substr(trim($filter_clause),5);
        }

        $question_mark_count = count_chars($filter_clause)[ord("?")];
        if (count($parameters) != $question_mark_count) {
            throw Exception ("query filter must have parameter for each question mark");
        }


        $sql = "select log_id where " . static::NAME_COLUMN . "= ?" . (empty($filter_clause) ? "" : " and " . $filter_clause);
        $params = array_merge([self::getObjectName()], $parameters);
        $module->emDebug($sql, $params);

        $result = $framework->queryLogs($sql,$params);
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = $row['log_id'];
        }
        return $ids;
    }

    /**
     * Return an array of objects instead of ids for the matching results
     * @param $module
     * @param $filter_clause
     * @param $parameters
     * @return array
     * @throws Exception
     */
    public static function queryObjects($module, $filter_clause = "", $parameters = []) {
        $ids = static::queryIds($module, $filter_clause, $parameters);
        $results = [];
        foreach ($ids as $id) {
            $obj = new static($module, $id);
            $results[] = $obj;
        }
        return $results;
    }


}
