#!/usr/bin/env php
<?php

/*
  This is what snmptrap is calling to add events into
  a database

  Setup all defaults at the very beginning
*/

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Curl.php';
require __DIR__ . '/../app/Logger.php';
date_default_timezone_set("UTC");

$logger = new Logger(basename(__FILE__), 0);

// Recieve the trap data
$f = fopen( 'php://stdin', 'r' );
$raw='';
while( $line = fgets( $f ) ) {
  $raw=$raw . $line;
}
fclose( $f );

$data=explode("\n", "$raw");  // convert all newlines into an array
$data=array_filter($data);    // clean the array of nulls and empty

// Set defaults that every trap must have defined
$details    = array();
$hostname   = gethostname();
$counter    = 1;
$receiver   = "trap";
$event_oid  = '';
$event_type = 1;
$monitor    = 0;

// Basic Datafill foreach loop
foreach ($data as $key => $value){
  $value = preg_replace('/iso\./', '1.' , $value);
  // echo "BASIC DATAFILL LOOP Key: $key; Value: $value \n"; // DEBUG log to /var/log/syslog
  if     ($key == 0) { $event_source= "$value"; }
  elseif ($key == 1) { $raw_source  = "$value"; }
  switch ($value) {
    case strpos($value, '1.3.6.1.6.3.18.1.3.0 ' ) !== false:
      $e_ip=explode(" ", "$value",2);
      $event_ip = $e_ip[1];  // source IP address that sent the trap
      break;
    case strpos($value, '1.3.6.1.6.3.18.1.4.0 ' ) !== false:
      $com=explode(' ', $value,2 );
      $community = $com[1]; // community string sent in varbind
      break;
    case strpos($value,  '1.3.6.1.2.1.1.3.0 ' ) !== false:
      $t_sys=explode(' ', $value, 2);
      $systime = $t_sys[1];
      break;
    // https://oidref.com/1.3.6.1.6.3.1.1.4
    // 4.1.0 raw trap oid
    // 4.3.0 enterprise associated with 4.1.0.  4.1.0 adds random values to end to make tables?!?  Why?
    case strpos($value, '1.3.6.1.6.3.1.1.4.1.0 ' ) !== false:
      $e_oid=explode(" ", "$value",2);
      $event_oid = $e_oid[1]; // snmpTrapOID
      // array_push(".1.3.6.1.6.3.1.1.4.1.0", $event_oid);
      $details[] = '.1.3.6.1.6.3.1.1.4.1.0 ' . $event_oid;
      break;
    case strpos($value, '1.3.6.1.6.3.1.1.4.3.0 ' ) !== false:
      $r_oid=explode(" ", $value,2);
      // $event_oid=$r_oid[1];
      $raw_oid = $r_oid[1];  // snmpTrapEnterprise
      break;
    default:
      array_push($details, $value);    // anything that we did not explicity match goes here
      break;
  }
}

// echo "EVENT OID " . $event_oid . "\n";  // logs to /var/log/syslog

// I would call this a bug as DNS should be set for all devices, but meh..  There are screwy ones out there.
if ($event_source == "<UNKNOWN>") {
  $logger->warning("Likely DNS failure in trap transform.  Event source is listed as UNKNOWN.  Setting as event_ip");
  $event_source = $event_ip;
}

// Attempt to set the proxy IP address (not a critical thing, but good to know if given)
if (!empty($raw_source)) {
  $filteredRawSource = explode(']:',$raw_source);
  $cleanedRawSource  = preg_replace('/.*.\[/', '', $filteredRawSource[0]);
  $event_source      = $cleanedRawSource;
  $logger->info("Parse event source IP as $event_source for Proxy source");
}

/*
  DEBUG
  echo "Basic Datafill foreach loop \n";
  print_r($data);
*/

// Save all of the trap information and attempt to set event details if it is empty
if (print_r($details) == 1){
  $details=array();
}

if (! $details ) {
  foreach ($data as $key2 => $value2) {
    $value2 = preg_replace( '/iso\./','1.',$value2);
    echo "add trap details if empty : key $key2  value $value2\n";
    array_push($details, $value2);
  }
}
/*
  DEBUG
  echo "Add ALL of the trap information into array before encoding \n";
  print_r($details);
*/

// Clean up the array
$details = array_filter($details);

//sort it so summary oid is first
asort($details);

// Reindex the array so summary oid is $varible[0]
array_multisort($details);

/*
  JSON encode and decode the array so we can put it in the database and also parse it better
  this is more of a belt-and-suspenders thing, but does seem to make
  the whole script more stable.
*/
$details       = json_encode($details);
$details_array = json_decode($details, true);

/* Attempt to start setting summary and event sources */
if (empty($event_ip)) {
  $event_ip = $event_source;
}

$logger->debug("SNMP trap received IP address " . $event_ip);

/*
  The first preg_replace should not find stuff, as we have turned off MIB parsing
  due to not being able to guarentee behavior on traps
*/
if (empty($event_ip)) {  // This should never happen, and is a last resort
  $event_ip      = preg_replace('/.*SNMP-COMMUNITY-MIB::snmpTrapAddress.0 /','', $details);
  $event_ip      = preg_replace('/".*/','', $event_ip);
  $event_summary = "SNMP trap received from $event_ip with translation done";
}
else {
  $event_summary = "SNMP trap received from $event_ip";
}
//echo "Set summary and event sources ". $event_ip . " and source defined as " . $event_source . "\n"; // logs to /var/log/syslog

// Get some kind of raw_oid value set?  why if it was not given freely?
if (empty($raw_oid)) {
  $raw_oid='';
}

// We now run a preg_replace to create raw_oid if it is not set
if (empty($raw_oid)) { // This should never happen, and is a last resort
  // echo "DAMMIT " . $details . "\n"; // logs to /var/log/syslog
  $raw_oid=(preg_replace('/.*snmpTrapOID.0 /','', $details));
  $raw_oid=(preg_replace('/".*/','', $raw_oid));
}

/*
  DEBUG
  echo "Set raw_oid values " . $raw_oid . "\n";
*/


/*
  This will need a better safety net in the future!
  Someone out there may have two networks with identical
  subnets, that will have to be taken into account??
*/

if (filter_var($event_ip, FILTER_VALIDATE_IP)) {
  $logger->debug("Searching for IP address ". $event_ip . "  Hope it is really an IP.  It should be if we get to here.");
}
else {
  // If we are not dealing with a valid IP address do something about it if possible
  if (strpos($event_ip, "UNKNOWN") !== false) {
    $logger->warning("Dealing with an UNKNOWN result.  Use caution on saving values into database");
  }
  else {
    $logger->debug("We in theory know an IP address, and will try for a hostname now");
  }

  $logger->debug("Searching for IP address ". $event_ip . "  Using DNS to attempt to find IP and set that way");
  $event_ip=gethostbyname("$event_ip");
  $logger->debug("Restults for function gethostbyname has set our IP address as ". $event_ip . " Hope this is correct ");
}

/*
  Search event IP address in database
  Do this via Curl NOT database direct
*/
$findIpAddressApi = new Curl();
$findIpAddressApi->url = $apiUrl . ":" . $apiPort . "/device/find";
$findIpAddressApi->method = "post";
$searchParam['address'] = $event_ip;
$findIpAddressApi->data($searchParam);
$findIpAddressApi->send();
$resultFindDevice = $findIpAddressApi->content();
$findIpAddressApi->close();
$logger->debug("Search Device table for address to get hostname" . json_encode($resultFindDevice,1) );


// print_r($resultFindDevice);
$resultFindDevice = json_decode($resultFindDevice, true);
// echo "Database returned hostname " . $resultFindDevice['data'][0]['hostname'] . "\n";  // logs to /var/log/syslog



$logger->debug("Curl result data for finding a device " . json_encode($resultFindDevice,1) );


if ( isset($resultFindDevice['data'][0]['hostname'])) {
  $known_hostname = $resultFindDevice['data'][0]['hostname'];
  $logger->info("Found hostname in Device table " . $known_hostname);
}
else {
  $known_hostname = $event_ip;
  $monitor = 0;
  $logger->info("Hostname not found in Device table.  Assuming new device seen " . $known_hostname);

  $logger->info("Adding host to Device table now " . $known_hostname);

  $deviceDetails['hostname'] = $known_hostname;
  $deviceDetails['address'] = $event_ip;
  $deviceDetails['productionState'] = $monitor;
  $logger->debug("Args to create new device " . json_encode($deviceDetails,1));

  $insertDevice = new Curl();
  $insertDevice->url = $apiUrl . ":" . $apiPort . "/device/create";
  $insertDevice->method = "post";
  $insertDevice->data($deviceDetails);
  $insertDevice->send();
  $insertDeviceResult = $insertDevice->content();
  $insertDevice->close();
  $logger->debug("Result of create device " . json_encode($insertDeviceResult,1));
}



// echo "RAW OID " . $raw_oid . "\n\n";  // logs to /var/log/syslog

$known_hostname = trim($known_hostname);  // Hopefully a FQDN for a device.  If not the IP address is the fallback
$counter        = (int)$counter;          // Should always be 1
$receiver       = trim($receiver);        // Usually API server public IP address, but can be otherwise as well
$event_ip       = trim($event_ip);        // What alarmed
$event_source   = trim($event_source);    // Proxy IP for source of event (ie trap forwarded from this ip)
$event_name     = trim($event_oid);       // this is what is matched in in the table trapEventMap and runs the transforms
$event_type     = (int)$event_type;       // 0-X  1 = trap, 3 = daemon.  Useful in the future more than now
$monitor        = (int)$monitor;          // 0 or 1.  Is trap monitored as an event. (future code)
$event_summary  = trim($event_summary);   // Catchall summary.  Should be changed in the preProcessing

$insertParams['device']       = $known_hostname;
$insertParams['eventAddress'] = $event_ip;
$insertParams['eventReceiver']= $receiver;
$insertParams['eventProxyIp'] = $event_source;
$insertParams['eventName']    = $event_name;
$insertParams['eventType']    = $event_type;
$insertParams['eventMonitor'] = $monitor;
$insertParams['eventSummary'] = $event_summary;
$insertParams['eventDetails'] = $details;
// $insertParams['eventDetails'] = json_encode($details,1);  // Must be array for mappings

$insertParams['eventRaw']     = $details;
//$insertParams['eventRaw']     = json_encode($details,1);  // This should not need manipulation elsewhere closer to a log

$logger->debug("Curl insertParameters for event " . json_encode($insertParams,1));
 print_r($insertParams);  // logs to /var/log/syslog

$insertTrap         = new Curl();
$insertTrap->url    = $apiUrl . ":" . $apiPort . "/trap";
$insertTrap->method = "post";
$insertTrap->data($insertParams);
$insertTrap->send();
$resultInsertTrap = $insertTrap->content();
$insertTrap->close();

$logger->debug("Curl object " . json_encode($insertTrap,1) );
$logger->debug("Curl result data for sending event " . json_encode($resultInsertTrap,1) );
$logger->info("SNMP trap received. Host: " . $known_hostname . " Event Name: " . $event_name);
?>
