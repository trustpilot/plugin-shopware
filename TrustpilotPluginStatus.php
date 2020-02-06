<?php

namespace trus2_Trustpilot_Reviews;

class TrustpilotPluginStatus
{
    const TRUSTPILOT_SUCCESSFUL_STATUS = 200;

    private $config;

    public function __construct()
    {
        $this->config = \TrustpilotConfig::getInstance();
    }

    public function setPluginStatus($response)
    {
        $data = json_encode(
            array(
                'pluginStatus' => $response['code'],
                'blockedDomains' => $response['data'] ?: array(),
            )
        );

        $this->config->writeConfig('plugin_status', $data);
    }

    public function checkPluginStatus($origin)
    {
        $data = json_decode($this->config->getConfig('plugin_status', false));

        if (in_array(parse_url($origin, PHP_URL_HOST), $data->blockedDomains)) {
            return $data->pluginStatus;
        }

        return self::TRUSTPILOT_SUCCESSFUL_STATUS;
    }
}