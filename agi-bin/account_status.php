#!/usr/bin/php -q 
<?php
/**
 * AGI script for saying account balance to customer.
 * Must be calld with two parameters: debt and dialId. Debt contains customer balance 
 * in positive decimal form. DialId is ID of corresponding dial scenario (set
 * of phrases to be announced)
 */

$stdlog = fopen("/var/log/asterisk/account_status.log", "a");

// Help in building balance phrases
$zeros[1] = '0';
$zeros[2] = '00';
$zeros[3] = '000';
$zeros[4] = '0000';


// toggle debugging output (more verbose) 
$debug = true;

// Do function definitions before we start the main loop 
// Write debug info to console and to file
function errlog($line, $level = 1) {
    global $stdlog;
    if (!is_numeric($level)) {
        $level = 1;
    }
    fwrite(STDOUT, "VERBOSE \"$line\" $level \n");
    fflush(STDOUT);
    fputs($stdlog, date("d-m-y H:i") . " Debug: $line\n");
}

// Read data from STDIN
function read() {
    global $debug;
    $input = str_replace("\n", "", fgets(STDIN, 4096));
    if ($debug)
        errlog($input);
    return $input;
}

// Write data to STDOUT
function write($line) {
    global $debug;
    if ($debug)
        errlog($line);
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

function db_init() {
    $link = mysql_connect('localhost', 'asterisk', 'ast_pwd');
    if (!$link)
        die(mysql_error());
    mysql_select_db('asterisk');
    return $link;
}

function execute_agi($command) {
    write($command);
    $result = read();
    $ret = array('code' => -1, 'result' => -1, 'timeout' => false, 'data' => '');
    if (preg_match("/^([0-9]{1,3}) (.*)/", $result, $matches)) {
        $ret['code'] = $matches[1];
        $ret['result'] = 0;
        if (preg_match('/^result=([0-9a-zA-Z]*)(?:\s?\((.*?)\))?$/', $matches[2], $match)) {
            $ret['result'] = $match[1];
            $ret['timeout'] = ($match[2] === 'timeout') ? true : false;
            $ret['data'] = $match[2];
        }
    }
    return $ret;
}

// +++++++++  HERE WE START ++++++++++

// parse agi headers into $agi array 
while ($env = read()) {
    $s = split(": ", $env);
    $agi[str_replace("agi_", "", $s[0])] = trim($s[1]);
    if (($env == "") || ($env == "\n")) {
        break;
    }
}

// Init dialplan and debt vers 
$dialIdArr = execute_agi("GET VARIABLE dialId");
$dialId = $dialIdArr['data'];

$debts = execute_agi("GET VARIABLE debt");
list($debt['bal_grn'], $debt['bal_kop']) = split('\.', (string) $debts['data']);

// $debts = 116.43;
// $debt['bal_grn'] = "116";
// $debt['bal_kop'] = 20; 
// Select phrases and soundfiles for required scenario
$db_link = db_init();
$query = "SELECT p.filename, s.phraseId FROM c_scenario s, c_dial d, c_phrases p 
               WHERE s.profileId=d.profileId AND s.phraseId = p.phraseId AND d.dialplanId=$dialId
               ORDER BY s.ord ASC";
$res = mysql_query($query);
if (!$res) {
    errlog(mysql_error());
    exit;
} else {
    $sounds = array();
    while ($row = mysql_fetch_assoc($res)) {
        $sounds[] = $row;
    }
}

// errlog(" VERBOSE \"Debt: ".$debt['bal_grn'].",  ".$debt['bal_kop']."\"");

// Split balance by hundreds, tens and ones: 126 -> 100 and 20 and 6; 
$grns = $debt['bal_grn'];
$i = 0;
while ($grns >= 1) {
    $grns1 = $grns / 10;
    if (($grns - 10 * (floor($grns1))) != 0) {
        if ($g == 'grn') {
            $name[$i++] = $grns - 10 * (floor($grns1)) . $zeros[strlen($debt['bal_grn']) - strlen($grns)];
        } else {
            $name[$i++] = $grns - 10 * (floor($grns1)) . $zeros[strlen($debt['bal_grn']) - strlen($grns)] . "grn";
            $g = "grn";
        }
    }
    $grns = floor($grns1);
}

// Correct tens of balance if needed: 116 -> 100 and 10 and 6 -> 100 and 16
if ($name[1] == 10) {
    if ($name[0] == 0) {
        $name[1] = '10grn';
    } else {
        $name[1] = "1" . $name[0];
    }
    unset($name[0]);
}

if ($debt['bal_grn'] == 0)
    $name[0] = "0grn";

// Correct order output algorithm
$name = array_reverse($name);

// Deal with pennies
$kops = (string) $debt['bal_kop'];

// $kops = '52';

if (strlen($kops) == 2) {
    if ($kops[0] == 1) {
        $name[count($name)] = $kops . 'k';
    } else {
//	$name[count($name)] = $kops[0];
        if ($kops[1] != 0) {
            $name[count($name)] = $kops[0] . "0";
            $name[count($name)] = $kops[1] . 'k';
        }
        else
            $name[count($name)] = $kops . 'k';
    }
}
else
    $name[count($name)] = $kops . '0k';

// Lets say it loud
foreach ($sounds as $sound) {
    // $sound['phraseId] == 1 -> At this moment we say balance
    if ($sound['phraseId'] != 1) {
        execute_agi("EXEC Playback \"odeko/phrases/" . substr($sound['filename'], 0, -4) . "\"");
    } else {
        foreach ($name as $key => $value) {
            // write ("EXEC Playback \"odeko/$value\" ");    
            execute_agi("EXEC Playback \"odeko/$value\" ");
        }
    }
}
// clean up file handlers etc. 
fclose($stdlog);
mysql_close($db_link);
exit;
?>  



