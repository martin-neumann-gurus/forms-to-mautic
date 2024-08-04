<?php

namespace Webgurus\Mautic;
/**
*
 * Base Object Class
 */
trait CRUD_Object {
    
    /**
     * Class constructor loading property data
     * @param mixed $data / numeric: loads the id from database record / array: transfers keys to properties / object: loads all public properties with the same name
     */
    public function __construct($data = null) {
        global $wpdb;
        if (!empty($data)) {
            if( is_numeric($data) ){
                $sql = $wpdb->prepare("SELECT * FROM ". self::table_name() ." WHERE id=%d", $data);
                $data = $wpdb->get_row($sql, ARRAY_A);
            }
            $this ->set_props($data);
        }
    }
    
    /*
     * sets object data properties from an array or from another object
     */
    function set_props( $array ){
        if( is_array($array) ){
            foreach (self::$fields as $key => $description ) {
                if(array_key_exists($key, $array)){
                    $this->$key = self::format_db2data($array[$key], $description);
                }
            }
        }
        elseif (is_object($array)) {
            foreach (self::$fields as $key => $description ) {
                if(isset($array->$key)){
                    $this->$key = self::format_db2data($array->$key, $description);
                }
            }
        }
    }

    // for internal use: converts db formatted data (or other formats) into proper object format
    protected static function format_db2data($value, $description) {
        switch ($description['type']) {
            case 'date':
                if (is_string($value)) {
                    return date_create_from_format('Y-m-d',$value);
                } else {
                    return $value;
                }
            
            case 'datetime':
                    if (is_string($value)) {
                        return date_create_from_format('Y-m-d H:i:s',$value);
                    } else {
                        return $value;
                    }
    
            case 'array':
                return (!empty($value)) ? maybe_unserialize($value) : array();
                
            default:
                return $value;
        }
    }
    
    // for internal use: converts object data to proper  formatted db data
    protected static function format_data2db($value, $description) {
        if( !empty($value) || $value === 0 || $value === '0' ){
            switch ($description['type']) {
                case 'date':
                    $result = $value->format('Y-m-d');
                    break;

                case 'datetime':
                    $result = $value->format('Y-m-d H:i:s');
                    break;

                case 'array':
                    $result = serialize($value);
                    break;

                default:
                    $result = $value;
            }
        }elseif( $value === null && !empty($description['null']) ){
            $result = null;
        }
        else {
            $result = $value;
        }
        return $result;
    }

    /**
     * Returns this object in the form of an array, useful for saving directly into a database table.
     * @param boolean $db : true to return in DB format
     * @return array
     */
    function get_props_array($db = false){
        $array = array();
        foreach ( self::$fields as $key => $description ) {
            if($db){
                $array[$key] = self::format_data2db($this->$key, $description);
            }else{
                $array[$key] = $this->$key;
            }
        }
        return $array;
    }
    
  
    /*
     * Save a record in the database, update if id is set.
     */
    public function save() {
        global $wpdb;
        $data = $this->get_props_array(TRUE);
        if (empty($this->id)) {
            $wpdb->insert(self::table_name(), $data);
            $this->id = $wpdb->insert_id;
        } else {
            $wpdb->update(self::table_name(), $data, ['id' => $this->id]);
        }
    }

    /**
     * static function table_name()
     * @return string: table name with proper prefix
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    //For internal use: Format conditions according to data type set for the field
    private static function format_sql_parms($arg_array, $is_query = false) {
        $parms = [];
        $values = [];
        foreach ($arg_array as $field=>$value) {
            $description = self::$fields[$field];
            if ($is_query && is_null($value)) {
                $parms[] = "`$field` IS NULL";
            }
            else {
                switch ($description['type']) {
                    case 'int':
                    case 'bigint':
                    case 'tinyint':
                        $format = '%d';
                        break;
    
                    case 'float':
                        $format = '%f';
                        break;
    
                    default:
                        $format = '%s';
                }
                $parms[] = "`$field` = $format";
                $values[] = self::format_data2db($value, $description);
            }
        }
        if ($is_query) {
            $querystring = implode( ' AND ', $parms );
        }
        else {
            $querystring = implode( ', ', $parms );
        }
        return ['values'=>$values, 'querystring'=>$querystring];
    }

    //for internal use: formats SQL condition string depending on the input format
    private static function format_condition($condition, $args) {
        if (is_int($condition)) {
            //we provided the id in integer format
            $args = [$condition];
            $condition = 'id = %s';
        } elseif (is_array ($condition)) {
            //we provided an array of conditions to be combined by AND
            $set_cnd = self::format_sql_parms($condition, true);
            $args = $set_cnd['values'];
            $condition = $set_cnd['querystring'];
        }
        return ['condition'=>$condition, 'values'=>$args];
    }
    
    /**
     * Updates records within the object database table
     * @param array $set : array of key=>value pairs to be set
     * @param mixed $condition : ID to update, array of conditions to be combined by AND, or search condition string in proper SQL formatting
     * @param mixed $args : replacement paramenters to pass to $wpdb->prepare
     */
    public static function update ($set, $condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        array_shift($args);
        $set_fmt = self::format_sql_parms($set);
        $cond_fmt = self::format_condition($condition, $args);

        $sql = $wpdb->prepare('UPDATE '. self::table_name().' SET '.$set_fmt['querystring'].' WHERE '.$cond_fmt['condition'], array_merge($set_fmt['values'], $cond_fmt['values']));
        return $wpdb->query($sql);
    }

    /**
     * Deletes records within the object database table
     * @param mixed $condition : ID to delete, or search condition string in proper SQL formatting
     * @param mixed $args : replacement paramenters to pass to $wpdb->prepare
     */
    public static function delete ($condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        $cond_fmt = self::format_condition($condition, $args);
        $sql = $wpdb->prepare('DELETE FROM '. self::table_name().' WHERE '.$cond_fmt['condition'], $cond_fmt['values']);
        return $wpdb->query($sql);
    }
    
    /**
     * searches records according to condition and returns in an array of ojbects
     * @param string $condition: search condition string in proper SQL formatting
     * @param mixed $args: replacement paramenters to pass to $wpdb->prepare
     * @return array of objects
     */
    public static function get_results($condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        $cond_fmt = self::format_condition($condition, $args);
        $sql = $wpdb->prepare('SELECT * FROM '. self::table_name().' WHERE '.$cond_fmt['condition'], $cond_fmt['values']);
        $result = $wpdb->get_results($sql, ARRAY_A);
        $output = array();
        $classname = get_class();
        foreach ($result AS $data) {
            $output[$data['id']] = new $classname($data);
        }
        return $output;
    }

    /**
     * searches the first record according to condition and returns in an ojbect
     * @param string $condition : search condition string in proper SQL formatting
     * @param mixed $args : replacement paramenters to pass to $wpdb->prepare
     * @return object
     */
    public static function get_row($condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        $cond_fmt = self::format_condition($condition, $args);
        $sql = $wpdb->prepare('SELECT * FROM '. self::table_name().' WHERE '.$cond_fmt['condition'], $cond_fmt['values']);
        $data = $wpdb->get_row($sql, ARRAY_A);
        $classname = get_class();
        if (!empty($data))  return new $classname($data);
    }

    /**
     * searches records according to condition and returns the specified field in an array
     * @param string $field : database field name to return
     * @param string $condition : search condition string in proper SQL formatting
     * @param mixed $args : replacement paramenters to pass to $wpdb->prepare
     * @return array
     */
    public static function get_col($field, $condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        array_shift($args);
        $cond_fmt = self::format_condition($condition, $args);
        $sql = $wpdb->prepare("SELECT $field FROM ". self::table_name().' WHERE '.$cond_fmt['condition'], $cond_fmt['values']);
        return $wpdb->get_col($sql);
    }

    /**
     * searches records according to condition and returns the count
     * @param string $field : database field name to return
     * @param string $condition : search condition string in proper SQL formatting
     * @param mixed $args : replacement paramenters to pass to $wpdb->prepare
     * @return array
     */
    public static function get_count($condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        $cond_fmt = self::format_condition($condition, $args);
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM ". self::table_name().' WHERE '.$cond_fmt['condition'], $cond_fmt['values']);
        return $wpdb->get_var($sql);
    }

    /**
     * creates an SQL statement to create the table
     */
    public static function create_table_sql() {
        global $wpdb;
        
        $out = 'CREATE TABLE '.self::table_name()." (\n";
        foreach ( self::$fields as $key => $description ) {
            $type = $description['type'];
            $length = null;
            switch ($type) {
                case 'primary':
                    $type = 'bigint(20) NOT NULL AUTO_INCREMENT';
                    $description['null'] = true;
                    break;
                    
                case 'array':
                    $type = 'longtext';
                    break;

                case 'tinyint':
                    $length = 4;
                    break;
                
                case 'int':
                    $length = 11;
                    break;
                
                case 'bigint':
                    $length = 20;
                    break;
                
            }
            $out .= $key.' '.$type;
            if (!empty($description['length'])) $length = $description['length'];
            if (!empty($length)) $out .= '('.$length.')';
            if (empty($description['null'])) $out .= ' NOT NULL';
            $out .=",\n";
        }
        return $out."PRIMARY KEY  (id)\n) ".$wpdb->get_charset_collate();
    }
        
}