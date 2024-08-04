<?php

namespace Webgurus\Admin;
use Carbon_Fields\Datastore\Datastore;
use \Carbon_Fields\Field\Field;

/**
 * Stores Settings of option pages in one option key
 */
class Options_Datastore extends Datastore {

    public $settings = [];
    private $fetched = false;
    public $keyname = null;
    private $autoload = false;

    public function __construct($keyname, $autoload = false) {
        $this->keyname = $keyname;
        $this->autoload = $autoload;
        add_action( 'carbon_fields_theme_options_container_saved', function($user_data, $class) {
            $datastore = $class->get_datastore();
            if (property_exists($datastore, 'keyname') && $datastore->keyname == $this->keyname) update_option($this->keyname, $this->settings, $this->autoload); 
        }, 10, 2);

    }

    public function init() {
    }

    public function load( Field $field ) {
        if (!$this->fetched) {
            $settings = get_option($this->keyname);
            if (is_array($settings)) $this->settings = $settings;
            $this->fetched = true;
        }
        $key = $field->get_base_name();
        if (key_exists($key, $this->settings)) {
            $value = $this->settings[$key];
            return $value;    
        }
    }

    public function save( Field $field ) {
        $key = $field->get_base_name();
        if ( is_a( $field, '\\Carbon_Fields\\Field\\Complex_Field' ) ) {
            $this->settings[$key] = [];
        }
        else {
            $value = $field->get_value();
            $hierarchy = $field->get_hierarchy();
            if (count($hierarchy) > 0) {
                $index = $field->get_hierarchy_index();
                $this->settings[$hierarchy[0]][$index[0]][$key] = $value;
            }
            else {
                $this->settings[$key] = $value;
            }
        }
    }

    public function delete( Field $field ) {
    }

}