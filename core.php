<?php

namespace WebgurusMautic\Integrations;

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
                        if (!empty($user_id)) {
                            $api = API::instance();
                            self::$confirm_ID = $api->schedule_confirm($user_id, ['email' => sanitize_email($_GET['email']), 'key' => $key]); 
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

