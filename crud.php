<?php

namespace WebgurusMautic\Integrations;
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
                    $this->$key = self::format_data($array[$key], $description);
                }
            }
        }
        elseif (is_object($array)) {
            foreach (self::$fields as $key => $description ) {
                if(isset($array->$key)){
                    $this->$key = self::format_data($array->$key, $description);
                }
            }
        }
    }

    // for internal use: loads db formatted data into proper object format
    protected static function format_data($value, $description) {
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
                return (!empty($value)) ? maybe_unserialize($value):array();
                
            default:
                return $value;
        }
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
                if( !empty($this->$key) || $this->$key === 0 || $this->$key === '0' ){
                    switch ($description['type']) {
                        case 'date':
                            $array[$key] = $this->$key->format('Y-m-d');
                            break;

                        case 'datetime':
                            $array[$key] = $this->$key->format('Y-m-d H:i:s');
                            break;
    
                        case 'array':
                            $array[$key] = serialize($this->$key);
                            break;

                        default:
                            $array[$key] = $this->$key;
                    }
                }elseif( $this->$key === null && !empty($description['null']) ){
                    $array[$key] = null;
                }
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
    
    /**
     * Updates records within the object database table
     * @param array $parms : key=>value pairs to update in the database
     * @param mixed $condition : ID to update, or key=>value pairs of search conditions
     */
    public static function update ($parms, $condition) {
        global $wpdb;
        if (!is_array($condition)) $condition = ['id'=>$condition];
        $wpdb->update(self::table_name(), $parms, $condition);
    }

    /**
     * Deletes records within the object database table
     * @param mixed $condition : ID to delete, or key=>value pairs of search conditions
     */
    public static function delete ($condition) {
        global $wpdb;
        if (!is_array($condition)) $condition = ['id'=>$condition];
        $wpdb->delete(self::table_name(), $condition);
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
        $sql = $wpdb->prepare('SELECT * FROM '. self::table_name().' WHERE '.$condition, $args);
        $result = $wpdb->get_results($sql, ARRAY_A);
        $output = array();
        $classname = get_class();
        foreach ($result AS $data) {
            $output[$data['id']] = new $classname($data);
        }
        return $output;
    }

    /**
     * searches records according to condition and returns in an array of ojbects
     * @param string $condition : search condition string in proper SQL formatting
     * @param mixed $args : replacement paramenters to pass to $wpdb->prepare
     * @return object
     */
    public static function get_row($condition, $args = null) {
        global $wpdb;
        $args = func_get_args();
        array_shift($args);
        $sql = $wpdb->prepare('SELECT * FROM '. self::table_name().' WHERE '.$condition, $args);
        $data = $wpdb->get_row($sql, ARRAY_A);
        $classname = get_class();
        if (!empty($data))  return new $classname($data);
     }

    /**
     * searches records according to condition and returns in an array of ojbects
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
        $sql = $wpdb->prepare("SELECT $field FROM ". self::table_name().' WHERE '.$condition, $args);
        return $wpdb->get_col($sql);
    }
    
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