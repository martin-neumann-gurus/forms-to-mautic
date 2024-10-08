<?php
namespace Webgurus\Mautic;

// ------------Main class. Instantiate always with  $api = API::instance();--------------------
class API
{
    protected static $instance = null;  //Instance for Singleton class
    protected $api_url = '';            //Mautic API URL
    protected $clientId = null;
    protected $clientSecret = null;
    public $optionKey = 'wg_mautic_connection';        //Key we are saving options in WP table
    public $callBackUrl = null;     //Callback URL used for OAuth Authentication
    public $settings = [];          //Loaded settings from Option table
    protected $scheduled = false;   //Changes to true after the class schedules an API call, to trigger action at shutdown

    //Buffers for not needing to repeat the API calls
    protected $campaigns = null;
    protected $segments = null;
    protected $fields = null;
    protected $emails = null;

    public function __construct()
    {
        $this->callBackUrl = admin_url('?wg_mautic_auth=1');
        if ( false === ( $settings = get_option('wg_mautic_connection') ) ) {  //check for default plugin settings
            if ( false === ( $settings = get_option("_fluentform_mautic_settings") ) ) {  //check for settings of Fluentforms for Mautic
                $settings = [];
            }
            else {
                // import Fluent Form for Mautic settings
                $settings['api_url'] = $settings['apiUrl'];
                unset($settings['apiUrl']);
                $settings['ffMauticSettings'] = true;
                $this->updateSettings($settings);
            }
        }
        
        $defaults = [
            'api_url'       => '',
            'client_id'     => '',
            'client_secret' => '',
            'emails'        => 'c',
            'status'        => false,
            'access_token'  => '',
            'refresh_token' => '',
            'expire_at'     => false,
            'ffMauticSettings' => false,
            'db_ver'        => 1,
            'mautic_ver'    => '5.0'
        ];

        $settings = wp_parse_args($settings, $defaults);
        if ($settings['ffMauticSettings'] == true) $this->callBackUrl = admin_url('?ff_mautic_auth=1');
        $this->settings = $settings;
        $this->initialize();
        

        add_filter ('http_request_timeout', function ($timeout, $url) {
            $pos = strpos($url, $this->api_url);
            if ($pos === 0) return 20;
            return $timeout;
        }, 10, 2);

        add_action('shutdown', function () {
            if ($this->scheduled) {
                //$this->process_pending();
                //----Make an asynchronous AJAX request that will trigger the API calls
                $url_parts = parse_url(admin_url( 'admin-ajax.php' ));
                $socket = fsockopen('localhost', 80, $errno, $errstr, 2);
                if (!$socket) {
                    $socket = fsockopen('ssl://localhost', 443, $errno, $errstr, 2);
                    if (!$socket) {
                        //Fallback if for some reasons it cannot establish a socket connection
                        $args = array(
                            'blocking'  => false,
                            'timeout'   => 0.3,
                            'sslverify' => false
                        );
                        $result = wp_remote_post( admin_url( 'admin-ajax.php' ).'?action=wg_mautic_process', $args );
                        return;
                    }
                }
                // Construct the HTTP GET request
                $request = "GET " . $url_parts['path'] . '?action=wg_mautic_process' . " HTTP/1.1\r\n";
                $request .= "Host: localhost\r\n";
                $request .= "Connection: Close\r\n\r\n";

                // Send the request
                fwrite($socket, $request);
                fclose($socket);
            }
        });
    }

    public function initialize() {
        $settings = $this->settings;
        $api_url = $settings['api_url'];
        if (substr($api_url, -1) == '/')  $api_url = substr($api_url, 0, -1);
        $this->api_url = $api_url;
        
        $this->clientId = $settings['client_id'];
        $this->clientSecret = $settings['client_secret'];
    }

    public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    public function updateSettings($settings) {
        update_option($this->optionKey, $settings, 'no');
        $this->settings = $settings;
        $this->initialize();
    }

    public function redirectToAuthServer()
    {
        $url = add_query_arg([
            'client_id'     => $this->clientId,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->callBackUrl,
            'response_type' => 'code',
            'state'         => md5($this->clientId)
        ], $this->api_url . '/oauth/v2/authorize');

        wp_redirect($url);
        exit();
    }

    public function generateAccessToken($code)
    {
        $response = wp_remote_post($this->api_url . '/oauth/v2/token', [
            'body' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->callBackUrl,
                'code'          => $code
            ]
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body['error_description'])) {
            return new \WP_Error('invalid_client', $body['error_description']);
        }

        if (isset($body['errors'])) {
            return new \WP_Error('invalid_client', $body['errors'][0]['message']);
        }

        if (wp_remote_retrieve_response_code($response) == 200) {
            $this->settings['access_token'] = $body['access_token'];
            $this->settings['refresh_token'] = $body['refresh_token'];
            $this->settings['expire_at'] = time() + intval($body['expires_in']);
            return $this->settings;
        }
        else {
            return new \WP_Error('invalid_client', 'Error at OAuth Access Token generation.');
        }


    }
    ///-----------------Routine to make the API request to Mautic--------------
    public function makeRequest($action, $data = array(), $method = 'GET')
    {
        $settings = $this->getApiSettings();
        if (is_wp_error($settings)) {
            return $settings;
        }

        $url = $this->api_url . '/api/' . $action;

        $headers = [
            'Authorization'  => " Bearer ". $settings['access_token'],
        ];

        if ($method == 'GET') {
            $response = wp_remote_get($url, [
                'headers' => $headers
            ]);
        } else{
            $response = wp_remote_request($url, [
                'headers' => $headers,
                'body' => $data,
                'method' => $method
            ]);
        }

        if (!$response) {
            return new \WP_Error('invalid', 'Request could not be performed');
        }

        if (is_wp_error($response)) {
            return new \WP_Error('wp_error', $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $body = \json_decode($body, true);

        if (isset($body['errors'])) {
            if (!empty($body['errors'][0]['message'])) {
                $message = $body['errors'][0]['message'];
            } elseif (!empty($body['error_description'])) {
                $message = $body['error_description'];
            } else {
                $message = 'Unspecified error when sending API Request';
            }

            return new \WP_Error('request_error', $message);
        }
        if (wp_remote_retrieve_response_code($response) < 202) {
            return $body;
        }
        else {
            return new \WP_Error('request_error', 'Error in sending the API request. Response:' .$response['response']['message']);
        }

        
    }

    protected function getApiSettings()
    {
        $response = $this->maybeRefreshToken();
        if (is_wp_error($response)) return $response;

        $apiSettings = $this->settings;

        if (!$apiSettings['status'] || !$apiSettings['expire_at']) {
            return new \WP_Error('invalid', 'API key is invalid');
        }

        return array(
            'baseUrl'       => $this->api_url,       // Base URL of the Mautic instance
            'version'       => 'OAuth2', // Version of the OAuth can be OAuth2 or OAuth1a. OAuth2 is the default value.
            'clientKey'     => $this->clientId,       // Client/Consumer key from Mautic
            'clientSecret'  => $this->clientSecret,       // Client/Consumer secret key from Mautic
            'callback'      => $this->callBackUrl,        // Redirect URI/Callback URI for this script
            'access_token'  => $apiSettings['access_token'],
            'refresh_token' => $apiSettings['refresh_token'],
            'expire_at'     => $apiSettings['expire_at']
        );
    }

    protected function maybeRefreshToken()
    {
        $settings = $this->settings;
        $expireAt = $settings['expire_at'];

        if ($expireAt && $expireAt <= (time() - 10)) {
            // we have to regenerate the tokens
            $response = wp_remote_post($this->api_url . '/oauth/v2/token', [
                'body' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $settings['refresh_token'],
                    'redirect_uri'  => $this->callBackUrl
                ]
            ]);

            $body = wp_remote_retrieve_body($response);
            $body = \json_decode($body, true);

            if (is_wp_error($response)) return $response;
            if (isset($body['errors'])) {
                if (!empty($body['errors'][0]['message'])) {
                    $message = $body['errors'][0]['message'];
                } elseif (!empty($body['error_description'])) {
                    $message = $body['error_description'];
                } else {
                    $message = 'Error when requesting OAuth token';
                }
    
                return new \WP_Error('request_error', $message);
            }
            elseif (wp_remote_retrieve_response_code($response) == 200)
            {
                $settings['access_token'] = $body['access_token'];
                $settings['refresh_token'] = $body['refresh_token'];
                $settings['expire_at'] = time() + intval($body['expires_in']);
                $this->updateSettings( $settings );
            }
            else 
            {
                return new \WP_Error('request_error', 'OAuth token error: '.$response['response']['message']);
            }
        }
    }

    public function subscribe($subscriber)
    {
        $response = $this->makeRequest('contacts/new', $subscriber, 'POST');

        if (is_wp_error($response)) {
            return new \WP_Error('error', $response->errors);
        }

        if ($response['contact']["id"]) {
            return $response;
        }
    }

    public function getCampaigns()
    {
        if ($this->campaigns == null) {
            $response = $this->makeRequest('campaigns?limit=5000&published=1&orderBy=name', [], 'GET');
            if (is_wp_error($response)) return;

            $campaigns = [];
            foreach ($response['campaigns'] as $campaign) {
                $campaigns[$campaign['id']] = $campaign['name'];
            } 

            $this->campaigns = $campaigns;
        }
        return $this->campaigns;
    }

    public function getSegments()
    {
        if ($this->segments == null) {
            $response = $this->makeRequest('segments?limit=5000&published=1&orderBy=name', [], 'GET');
            if (is_wp_error($response)) return;
    
            $segments = [];
            foreach ($response['lists'] as $segment) {
                $segments[$segment['id']] = $segment['name'];
            } 
            $this->segments = $segments;
        }
        return $this->segments;
    }

    public function getEmails($select = false)
    {
        if ($this->emails == null) {
            switch ($this->settings['emails']) {
                case 't':
                    $par = '&where%5B0%5D%5Bcol%5D=emailType&where%5B0%5D%5Bexpr%5D=like&where%5B0%5D%5Bval%5D=template';
                    break;

                case 's':
                    $par = '&where%5B0%5D%5Bcol%5D=emailType&where%5B0%5D%5Bexpr%5D=like&where%5B0%5D%5Bval%5D=list';
                    break;

                default:
                    $par = '';
            }
            $response = $this->makeRequest("emails?published=1$par&limit=5000&minimal=1&orderBy=name", [], 'GET');
            if (is_wp_error($response)) return;
    
            $emails = [];
            if ($select) $emails[0] = __('Send no Email', 'webgurus-mautic');
            foreach ($response['emails'] as $email) {
                $emails[$email['id']] = $email['name'];
            } 
            $this->emails = $emails;
        }
        return $this->emails;
    }

    public function getFields()
    {
        if ($this->fields == null) {
            $fields = $this->makeRequest('contacts/list/fields', [], 'GET');
            if (is_wp_error($fields)) return;
    
            if ($fields) {
                //sorting by id for standard ordered list
                usort($fields, function($a, $b) {
                    return $a['id'] - $b['id'];
                });
    
                $fieldsFormatted = [];
                foreach ($fields as $field) {
                    if ($field['object'] == 'company') continue;
                    $fieldsFormatted[$field['alias']] = $field['label'];
                }
    
                unset($fieldsFormatted['email']);
                $this->fields = $fieldsFormatted;
            }
        }
        return $this->fields;
    }

    public function schedule_subscribe ($subscriber, $context, $context_id) {
        $record = new Record();
        $record->parms = apply_filters ('wg_mautic_submission', $subscriber, $context, $context_id);
        $record->context = $context;
        $record->context_ID = $context_id;
        $record->command = 'subscribe';
        $record->time = new \DateTime();
        $record->save();
        $this->scheduled = true;
        return $record->id;
    }

    public function schedule_confirm ($id, $settings) {
        $record = new Record();
        $record->parms = $settings;
        $record->contact_ID = $id;
        $record->command = 'confirm';
        $record->time = new \DateTime();
        $record->save();
        $this->scheduled = true;
        return $record->id;
    }

    public function schedule_action ($id, $command, $parms = null) {
        $record = new Record();
        $record->parent_ID = $id;
        $record->command = $command;
        $record->parms = $parms;
        $record->time = new \DateTime();
        $record->save();
        return $record->id;
    }

    public function schedule_full ($subscriber, $context, $context_id, $settings) {
        if (!empty($settings['tags'])) {

        $tags = explode(',', $settings['tags']);
        $formtedTags = [];
        foreach ($tags as $tag) {
            $formtedTags[] = wp_strip_all_tags(trim($tag));
        }
        $subscriber ['tags'] = $formtedTags;
        }
        $id = $this->schedule_subscribe ($subscriber, $context, $context_id);
        if (is_null($id)) return;
        $this->schedule_actions($id, $settings);
    }

    public function schedule_actions ($id, $settings) {
        if (!empty($settings['add_campaigns'])) {
            foreach ($settings['add_campaigns'] as $campaign_ID) {
                $this->schedule_action($id, 'add_campaign', $campaign_ID);
            }
        }
        if (!empty($settings['remove_campaigns'])) {
            foreach ($settings['remove_campaigns'] as $campaign_ID) {
                $this->schedule_action($id, 'remove_campaign', $campaign_ID);
            }
        }
        if (!empty($settings['add_segments'])) {
            foreach ($settings['add_segments'] as $segment_ID) {
                $this->schedule_action($id, 'add_segment', $segment_ID);
            }
        }
        if (!empty($settings['remove_segments'])) {
            foreach ($settings['remove_segments'] as $segment_ID) {
                $this->schedule_action($id, 'remove_segment', $segment_ID);
            }
        }
        if (!empty($settings['do_not_contact'])) {
            $this->schedule_action($id, 'do_not_contact', $settings['do_not_contact']);
        }
        if (!empty($settings['send_email'])) {
            $this->schedule_action($id, 'send_email', $settings['send_email']);
        }
    }

    //------------------Processing the scheduled requests----------------------
    public function process_pending() {
        global $wpdb;
        $records = Record::get_results("status = 0 AND command IN ('subscribe', 'confirm')");
        foreach ($records as $record) {
            $response = $this->process_record($record);
            if (!is_wp_error($response) && !empty($response['contact']["id"])) {
                $contact_ID = $response['contact']["id"];
                $commands = Record::get_results("status = 0 AND parent_ID = %s", $record->id);
                foreach ($commands as $command) {
                    $this->process_command($command, $contact_ID);
                } 
            }
        }
        //-----Check for records that need to process only the commands-----
        $sql = $wpdb->prepare('SELECT s1.*, s2.contact_id FROM %1$s s1 INNER JOIN %1$s s2 ON s1.parent_ID = s2.id WHERE s2.status = 1 AND s1.status = 0', Record::table_name());
        $results = $wpdb->get_results($sql, ARRAY_A);
        foreach ($results as $result) {
            $command = new Record($result);
            $this->process_command($command, $result['contact_id']);
        }

    }

    //--------------------Treating Subscribe and Confirm action------------
    public function process_record($record) {
        switch ($record->command) {
            case 'subscribe':
                $response = $this->makeRequest('contacts/new', $record->parms, 'POST');

                if (is_wp_error($response)) {
                    //Check for invalid country or state error
                    $errmsg = $response->get_error_message();
                    $repeat = false;
                    if (strpos($errmsg, "country: This value is not valid.") !== false) {
                        $record->parms['country'] = '';
                        $repeat = true;
                    }
                    if (strpos($errmsg, "state: This value is not valid.") !== false) {
                        $record->parms['state'] = '';
                        $repeat = true;
                    }
                    if ($repeat) {
                        $record->error = $errmsg;
                        $response = $this->makeRequest('contacts/new', $record->parms, 'POST');
                        if (is_wp_error($response)) {
                            $record->write_error($response);
                            return $response;
                        }

                    }
                    else {
                        $record->write_error($response);
                        return $response;
                    }
                    
                }
                if (empty($response['contact']["id"])) return 'wrong';

                $record->contact_ID =  $response['contact']["id"];
                break;

            case 'confirm':
                $contact_id = $record->contact_ID;
                $response = $this->makeRequest('contacts/'.$contact_id, 'GET');
                if (is_wp_error($response)) {
                    $record->write_error($response);
                    return $response;
                }
                elseif (empty($response['contact']["id"])) return 'wrong';
                if ($response['contact']['fields']['all']['email'] != $record->parms['email']) {  //does not match with email supplied
                    $record->write_status(Record::CANCELLED);
                    return 'wrong';
                } 
                //Segment Check
                $settings = get_option('wg_mautic_pages');
                $parms = $settings['fields'][$record->parms['key']];
                if ($parms['confirm_check'] != 'n') {
                    $segments = $this->makeRequest('contacts/'.$contact_id.'/segments', 'GET');
                    if (is_wp_error($segments)) {
                        $record->write_error($segments);
                        return $response;
                    }
                    
                    switch ($parms['confirm_check']) {
                        case 's':
                            if (key_exists($parms['confirm_segment'], $segments['lists'])) {
                                $record->write_status(Record::CANCELLED);
                                return 'segcheck'; 
                            } 
                            break;  

                        case 'u':
                            if (!key_exists($parms['confirm_segment'], $segments['lists'])) {
                                $record->write_status(Record::CANCELLED);
                                return 'segcheck'; 
                            } 
                            break;  
                    }
                }
                //if all checks positive, we schedule the actions that we will process in the next step
                $this->schedule_actions($record->id, $parms);
                break;

        }
        $record->write_status(Record::FINISHED);
        return $response;
    }

    //-----------Processing a single command------------------
    public function process_command($command, $contact_id) {
        switch ($command->command) {
            case 'add_campaign':
                $response = $this->makeRequest('campaigns/'.$command->parms.'/contact/'.$contact_id.'/add', [], 'POST');
                break;
            
            case 'remove_campaign':
                $response = $this->makeRequest('campaigns/'.$command->parms.'/contact/'.$contact_id.'/remove', [], 'POST');
                break;

            case 'add_segment':
                $response = $this->makeRequest('segments/'.$command->parms.'/contact/'.$contact_id.'/add', [], 'POST');
                break;
            
            case 'remove_segment':
                $response = $this->makeRequest('segments/'.$command->parms.'/contact/'.$contact_id.'/remove', [], 'POST');
                break;
            
            case 'do_not_contact':
                $response = $this->makeRequest('contacts/'.$contact_id.'/dnc/email/'.$command->parms, [], 'POST');
                break;

            case 'send_email':
                $response = $this->makeRequest('emails/'.$command->parms.'/contact/'.$contact_id.'/send', [], 'POST');
                break;
        }
        $command->contact_ID = $contact_id;
        $command->time = new \DateTime();

        if (is_wp_error($response)) {
            $command->write_error($response);
        }
        else {
            $command->write_status(Record::FINISHED);
        }
        return $response;
    }

    //------------------Processing the failed requests----------------------
    public function process_errors($minerror, $maxerror) {
        global $wpdb;        
        $this->process_pending();
        $records = Record::get_results("status <= %s AND status >= %s AND command IN ('subscribe', 'confirm')", $minerror, $maxerror);
        foreach ($records as $record) {
            set_time_limit(100);    //unless we are in save mode, we get some extra time
            $response = $this->process_record($record);
            if (!is_wp_error($response) && !empty($response['contact']["id"])) {
                $contact_ID = $response['contact']["id"];
                $commands = Record::get_results("status = 0 AND parent_ID = %s", $record->id);
                foreach ($commands as $command) {
                    $this->process_command($command, $contact_ID);
                } 
            }
        }
        //-----Check for records that need to process only the commands-----
        $sql = $wpdb->prepare('SELECT s1.*, s2.contact_id FROM %1$s s1 INNER JOIN %1$s s2 ON s1.parent_ID = s2.id WHERE s2.status = 1 AND s1.status <= %2$s AND s1.status >= %3$s', Record::table_name(), $minerror, $maxerror);
        $results = $wpdb->get_results($sql, ARRAY_A);
        foreach ($results as $result) {
            $command = new Record($result);
            $this->process_command($command, $result['contact_id']);
        }
    }
}
