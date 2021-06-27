<?php
/**
 * Provides a wrapper class for communicating with a MakerBot Replicator
 * 3D printer through JSON-RPC
 *
 * @package    Makerbot
 * @subpackage Makerbot.Replicator
 * @copyright  Copyright (c) 2018-2021 Abdy Franco. All Rights Reserved.
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 * @author     Abdy Franco <iam@abdyfran.co>
 */

namespace Makerbot;

class Replicator
{
    private $ip_address;

    private $auth_code;

    private $client_id = 'MakerWare';

    private $client_secret = 'secret';

    private $socket;

    public function __construct($ip_address)
    {
        ini_set('max_execution_time', '300');

        $this->ip_address = $ip_address;
    }

    /**
     * Sends a HTTP request to the printer
     *
     * @param string $path
     * @param string $method
     * @param array $params
     * @param bool $raw
     * @return mixed
     */
    private function makeRequest($path, $method = 'GET', $params = [], $raw = false)
    {
        $url = 'http://' . $this->ip_address . '/' . ltrim($path, '/');

        if ($method == 'GET') {
            $url = $url . '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $response = curl_exec($ch);

        if (!empty($response) && !$raw) {
            $response = json_decode($response);
        }

        return $response;
    }

    /**
     * Sets the authentication code for the future requests
     *
     * @param string $auth_code The authentication code to use
     */
    public function setAuthCode($auth_code)
    {
        $this->auth_code = $auth_code;

        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);
    }

    /**
     * Gets a one time access token for privileged requests
     *
     * @param string $context The context for the access token ('jsonrpc', 'put' or 'camera')
     * @return string The one time access token
     */
    public function getAccessToken($context = 'jsonrpc')
    {
        $token = $this->makeRequest('auth', 'GET', [
            'response_type' => 'token',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'context' => $context,
            'auth_code' => $this->auth_code
        ]);

        if (isset($token->status) && $token->status == 'success') {
            return $token->access_token;
        }

        return false;
    }

    /**
     * Authenticates the application with the printer
     *
     * @return string The authentication code if authenticated successfully, false otherwise
     */
    public function authenticate()
    {
        $code = $this->makeRequest('auth', 'GET', [
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'username' => 'MakerBot API'
        ]);

        // Wait until the user press the knob on the printer
        $executions = 0;

        while (true) {
            $answer = $this->makeRequest('auth', 'GET', [
                'response_type' => 'answer',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'answer_code' => isset($code->answer_code) ? $code->answer_code : null
            ]);

            if (isset($answer->answer) && $answer->answer == 'accepted' || $executions > 200) {
                break;
            }
            $executions++;
            sleep(1);
        }

        if (isset($answer->answer)) {
            $this->setAuthCode($answer->code);

            return $answer->code;
        }

        return false;
    }

    /**
     * Initializes a TCP socket to the printer
     */
    private function createSocket()
    {
        if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
            throw new Exception('socket_create() failed: ' . socket_strerror(socket_last_error()));
        }

        if (($result = socket_connect($socket, $this->ip_address, 9999)) === false) {
            throw new Exception('socket_bind() failed: ' . socket_strerror(socket_last_error($socket)));
        }

        $this->socket = $socket;
    }

    /**
     * Sends a RPC request to the printer socket
     *
     * @param string $method The RPC method to invoke
     * @param array $params The parameters to pass to the invoked function
     * @return stdClass The object response from the remote function
     * @see https://github.com/TrueAnalyticsSolutions/MakerBotAgentAdapterCore/wiki/JSON-RPC
     */
    public function socketRequest($method, $params = null)
    {
        $rpc_params = [
            'jsonrpc' => '2.0',
            'id' => -1,
            'method' => trim($method),
            'params' => $params
        ];

        $rpc_params = json_encode($rpc_params);

        // Send request
        socket_write($this->socket, $rpc_params, strlen($rpc_params));
        $response = socket_read($this->socket, 2048);

        return json_decode($response);
    }

    /**
     * @return stdClass
     */
    public function getCameraFrame()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $response = $this->socketRequest('capture_image', ['output_file' => '/home/settings/frame.png']);

        $image = file_get_contents('http://' . $this->ip_address . '/settings/frame.png');

        if (!empty($image)) {
            $response->params = (object) [
                'url' => 'http://' . $this->ip_address . '/settings/frame.png',
                'base64' => base64_encode($image)
            ];

            return $response;
        }

        return false;
    }

    /**
     * @return stdClass
     */
    public function loadFilament()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $this->socketRequest('process_method', ['method' => 'load_filament']);

        return $this->socketRequest('load_filament', ['tool_index' => 0]);
    }

    /**
     * @return stdClass
     */
    public function unloadFilament()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $this->socketRequest('process_method', ['method' => 'unload_filament']);

        return $this->socketRequest('unload_filament', ['tool_index' => 0]);
    }

    /**
     * @return stdClass
     */
    public function stopFilament()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('process_method', ['method' => 'stop_filament']);
    }

    /**
     * @return stdClass
     */
    public function cancel()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('cancel');
    }

    /**
     * @return stdClass
     */
    public function attachExtruder()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $response = $this->socketRequest('load_print_tool', ['index' => 0]);

        while (true) {
            if (!isset($response->method)) {
                break;
            } else {
                $response = $this->socketRequest('load_print_tool', ['index' => 0]);
            }
        }

        return $response;
    }

    /**
     * @return stdClass
     */
    public function getExtruderInformation()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $response = $this->socketRequest('get_tool_usage_stats');

        while (true) {
            if (!isset($response->method)) {
                break;
            } else {
                $response = $this->socketRequest('get_tool_usage_stats');
            }
        }

        return $response;
    }

    /**
     * @return stdClass
     */
    public function getInformation()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $settings = $this->socketRequest('get_system_information');

        while (true) {
            if (!isset($settings->method)) {
                break;
            } else {
                $settings = $this->socketRequest('get_system_information');
            }
        }

        return $settings;
    }

    /**
     * @return stdClass
     */
    public function getTemperature()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('machine_query_command', ['machine_func' => 'get_temperature', 'params' => ['index' => 0]]);
    }

    /**
     * @param int $temperature
     * @return stdClass
     */
    public function preheat($temperature = 180)
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $response = $this->socketRequest('preheat', ['temperature_settings' => [$temperature]]);

        while (true) {
            if (!isset($response->method)) {
                break;
            } else {
                $response = $this->socketRequest('preheat', ['temperature_settings' => [$temperature]]);
            }
        }

        return $response;
    }

    /**
     * @return stdClass
     */
    public function cool()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $response = $this->socketRequest('cool', ['ignore_tool_errors' => false]);

        while (true) {
            if (!isset($response->method)) {
                break;
            } else {
                $response = $this->socketRequest('cool', ['ignore_tool_errors' => false]);
            }
        }

        return $response;
    }

    /**
     * @param string $file_url
     * @return stdClass
     */
    public function print($file_url)
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('external_print', ['url' => $file_url, 'ensure_build_plate_clear' => true]);
    }

    /**
     * @return stdClass
     */
    public function printAgain()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('print_again');
    }

    /**
     * @param int $error_id
     * @return stdClass
     */
    public function acknowledgeError($error_id = -1)
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        $this->socketRequest('process_method', ['method' => 'acknowledge_error']);

        return $this->socketRequest('acknowledged', ['error_id' => -1]);
    }

    /**
     * @return stdClass
     */
    public function pause()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('process_method', ['method' => 'suspend']);
    }

    /**
     * @return stdClass
     */
    public function unpause()
    {
        $this->createSocket();
        $this->socketRequest('authenticate', ['access_token' => $this->getAccessToken()]);

        return $this->socketRequest('process_method', ['method' => 'resume']);
    }

    /**
     * @return stdClass
     */
    public function recoverFilamentSlip()
    {
        $this->pause();
        sleep(7);
        $this->loadFilament();
        sleep(7);
        $this->stopFilament();
        sleep(2);

        return $this->unpause();
    }

    /**
     * @return stdClass
     */
    public function recoverTemperatureSag()
    {
        $this->pause();
        sleep(10);

        return $this->unpause();
    }

    /**
     * @return bool
     */
    public function validateIp()
    {
        $response = $this->makeRequest('auth', 'GET');

        return isset($response->status);
    }

    /**
     * @return bool
     */
    public function isAuthenticated()
    {
        $response = $this->getAccessToken();

        return (bool) $response;
    }
}
