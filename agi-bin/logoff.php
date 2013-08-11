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

$i = 0;
while ($gotcode != TRUE)
{
   $i++;
   $result = execute_agi("GET DATA odeko/vvedit_kod_operatora 5000 4");
   if ($i<4)
   {
      if ($result['result'])
      {
          $operator_code = $result['result'];
          $query = 'SELECT pin FROM agents WHERE agentId="'.$operator_code.'"';
          $res = mysql_query($query);
          if (!res) 
          {
               db_crash('Error in query');  
          }
          if (mysql_num_rows($res)!=0)
          {
            $row =  mysql_fetch_row($res);
            $pin = $row[0];
            log_agi('PIN from database: '.$pin);
            $gotcode = TRUE;      
          }
       }
   }
   else
   {
         execute_agi("STREAM FILE odeko/diakuju_do_pobachennia \"\"");
         execute_agi("HANGUP");
         exit;
   }
}

$gotcode = FALSE;
$i = 0;
while ($gotcode != TRUE)
{
   $i++;
   if ($i<4)
   {
      $result = execute_agi("GET DATA odeko/vvedit_parol 5000 4");
      if($result['result']>1)
      {
          $operator_pin = $result['result'];
          if($pin == $operator_pin)
          {
              $gotcode = TRUE;
          }
      }
   }
   else
   {
          execute_agi("STREAM FILE odeko/diakuju_do_pobachennia \"\"");
          execute_agi("HANGUP");
          exit;
   }
}
   log_agi('Received operator:'.$operator_code.', pin:'.$operator_pin,1);

// Lets get SIPURI variable for operator's interface

$result = execute_agi("GET VARIABLE SIPURI");
$interface = 'SIP/'.substr($result['data'],4);

// OK, done. We have 1) $operator_code, 2) $pin and 3) operator's $interface
// Now lets update database (table AGENTS) with these data

log_agi('Extention dialed is '.$agi_extension);
if ($agi_extension == 2880)
{
      $query = 'SELECT interface FROM agents WHERE agentId = "'.$operator_code.'"';
      $res = mysql_query($query);
      if (!$res)
      {
            db_crash('Cannot select data from MySQL: '.mysql_error());
      }
      
      if (mysql_num_rows($res)>0)
      {
            while($row = mysql_fetch_row($res))
            {
                if ($row[0])
                {
                    execute_agi("EXEC REMOVEQUEUEMEMBER callcenterq,".$row[0]);
                    log_agi('Removed agent '.$operator_code.' on interface '.$row[0]);
                }
            }
      }
      
      $query = 'SELECT agentId FROM agents WHERE interface = "'.$interface.'"';
      $res = mysql_query($query);
      if (!$res)
      {
            db_crash('Cannot select data from MySQL: '.mysql_error());
      }
      
      if (mysql_num_rows($res)>0)
      {
            while($row = mysql_fetch_row($res))
            {
                if ($row[0])
                {
                    execute_agi("EXEC REMOVEQUEUEMEMBER callcenterq,".$interface);
                    log_agi('Removed operator '.$row[0].' on interface '.$interface);
                }
            }
      }
      
      log_agi('Added member '.$operator_code.' on '.$interface);
      $queue = "UPDATE agents SET interface='".$interface."' WHERE agentId=".$operator_code;
      $res = mysql_query($queue);
    if (!$res)
    {
         db_crash('Cannot select data from MySQL '.mysql_error());
    }      
      execute_agi("EXEC ADDQUEUEMEMBER callcenterq,".$interface);
      execute_agi("STREAM FILE odeko/uspishno_vvijshly \"\"");
}

if ($agi_extension == 2881)
{
    $query = 'SELECT agentId FROM agents WHERE interface="'.$interface.'"';
    $res = mysql_query($query);
    if (!$res)
    {
         db_crash('Cannot select data from MySQL '.mysql_error());
    }
    $row = mysql_fetch_row($res);
    log_agi('Operator '.$row[0].' is logging off on '.$interface);
    if ($row[0] == $operator_code)
    {
         execute_agi("EXEC RemoveQueueMember callcenterq,".$interface);
         log_agi('Removed member from queue on '.$inteface);
         execute_agi("STREAM FILE odeko/uspishno_vyjshly \"\"");
    }
}

execute_agi("STREAM FILE odeko/diakuju_do_pobachennia \"\"");
if (!res)
{
   db_crash('Cannot update AGENTS table. Query was '.$query);
}
log_agi('Interface for operator '.$operator_code.' is '.$interface);

exit;
?>
                        