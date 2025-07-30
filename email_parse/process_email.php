<?php
/*
 * @client  assemble_macaulay
 * @author  Ali (copied Bryce script from Dickson Village)
 * @since   2025-03-31
 * 
 * pplan https://docs.google.com/spreadsheets/d/1uIeermfMDPUpylkDcRwlKnuBh2aN2tZ3LoPHho5ZDC4/edit?gid=1309384213#gid=1309384213&range=5:11
 */

use zcrmsdk\crm\crud\ZCRMNote;
const ASSEMBLE_USER_ID = 32553000000360001;

require getenv('SQUIRREL_CLIENT_LIB_V2');
$sc = new SquirrelClient('assemble_macaulay');
if($sc->debug){ echo "<pre>"; }

if(!isset($_GET['id'])) { exit; }

$email_id = $_GET['id'];

try {
    $rawEmail = $sc->getEmail($email_id);

    if(empty($rawEmail)) {
        $sc->log->error("Could not retrieve email with ID: {$email_id}");
        exit;
    }

    $sc->log->info("Incoming email with ID: " . $email_id);
    
    $body = str_replace("  ", " ", strip_tags(preg_replace("/\s+/u", " ", $rawEmail['html'])));
    $subject = trim($rawEmail['subject']);
    $from_email = trim($rawEmail['from_email']);
    $to_email = trim($rawEmail['recipients']);

    $ownerId = ASSEMBLE_USER_ID;

    $description = $method = $website_source = null;
    $property_address = "";

    if(strpos($to_email, "realestate_native") !== false || strpos($from_email, "realestate_native") !== false){
        $website_source = "REA";
        $method = "Native";

        $variable_map = [
            "name" => [
                "start" => "Name:",
                "end" => "Phone:",
            ],
            "email" => [
                "start" => "Email:",
                "end" => "Responses:"
            ],
            "phone" => [
                "start" => "Phone:",
                "end" => "Email:"
            ],
            "i_would_like" => [
                "start" => "Responses:",
                "end" => "Source:"
            ]
        ];
    } else if(strpos($to_email, "realestate_sponsored") !== false || strpos($from_email, "realestate_sponsored") !== false){
        $website_source = "REA";
        $method = "Sponsored";

        $variable_map = [
            "name" => [
                "start" => "Name:",
                "end" => "Phone:",
            ],
            "email" => [
                "start" => "Email:",
                "end" => "Responses:"
            ],
            "phone" => [
                "start" => "Phone:",
                "end" => "Email:"
            ],
            "i_would_like" => [
                "start" => "Responses:",
                "end" => "Last name:"
            ],
            "last_name" => [
                "start" => "Last name:",
                "end" => "View all"
            ]
        ];
    } else if(strpos($to_email, "realestate") !== false || strpos($from_email, "realestate") !== false){
        $website_source = "REA";

        $variable_map = [
            "property_id" => [
                "start" => "Property id:",
                "end" => "Property address:"
            ], 
            "property_address" => [
                "start" => "Property address:",
                "end" => "Property URL:",
            ], 
            "property_url" => [
                "start" => "Property URL:",
                "end" => "User Details:"
            ],
            "name" => [
                "start" => "Name:",
                "end" => "Email:",
            ],
            "email" => [
                "start" => "Email:",
                "end" => "Phone:"
            ], 
            "comments" => [
                "start" => "Comments:",
                "end" => "You can only use"
            ]
        ];

        // Seems like 2 variations of same email or perhaps field is removed
        if(strpos($body, "I would like to") !== false){
            $variable_map = array_merge($variable_map, [
                "phone" => [
                    "start" => "Phone:",
                    "end" => "I would like to:"
                ], 
                "i_would_like" => [
                    "start" => "I would like to:",
                    "end" => "Comments:"
                ]
            ]);
        } else {
            $variable_map = array_merge($variable_map, [
                "phone" => [
                    "start" => "Phone:",
                    "end" => "Comments:"
                ]
            ]);
        }
    } else if(strpos($to_email, "domain") !== false || strpos($from_email, "domain") !== false){
        $website_source = "Domain.com.au";

        $variable_map = [
            "property_address" => [
                "start" => "View the details of the property at",
                "end" => "(Your ref :",
            ],
            "name" => [
                "start" => "From:  ", // 2 spaces will get 2nd From: , 1 or 0 spaces will return first From (which for most emails is sender address)
                "end" => "Email:",
            ],
            "email" => [
                "start" => "Email:",
                "end" => "Phone:"
            ], 
            "phone" => [
                "start" => "Phone:",
                "end" => "Message:"
            ], 
            "i_would_like" => [
                "start" => "Message:",
                "end" => "Security Policy"
            ]
        ];
    } else {
        $sc->log->error("Sender email $to_email not recognised.");
        exit;
    }

    $sc->log->debug("Email source: '$website_source'");

    foreach($variable_map as $var_name => $start_end){
        try {
            ${$var_name} = trim(get_string_between($body, $start_end["start"], $start_end["end"]));
        }catch(Exception $e) {
            $sc->log->error("Error parsing $website_source email. ".$e->getMessage());
        }
    }

    if(!isset($email) || empty($email)){
        $sc->log->warning("Email is not extracted from email body of email $email_id, should not occur.");
        exit;
    }

    $description = isset($i_would_like) ? $i_would_like : "";
    $description .= isset($comments) & !empty($comments) ? (!empty($description) ? PHP_EOL.$comments : $comments) : "";

    // If 'last_name' is available, add it to 'name' to be splitted below (also if 'last_name' is available separately then 'name' will only have first_name)
    if(isset($last_name)) {
        $name .= " ".$last_name;
    }
    
    if(!isset($email)){
        $sc->log->error("Email was not present in Email notification for $website_source of email $email_id. Cannot do existance check.");
    } else{
        try{
            $matching_leads = $sc->zoho->searchRecordsByCriteria("Leads", "(Email:equals:$email)");
        } catch(Exception $e){
            if($e->getCode() != 204){
                $sc->log->error("Error while trying to find matching Lead in CRM for '$website_source' submission from email '$email'. ".$e->getMessage(), [
                    "error" => $e->getExceptionDetails()
                ]);
            }
            $matching_leads = [];
        }

        if($matching_leads){
            foreach($matching_leads as $match){
                if($match->getFieldValue("Email") == $email){
                    $note_content = $property_address."\n\n".$description;
                    try{
                        $noteIns = ZCRMNote::getInstance($match);
                        $noteIns->setTitle("$website_source Submission");
                        $noteIns->setContent($note_content);
                        $responseIns =	$match->addNote($noteIns);
                        $sc->log->info("Lead for $email already exists in CRM, note has been added to Lead ".$match->getEntityId().".");
                    } catch(Exception $e){
                        $sc->log->error("Error while adding note to Lead ".$match->getEntityId().". ".$e->getMessage());
                    }
                    try{
                        createTask($match, $ownerId, $sc);
                    } catch(Exception $e){
                        $sc->log->error("Error while creating task for Lead ".$match->getEntityId().". ".$e->getMessage());
                    }
                    exit;
                }
            }
        }
    }

    $lead = array(
        'First_Name' 					=> isset($name)  ? split_name($name)[0] : "",
        'Last_Name' 					=> isset($name)  ? split_name($name)[1] : "x",
        'Email' 						=> isset($email) ? $email : "",
        'Mobile'	                    => isset($phone) ? $phone : "",
        "Description"                   => $description,
        'Property_Address'              => isset($property_address) ? $property_address : "",
        "Enquiry_Rating_NEW"            => "D - Unknown",
        "Enquiry_Booking_Status"        => "New - no response",
        "Owner"                         => $ownerId,
        "Method_NEW"                    => !empty($method) ? $method : "Listing",
        "Source_NEW"                    => $website_source,
    );

    try{
        $response = $sc->zoho->createRecord("Leads", $lead, ["workflow"]);
        $sc->log->info("Created new lead in Zoho CRM for $email from $website_source enquiry with ID: {$response['id']}");
    } catch(Exception $e){
        $sc->log->error("Error creating lead in Zoho CRM for $email: " . $e->getCode() . " | Message: " . $e->getMessage(), [
            "error" => $e->getExceptionDetails(),
            "data" => $lead
        ]);
    }
}catch(Exception $e) {
    $sc->log->error("Error:" . $e->getCode() . " | Message: " . $e->getMessage());
}

function split_name($name) {
    if(empty(trim($name))) { return ['', 'x']; }
    $name_segments = explode(" ", $name);
    $first_name =  array_shift($name_segments);
    $last_name = count($name_segments) > 0 ? (implode(" ", $name_segments)) : "x";
    return [$first_name, $last_name];
}

function get_string_between($string, $start, $end){
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini === false) {
        throw new Exception("Start string '{$start}' not found.");
    }
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}

function get_numerics ($str) {
    preg_match_all('/\d+/', $str, $matches);
    return $matches[0];
}

function createTask($record, $ownerId, $sc){
    $recordId = $record->getEntityId();
    $moduleName = $record->getModuleApiName();
    $name = $record->getFieldValue("First_Name")." ".$record->getFieldValue("Last_Name");
    try{
        $task = [];
        $task["Owner"] = $ownerId;
        $task["Subject"] = "SUPER HOT LEAD - Lead ($name) - please follow up";
        $task["Due_Date"] = (new DateTime("+1 Days"))->format("Y-m-d");
        if($moduleName == "Contacts"){
            $task["Who_Id"] = $recordId;
        } else{
            $task["What_Id"] = $recordId;
        }
        $task['$se_module'] = $moduleName;
        $sc->zoho->createRecord("Tasks", $task);
        $sc->log->info("Task created for $moduleName {'$recordId'}");
    }  catch(Exception $e){
        $sc->log->error("Error while creating Task for $moduleName {'$recordId'}. ".$e->getMessage()." ".print_r($e->getExceptionDetails(),true));
    }
}
