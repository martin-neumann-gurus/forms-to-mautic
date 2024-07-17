<?php

namespace WebgurusMautic\Integrations;

use FluentForm\App\Services\Integrations\IntegrationManager;
use FluentForm\Framework\Foundation\Application;
use FluentForm\Framework\Helpers\ArrayHelper;

class Bootstrap extends IntegrationManager
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Mautic',
            'mautic',
            'wg_mautic_connection',
            'mautic_feed',
            36
        );

        $this->logo = fluentFormMix('img/integrations/mautic.png');

        $this->description = __('Mautic is a fully-featured marketing automation platform that enables organizations of all sizes to send multi-channel communications at scale.', 'webgurus-mautic');

        $this->registerAdminHooks();

        add_filter('fluentform/notifying_async_mautic', '__return_false');  //We just schedule our submissions here, so do it right away
    }

    public function getGlobalFields($fields) {
        return [
            'logo'               => $this->logo,
            'menu_title'         => __('Mautic Settings', 'webgurus-mautic'),
            'menu_description'   => $this->description,
            'valid_message'      => __('Your Mautic API Key is valid', 'webgurus-mautic'),
            'invalid_message'    => __('Your Mautic API Key is not valid', 'webgurus-mautic'),
            'save_button_text'   => __('Save Settings', 'webgurus-mautic'),
            'config_instruction' => __('Please use the Forms to Mautic plugin settings in order to configure the connection with the Mautic API.', 'webgurus-mautic'),
            'fields'             => [],
            'hide_on_valid'      => true,
            'discard_settings'   => [
                'section_description' => __('Your Mautic API integration is up and running', 'webgurus-mautic'),
                'data'                => [
                    'api_url'       => '',
                    'client_id'     => '',
                    'client_secret' => ''
                ],
                'show_verify'         => true
            ]
        ];
    }

    public function getGlobalSettings($settings)
    {
        $api = $this->getRemoteClient();
        return $api->settings;
    }



    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title . ' Integration',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => __('Configuration required!','webgurus-mautic'),
            'global_configure_url'  => admin_url('admin.php?page=crb_carbon_fields_container_forms_to_mautic.php'),
            'configure_message'     => __('Mautic is not configured yet! Please configure your Mautic API first', 'webgurus-mautic'),
            'configure_button_text' => __('Set Mautic API', 'webgurus-mautic')
        ];
        return $integrations;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'                 => '',
            'list_id'              => '',
            'fields'               => (object)[],
            'other_fields_mapping' => [
                [
                    'item_value' => '',
                    'label'      => ''
                ]
            ],
            'conditionals'         => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],
            'resubscribe'          => false,
            'enabled'              => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        $api = $this->getRemoteClient();
        $fields = $api->getFields();
        $campaigns = $api->getCampaigns();
        $segments = $api->getSegments();
        $emails = $api->getEmails();

        return [
            'fields'            => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'webgurus-mautic'),
                    'required'    => true,
                    'placeholder' => __('Your Feed Name', 'webgurus-mautic'),
                    'component'   => 'text'
                ],
                [
                    'key'                => 'fields',
                    'label'              => __('Field Mapping', 'webgurus-mautic'),
                    'tips'               => __('Select which Fluent Form fields pair with their respective Mautic fields.', 'webgurus-mautic'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('Mautic Fields', 'webgurus-mautic'),
                    'field_label_local'  => 'Form Field',
                    'primary_fileds'     => [
                        [
                            'key'           => 'email',
                            'label'         => __('Email Address', 'webgurus-mautic'),
                            'required'      => true,
                            'input_options' => 'emails'
                        ]
                    ]
                ],
                [
                    'key'                => 'other_fields_mapping',
                    'require_list'       => false,
                    'label'              => __('Other Fields', 'webgurus-mautic'),
                    'tips'               => __('Select which Fluent Form fields pair with their respective Mautic fields.', 'webgurus-mautic'),
                    'component'          => 'dropdown_many_fields',
                    'field_label_remote' => __('Mautic Field', 'webgurus-mautic'),
                    'field_label_local'  => __('Fluent Forms Value', 'webgurus-mautic'),
                    'options'            => $fields
                ],
                [
                    'key'         => 'tags',
                    'label'       => __('Lead Tags', 'webgurus-mautic'),
                    'required'    => false,
                    'placeholder' => __('Tags', 'webgurus-mautic'),
                    'component'   => 'value_text',
                    'inline_tip'  => __( 'Enter a comma separated list of tags to add. Adding a minus before the tag will remove it.', 'webgurus-mautic' ). ' '.__('You can use Fluent Forms smart tags here.', 'webgurus-mautic'),
                ],
                [
                    'key'         => 'add_campaigns',
                    'label'       => __('Add to Campaigns', 'webgurus-mautic'),
                    'required'    =>  false,
                    'placeholder' => __('Choose multiple', 'webgurus-mautic'),
                    'component'   => 'select', //  component type
                    'is_multiple' =>  true,
                    'options'     => $campaigns
                ],
                [
                    'key'         => 'remove_campaigns',
                    'label'       => __('Remove from Campaigns', 'webgurus-mautic'),
                    'required'    =>  false,
                    'placeholder' => __('Choose multiple', 'webgurus-mautic'),
                    'component'   => 'select', //  component type
                    'is_multiple' =>  true,
                    'options'     => $campaigns
                ],
                [
                    'key'         => 'add_segments',
                    'label'       => __('Add to Segments', 'webgurus-mautic'),
                    'required'    =>  false,
                    'placeholder' => __('Choose multiple', 'webgurus-mautic'),
                    'component'   => 'select', //  component type
                    'is_multiple' =>  true,
                    'options'     => $segments
                ],
                [
                    'key'         => 'remove_segments',
                    'label'       => __('Remove from Segments', 'webgurus-mautic'),
                    'required'    =>  false,
                    'placeholder' => __('Choose multiple', 'webgurus-mautic'),
                    'component'   => 'select', //  component type
                    'is_multiple' =>  true,
                    'options'     => $segments
                ],
                [
                    'key'            => 'dnc',
                    'label'          => __('Do not Contact (Email Channel)', 'webgurus-mautic'),
                    'tips'           => __('This option determines whether a contact should be added or removed from the Do Not Contact Email list in Mautic', 'webgurus-mautic'),
                    'component'      => 'radio_choice',
                    'options'     => [
                        'add'  => __('Add', 'webgurus-mautic'),
                        'remove' => __('Remove', 'webgurus-mautic'),
                        '' => __('Maintain Unchanged', 'webgurus-mautic')
                    ]
                ],
                [
                    'key'         => 'send_email',
                    'label'       => __('Send Email', 'webgurus-mautic'),
                    'required'    =>  false,
                    'placeholder' => __('Choose one', 'webgurus-mautic'),
                    'component'   => 'select', //  component type
                    'is_multiple' =>  false,
                    'options'     => $emails
                ],
                [
                    'key'       => 'conditionals',
                    'label'     => __('Conditional Logics', 'webgurus-mautic'),
                    'tips'      => __('Activate Mautic integration conditionally based on the submission values of the form', 'webgurus-mautic'),
                    'component' => 'conditional_block'
                ],
                [
                    'key'            => 'enabled',
                    'label'          => __('Status', 'webgurus-mautic'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable This feed', 'webgurus-mautic')
                ]
            ],
            'integration_title' => $this->title
        ];
    }

    protected function getLists()
    {
        return [];
    }

    public function getMergeFields($list = false, $listId = false, $formId = false)
    {
        return [];
    }


    /*
    * Form Submission Hooks Here
    */
    /*
    * Form Submission Hooks Here
    */
    public function notify($feed, $formData, $entry, $form)
    {
        $feedData = $feed['processedValues'];
        $time = new \DateTime('now', new \DateTimeZone('UTC'));
        $timestring = $time->format('Y-m-d H:i:s');
        $atts = $entry->attributes;

        $subscriber = [
            'email'        => ArrayHelper::get($feedData, 'email'),
            'dateModified' => $timestring,
            'lastActive'   => $timestring,
        ];

        $tags = ArrayHelper::get($feedData, 'tags');
        if ($tags) {
            $tags = explode(',', $tags);
            $formtedTags = [];
            foreach ($tags as $tag) {
                $formtedTags[] = wp_strip_all_tags(trim($tag));
            }
            $subscriber['tags'] = $formtedTags;
        }

        //if (ArrayHelper::isTrue($feedData, 'last_active')) {
        $subscriber['ipAddress'] = $entry->ip;

        $subscriber = array_filter($subscriber);

        if (!empty($subscriber['email']) && !is_email($subscriber['email'])) {
            $subscriber['email'] = ArrayHelper::get($formData, $subscriber['email']);
        }

        foreach (ArrayHelper::get($feedData, 'other_fields_mapping') as $item) {
            $subscriber[$item['label']] = $item['item_value'];
        }

        if (!is_email($subscriber['email'])) {
            return;
        }
        $subscriber = apply_filters ('wg_mautic_ff_submission', $subscriber, $feed, $formData, $entry, $form);

        $api = $this->getRemoteClient();
        $id = $api->schedule_subscribe ($subscriber, 'fluentforms', $entry->id);

        // It's success
        do_action('ff_log_data', [
            'parent_source_id' => $form->id,
            'source_type'      => 'submission_item',
            'source_id'        => $entry->id,
            'component'        => $this->integrationKey,
            'status'           => 'success',
            'title'            => $feed['settings']['name'],
            'description'      => __('Mautic feed has scheduled ID:', 'webgurus-mautic') . ' ' . $id
        ]);

        $addCampaign = ArrayHelper::get($feedData, 'add_campaign');
        if ($addCampaign) {
            foreach ($addCampaign as $campaign_ID) {
                $response = $api->schedule_action($id, 'add_campaign', $campaign_ID);
            }   
        }

        $removeCampaign = ArrayHelper::get($feedData, 'remove_campaign');
        if ($removeCampaign) {
            foreach ($removeCampaign as $campaign_ID) {
                $response = $api->schedule_action($id, 'remove_campaign', $campaign_ID);
            } 
        }

        $addSegments = ArrayHelper::get($feedData, 'add_segments');
        if ($addSegments) {
            foreach ($addSegments as $segment_ID) {
                $response = $api->schedule_action($id, 'add_segment', $segment_ID);
            }   
        }

        $removeSegments = ArrayHelper::get($feedData, 'remove_segments');
        if ($removeSegments) {
            foreach ($removeSegments as $segment_ID) {
                $response = $api->schedule_action($id, 'remove_segment', $segment_ID);
            }   
        }

        $dnc = ArrayHelper::get($feedData, 'dnc');
        if ($dnc) {
            $response = $api->schedule_action($id, 'do_not_contact', $dnc);
        }

        $sendEmail = ArrayHelper::get($feedData, 'send_email');
        if ($sendEmail) {
            $response = $api->schedule_action($id, 'send_email', $sendEmail);
        }

    }

    public function getRemoteClient()
    {
        return API::instance();
    }
}
