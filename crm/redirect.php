<?php

require getenv('SQUIRREL_CLIENT_LIB_V2');

const CLIENT_CODE = 'assemble_macaulay';
const DEBUG_MODE = false;
const CONTEXT = 'Redirect';

try {
    $sc = new SquirrelClient(CLIENT_CODE, DEBUG_MODE, CONTEXT);
    if($sc->debug){ echo "<pre>"; }
}  catch(Exception $e){
    //print_r("Error while initializing Squirrel Client API: ".$e->getMessage());
    exit;
}

if(!isset($_GET["record_id"]) && !isset($_GET['module'])){
    $sc->log->warning("No params set!", [
        "data" => $_GET
    ]);
    exit;
}


$moduleName = $_GET['module'];
$recordId = $_GET["record_id"];

try{
    $res = $sc->zoho->updateRecord($moduleName, $recordId, ["Marketing_Opt_Out" => true], ["workflow"]);
    $sc->log->debug("Success unsubscribing record.", [
        "module" => $moduleName,
        "record_id" => $recordId
    ]);
} catch(Exception $e){
    $sc->log->error("Error while unsubscribing record.", [
        "module" => $moduleName,
        "record_id" => $recordId,
        "error" => $e->getMessage()
    ]);
    exit;
}

header('Location: https://www.indi.com.au/unsubscribed'); //TODO - We need to change this URL to the new one

/*<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#ab5d5d] flex flex-col items-center h-screen justify-center gap-5">
<img src="https://resource.rentcafe.com/image/upload/x_0,y_0,w_663,h_452,c_crop/q_auto,f_auto,c_limit,w_73,h_50/s3au/2/82414/primary-logo-white.png">
<h1 class="text-[#f1ded8] text-xl">You have been unsubscribed.</h1>
</body>
</html>*/