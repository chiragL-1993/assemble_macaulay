<?php

define("ERROR_NOTIFICATION", "ali+assemble_macaulay@squirrel.biz");

require getenv('SQUIRREL_CLIENT_LIB_V2'); 
require_once('../vendor/autoload.php');

$sc = new SquirrelClient("assemble_macaulay");
if($sc->debug){ echo "<pre>"; }

use zcrmsdk\crm\crud\ZCRMNote;
use zcrmsdk\crm\exception\ZCRMException;
use Essence\Model\Bookings;

const VALID_OVERWRITE_STATUSES = [
    "New - No Response",
    "New - Inspection Booked",
    "Follow up 1",
    "Follow up 2",
    "Follow up 3",
    "Inspection booked",
    "Inspection Booked",
    "Inspection Cancelled",
];

$access_token = "BBM3YkITZm4HHF3hjZQMjA7l2ssgul5bcvtaHO7s"; //TODO - Need to replace this with Assemble Acuity Access Token

// SDK seems to be missing checks for array values that are not mandatory so spits out notices, @ to silence
@$acuity = new AcuitySchedulingOAuth(array(
    'accessToken' => $access_token,
));

$sc->log->debug("Webhook Received", ["data" => $_POST]);

exit; // Remove this line when we have the Acuity Account details for Assemble & ready to go live

if(!isset($_POST["action"]) || !isset($_POST["id"])){
    $sc->log->warning("Action or ID is not set in POST request from Acuity.");
    exit;
}

$action = $_POST["action"];
$appointment_id = $_POST["id"];
$calendar_id = $_POST["calendarID"];
$appoinment_type_id = $_POST["appointmentTypeID"];

// TODO - Need to replace the calendar_id for the Assemble calendar
if($calendar_id != "9887731" && $calendar_id != "10991373") { 
    $sc->log->info("Appointment $appointment_id is in calendar $calendar_id not the Assemble calendar. Exiting.");
    exit;
}

try{
    $app = $acuity->request('appointments/'.$appointment_id);
} catch(Exception $e){
    $sc->log->error("Error while retrieving appointment $appointment_id from Acuity. ".$e->getMessage());
    exit;
}


try{
    $start_time = new DateTime($app["date"]." ".$app["time"]);
    $end_time = new DateTime($app["date"]." ".$app["endTime"]);
    $submitted_time = new DateTime($app["datetimeCreated"]);
}catch(Exception $e){
    $sc->log->error("Error while parsing Date Times for Start/End/Create from Acuity appt $appointment_id. ".$e->getMessage());
    exit;
}

$first_name = $app["firstName"];
$last_name = $app["lastName"];
$email = $app["email"];
$mobile = $app["phone"];
$form = $app["forms"];
$havePet = "";
if(!empty($form) && isset($form[0]) && isset($form[0]["values"])) {
    foreach ($form[0]["values"] as $key => $value) {
        if($value["name"] == "Do you have a pet") {
            $havePet = $value["value"] == "no" ? "No" : "Yes";
            break;
        }
    }
}

try{
    $mobile = formatPhone($mobile);
} catch(Exception $e){
    $sc->log->error("Mobile was unable to be formatted. ".$e->getMessage());
}

$row = Bookings::where(["client" => $sc->clientCode, "appointment_id" => $appointment_id])->first();

if($row){
    try{
        $crmRecord = $sc->zoho->getRecord($row->module, $row->record_id);
    } catch(Exception $e){
        $sc->log->error("Error while retrieving record from CRM.",[
            "message" => $e->getMessage(),
            "zoho_error" => $e instanceof ZCRMException ? $e->getExceptionDetails() : null,
            "module" => $row->module,
            "record_id" => $row->record_id
        ]);
        exit;
    }
    
    // Cancel Reschedule
    if($action == "rescheduled"){
        if(json_encode($app) == $row->data){
            $sc->log->debug("No update required for appointment $appointment_id.");
            exit;
        }
        $record_update = [
            "Inspection_Date_Time" => $start_time->format("c"),
        ];

        if($row->module == "Leads") {
            $new_status = getStatusUpdate($crmRecord, $action);
            if($new_status){
                $record_update["Enquiry_Booking_Status"] = $new_status;
            }
        }
    
        $noteIns = ZCRMNote::getInstance();
        $noteIns->setContent("Lead rescheduled their Inspection to ". $start_time->format("H:iA d/m/Y"));
        $noteIns->setTitle("Rescheduled Inspection");
        
        try{
            $res = $sc->zoho->updateRecord($row->module, $row->record_id, $record_update, ["workflow"]);
            $res = $sc->zoho->addRecordNote($row->module, $row->record_id, $noteIns);
            $sc->log->info("Success when updating {$row->module} {$row->record_id} with rescheduled time and adding note for appt with id $appointment_id.");
        }catch(Exception $e){
            $sc->log->error("Error while updating {$row->module} {$row->record_id} or adding note for appt with id $appointment_id. ".$e->getMessage());
            exit;
        }
        $row->update(["data" => json_encode($app)]);
    } else if($action == "canceled"){
        $record_update = [
            "Inspection_Status_NEW" => "Inspection cancelled",
            "Inspection_Date_Time" => null,
        ];

        if($row->module == "Leads") {
            $new_status = getStatusUpdate($crmRecord, $action);
            if($new_status){
                $record_update["Enquiry_Booking_Status"] = $new_status;
            }
        }

        $noteIns = ZCRMNote::getInstance();
        $noteIns->setContent("Lead cancelled their Inspection for ". $start_time->format("H:iA d/m/Y"));
        $noteIns->setTitle("Cancelled Inspection");
        
        try{
            $res = $sc->zoho->updateRecord($row->module, $row->record_id, $record_update, ["workflow"]);
            $res = $sc->zoho->addRecordNote($row->module, $row->record_id, $noteIns);
            $row->delete(); // May not need to do this but probably worth while
            $sc->log->info("Success when updating {$row->module} {$row->record_id} with canceled status and adding note for appt with id $appointment_id.");
        }catch(Exception $e){
            $sc->log->error("Error while updating {$row->module} {$row->record_id} to canceled or adding note for appt with id $appointment_id. ".$e->getMessage());
            exit;
        }
    } else{
        $sc->log->warning("Action $action is not handled for existing row for appt $appointment_id.");
    }

} else {

    if($action != "scheduled"){
        $sc->log->warning("Row does not already exist and appt $appointment_id is of type $action. Do not create meeting.");
        exit;
    }

    $module = "Contacts";
    $match = checkExistence($module, $email, $mobile);
    if(!$match) {
        $module = "Leads";
        $match = checkExistence($module, $email, $mobile);
    }

    $apartment_type = null;
    if ($app["appointmentTypeID"] == 65973496 || $app["appointmentTypeID"] == 66032439 || $app["appointmentTypeID"] == 69787255){
        $apartment_type = ["One Bedroom Apartment"];
        $noBedrooms = 1;
    } else if ($app["appointmentTypeID"] == 64186999 || $app["appointmentTypeID"] == 63861002 || $app["appointmentTypeID"] == 69787279){
        $apartment_type = ["Two Bedroom Apartment"];
        $noBedrooms = 2;
    }  else if ($app["appointmentTypeID"] == 65973515 || $app["appointmentTypeID"] == 63861042 || $app["appointmentTypeID"] == 69787294) {
        $apartment_type = ["Three Bedroom Apartment"];
        $noBedrooms = 3;
    }

    $lead = [
        "First_Name" => $first_name,
        "Last_Name" => $last_name,
        "Mobile" => $mobile,
        "Email" => $email,
        "Inspection_Date_Time" => $start_time->format("c"),
        "Acuity_Inspection_URL" => $app["confirmationPage"],
        "Submit_application" => '',
        "Type1" => $apartment_type,
        "Do_you_have_pets" => $havePet,
        "Number_of_Bedrooms_NEW" => (string)$noBedrooms,
        "Inspection_Scheduled_By" => isset($app["scheduledBy"]) ? $app["scheduledBy"] : ""
    ];

    $noteIns = ZCRMNote::getInstance();
    $noteIns->setContent("Lead booked inspection for ".$start_time->format("H:iA d/m/Y").".");
    $noteIns->setTitle("Inspection Booked");
    
    if($match){
        $updateData = null;
        $recordId = $match->getEntityId();
        if($module == "Leads") {
            // Update Lead details if existence check is successful 
            $noInspections = !empty($match->getFieldValue("Number_of_Inspections")) ? $match->getFieldValue("Number_of_Inspections") : 0;
            $noInspections++;
            $lead["Number_of_Inspections"] = $noInspections;
            $new_status = getStatusUpdate($match, $action);
            if($new_status){
                $lead["Enquiry_Booking_Status"] = $new_status;
            }
            $updateData = $lead;
        } else {
            $existing_apartment_type = $match->getFieldValue("Apartment_Type");
            $apartment_type = array_merge($existing_apartment_type, $apartment_type);
            $updateData = [
                "Inspection_Date_Time" => $start_time->format("c"),
                "Acuity_Inspection_URL" => $app["confirmationPage"],
                "Apartment_Type" => $apartment_type,
                "Number_of_Bedrooms_NEW" => (string)$noBedrooms
            ];
        }

        try{
            $res = $sc->zoho->updateRecord(
                $module,
                $recordId,
                $updateData,
                ["workflow"]
            );
            $sc->log->info("Success updating $module $recordId with information from Acuity appt $appointment_id.");
        }catch(Exception $e){
            $sc->log->error("Error while updating $module $recordId with Booking URL '{$app["confirmationPage"]}' $appointment_id. ".$e->getMessage()." ".json_encode($e->getExceptionDetails()));
            exit;
        }
	//
	if($app["appointmentTypeID"] == 70298754 || $app["appointmentTypeID"] == 70299066){
		$currentTags = $match->getTags();
		$currentTagNames = [];
		foreach($currentTags as $tag){
		    $currentTagNames[] = $tag->getName();
		}
		if($app["appointmentTypeID"] == 70298754){
			$newTags = ["Indi Experience - RSVP"];
		}
		else{
			$newTags = ["Meet Neighbours - RSVP"];
		}
	    	// Remove any existing tags from tag update
		    
		$tagsThatDontExist = array_diff($newTags, $currentTagNames);
		if(!empty($tagsThatDontExist)){
			try{
		            $res = $match->addTags($tagsThatDontExist);
		            $sc->log->info("Success adding Tags to for Acuity appt", [
				   "appointment_id" => $appointment_id,
				   "tags" => $tagsThatDontExist
			    ]);
		        } catch(Exception $e){
		            $sc->log->error("Error while adding Tags to CRM for Acuity appt", [
		            "module" => $module,
		            "appointment_id" => $appointment_id,
		            "exception" => $e->getMessage(),
		            "details" => $e->getExceptionDetails()
		            ]);
		            exit;
		        }
		}
	}
	
	    
    } else {
        $module = "Leads";
        // Create new Lead if existence check is unsuccessful
	if($app["appointmentTypeID"] == 70298754 || $app["appointmentTypeID"] == 70299066){
		if($app["appointmentTypeID"] == 70298754){
			$lead["Tag"] = ['Indi Experience - RSVP'];
		}
		else{
			$lead["Tag"] = ['Meet Neighbours - RSVP'];
		}
		$lead["Source_NEW"] = "Scheduler";
	        $lead["Method_NEW"] = "Event";
	}
	else{
	        $lead["Source_NEW"] = "Scheduler";
	        $lead["Method_NEW"] = "Web";
	}
        $lead["Enquiry_Booking_Status"] = "New - Inspection Booked";
        $lead["Number_of_Inspections"] = 1;
        try{
            $res = $sc->zoho->createRecord($module, $lead, ["workflow"]);
            $recordId = $res["id"];
            $sc->log->info("Success creating $module $recordId for Acuity appt with id $appointment_id.");
        }catch(Exception $e){
            $sc->log->error("Error while adding $module to CRM for Acuity appt with id $appointment_id. ".$e->getMessage()." ".json_encode($e->getExceptionDetails()));
            exit;
        }
    }

    try{
        $res = $sc->zoho->addRecordNote($module, $recordId, $noteIns);
        $sc->log->info("Success adding note to CRM $module $recordId in CRM for Acuity appt with id $appointment_id.");
    }catch(Exception $e){
        $sc->log->error("Error while adding note to CRM $module $recordId for Acuity appt with id $appointment_id. ".$e->getMessage());
        exit;
    }

    try{
        $row = Bookings::create([
            "module" => $module,
            "record_id" => $recordId,
            "client" => $sc->clientCode, 
            "appointment_id" => $appointment_id,
            "calendar_id" => $app["calendarID"],
            "data" => json_encode($app),
        ]);
    } catch(Exception $e){
        $sc->log->error("Error while insering record Appointment into db. ".$e->getMessage());
    }
 
}

function sendEmail($subject, $body, $sc){
    $from = 'noreply@scripts.squirrelcrmhub.com.au';
	$bcc  = 'ali+assemble_macaulay@squirrel.biz';
    $sc->sendTemplateEmail(
		"general",
		ERROR_NOTIFICATION, 
		$subject, 
		[ "body" => $body ],
		false,
		$bcc,
		'text/html',
		$from
	);
}

function formatPhone($phone){
    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
    $phone = str_replace(" ","", strval($phone));
    if(!empty($phone) && strlen($phone) > 8){
        $phoneProto  = $phoneUtil->parse($phone, "AU");
        $formattedPhone = $phoneUtil->format($phoneProto, \libphonenumber\PhoneNumberFormat::E164);
        $phone = $formattedPhone;
    }
    return $phone;
}

function getStatusUpdate($crmRecord, $action){
    if(empty($crmRecord)){
        return "New - Inspection Booked";
    }
    $current_enquiry_status = $crmRecord->getFieldValue("Enquiry_Booking_Status");
    if(!in_array($current_enquiry_status, VALID_OVERWRITE_STATUSES)){
        return false;
    }

    if($action == "canceled"){
        return "Inspection Cancelled";
    }

    return "Inspection booked";
}

function checkExistence($module, $email, $mobile) {
    Global $sc;
    $match = null;

    // Check for match on Email
    try{
		$matching_email_records = $sc->zoho->searchRecordsByCriteria($module, "(Email:equals:$email)");
        if($matching_email_records){
            foreach($matching_email_records as $record){
                if(strtolower($record->getFieldValue("Email")) == strtolower($email)){
                    $match = $record;
                }
            }
        }
	} catch(Exception $e){  
		$sc->log->error("Error while searching $module in CRM using email $email as criteria. ".$e->getMessage().print_r($e->getExceptionDetails(),true));
	}

    // Check for match on Mobile if no match found for Email
    if(empty($match)){
        try{
            $matching_mobile_records = $sc->zoho->searchRecordsByCriteria($module, "(Mobile:equals:$mobile)");
            if($matching_mobile_records){
                foreach($matching_mobile_records as $record){
                    if($record->getFieldValue("Mobile") == $mobile){
                        $match = $record;
                    }
                }
            }
        } catch(Exception $e){  
            $sc->log->error("Error while searching $module in CRM using mobile $mobile as criteria. ".$e->getMessage().print_r($e->getExceptionDetails(),true));
        }
    }

    return $match;
}
