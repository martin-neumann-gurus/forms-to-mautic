<?php

namespace Carbon_Fields\Field;
use Webgurus\Admin\MauticAdmin;

/**
 * Wg_Mautic_Link field class.
 * Fetches the page link at the Webgurus Mautic Page Request Complex fields
 */
class Wg_Mautic_Link_Field extends Html_Field {
    public function to_json( $load ) {
        $index = $this->get_hierarchy_index();
        $pg_defs = MauticAdmin::$pages_ds->settings['fields'];
        if (array_key_exists(0, $index)) {
            $page_id = $pg_defs[$index[0]]['page'];
            $this->field_html =  __( 'Link for Confirmation Email:' , 'webgurus-mautic') . '<br>' . get_permalink($page_id).'?id={contactfield=id}&email={contactfield=email}';    
        }
        $field_data = parent::to_json( $load );
        $field_data['type'] = 'html';
        return $field_data;
    }
}
