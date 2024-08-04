<?php

namespace Webgurus\Admin;

use Webgurus\Mautic\API;
use Webgurus\Mautic\Record;
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Datastore\Datastore;

add_action('admin_init', function() {
    global $wpdb;

    if (isset($_REQUEST['ff_mautic_auth']) || isset($_REQUEST['wg_mautic_auth'])) {
        $api = API::instance();
        if (isset($_REQUEST['code'])) {
            // Get the access token now
            $code = sanitize_text_field($_REQUEST['code']);
            $settings = $api->generateAccessToken($code);

            if (!is_wp_error($settings)) {
                $settings['status'] = true;
                $api->updateSettings ($settings);
                $parms = '';
            }
            else {
                $parms = '&error=' . rawurlencode($settings->get_error_message());
            }

            wp_redirect(admin_url('admin.php?page=crb_carbon_fields_container_wg_mautic.php' . $parms));
        } else {
            $api->redirectToAuthServer();
        }
        die();
    }

    //--------------------Processing Page Options---------------
    if (isset($_REQUEST['page'])) {

        switch ($_REQUEST['page']) {
            case 'crb_carbon_fields_container_wg_mautic.php':

                //--------------Reseting the OAuth Data--------
                if (isset($_REQUEST['reset'])) {
                    $api = API::instance();
                    $settings = $api->settings;
                    $settings['ffMauticSettings'] = false;
                    $settings['status'] = false;
                    $settings['client_id'] = '';
                    $settings['client_secret'] = '';
                    $api->updateSettings ($settings);

                    wp_redirect(admin_url('admin.php?page=crb_carbon_fields_container_wg_mautic.php'));
                    die();
                }
                break;

            case 'crb_carbon_fields_container_wg_mautic_status.php':
                if (isset($_POST['delete'])) {
                    $count = Record::update(['status'=>Record::TRASHED], "status < -3 AND command IN ('subscribe', 'confirm')");
                    $sql = $wpdb->prepare('UPDATE %1$s s1 INNER JOIN %1$s s2 ON s1.parent_ID = s2.id SET s1.status = 100 WHERE s2.status = 1 AND s1.status < -3', Record::table_name());
                    $count+= $wpdb->query($sql);
                    MauticAdmin::$status_pg->add_notification(sprintf (__('Trashed %d failed requests with more than 3 retries.', 'webgurus-mautic'), $count));
                }

                if (isset($_POST['process'])) {
                    //--------------Output the script that initiates the AJAX request and receives the results to show the processing of failed requests-------
                    add_action('admin_head', function() {
                        ?>
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            
                            fetch(`<?php echo admin_url('admin-ajax.php?action=wg_mautic_process&security=' . wp_create_nonce('wg_mautic_status_nonce'));?>`)
                            .catch(error => {
                                    console.error('Main Request Error:', error);
                            });
                            function makeCall() {
                                fetch(`<?php echo admin_url('admin-ajax.php?action=wg_mautic_update&security=' . wp_create_nonce('wg_mautic_status_nonce'));?>`)
                                .then(response => response.json())
                                .then(arrd => {
                                    let div = document.getElementById('wg_mautic_ajax');
                                    if (arrd) {
                                        for (let data of arrd.data) {
                                            if (data.finished) {
                                                div.innerHTML += '<p style="color: red;"><?php _e('Finished processing.', 'webgurus-mautic')?></p>';
                                                return;
                                            }
                                            else {                                        
                                                let color = data.success ? 'black' : 'red';
                                                div.innerHTML += `<p style="color: ${color};">ID:${data.id} ${data.msg}</p>`;
                                            }
                                        }
                                    }
                                    makeCall();
                                })
                                .catch(error => {
                                    console.error('Error:', error);
                                    document.getElementById('wg_mautic_ajax').innerHTML += '<p style="color: red;"><?php _e('Connection Error.', 'webgurus-mautic')?></p>';
                                });
                            }
                            makeCall();
                        });
                        </script>
                        <?php
                    });
                }

        }
    }    


    add_action( 'carbon_fields_theme_options_container_saved', function($user_data, $class) {
        switch ($class->id) {
            case 'carbon_fields_container_wg_mautic':
                $api = API::instance();
                $api->updateSettings($api->settings);
                if ($api->settings['status'] == false) {
                    $api->redirectToAuthServer();
                    die();
                }
        }
    }, 10, 2);

});


/**
 * Stores OAuth settings in wg_mautic_connection
 */
class OAuth_Datastore extends Datastore {

    public function init() {
    }

    public function load( \Carbon_Fields\Field\Field $field ) {
        $api = API::instance();
        $key = $field->get_base_name();
        $value = $api->settings[$key];
        return $value;
    }

    public function save( \Carbon_Fields\Field\Field $field ) {
        $api = API::instance();
        $key = $field->get_base_name();
        $value = $field->get_value();
        $api->settings[$key] = $value;
        //$api->updateSettings($api->settings);
    }

    public function delete( \Carbon_Fields\Field\Field $field ) {
    }

}

Class MauticAdmin {

    public static $pages; //Buffer for loaded page list
    private static $page_cnt = 0; //Iteration for the getConfirmationLink function

    public static $main_pg;
    public static $comments_pg;
    public static $woocommerce_pg;
    public static $pages_pg;
    public static $status_pg;

    public static $comments_ds;
    public static $woocommerce_ds;
    public static $pages_ds;

    public static function Boot() {
        //----------Webgurus Screen----------
        if (empty(MainMenu::$mainmenu)) MainMenu::boot();

        //--------------------OAuth Settings -------------
        self::$main_pg = Container::make( 'theme_options', 'wg_mautic', __( 'Forms to Mautic', 'webgurus-mautic' ))
        ->set_page_parent( MainMenu::$mainmenu )
        ->add_fields (array (
            Field::make( 'html', 'wg_information_text' ) ->set_html( array('\Webgurus\Admin\MauticAdmin', 'getOAuthStatus') ), 
            Field::make( 'text', 'api_url', __( 'Your Mautic Base URL', 'webgurus-mautic' ) ) ->set_required( true ),
            Field::make( 'text', 'client_id', __( 'Public Key', 'webgurus-mautic' ) ) ->set_required( true ),
            Field::make( 'text', 'client_secret', __( 'Secret Key', 'webgurus-mautic' ) )->set_required( true ),
            Field::make( 'select', 'emails', __( 'Emails for Selection', 'webgurus-mautic' ) )
                ->set_help_text(  __( 'Select which email models will show up in the select fields of all the subsequent settings pages.', 'webgurus-mautic' ))
                ->set_options([
                    't' => __( 'Template Emails', 'webgurus-mautic' ),
                    's' => __( 'Segment Emails', 'webgurus-mautic' ),
                    'a' => __( 'All Emails', 'webgurus-mautic' )                  
                ])
        ))
        ->set_datastore( new Oauth_Datastore());

        //-----------------Comment Section --------------------------
        self::$comments_ds = new Options_Datastore('wg_mautic_comments');
        self::$comments_pg = Container::make( 'theme_options', 'wg_mautic_comments',  __( 'Comment Section', 'webgurus-mautic' ) )
            ->set_page_parent( MainMenu::$mainmenu ) 
            ->set_page_menu_title('- ' . __( 'Comment Section', 'webgurus-mautic' ) )
            ->add_fields( self::add_MauticFields([
                Field::make( 'checkbox', 'enabled', __( 'Enable Checkbox in Comment Section for Newsletter Signup', 'webgurus-mautic' ) ) ->set_option_value( 'yes' ),
                Field::make( 'text', 'text', __( 'Text that appears on Checkbox to ask for the Newsletter Signup', 'webgurus-mautic' ) )->set_default_value( __( 'Sign me up for the newsletter', 'webgurus-mautic' ) ),
            ]))
            ->set_datastore(self::$comments_ds);

        //--------------------WooCommerce Settings ----------------------
        if ( class_exists( 'WooCommerce' ) ) {
            self::$woocommerce_ds = new Options_Datastore('wg_mautic_woocommerce');
            self::$woocommerce_pg = Container::make( 'theme_options', 'wg_mautic_woocommerce', 'WooCommerce' )
                ->set_page_parent( MainMenu::$mainmenu )
                ->set_page_menu_title( '- WooCommerce' )
                ->add_fields( self::add_MauticFields([
                    Field::make( 'checkbox', 'enabled', __( 'Enable Checkbox in WooCommerce Checkout for Newsletter Signup', 'webgurus-mautic' ) ) ->set_option_value( 'yes' ),
                    Field::make( 'text', 'text', __( 'Text that appears on Checkbox to ask for the Newsletter Signup', 'webgurus-mautic' ) )->set_default_value( __( 'Sign me up for the newsletter', 'webgurus-mautic' ) ),
                    Field::make( 'html', 'wg_information_text' )
                    ->set_html( sprintf('<div><h3>%s</h3>
                    <p>%s</p>
                    <ol>
                        <li>%s</li>
                        <li>%s</li>
                        <li>%s</li>
                        <li>%s</li>
                    </ol></div>',
                    __( 'Field Mapping', 'webgurus-mautic' ),
                    __( 'We always match the following fields:', 'webgurus-mautic' ),
                    __( 'First name', 'woocommerce' ),
                    __( 'Last name', 'woocommerce' ),
                    __( 'Email address', 'woocommerce' ),
                    __( 'Phone Number', 'woocommerce' )
                    )),
                    Field::make( 'select', 'address', __( 'Address to Match', 'webgurus-mautic' ) )
                        ->set_help_text( __( 'If Company field is filled out, the alternate address may be consulted for the home address in this case.', 'webgurus-mautic') )
                        ->set_options( array(
                            'billing' => __( 'Billing Address', 'woocommerce' ),
                            'shipping' => __( 'Shipping Address', 'woocommerce' ),
                            'manual' => __( 'Manual Mapping', 'webgurus-mautic' ),
                        )),

                    Field::make( 'complex', 'fields', __( 'Field Mapping', 'webgurus-mautic' ) )
                        ->add_fields( array(
                            Field::make( 'select', 'wc_field', __( 'WooCommerce Field', 'webgurus-mautic' ) ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getWCFields' )),
                            Field::make( 'select', 'mautic_field', __( 'Mautic Field', 'webgurus-mautic' ) ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getFields' )),
                        )),
                ]))
                ->set_datastore(self::$woocommerce_ds);
        }

        //--------------------Confirmation Pages Settings ----------------------
        self::$pages_ds = new Options_Datastore('wg_mautic_pages', true);
        self::$pages_pg = Container::make( 'theme_options', 'wg_mautic_pages', __( 'Confirmation Pages' , 'webgurus-mautic') )
            ->set_page_parent( MainMenu::$mainmenu )
            ->set_page_menu_title('- ' .  __( 'Confirmation Pages' , 'webgurus-mautic') )
            ->add_fields( [
                Field::make( 'html', 'confirmation_info' ) ->set_html( sprintf(__( 'In the confirmation email add a button with the link to the page and the parameter %s at the end.' , 'webgurus-mautic'), '<b>?id={contactfield=id}&email={contactfield=email}</b>')),
                Field::make( 'complex', 'fields', __( 'Page Definitions', 'webgurus-mautic' ) )
                    ->add_fields( self::add_MauticFields([
                        Field::make( 'select', 'page', __( 'Name of Confirmation Page' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getPages' ) ),
                        Field::make( 'wg_mautic_link', 'confirmation_link' ),
                        Field::make( 'select', 'confirm_check', __( 'Segment Check' , 'webgurus-mautic') ) ->set_options( [
                            's' =>  __( 'Check if subscribed' , 'webgurus-mautic'),
                            'u' =>  __( 'Check if unsubscribed' , 'webgurus-mautic'),
                            'n' =>  __( 'No Segment Check' , 'webgurus-mautic') 
                        ]),
                        Field::make( 'select', 'confirm_segment', __( 'Segment to confirm' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
                        Field::make( 'text', 'confirmed_text', __( 'Text to show if confirmation action will be taken (segment check false)', 'webgurus-mautic' ) ),
                        Field::make( 'text', 'resolved_text', __( 'Text to show if confirmation action already resolved (segment check already true)', 'webgurus-mautic' ) ),
                    ]))
            ])
            ->set_datastore(self::$pages_ds);



        //---------------Execution Status Page-----------------
        self::$status_pg = Container::make( 'custom_page', 'wg_mautic_status', __( 'Execution Logs', 'webgurus-mautic' ))
            ->set_page_parent( MainMenu::$mainmenu )
            ->set_page_menu_title('- ' . __( 'Execution Logs', 'webgurus-mautic' ) )
            ->add_fields ([
                Field::make( 'html', 'wg_status_text' ) ->set_html( array('\Webgurus\Admin\MauticAdmin', 'getStatus') ), 
            ])
            ->set_sidebar (sprintf(
                '
                    <div id="submitdiv" class="postbox">
                        <h3>%s</h3>

                        <div id="major-publishing-actions">

                            <div id="publishing-action">
                                <span class="spinner"></span>
                                <input type="submit" value="%s" name="process" id="process" class="button button-primary button-large">
                            </div>
                            <div style="margin-bottom: 40px;">&nbsp;</div>
                            <div id="publishing-action">
                                <span class="spinner"></span>
                                <input type="submit" value="%s" name="delete" id="delete" class="button button-primary button-large">
                            </div>

                            <div class="clear"></div>
                        </div>
                    </div>
                ',
                __( 'Actions', 'carbon-fields' ),
                __( 'Process Failed Requests' , 'webgurus-mautic'),
                __( 'Trash Failed Requests' , 'webgurus-mautic')
            )); 
    }

    static function add_MauticFields($fields) {
        return array_merge($fields, [
            Field::make( 'text', 'tags', __( 'Lead Tags', 'webgurus-mautic' ) )->set_help_text( __( 'Enter a comma separated list of tags to add. Adding a minus before the tag will remove it.', 'webgurus-mautic' )),
            Field::make( 'multiselect', 'add_campaigns', __( 'Add to Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
            Field::make( 'multiselect', 'remove_campaigns', __( 'Remove from Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
            Field::make( 'multiselect', 'add_segments', __( 'Add to Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
            Field::make( 'multiselect', 'remove_segments', __( 'Remove from Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
            Field::make( 'radio', 'do_not_contact', __( 'Do not Contact (Email Channel)', 'webgurus-mautic' ) ) ->set_options( array('add' => __( 'Add' , 'webgurus-mautic'), 'remove' => __( 'Remove' , 'webgurus-mautic'), '' => __( 'Maintain Unchanged' , 'webgurus-mautic'))),
            Field::make( 'select', 'send_email', __( 'Send Email' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getEmails' ) )
        ]);
    }

    //---------------Create Message for HTML field depending on OAuth Status------------------
    static function getOAuthStatus() {
        $api = API::instance();
        //-----OAuth Error Message--------------
        if (isset($_REQUEST['error'])) {
            return sprintf(
                '<h3>%s</h3>
                <p>%s <b>%s</b></p>
                <p>%s</p>',
            __( 'An Error occured while authenticating.', 'webgurus-mautic' ), 
            __( 'Error message:', 'webgurus-mautic' ), 
            $_REQUEST['error'],
            __( 'Please check the connection to your Mautic instance and the correctness of the keys.', 'webgurus-mautic' ));
        }
        //-----Authentication Confirmation
        elseif ($api->settings['status'] == true) {
            $fields = self::$main_pg->get_fields();
            for ($i=1; $i <= 3; $i++) {
                $fields[$i]->set_attribute( 'readOnly', true );
            }
            if ($api->settings['ffMauticSettings']) {
                $msg = __( 'Found settings of Fluent Forms for Mautic Plugin', 'webgurus-mautic' );            
            }
            else {
                $msg = __( 'You have successfully authenticated with Mautic', 'webgurus-mautic' );
            }
            return 
                '<style>
                .webgurus-checkmark {
                    display: flex; 
                    border-radius: 50%; 
                    width: 58px; 
                    height: 58px; 
                    justify-content: center; 
                    align-items: center; 
                    background-color: #00b27f; 
                    color: #fff; 
                    font-size: 28px;
                }

                .webgurus-button-ok {
                    background-color: #1a7efb;
                    color: #fff;
                    border-radius: 7px;
                    border: 0px;
                    padding: 12px 20px;
                }

                .webgurus-button-ok:hover {
                    background-color: #136ecc; 
                }

                .webgurus-button-ok:active {
                    background-color: #94ceff; 
                }

                .webgurus-button-warning {
                    background-color: #ff6154;
                    color: #fff;
                    border-radius: 7px;
                    border: 0px;
                    padding: 12px 20px;
                }

                .webgurus-button-warning:hover {
                    background-color: #d74a42; 
                }

                .webgurus-button-warning:active {
                    background-color: #ffa08c; 
                }
                </style>'.sprintf('
                <h3>%s</h3>
                <div align = "center"><i class="webgurus-checkmark">&check;</i> 
                <p>%s</p> 
                <button type="button" class="webgurus-button-ok" onclick="window.location.href=%s">%s</button> 
                <button type="button" class="webgurus-button-warning" onclick="window.location.href=%s">%s</span></button></div>',
                __( 'Mautic API is connected', 'webgurus-mautic' ), 
                $msg,
                "'" . $api->callBackUrl . "'",
                __( 'Verify Connection Again', 'webgurus-mautic' ), 
                "'" . admin_url('admin.php?page=crb_carbon_fields_container_wg_mautic.php&reset=1') . "'",
                __( 'Disconnect Mautic', 'webgurus-mautic' )    
            ); 

        } 
        else {
            //-------------Instructions how to Authenticate--------------
            return sprintf(
                '<h4>%s</h4>
            <ol>
                <li>%s</li>
                <li>%s <b>%s</b></li>
                <li>%s</li>
            </ol>', 
            __( 'To Authenticate Mautic you have to configure your API connection first', 'webgurus-mautic' ), 
            __( 'Go to Your Mautic account dashboard, Click on the gear icon next to the username on top right corner. Click on Configuration settings >> API settings and enable the API.', 'webgurus-mautic' ), 
            __( 'Then go to "API Credentials" and create a new oAuth 2 credentials, putting as redirect URL:', 'webgurus-mautic' ), 
            admin_url('?wg_mautic_auth=1'),
            __( 'Paste the Mautic Base URL of your installation, also paste the Public Key and Secret Key. Then click: Save Changes.', 'webgurus-mautic' ));
        }
    }

    //---------------Create Message for HTML field of Execution Status------------------
    static function getStatus() {
        global $wpdb;
        //we are processing failed request. Just write this message and inject the div, that the above Javascript will write to.
        if (isset($_POST['process'])) {

            return sprintf("<h1>%s</h1>
            <p>%s</p>
            <div id='wg_mautic_ajax'></div>",
            __( 'Process Failed Requests', 'webgurus-mautic' ),
            __( 'Starting the processing of failed requests.', 'webgurus-mautic' ));
        }
        else {
            $records = Record::get_results("status <= 0 AND command IN ('subscribe', 'confirm')");

            $table_name = Record::table_name();
            $sql = $wpdb->prepare("SELECT s1.*, s2.Parms FROM $table_name s1 INNER JOIN $table_name s2 ON s1.parent_ID = s2.id WHERE s2.status = 1 AND s1.status <= 0");
            $commands = $wpdb->get_results($sql, ARRAY_A);

            $processedcount = Record::get_count("status = 1 AND command IN ('subscribe', 'confirm')");

            $format = get_option('date_format') . ' ' . get_option('time_format');

            $msg = sprintf(
                '<p>%s: %s<br>%s: %s<br>%s: %s<br></p>
                <h1>%s</h1>
                <style>
                    .webgurus-table {
                    border-collapse: collapse;
                    }

                    .webgurus-table th, .webgurus-table td {
                    padding: 3px;
                    border: 1px solid #000;
                    }


                    .webgurus-table thead th {
                        background-color: #ddd; /* Light grey for header */
                    }
                    
                    .webgurus-table tbody tr:nth-child(even) {
                        background-color: #f5f5f5; /* Very light grey for even rows */
                    }

                </style>
                <table class="webgurus-table">
                <thead>
                    <tr>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    </tr>
                </thead>

                <tbody>',
                __( 'Processed Submissions', 'webgurus-mautic' ),
                $processedcount,
                __( 'Unprocessed Submissions', 'webgurus-mautic' ),
                count($records),
                __( 'Unprocessed Commands of Processed Submissions', 'webgurus-mautic' ),
                count($commands),
                __( 'Unprocessed Submissions', 'webgurus-mautic' ),
                __( 'ID', 'webgurus-mautic' ),
                __( 'Command', 'webgurus-mautic' ),
                __( 'Date', 'webgurus-mautic' ),
                __( 'Error Message', 'webgurus-mautic' ),
                __( 'Email', 'webgurus-mautic' ),
                __( 'Retries', 'webgurus-mautic' ));
                
            foreach ($records as $record) {
                $date = $record->time->format($format);
                $msg .= sprintf('
                        <tr>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                        </tr>',
                        $record->id,
                        $record->command,
                        $date,
                        $record->error,
                        $record->parms['email'],
                        -$record->status);
            }
            $msg .= sprintf( '
                    </tbody>
                    </table>
                    <h1>%s</h1>
                    <table class="webgurus-table">
                        <thead>
                            <tr>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                            </tr>
                        </thead>
                    <tbody>',
                    __( 'Unprocessed Commands of Processed Submissions', 'webgurus-mautic' ),
                __( 'ID', 'webgurus-mautic' ),
                __( 'Parent ID', 'webgurus-mautic' ),
                __( 'Command', 'webgurus-mautic' ),
                __( 'Date', 'webgurus-mautic' ),
                __( 'Error Message', 'webgurus-mautic' ),
                __( 'Email', 'webgurus-mautic' ),
                __( 'Retries', 'webgurus-mautic' ));

            foreach ($commands as $result) {
                $command = new Record($result);
                $parms = maybe_unserialize($result['Parms']);
                $msg .= sprintf('
                <tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                </tr>',
                $command->id,
                $command->parent_ID,
                $command->command,
                $command->time->format($format),
                $command->error,
                $parms['email'],
                -$command->status);
            }
            $msg .= sprintf('
                    </tbody>
                </table>
                <h1>%s</h1>
     
                <table class="webgurus-table">
                <thead>
                    <tr>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    <th>%s</th>
                    </tr>
                </thead>

                <tbody>',
                __( 'Last Processed Submissions', 'webgurus-mautic' ),
                __( 'ID', 'webgurus-mautic' ),
                __( 'Command', 'webgurus-mautic' ),
                __( 'Date', 'webgurus-mautic' ),
                __( 'Email', 'webgurus-mautic' ),
                __( 'Contact ID', 'webgurus-mautic' ));

            $records = Record::get_results("status = 1 AND command IN ('subscribe', 'confirm') ORDER BY time DESC LIMIT 100");
            foreach ($records as $record) {
                $date = $record->time->format($format);
                $msg .= sprintf('
                        <tr>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                            <td>%s</td>
                        </tr>',
                        $record->id,
                        $record->command,
                        $date,
                        $record->parms['email'],
                        $record->contact_ID);
            }
            return $msg;
        }

    }


    static function getfields () {
        $api = API::instance();
        return $api->getFields();
    }

    static function getCampaigns() {
        $api = API::instance();
        return $api->getCampaigns();
    }

    static function getSegments() {
        $api = API::instance();
        return $api->getSegments();
    }

    static function getEmails() {
        $api = API::instance();
        return $api->getEmails( true );
    }

    static function getPages() {
        if (empty (self::$pages)) {
        $args = array(
            'post_type' => 'page', // Specify page post type
            'orderby' => 'title', // Order by title
            'order' => 'ASC', // Sort ascending (alphabetical)
            'posts_per_page' => -1, // Get all pages
        );
        $all_pages = new \WP_Query($args);
        if (!$all_pages->have_posts()) return array();

        $sorted_pages = array();
        while ($all_pages->have_posts()) {
            $all_pages->the_post();
            $sorted_pages[get_the_ID()] = get_the_title();
        }

        wp_reset_postdata(); // Reset post data
        self::$pages = $sorted_pages;
        return $sorted_pages;
        }
        return self::$pages;
    }


    static function getWCfields () {
        $checkout = WC()->checkout();
        $result = $checkout->get_checkout_fields();
        $fields = [];
        foreach ($result as $skey => $sections) {
            switch ($skey) {
                case 'billing':
                    $prefix = __( 'Billing', 'woocommerce' ) . ': ';
                    break;

                case 'shipping':
                    $prefix = __( 'Shipping', 'woocommerce' ) . ': ';
                    break;

                default:
                    $prefix = '';
            }

            foreach ($sections as $key => $value) {
                if ($key == 'fields') {
                    foreach ($value as $key2 => $value2) {
                        $fields [$key2] =$prefix . $value2['label'];
                    }
                }
                else {
                    $fields [$key] = $prefix . $value['label'];
                }
            }
        }
        return $fields;
    }

}

