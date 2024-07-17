<?php

namespace Webgurus\Admin;

use WebgurusMautic\Integrations;
use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Datastore\Datastore;

add_action('admin_init', function() {
    if (isset($_REQUEST['ff_mautic_auth']) || isset($_REQUEST['wg_mautic_auth'])) {
        $api = MauticAdmin::getRemoteClient();
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

    //--------------------Finding the OAuth Settings Page---------------
    if (isset($_REQUEST['page']) && ($_REQUEST['page'] == 'crb_carbon_fields_container_wg_mautic.php')) {
        
        add_action( 'admin_print_scripts', function($hook) {
            ?>
            <style>
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
            </style>
            <?php
        });


        //--------------Reseting the OAuth Data--------
        if (isset($_REQUEST['reset'])) {
            $api = MauticAdmin::getRemoteClient();
            $settings['ffMauticSettings'] = false;
            $settings['status'] = false;
            $settings['client_id'] = '';
            $settings['client_secret'] = '';
            $api->updateSettings ($settings);

            wp_redirect(admin_url('admin.php?page=crb_carbon_fields_container_wg_mautic.php'));
            die();
        }
    }

    add_action( 'carbon_fields_theme_options_container_saved', function($user_data, $class) {
        switch ($class->id) {
            case 'carbon_fields_container_wg_mautic':
                $api = MauticAdmin::getRemoteClient();
                $api->updateSettings($api->settings);
                $api->redirectToAuthServer();
                die();

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
        $api = MauticAdmin::getRemoteClient();
        $key = $field->get_base_name();
        $value = $api->settings[$key];
        return $value;
    }

    public function save( \Carbon_Fields\Field\Field $field ) {
        $api = MauticAdmin::getRemoteClient();
        $key = $field->get_base_name();
        $value = $field->get_value();
        $api->settings[$key] = $value;
        //$api->updateSettings($api->settings);
    }

    public function delete( \Carbon_Fields\Field\Field $field ) {
    }

}

/**
 * Stores Settings of other pages in one option key
 */
class Settings_Datastore extends Datastore {

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

    public function load( \Carbon_Fields\Field\Field $field ) {
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

    public function save( \Carbon_Fields\Field\Field $field ) {
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

    public function delete( \Carbon_Fields\Field\Field $field ) {
    }

}

if (!class_exists('Webgurus\Admin\MainMenu')) {
    Class MainMenu {
        public static $mainmenu;
        public static function Boot() {
            self::$mainmenu = Container::make( 'theme_options', 'webgurus', 'WebGurus')
            ->add_fields ([ Field::make('html', 'webgurus_instruction')
                ->set_html(sprintf('<style>
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

            .webgurus-centered-div {
                 max-width: 700px;
                margin: 0 auto;
                text-align: center;  
            }
            </style>
            <p>%s</p>
            <p><b><a href = "https://www.webgurus.net/wordpress/plugins/" target = "_blank">%s</a></b></p>
            <p><b><a href = "https://www.webgurus.net/blog/" target = "_blank">%s</a></b></p>
            <div class = "webgurus-centered-div">
            <p style="font-size: 1.2em;"><b>%s</b></p> 
            <button type="button" class="webgurus-button-ok" onclick="window.location.href=%s">%s</button> 
            </div>',
            __( 'Welcome to WebGurus WordPress plugins. We are trying to make your life on WordPress more productive.', 'webgurus-mautic' ),
                __( 'Find Other Plugins', 'webgurus-mautic' ),
                __( 'Read the Blog', 'webgurus-mautic' ),
                __( 'If you want to stay up to date about plugin updates and news around WordPress and Marketing Automation, sign up for our newsletter.', 'webgurus-mautic' ),
                "'https://www.webgurus.net/newsletter/'",
                __( 'Sign up Now', 'webgurus-mautic' )
                ))
            ]);
        }    
    }
}


Class MauticAdmin {

    public static $main;
    public static $pages;

    public static $comments_ds;
    public static $woocommerce_ds;
    public static $pages_ds;

    public static function Boot() {
        //----------Webgurus Screen----------
        if (empty(MainMenu::$mainmenu)) MainMenu::boot();

        //--------------------OAuth Settings -------------
        self::$main = Container::make( 'theme_options', 'wg_mautic', __( 'Forms to Mautic', 'webgurus-mautic' ))
        ->set_page_parent( MainMenu::$mainmenu )
        ->add_fields (array (
            Field::make( 'html', 'wg_information_text' ) ->set_html( array('\Webgurus\Admin\MauticAdmin', 'getOAuthStatus') ), 
            Field::make( 'text', 'api_url', __( 'Your Mautic Base URL', 'webgurus-mautic' ) ) ->set_required( true ),
            Field::make( 'text', 'client_id', __( 'Public Key', 'webgurus-mautic' ) ) ->set_required( true ),
            Field::make( 'text', 'client_secret', __( 'Secret Key', 'webgurus-mautic' ) )->set_required( true ),
        ))
        ->set_datastore( new Oauth_Datastore());

        //-----------------Comment Section --------------------------
        self::$comments_ds = new Settings_Datastore('wg_mautic_comments');
        Container::make( 'theme_options', 'wg_mautic_comments', '- ' . __( 'Comment Section', 'webgurus-mautic' ) )
        ->set_page_parent( MainMenu::$mainmenu ) 
        ->add_fields( array(
            Field::make( 'checkbox', 'enabled', __( 'Enable Checkbox in Comment Section for Newsletter Signup', 'webgurus-mautic' ) ) ->set_option_value( 'yes' ),
            Field::make( 'text', 'text', __( 'Text that appears on Checkbox to ask for the Newsletter Signup', 'webgurus-mautic' ) )->set_default_value( __( 'Sign me up for the newsletter', 'webgurus-mautic' ) ),
            Field::make( 'text', 'tags', __( 'Lead Tags', 'webgurus-mautic' ) )->set_help_text( __( 'Enter a comma separated list of tags to add. Adding a minus before the tag will remove it.', 'webgurus-mautic' )),
            Field::make( 'multiselect', 'add_campaigns', __( 'Add to Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
            Field::make( 'multiselect', 'remove_campaigns', __( 'Remove from Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
            Field::make( 'multiselect', 'add_segments', __( 'Add to Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
            Field::make( 'multiselect', 'remove_segments', __( 'Remove from Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
            Field::make( 'radio', 'do_not_contact', __( 'Do not Contact (Email Channel)', 'webgurus-mautic' ) ) ->set_options( array('add' => __( 'Add' , 'webgurus-mautic'), 'remove' => __( 'Remove' , 'webgurus-mautic'), '' => __( 'Maintain Unchanged' , 'webgurus-mautic'))),
            Field::make( 'select', 'send_email', __( 'Send Email' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getEmails' ) )
        ))
        ->set_datastore(self::$comments_ds);

        //--------------------WooCommerce Settings ----------------------
        if ( class_exists( 'WooCommerce' ) ) {
            self::$woocommerce_ds = new Settings_Datastore('wg_mautic_woocommerce');
            Container::make( 'theme_options', 'wg_mautic_woocommerce', '- WooCommerce' )
                ->set_page_parent( MainMenu::$mainmenu )
                ->add_fields( array(
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
                    Field::make( 'text', 'tags', __( 'Lead Tags', 'webgurus-mautic' ) )->set_help_text( __( 'Enter a comma separated list of tags to add. Adding a minus before the tag will remove it.', 'webgurus-mautic' )),
                    Field::make( 'multiselect', 'add_campaigns', __( 'Add to Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
                    Field::make( 'multiselect', 'remove_campaigns', __( 'Remove from Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
                    Field::make( 'multiselect', 'add_segments', __( 'Add to Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
                    Field::make( 'multiselect', 'remove_segments', __( 'Remove from Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
                    Field::make( 'radio', 'do_not_contact', __( 'Do not Contact (Email Channel)', 'webgurus-mautic' ) ) ->set_options( array('add' => __( 'Add' , 'webgurus-mautic'), 'remove' => __( 'Remove' , 'webgurus-mautic'), '' => __( 'Maintain Unchanged' , 'webgurus-mautic'))),
                    Field::make( 'select', 'send_email', __( 'Send Email' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getEmails' ) )
                ))
                ->set_datastore(self::$woocommerce_ds);
        }

        //--------------------Confirmation Pages Settings ----------------------
        self::$pages_ds = new Settings_Datastore('wg_mautic_pages', true);
        Container::make( 'theme_options', 'wg_mautic_pages', '- ' . __( 'Confirmation Pages' , 'webgurus-mautic') )
            ->set_page_parent( MainMenu::$mainmenu )
            ->add_fields( array(
                Field::make( 'complex', 'fields', __( 'Page Definitions', 'webgurus-mautic' ) )
                ->add_fields( array(
                    Field::make( 'select', 'page', __( 'Name of Confirmation Page' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getPages' ) ),
                    Field::make( 'select', 'confirm_check', __( 'Segment Check' , 'webgurus-mautic') ) ->set_options( [
                        's' =>  __( 'Check if subscribed' , 'webgurus-mautic'),
                        'u' =>  __( 'Check if unsubscribed' , 'webgurus-mautic'),
                        'n' =>  __( 'No Segment Check' , 'webgurus-mautic') 
                    ]),
                    Field::make( 'select', 'confirm_segment', __( 'Segment to confirm' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
                    Field::make( 'text', 'confirmed_text', __( 'Text to show if confirmation action will be taken (segment check false)', 'webgurus-mautic' ) ),
                    Field::make( 'text', 'resolved_text', __( 'Text to show if confirmation action already resolved (segment check already true)', 'webgurus-mautic' ) ),
                    Field::make( 'text', 'tags', __( 'Lead Tags', 'webgurus-mautic' ) )->set_help_text( __( 'Enter a comma separated list of tags to add. Adding a minus before the tag will remove it.', 'webgurus-mautic' )),
                    Field::make( 'multiselect', 'add_campaigns', __( 'Add to Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
                    Field::make( 'multiselect', 'remove_campaigns', __( 'Remove from Campaigns' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getCampaigns' ) ),
                    Field::make( 'multiselect', 'add_segments', __( 'Add to Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
                    Field::make( 'multiselect', 'remove_segments', __( 'Remove from Segments' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getSegments' ) ),
                    Field::make( 'radio', 'do_not_contact', __( 'Do not Contact (Email Channel)', 'webgurus-mautic' ) ) ->set_options( array('add' => __( 'Add' , 'webgurus-mautic'), 'remove' => __( 'Remove' , 'webgurus-mautic'), '' => __( 'Maintain Unchanged' , 'webgurus-mautic'))),
                    Field::make( 'select', 'send_email', __( 'Send Email' , 'webgurus-mautic') ) ->set_options( array('\Webgurus\Admin\MauticAdmin', 'getEmails' ) )
                ))
            ))
            ->set_datastore(self::$pages_ds);

        Container::make( 'theme_options', 'wg_mautic_status', '- ' .__( 'Execution Status', 'webgurus-mautic' ))
            ->set_page_parent( MainMenu::$mainmenu )
            ->add_fields (array (
                Field::make( 'html', 'wg_status_text' ) ->set_html( array('\Webgurus\Admin\MauticAdmin', 'getStatus') ), 
            ));
        
    }

    //---------------Create Message for HTML field depending on OAuth Status------------------
    static function getOAuthStatus() {
        $api = self::getRemoteClient();
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
            $fields = self::$main->get_fields();
            for ($i=1; $i <= 3; $i++) {
                $fields[$i]->set_attribute( 'readOnly', true );
            }
            if ($api->settings['ffMauticSettings']) {
                $msg = __( 'Found settings of Fluent Forms for Mautic Plugin', 'webgurus-mautic' );            
            }
            else {
                $msg = __( 'You have successfully authenticated with Mautic', 'webgurus-mautic' );
            }
            return sprintf(
                '<h3>%s</h3>
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
    
    $records = Record::get_results("status <= 0 AND command IN ('subscribe', 'confirm')");

    $sql = $wpdb->prepare('SELECT s1.*, s2.contact_id, s2.Parms FROM %1$s s1 INNER JOIN %1$s s2 ON s1.parent_ID = s2.id WHERE s2.status = 1 AND s1.status <= 0', Record::table_name());
    $commands = $wpdb->get_results($sql, ARRAY_A);
    $format = get_option('date_format') . ' ' . get_option('time_format');

    $msg = sprintf(
        '<p>%s</p>
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
        sprintf(__( 'There are %s unprocessed submissions and %s unprocessed commands from already processed submissions.', 'webgurus-mautic' ), count($records), count($commands)),
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
    </tr>
  </thead>

  <tbody>',
  __( 'Unprocessed Commands', 'webgurus-mautic' ),
  __( 'ID', 'webgurus-mautic' ),
  __( 'Command', 'webgurus-mautic' ),
  __( 'Date', 'webgurus-mautic' ),
  __( 'Error Message', 'webgurus-mautic' ),
  __( 'Email', 'webgurus-mautic' ),
  __( 'Retries', 'webgurus-mautic' ));

    foreach ($commands as $result) {
        $command = new Record($result);
        $msg .= sprintf('
        <tr>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
            <td>%s</td>
        </tr>',
        $command->id,
        $command->command,
        $command->time->format($format),
        $command->error,
        $command->Parms['email'],
        -$command->status);
    }
    $msg .= '
        </tbody>
    </table>';
    return $msg;
}


    static function getfields () {
        $api = self::getRemoteClient();
        return $api->getFields();
    }

    static function getCampaigns() {
        $api = self::getRemoteClient();
        return $api->getCampaigns();
    }

    static function getSegments() {
        $api = self::getRemoteClient();
        return $api->getSegments();
    }

    static function getEmails() {
        $api = self::getRemoteClient();
        return $api->getEmails( true );
    }


    static function getRemoteClient()
    {
        return \WebgurusMautic\Integrations\API::instance();
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