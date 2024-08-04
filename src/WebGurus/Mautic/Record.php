<?php

namespace Webgurus\Mautic;

//----------------------DB Class Definition --------------
Class Record {
    //Status codes
    public const UNPROCESSED = 0;
    public const FINISHED = 1;
    public const CANCELLED = 2;
    public const TRASHED = 100;

    use CRUD_Object;
    
    var $id;
    var $parent_ID; //Points to the main call that creates the user account
    var $command;   //The command to send to the Mautic API    
    var $parms;     //A parameter array
    var $context;   //In the main call the context where the call was created
    var $context_ID;//The ID of the context event. 
    var $contact_ID;   //The Mautic User ID
    var $status = 0;    //Status according to codes above. Failed requests have an incremental negative count
    var $error;     //The error message that occured
    var $time; //Time of the action
    public static $fields = [
    'id' =>['type'=>'primary'],
    'parent_ID' =>['type'=>'bigint', 'null'=>true],
    'command' =>['type'=>'char', 'length'=>16],
    'parms' =>['type'=>'array', 'null'=>true],
    'context' =>['type'=>'char', 'length'=>12, 'null'=>true],
    'context_ID' =>['type'=>'bigint', 'null'=>true],
    'contact_ID' =>['type'=>'bigint', 'null'=>true],
    'status' =>['type'=>'tinyint'],
    'error' => ['type'=>'text', 'length'=>500, 'null'=>true],
    'time' => ['type'=>'datetime']
    ];
    public static $table_name = 'wg_mautic_submissions';

    public function write_error($response) {
        $this->error = $response->get_error_message();
        $this->status--;
        $this->save();
        if (apply_filters('wg_mautic_send_error_email', true, $response )) {
            switch($status) {
                case -1:
                    $this->error_email(__( 'Mautic API Action Failed. Still repeating.', 'wgmautic' ));
    
                case -7:
                    $this->error_email(__( 'Mautic API Action Failed 7x. Giving up!', 'wgmautic' ));
            }
        }
    }

    public function write_status($status = 1) {
        $this->status = $status;
        $this->save();
    }

    public function error_email($title) {
        // Get site name and admin email
        $site_name = get_bloginfo('name');
        $admin_email = get_option('admin_email');

        // Prepare email content
        $subject = "[{$site_name}] - $title";
        $message = sprintf(
        '%s
        %s
        %s
        %s
        %s',
            __( 'An Error occured while trying to send the following request to the Mautic API.', 'wgmautic' ), 
            __( 'Error Message:', 'wgmautic' ).' '.$this->error, 
            __( 'Command:', 'wgmautic' ).' '.$this->command, 
            __( 'Log ID:', 'wgmautic' ).' '.$this->id, 
            __( 'Further infos you can find in the status page of the Webgurus Forms to Mautic plugin.', 'wgmautic' ));


        // Set headers
        $headers = [
        'From: ' . $site_name . ' <' . $admin_email . '>',
        'Content-Type: text/plain; charset=UTF-8'
        ];

        // Send email using wp_mail
        $success = wp_mail($admin_email, $subject, $message, $headers);

        return $success;
  }
}