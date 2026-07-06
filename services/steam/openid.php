<?php
/**
 * LightOpenID 1.2.3
 * @author: Dmitry Dulepov <dmitry@dulepov.net>
 * @license: BSD
 */

class LightOpenID {

    public $returnUrl;
    public $required = array();
    public $optional = array();
    public $verify_peer = true;

    protected $data;
    protected $server;
    protected $version;
    protected $trustRoot;
    protected $identity;
    protected $claimed_id;
    protected $ax = true;
    protected $realm;

    function __construct($host, $returnUrl) {
        $this->trustRoot = $this->realm = 'http://' . $host;
        $this->returnUrl = $returnUrl;
    }

    function __get($name) {
        if ($name === 'identity') {
            return $this->identity;
        } elseif ($name === 'mode') {
            return isset($_GET['openid_mode']) ? $_GET['openid_mode'] : null;
        } else {
            throw new ErrorException('Cannot access property ' . $name);
        }
    }

    function __set($name, $value) {
        if ($name === 'identity') {
            $this->identity = $value;
            $this->claimed_id = $value;
        } else {
            throw new ErrorException('Cannot set property ' . $name);
        }
    }

    function authUrl() {
        $params = array(
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $this->returnUrl,
            'openid.realm'      => $this->realm,
            'openid.identity'   => $this->identity,
            'openid.claimed_id' => $this->claimed_id
        );
        return 'https://steamcommunity.com/openid/login?' . http_build_query($params);
    }

    function validate() {
        if (!$_GET['openid_assoc_handle']) return false;

        $params = array(
            'openid.assoc_handle' => $_GET['openid_assoc_handle'],
            'openid.signed'       => $_GET['openid_signed'],
            'openid.sig'          => $_GET['openid_sig'],
            'openid.ns'           => 'http://specs.openid.net/auth/2.0',
        );

        $signed = explode(',', $_GET['openid_signed']);
        foreach ($signed as $item) {
            $val = $_GET['openid_' . str_replace('.', '_', $item)];
            $params['openid.' . $item] = $val;
        }

        $params['openid.mode'] = 'check_authentication';

        $data = http_build_query($params);
        $context = stream_context_create(array(
            'http' => array(
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n" .
                             "Content-Length: " . strlen($data) . "\r\n",
                'content' => $data,
            )
        ));

        $result = file_get_contents('https://steamcommunity.com/openid/login', false, $context);
        return (strpos($result, "is_valid:true") !== false);
    }

    function getSteamID() {
        if (!$this->validate()) {
            return false;
        }

        $identity = $_GET['openid_claimed_id'];
        $matches = array();
        preg_match("#^https://steamcommunity.com/openid/id/(\d+)$#", $identity, $matches);
        return isset($matches[1]) ? $matches[1] : false;
    }
}






