<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class WAPluginConfig extends PluginConfig {
    function getOptions() {
        return array(
            'wa' => new SectionBreakField(array(
                'label' => 'WA Bot by Haekal', // Update this label for clarity
            )),
            'wa-appkey' => new TextboxField(array(
                'label' => 'WA Bot App Key',
                'configuration' => array('size' => 100, 'length' => 200),
            )),
            'wa-authkey' => new TextboxField(array(
                'label' => 'WA Bot Auth Key',
                'configuration' => array('size' => 100, 'length' => 200),
            )),
            'wa-webhook-url' => new TextboxField(array(
                'label' => 'WA Bot Webhook URL (Optional)', // If needed for further configuration
                'configuration' => array('size' => 100, 'length' => 200),
            )),
            'wa-include-body' => new BooleanField(array(
                'label' => 'Include Body in Message',
                'default' => 0, // Default to not include body in the message
            )),
            'debug' => new BooleanField(array(
                'label' => 'Debug message in error.log',
                'default' => 0, // Default to not log debug messages
            )),
        );
    }
}
