<?php

/**
 * BooksAPI.php
 *
 *
 *
 *
 * @author Bryce
 * @version 0.1
 * @since 10/09/20
 */

use Guzzle\Http\Client;

Class ZCRMv2API {
    private $access_token;
    private $sc;
    private $base_url;
    
    public function __construct($sc) {
        $this->access_token = $sc->zoho->getAccessToken();
        $this->sc = $sc;
        $this->base_url = "https://".$sc->getOption("sbhconnect_api_base_url")."/crm/v2.1";
    }

    public function uploadFile($file_path){
        $url = $this->base_url."/files";
        $res = $this->postRequest($url,null,$file_path);
        return $res;
    }

    public function sendMail($module, $id, $postData){
        $url = $this->base_url."/$module/$id/actions/send_mail";
        $res = $this->postRequest($url, json_encode($postData));
        return $res;
    }

    function postRequest($url, $postData, $file_path = null) {

        $headers = [
            "Authorization" => "Zoho-oauthtoken ".$this->access_token, 
        ];
        
        try {
            $client = new Guzzle\Http\Client();
            $client->setDefaultOption('verify', false);
            $request = $client->post($url, $headers, $postData);
            if($file_path){
                $request->addPostFiles(array('file' => $file_path));
            }
            $response = $request->Send();
            if ($response->getStatusCode() == 200 || $response->getStatusCode() == 201) {
                $responseBody = $response->getBody(true);
                $responseData = json_decode($responseBody,true);
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
    
    function getRequest($url) {
    
        $headers = [
            "Authorization" => "Zoho-oauthtoken ".$this->access_token, 
        ];
        try {
            $client = new Guzzle\Http\Client();
            $client->setDefaultOption('verify', false);
            $request = $client->get($url, $headers);
            $response = $request->Send();
            if ($response->getStatusCode() == 200) {
                $records = json_decode($response->getBody(true),true);
                return $records;            
            } else {
                return false;
            }
        } catch (Exception $e) {
            throw $e;
        }
        
    }
}