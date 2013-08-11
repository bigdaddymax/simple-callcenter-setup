#!/usr/bin/php
<?php

function writelog($message, $function, $line)
{
    global $log_file, $debug_mode;
    $h = fopen($log_file, 'a+');
    if ($debug_mode)
	$message= date("d-m-y H:i").' '.$function.'['.$line.']: '.$message."\n";
    else $message = date("d-m-y H:i").' '.$message."\n";
    fwrite($h, $message);
    fclose($h);
    
}

function db_crash($last_message)
{
   writelog($last_message.'. MySQL Error was: '.mysql_error(), __FUNCTION__, __LINE__);
   die('MySQL problems. Error was '.mysql_error());
}

$debug_mode = true; //debug mode writes extra data to the log file below whenever an AGI command is executed
$log_file = '/tmp/correct_codes.log'; //log file to use in debug mode

// Parameters of MySQL connection
$db_host = 'localhost';
$db_user = 'asterisk';
$db_pass = 'ast_pwd';
$db_schema = 'asterisk';

// Get connected to MySQL
$conn = mysql_connect($db_host, $db_user, $db_pass);
if (!$conn)
{
   db_crash('Cannot connect to MySQL server', __FUNCTION__, __LINE__);
   exit;
}

// Successfully - lets select DB
$res = mysql_select_db($db_schema);
if (!$res)
{
   db_crash('Cannot select DB', __FUNCTION__, __LINE__);
   exit;
}

$query = 'SELECT id, interface FROM agents WHERE interface<>""';
$stmt = mysql_query($query);
if (!$stmt) db_crash('Cannot select data from AGENTS table');

while($row = mysql_fetch_row($stmt))
{
    writelog($row[0].' '.$row[1], __FUNCTION__, __LINE__);
    $query = "UPDATE cdr set accountcode = '".$row[0]."' WHERE accountcode='' AND substring_index(userfield, '@',1) = '".$row[1]."'";
    $stmt1 = mysql_query($query);
    if(!$stmt1) db_crash('Trying to update CDR');
}
exit;
?>
                        