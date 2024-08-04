<?php

namespace Webgurus\Admin;
use Carbon_Fields\Container;
use Carbon_Fields\Field;

Class MainMenu {
        public static $mainmenu;
        public static function Boot() {
            self::$mainmenu = Container::make( 'custom_page', 'webgurus', 'WebGurus')
            ->add_fields ([ 
                Field::make('html', 'webgurus_instruction')
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
            ])
            ->set_sidebar ('<div></div>');
        }    
    }