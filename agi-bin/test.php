#!/usr/bin/php
<?php

function execute_agi($command) {
     fwrite(STDOUT, "$command\n");
     fflush(STDOUT);
     $result = fgets(STDIN);
     $ret = array('code'=> -1, 'result'=> -1, 'timeout'=> false, 'data'=> '');
     if (preg_match("/^([0-9]{1,3}) (.*)/", $result, $matches)) {
         $ret['code'] = $matches[1];
         $ret['result'] = 0;
         if (preg_match('/^result=([0-9a-zA-Z]*)(?:\s?\((.*?)\))?$/', $matches[2], $match))  {
               $ret['result'] = $match[1];
               $ret['timeout'] = ($match[2] === 'timeout') ? true : false;
               $ret['data'] = $match[2];
         }
     }
     return $ret;
}
                                                                                                               
function log_agi($entry, $level = 1) {
     if (!is_numeric($level)) {
          $level = 1;
     }
     $result = execute_agi("VERBOSE \"$entry\" $level");
}


function db_crash($last_message)
{
   log_agi($last_message.'. MySQL Error was: '.mysql_error());
   die('MySQL problems. Error was '.mysql_error());
}

function agi_hangup_handler($signo) {
      //this function is run when Asterisk kills your script ($signo is always 1)
      //close file handles, write database records, etc.
}

/*
This script answers the call and prompts for an extension, provided your caller ID is approved.
When it receives 4 digits it will read them back to you and hang up.
*/
$debug_mode = false; //debug mode writes extra data to the log file below whenever an AGI command is executed
$log_file = '/tmp/agitest.log'; //log file to use in debug mode

// Parameters of MySQL connection
$db_host = 'localhost';
$db_user = 'asterisk';
$db_pass = 'ast_pwd';
$db_schema = 'asterisk';

// Get connected to MySQL
$conn = mysql_connect($db_host, $db_user, $db_pass);
if (!$conn)
{
   db_crash('Cannot connect to MySQL server');
   exit;
}

// Successfully - lets select DB
$res = mysql_select_db($db_schema);
if (!$res)
{
   db_crash('Cannot select DB');
   exit;
}

//get the AGI variables; we will check caller id
$agivars = array();
while (!feof(STDIN)) {
  $agivar = trim(fgets(STDIN));
  if ($agivar === '') {
       break;
  }
  else {
       $agivar = explode(':', $agivar);
       $agivars[$agivar[0]] = trim($agivar[1]);
  }
}
foreach($agivars as $k=>$v) {
  log_agi("Got $k=$v");
}
extract($agivars);

//

   log_agi('Received operator:'.$operator_code.', pin:'.$operator_pin,1);

// Lets get SIPURI variable for operator's interface

$result = execute_agi("GET VARIABLE SIPURI");
$interface = 'SIP/'.substr($result['data'],4);
$list = execute_agi('SET VARIABLE reg SIPPEER(SIP/max_test)');
$list = execute_agi('GET VARIABLE reg');
log_agi($list['data']);
// OK, done. We have 1) $operator_code, 2) $pin and 3) operator's $interface
// Now lets update database (table AGENTS) with these data

log_agi('Extention dialed is '.$agi_extension);

log_agi('Interface for operator '.$operator_code.' is '.$interface);

exit;
?>
                        