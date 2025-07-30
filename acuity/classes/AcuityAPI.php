<?php

/**
 * DeskAPI.php
 *
 *
 *
 *
 * @author Bryce
 * @version 0.1
 * @since 10/09/20
 */

use Guzzle\Http\Client;
use GuzzleHttp\Exception\RequestException;

Class AcuityAPI {
    private $sc;
    private $base_url = 'https://acuityscheduling.com/api/v1';
    private $user_id;
    private $api_key;
    private $headers = [];

    private $client_id = "grnrtNDmlt5zAygd";
    private $client_secret = "uLur9kfuZXpykwfIaaCrBocIVRkkKPN4xulM9SGg";
    private $redirect_uri = "https://scripts.squirrelcrmhub.com.au/oauth2/acuity/index.php";
    
    private $access_token;

    public function __construct($user_id, $api_key, $sc) {
        $this->user_id = $user_id;
        $this->api_key = $api_key;
        $this->sc = $sc;
    }

    // Manually auth this link returned in a browser as its a GUI process
    // Use code returned to get Access Token using getAccessToken ASAP as code expires
    public function getAuthURL(){
        $config = [
            "response_type" => "code",
            "scope" => "api-v1",
            "client_id" => $this->client_id,
            "redirect_uri" => $this->redirect_uri,
        ];
        return "https://acuityscheduling.com/oauth2/authorize?".http_build_query($config);
    }

    public function getAccessToken($code){
        $acuity = new AcuitySchedulingOAuth(array(
            'clientId' => $this->client_id,
            'clientSecret' => $this->client_secret,
            'redirectUri' => $this->redirect_uri
          ));
          
        $tokenResponse = $acuity->requestAccessToken($code);

        return $tokenResponse;
    }

    public function getAppointment($appointment_id){
        $url = $this->base_url."/appointments/$appointment_id";
        $res = $this->getRequest($url);
        return $res;
    }

    public function getAppointments($calendar_id){
        $url = $this->base_url."/appointments?calendarID=$calendar_id";
        $res = $this->getRequest($url);
        return $res;
    }

    public function postRequest($url, $postData, $file_path = null) {
        
        try {
            $client = new Guzzle\Http\Client();
            $request = $client->post($url, $this->headers, $postData);
            $request->setAuth($this->user_id, $this->api_key);
            if($file_path){
                $request->addPostFiles(array('file' => $file_path));
            }
            $response = $request->Send();
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                
                $responseBody = $response->getBody(true);
                $responseData = json_decode($responseBody);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid response: $responseBody");
                }
                
                return $responseData;

            } else {

                throw new Exception("Invalid status code: ".$response->getStatusCode());

            }
        } catch (Exception $e) {
            throw $e;
        }

    }

    public function putRequest($url, $putData) {
        
        try {
            $client = new Guzzle\Http\Client();
            $request = $client->patch($url, $this->headers, $putData);
            $request->setAuth($this->user_id, $this->api_key);
            $response = $request->Send();
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {                
                $responseBody = $response->getBody(true);
                $responseData = json_decode($responseBody);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid response: $responseBody");
                }
                
                return $responseData;

            } else {

                throw new Exception("Invalid status code: ".$response->getStatusCode());

            }
        } catch (Exception $e) {
            throw $e;
        }

    }

    private function getRequest($url) {

	    try {
            $client = new Guzzle\Http\Client();
            $request = $client->get($url, $this->headers);
            $request->setAuth($this->user_id, $this->api_key);
            $response = $request->Send();
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                $data = json_decode($response->getBody(true));
                if ($data) {
                    return $data;
                } 
            } 
            return false;
            
        } catch (Exception $e) {
            throw $e;
        }

	}
}