<?php
/**
 * StarRez Report to Analytics
 * Fetches a report from StarRez and then saves it to an Analytics table
 *
 * PHP Version: 5.6.4
 *
 * @category   StarRez
 * @package    StarRez
 *
 * @author     Phil Hawthorne <phil@squirrel.biz>
 * @license    Copyright Squirrel Business Solutions
 * @link       http://squirrel.biz
 * @since      May 2024
 */

require getenv('SQUIRREL_CLIENT_LIB_V2');
use Squirrel\StarRez;
$sc = new SquirrelClient("wentworth_quarter_analytics");

// This is for Assemble
$analytics_table = '126006000000077462'; // TODO - Update this to the correct table ID for Assemble
$starrez_report = '697195'; // TODO - Update this to the correct report ID for Assemble
$analytics_email = "grant.waldeck@essencecommunities.com.au";
define("WORKSPACE_ID", "126006000000004003");
define("ORG_ID", "7004076783");
define("ACCOUNT_NAME", "Assemble");

exit; //TODO- Remove this line after update above table id and report id

try {
    $throttle = new Stiphle\Throttle\LeakyBucket;
    $starrez = StarRez::getForClient($sc);
} catch (Exception $e) {
    $errorArray = [
        'error' => $e->getMessage(),
        'starrez_report' => $starrez_report,
        'analytics_table' => $analytics_table
    ];
    $errorMsg = "Could not initialize StarRez API Class for client";
    $sc->log->critical($errorMsg, $errorArray);
    $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
    emailClient(ACCOUNT_NAME, $errorMsg);
    exit();
}

try {
    //Attempt to get the report for the client
    $report = $starrez->getReport($starrez_report);
    if (empty($report)) {
        throw new Exception("Empty report when trying to fetch from StarRez, nothing to do");
    }

    if(strpos($report, "<") === 0){
        $xmlData = $starrez::convertXMLtoArr($report);
        if(!empty($xmlData["error"]["description"])){
            throw new Exception(trim($xmlData["error"]["description"]));
        }
    }

    //Put the report into a CSV format
    //The first line is the header
    $data = array_map("str_getcsv", explode("\n", $report));
    $fh = fopen(__DIR__ . "/report.csv", "w");
    foreach ($data as $d) {
        fputcsv($fh, $d);
    }
    fclose($fh);
} catch (Exception $e) {
    $errorArray = [
        'error' => $e->getMessage()
    ];
    $errorMsg = "Error when trying to fetch Starrez Report";
    $sc->log->critical($errorMsg, $errorArray);
    $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
    emailClient(ACCOUNT_NAME, $errorMsg);
    exit();
}

//We should have our report now, let's go ahead and push that into Analytics
try {
    $cleaned_data = [];
    $analyticsAPI = new AnalyticsAPI($sc, WORKSPACE_ID, ORG_ID);
    $header = true;
    foreach ($data as $row) {
        if ($header) {
            $headers = $row;
            $header = false;
            continue;
        }
        try {
            if (count($row) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $row);
            $cleaned_data[] = $row;
            $rows = $analyticsAPI->addRow($analytics_table, [
                "columns" => $row
            ]);
            $throttle->throttle("analytics", 1, 2000);
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $response = json_decode($e->getResponse()->getBody(true));
            if (!empty($response->summary) && $response->summary == "DATA_VALIDATION_ERROR") {
                $errorArray = [
                    "row" => $row,
                    "response" => $response,
                    'starrez_report' => $starrez_report,
                    'analytics_table' => $analytics_table
                ];
                $errorMsg = "Data validation error when adding Starrez Report to Zoho Analytics";
                $sc->log->warning($errorMsg, $errorArray);
                $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
                //emailClient(ACCOUNT_NAME, $errorMsg);
            } else {
                $errorArray = [
                    "error" => $e->getMessage(),
                    "response" => $response,
                    "row" => $row,
                    'starrez_report' => $starrez_report,
                    'analytics_table' => $analytics_table
                ];
                $errorMsg = "Error when trying to add row in Zoho Analytics";
                $sc->log->error($errorMsg, $errorArray);
                $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
                emailClient(ACCOUNT_NAME, $errorMsg);
                exit();
            }
        } catch (Exception $e) {
            $errorArray = [
                "error" => $e->getMessage(),
                'starrez_report' => $starrez_report,
                'analytics_table' => $analytics_table
            ];
            $errorMsg = "Error when trying to add row in Zoho Analytics";
            $sc->log->error($errorMsg, $errorArray);
            $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
            emailClient(ACCOUNT_NAME, $errorMsg);
            exit();
        }
    }
} catch (Exception $e) {
    $errorArray = [
        "error" => $e->getMessage(),
        'starrez_report' => $starrez_report,
        'analytics_table' => $analytics_table
    ];
    $errorMsg = "Error when trying to add row in Zoho Analytics";
    $sc->log->error($errorMsg, $errorArray);
    $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
    emailClient(ACCOUNT_NAME, $errorMsg);
}

//Now push the data over to Zoho Analytics
if (!empty($cleaned_data)) {
    file_put_contents(__DIR__ . "/report.json", json_encode($cleaned_data));
    try {
        $analyticsAPI->importTable($analytics_table, __DIR__ . "/report.json", [
            "importType" => "truncateadd",
            "autoIdentify" => true,
            "onError" => "setcolumnempty",
            "fileType" => "json"
        ]);
    }  catch (GuzzleHttp\Exception\ClientException $e) {
        $response = json_decode($e->getResponse()->getBody(true));
        if (!empty($response->summary) && $response->summary == "DATA_VALIDATION_ERROR") {
            $sc->log->warning("Data validation error when adding Starrez Report to Zoho Analytics", [
                "row" => $row,
                "response" => $response,
                'starrez_report' => $starrez_report,
                'analytics_table' => $analytics_table
            ]);
        } else {
            $errorArray = [
                "error" => $e->getMessage(),
                "response" => $response,
                'starrez_report' => $starrez_report,
                'analytics_table' => $analytics_table
            ];
            $errorMsg = "Error when trying to import table in Zoho Analytics";
            $sc->log->error($errorMsg, $errorArray);
            $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
            emailClient(ACCOUNT_NAME, $errorMsg);
            exit();
        }
    } catch (GuzzleHttp\Exception\ServerException $e) {
        $response = json_decode($e->getResponse()->getBody(true));
        if (!empty($response->summary) && $response->summary == "DATA_VALIDATION_ERROR") {
            $sc->log->warning("Data validation error when adding Starrez Report to Zoho Analytics", [
                "row" => $row,
                "response" => $response,
                'starrez_report' => $starrez_report,
                'analytics_table' => $analytics_table
            ]);
        } else {
            $errorArray = [
                "error" => $e->getMessage(),
                "response" => $response,
                'starrez_report' => $starrez_report,
                'analytics_table' => $analytics_table
            ];
            $errorMsg = "Error when trying to import table in Zoho Analytics"; 
            $sc->log->error($errorMsg, $errorArray);
            $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
            emailClient(ACCOUNT_NAME, $errorMsg);
        }
        exit();
    } catch (Exception $e) {
        $errorArray = [
            "error" => $e->getMessage(),
            'starrez_report' => $starrez_report,
            'analytics_table' => $analytics_table
        ];
        $errorMsg = 'Error when trying to add row in Zoho Analytics';
        $sc->log->error($errorMsg, $errorArray);
        $errorMsg .= $errorMsg ."<br/>". json_encode($errorArray);
        emailClient(ACCOUNT_NAME, $errorMsg);
        exit();
    }
}

function emailClient($account, $error){
    Global $sc;
    $body = "Hello,<br/><br/>";
    $body .= "Error encountered while running starrez reporting for $account<br/><br/>";
    $body .= "Error: $error<br/><br/>";
    $body .= "Thanks,<br/>Sqible";
    $from = 'noreply@scripts.squirrelcrmhub.com.au';
    $to = [
        'grant.waldeck@unilodge.com.au' => 'Grant Waldeck',
        'faizan@sqible.com.au' => 'Faizan'
    ];
    $bcc  = 'ali+essence@sqible.com.au';
    $sc->sendTemplateEmail(
        "general",
        $to,
        "Starrez Error - $account",
        [ "body" => $body ],
        false,
        $bcc,
        'text/html',
        $from
    );
}

//If we get here, everything is good, so cleanup and report OK
unlink(__DIR__ . "/report.json");
unlink(__DIR__ . "/report.csv");

//Healthcheck
file_get_contents('https://status.squirrel.biz/api/push/zgNuLtRWnc?status=up&msg=OK');// TODO update the Health check url for Assemble

//If not run from the CLI, echo
if (php_sapi_name() != "cli") {
    echo "StarRez Report Synced to Analytics if you don't see any errors";
}
