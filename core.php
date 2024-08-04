<?php

namespace Webgurus\Mautic;

//---------------Outputs Checkbox on Comment Forms-------------------
add_filter( 'comment_form_default_fields', function ( $fields ) {
    $settings = get_option('wg_mautic_comments');
    if (is_array($settings) && key_exists('enabled', $settings) &&$settings['enabled'] == 'yes') {
        $fields['wg_mautic_checkbox'] = '<p class="comment-form-wg-mautic-checkbox"><input id="comment-form-wg-mautic-checkbox" name="comment-form-wg-mautic-checkbox" type="checkbox" value="1" /> <label for="comment-form-wg-mautic-checkbox">' . $settings['text'] ?: __( 'Sign me up for the newsletter', 'webgurus-mautic' ). '</label></p>';
    }
     return $fields;
});

//---------------Processes Comment Forms submission--------------------
add_action( 'comment_post', function ( $comment_id, $comment_approved ) {
    if ( isset( $_POST['comment-form-wg-mautic-checkbox'] ) && '1' === $_POST['comment-form-wg-mautic-checkbox'] ) {
        $settings = get_option('wg_mautic_comments');
        if (is_array($settings) && key_exists('enabled', $settings) &&$settings['enabled'] == 'yes') {
            $names = explode (' ', sanitize_text_field( $_POST['author'] ));
            $time = new \DateTime('now', new \DateTimeZone('UTC'));
            $timestring = $time->format('Y-m-d H:i:s');

            $subscriber = [
                'firstname'   => $names[0],
                'email'        => sanitize_email( $_POST['email'] ),
                'dateModified' => 'now',
                'lastActive'   => $timestring
            ];
            $subscriber['ipAddress'] = $_SERVER['REMOTE_ADDR'];
            
            $subscriber = apply_filters ('wg_mautic_comment_submission', $subscriber, $comment_id, $settings);
            $api = API::instance();
            $api->schedule_full ($subscriber, 'comment', $comment_id, $settings);
        }
    }
}, 10, 2 );

Class PageConfirm {
    public static $confirm_ID;

    public static function boot() {
        //--------------Check if we process Page Confirmations-----------
        add_action( 'template_redirect', function() {
            $settings = get_option('wg_mautic_pages');
            foreach ($settings['fields'] as $key => $def) {
                if (is_page( $def['page'] )) {
                    if (isset($_GET['id']) && isset($_GET['email'])) {
                        $user_id = $_GET['id'];
                        $email = sanitize_email($_GET['email']);
                        if (!empty($user_id) && is_email($email)) {
                            $api = API::instance();
                            self::$confirm_ID = $api->schedule_confirm($user_id, ['email' => $email, 'key' => $key]); 
                        }
                    }
                    
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                }
            }
        });

        //--------Shortcode to use on Confirmation Page---------
        add_shortcode('wg_mautic_confirm', function() {
            if (empty(self::$confirm_ID)) return __( 'Something went wrong with your confirmation request.', 'webgurus-mautic' );
            $record = new Record(self::$confirm_ID);
            $settings = get_option('wg_mautic_pages');
            $def = $settings['fields'][$record->parms['key']];
            $api = API::instance();
            $response = $api->process_record($record);
            if (is_string($response)) {
                switch($response) {
                    case 'wrong':
                        return __( 'Something went wrong with your confirmation request.', 'webgurus-mautic' );

                    case 'segcheck':
                        return $def['resolved_text'];
                }
            } elseif (is_wp_error($response)) {
                return __( 'Error when communicating to the Email system:', 'webgurus-mautic' ).' '.$response->get_error_message();
            } else {
                return sprintf('<h3>%s</h3>
                        <p>%s</p>',
                        sprintf(__( 'Hello %s!', 'webgurus-mautic' ), $response['contact']['fields']['all']['firstname']),
                        $def['confirmed_text']
                );
            }

        });
    }
}

PageConfirm::boot();

//--Country Mapping of WooCommerce country and state codes to Mautic names. Sice it uses ISO codes, it can be repurposed for other use cases.
Class Mautic_Mapping {
    protected static $instance = null; 
    public $Mautic_Version = '5.0';

    public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

    public function get_countries() {
        return include __DIR__.'/Mautic/'.$this->Mautic_Version.'/Countries.php';
    }

    public function get_states() {
        return include __DIR__.'/Mautic/'.$this->Mautic_Version.'/States.php';
    }
}


//----------------AJAX handler for processing failed request on status page-----
add_action( 'wp_ajax_wg_mautic_process', function () {
    global $wpdb;

    check_ajax_referer('wg_mautic_status_nonce', 'security');
    $api = API::instance();
    set_time_limit(0);  // Switch off time limits of this script

    add_filter ('wg_mautic_send_error_email', function() {
        return false;
    });

    // Doing unprocessed commands
    $sql = $wpdb->prepare('SELECT s1.*, s2.contact_id, s2.Parms FROM %1$s s1 INNER JOIN %1$s s2 ON s1.parent_ID = s2.id WHERE s2.status = 1 AND s1.status <= 0 AND s1.id > %2$d ORDER BY s1.id', Record::table_name(), $id);
    $results = $wpdb->get_results($sql, ARRAY_A);
    foreach ($results as $result) {
        $command = new Record($result);
        $parms = maybe_unserialize($result['Parms']);
        $response = $api->process_command($command, $result['contact_id']);
        $out = ['id' => $command->id, 'success' => true];
        $msg = sprintf ( __( 'Processing command %s for email %s.', 'webgurus-mautic' ) , $command->command, $parms['email']).'<br>';
        if (is_wp_error($response)) {
            $msg .= __( 'Error:', 'webgurus-mautic' ) .' '. $response->get_error_message();
            $out ['success'] = false;
        }
        $out['msg'] = $msg;
        write_output($out);
    }
    
    // Doing unprocessed submissions
    $records = Record::get_results("status <= 0 AND command IN ('subscribe', 'confirm') AND id > %d ORDER BY id", $id);
    foreach ($records as $record) {
        $response = $api->process_record($record);
        $result = ['id' => $record->id, 'success' => false];
        $msg = sprintf (($record->command == 'subscribe') ? __( 'Processing sign up of email %s.', 'webgurus-mautic' ) : __( 'Processing page confirmation for email %s.', 'webgurus-mautic' ), $record->parms['email']).'<br>';
        if (is_wp_error($response)) {
            $msg .= __( 'Error:', 'webgurus-mautic' ) . ' ' . $response->get_error_message();
        }
        elseif (!empty($response['contact']["id"])) {
            $result ['success'] = true;
            $contact_ID = $response['contact']["id"];
            $commands = Record::get_results("status <= 0 AND parent_ID = %s", $record->id);

            foreach ($commands as $command) {
                $msg .= $command->command . ' ';
                $response = $api->process_command($command, $contact_ID);
                if (is_wp_error($response)) $msg .= __( 'Error:', 'webgurus-mautic' ) . $response->get_error_message(). ' ';
            } 
        } 
        elseif ($record->status == Record::CANCELLED) {
            $msg .= __( 'Request already confirmed', 'webgurus-mautic' );
            $result ['success'] = true;
        }
        else {
            $msg .= __( 'Error: Mautic did not send a proper response.', 'webgurus-mautic' );
        }
        $result['msg'] = $msg;
        write_output($result);
    }
    write_output(['finished' => true]);
    wp_die();
});

function write_output($out) {
    session_name('wg_mautic_process');
    session_start();
    if (empty($_SESSION['wg_buffer'])) {
        $datarr = [];
    }
    else {
        $datarr = $_SESSION['wg_buffer'];
    }
    $datarr[] = $out;
    $_SESSION['wg_buffer'] = $datarr;
    session_write_close();
}

//---------------Responding updates to client -----------
add_action( 'wp_ajax_wg_mautic_update', function () {
    session_name('wg_mautic_process');
    for ($i = 1; $i <= 60; $i++) {
        session_start();
        if (empty($_SESSION['wg_buffer'])) {
            session_write_close();
            sleep(1);
        }
        else {
            $data = $_SESSION['wg_buffer'];
            $_SESSION['wg_buffer'] = [];
            wp_send_json_success($data);
        }
    }
});

//---------------AJAX handler for API submissions------------
add_action( 'wp_ajax_wg_mautic_process', '\Webgurus\Mautic\process_ajax');
add_action( 'wp_ajax_nopriv_wg_mautic_process', '\Webgurus\Mautic\process_ajax');

function process_ajax() {
    $api = API::instance();
    $api->process_pending();
    echo 'success';
    die();
}

//---------Cron Schedules for handling errors---------
// Add a new schedule for every 5 minutes
add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['every_five_minutes'] = array(
      'interval' => 300, // 5 minutes in seconds
      'display' => 'Every Five Minutes',
    );
    return $schedules;
});
  
// Schedule the cron job (if not already scheduled)
if ( ! wp_next_scheduled( 'wg_mautic_five_minute_error_handler' ) ) {
    wp_schedule_event( time(), 'every_five_minutes', 'wg_mautic_five_minute_error_handler' );
}

// The function to be executed by the cron job
add_action( 'wg_mautic_five_minute_error_handler', function() {
    $api = API::instance();
    $api->process_errors(0,-3);
});
  
// Schedule the cron job (if not already scheduled)
if ( ! wp_next_scheduled( 'wg_mautic_hourly_error_handler' ) ) {
    wp_schedule_event( time(), 'hourly', 'wg_mautic_hourly_error_handler' );
}
    
// The function to be executed by the cron job
add_action( 'wg_mautic_hourly_error_handler', function() {
    $api = API::instance();
    $api->process_errors(-4,-6);
});