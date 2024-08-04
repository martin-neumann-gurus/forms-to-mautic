<?php

namespace Webgurus\Mautic;

add_action( 'woocommerce_after_order_notes', function ( $checkout ) {
    $settings = get_option('wg_mautic_woocommerce');
    if (is_array($settings) && key_exists('enabled', $settings) && $settings['enabled'] == 'yes') {
        woocommerce_form_field( 
            'wg_mautic_subscribe', 
            array(
                'type'	=> 'checkbox',
                'class'	=> array( 'form-row-wide' ),
                'label'	=> $settings['text'] ?: __( 'Sign me up for the newsletter', 'webgurus-mautic' ),
            ),
            $checkout->get_value( 'wg_mautic_subscribe' )
        );
    }
});

add_action( 'woocommerce_checkout_order_processed', function ( $order_id, $data, $order ) {
    if ( isset( $_POST['wg_mautic_subscribe'] ) && '1' === $_POST['wg_mautic_subscribe'] ) {
        $settings = get_option('wg_mautic_woocommerce');
        if (is_array($settings) && key_exists('enabled', $settings) && $settings['enabled'] == 'yes') {
            $time = new \DateTime('now', new \DateTimeZone('UTC'));
            $timestring = $time->format('Y-m-d H:i:s');
            $subscriber = [
                'email'        => $data['billing_email'],
                'phone'        => $data['billing_phone'],
                'dateModified' => 'now',
                'lastActive'   => $timestring
            ];
            
            $subscriber['ipAddress'] = $_SERVER['REMOTE_ADDR'];

            $address = $settings['address'];
            if (empty($address) || $address  == 'manual') {
                //---Copy name in manual address mapping---
                $subscriber['firstname'] = $data['billing_first_name'];
                $subscriber['lastname'] = $data['billing_last_name'];
            }
            else {
                $address .= '_';
                if (empty($data[$address.'company'])) {
                    $prefix = '';
                    $subscriber = wc_match_address($subscriber, $prefix, $address, $data);
                }
                else {
                    $prefix = 'company';
                    //$subscriber['companyname'] = $data[$address.'company'];  //Not working that way, we may implement company treatment later
                    //$subscriber = wc_match_address($subscriber, $prefix, $address, $data);
                }
                $subscriber['firstname'] = $data[$address.'first_name'];
                $subscriber['lastname'] = $data[$address.'last_name'];

                
                //----Check if Billing and Shipping Name are equal-----
                if (($data['billing_first_name'] == $data['shipping_first_name']) && ($data['billing_last_name'] == $data['shipping_last_name'])) {
                    if ($address == 'billing_') {
                        $address2 = 'shipping_';
                    }
                    else {
                        $address2 = 'billing_';
                    }
                    if (empty($prefix)) {
                        //---We got home address, check for company address
                        if (!empty($data[$address2.'company'])) {
                            //$subscriber['companyname'] = $data[$address2.'company'];  //Not working that way
                            //$subscriber = wc_match_address($subscriber, 'company', $address2, $data);
                        }
                    }
                    else {
                        //---We got company address, check for home address
                        if (empty($data[$address2.'company'])) {
                            $subscriber = wc_match_address($subscriber, '', $address2, $data);
                        }
                    }
                }
            }
            //----Process Custom Field Mappings------
            foreach ($settings['fields'] as $field) {
                $value = sanitize_text_field( $_POST[$field['wc_field']] );
                if (!empty($value)) $subscriber[$field['mautic_field']] = $value;
            }
            
            $subscriber = apply_filters ('wg_mautic_woocommerce_submission', $subscriber, $order_id, $data, $order, $settings);
            $api = API::instance();
            $api->schedule_full ($subscriber, 'woocommerce', $order_id, $settings);
        }
    
    }
}, 10, 3);

function wc_match_address($subscriber, $prefix, $address, $data) {
    $match = [
        'country' => 'country',
        'state' => 'state',
        'city' => 'city',
        'zipcode' => 'postcode',
        'address1' => 'address_1',
        'address2' => 'address_2'
    ];
    $map = Mautic_Mapping::instance();
    
    foreach ($match as $key => $wc_key) {
        $value = $data[$address . $wc_key];
        if (empty($value)) continue;
        switch ($wc_key) {
            case 'country':
                $country_code = $value;
                $countries = $map->get_countries();
                $value = $countries[$country_code];               
                break;

            case 'state':
                $states = $map->get_states();
                if (isset( $states[$country_code] )) {
                    $states = $states[$country_code];
                    if (isset( $states[$value] )) $value = $states[$value];
                }
        }
        $subscriber[$prefix . $key] = $value;
    }
    return $subscriber;
}