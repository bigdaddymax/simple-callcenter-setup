--
-- Database: `asterisk`
--

-- --------------------------------------------------------

--
-- Structure of table `agents`
--

CREATE TABLE IF NOT EXISTS `agents` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`agentId` varchar(40) CHARACTER SET latin1 NOT NULL,
`name` varchar(100) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
`pin` int(4) NOT NULL,
`interface` varchar(40) CHARACTER SET latin1 DEFAULT NULL,
`level` varchar(10) COLLATE utf8_unicode_ci NOT NULL,
PRIMARY KEY (`id`),
UNIQUE KEY `agentId` (`agentId`),
KEY `level` (`level`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=23 ;

-- --------------------------------------------------------

--
-- Structure of table `agents_history`
--

CREATE TABLE IF NOT EXISTS `agents_history` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`agentId` varchar(40) DEFAULT NULL,
`interface` varchar(40) NOT NULL,
`timestamp` datetime NOT NULL,
`event` varchar(40) NOT NULL,
PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=2797 ;

-- --------------------------------------------------------

--
-- Structure of table `agent_status`
--

CREATE TABLE IF NOT EXISTS `agent_status` (
`agentId` varchar(40) NOT NULL DEFAULT '',
`agentName` varchar(40) DEFAULT NULL,
`agentStatus` varchar(30) DEFAULT NULL,
`timestamp` timestamp NULL DEFAULT NULL,
`callid` double(18,6) unsigned DEFAULT '0.000000',
`queue` varchar(20) DEFAULT NULL,
PRIMARY KEY (`agentId`),
KEY `agentName` (`agentName`),
KEY `agentStatus` (`agentStatus`,`timestamp`,`callid`),
KEY `queue` (`queue`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Structure of table `call_status`
--

CREATE TABLE IF NOT EXISTS `call_status` (
`callId` varchar(32) NOT NULL,
`callerId` varchar(13) NOT NULL,
`status` varchar(30) NOT NULL,
`timestamp` timestamp NULL DEFAULT NULL,
`queue` varchar(25) NOT NULL,
`position` varchar(11) NOT NULL,
`originalPosition` varchar(11) NOT NULL,
`holdtime` varchar(11) NOT NULL,
`keyPressed` varchar(11) NOT NULL,
`callduration` int(11) NOT NULL,
`agentId` varchar(40) DEFAULT NULL,
PRIMARY KEY (`callId`),
KEY `callerId` (`callerId`),
KEY `status` (`status`),
KEY `timestamp` (`timestamp`),
KEY `queue` (`queue`),
KEY `position` (`position`,`originalPosition`,`holdtime`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Structure of table `cdr`
--

CREATE TABLE IF NOT EXISTS `cdr` (
`calldate` datetime NOT NULL,
`clid` varchar(80) NOT NULL,
`src` varchar(80) NOT NULL,
`dst` varchar(80) NOT NULL,
`dcontext` varchar(80) NOT NULL,
`channel` varchar(80) NOT NULL,
`dstchannel` varchar(80) NOT NULL,
`lastapp` varchar(80) NOT NULL,
`lastdata` varchar(80) NOT NULL,
`duration` int(11) NOT NULL,
`billsec` int(11) NOT NULL,
`disposition` varchar(45) NOT NULL,
`amaflags` int(11) NOT NULL,
`accountcode` varchar(20) NOT NULL,
`uniqueid` varchar(150) NOT NULL,
`userfield` varchar(255) NOT NULL,
KEY `calldate` (`calldate`),
KEY `channel` (`channel`),
KEY `dstchannel` (`dstchannel`),
KEY `src` (`src`),
KEY `dst` (`dst`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------


--
-- Structure of table `queue_log`
--

CREATE TABLE IF NOT EXISTS `queue_log` (
`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
`time` varchar(20) DEFAULT NULL,
`callid` varchar(32) NOT NULL DEFAULT '',
`queuename` varchar(32) NOT NULL DEFAULT '',
`agent` varchar(32) NOT NULL DEFAULT '',
`event` varchar(32) NOT NULL DEFAULT '',
`data` varchar(255) NOT NULL DEFAULT '',
PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=312335 ;

--
-- Triggers `queue_log`
--
DROP TRIGGER IF EXISTS `b_call_status`;
DELIMITER //
CREATE TRIGGER `b_call_status` BEFORE INSERT ON `queue_log`
FOR EACH ROW BEGIN
declare agent varchar(40);
declare calls int;
declare inque int;
select id into agent from agents where interface = new.agent;
IF NEW.event = 'ADDMEMBER' THEN
INSERT INTO agent_status (agentId,agentStatus,timestamp,callid) VALUES (agent,'READY',FROM_UNIXTIME(NEW.time),NULL) ON DUPLICATE KEY UPDATE agentStatus = "READY", timestamp = FROM_UNIXTIME(NEW.time), callid = NULL;
INSERT INTO agents_history (agentId, event, interface, timestamp) VALUES (agent, 'LOGIN', new.agent, now());
ELSEIF NEW.event = 'REMOVEMEMBER' THEN
INSERT INTO `agent_status` (agentId,agentStatus,timestamp,callid) VALUES (agent,'LOGGEDOUT',FROM_UNIXTIME(NEW.time),NULL) ON DUPLICATE KEY UPDATE agentStatus = "LOGGEDOUT", timestamp = FROM_UNIXTIME(NEW.time), callid = NULL;
INSERT INTO agents_history (agentId, event, interface, timestamp) VALUES (agent, 'LOGGEDOUT', new.agent, now());
UPDATE agents SET interface="" WHERE id = agent;
ELSEIF NEW.event = 'PAUSE' THEN
INSERT INTO agent_status (agentId,agentStatus,timestamp,callid) VALUES (agent,'PAUSE',FROM_UNIXTIME(NEW.time),NULL) ON DUPLICATE KEY UPDATE agentStatus = "PAUSE", timestamp = FROM_UNIXTIME(NEW.time), callid = NULL;
ELSEIF NEW.event = 'UNPAUSE' THEN
INSERT INTO `agent_status` (agentId,agentStatus,timestamp,callid) VALUES (agent,'READY',FROM_UNIXTIME(NEW.time),NULL) ON DUPLICATE KEY UPDATE agentStatus = "READY", timestamp = FROM_UNIXTIME(NEW.time), callid = NULL;
ELSEIF NEW.event = 'ENTERQUEUE' THEN
REPLACE INTO `call_status` VALUES
(NEW.callid,
replace(replace(substring(substring_index(NEW.data, '|', 2), length(substring_index(New.data, '|', 2 - 1)) + 1), '|', '')
, '|', ''),
'inQue',
FROM_UNIXTIME(NEW.time),
NEW.queuename,
'',
'',
'',
'',
'',
'');
ELSEIF NEW.event = 'CONNECT' THEN
UPDATE `call_status` SET
callid = NEW.callid, 
status = NEW.event, 
timestamp = FROM_UNIXTIME(NEW.time),
queue = NEW.queuename,
agentId = agent,
holdtime = replace(substring(substring_index(NEW.data, '|', 1), length(substring_index(NEW.data, '|', 1 - 1)) + 1), '|', '') 
where callid = NEW.callid;
INSERT INTO agent_status (agentId,agentStatus,timestamp,callid) VALUES 
(agent,NEW.event,
FROM_UNIXTIME(NEW.time),
NEW.callid) 
ON DUPLICATE KEY UPDATE 
agentStatus = NEW.event, 
timestamp = FROM_UNIXTIME(NEW.time),
callid = NEW.callid; 
ELSEIF NEW.event in ('COMPLETECALLER','COMPLETEAGENT') THEN 
UPDATE `call_status` SET 
callid = NEW.callid, 
status = NEW.event, 
timestamp = FROM_UNIXTIME(NEW.time), 
queue = NEW.queuename, 
originalPosition = replace(substring(substring_index(NEW.data, '|', 3), length(substring_index(NEW.data, '|', 3 - 1)) + 1), '|', ''),
holdtime = replace(substring(substring_index(NEW.data, '|', 1), length(substring_index(NEW.data, '|', 1 - 1)) + 1), '|', ''),
callduration = replace(substring(substring_index(NEW.data, '|', 2), length(substring_index(NEW.data, '|', 2 - 1)) + 1), '|', '') 
where callid = NEW.callid; 
INSERT INTO agent_status (agentId,agentStatus,timestamp,callid) VALUES (agent,NEW.event,FROM_UNIXTIME(NEW.time),NULL) ON DUPLICATE KEY UPDATE agentStatus = "READY", timestamp = FROM_UNIXTIME(NEW.time), callid = NULL;
ELSEIF NEW.event in ('TRANSFER') THEN
UPDATE `call_status` SET 
callid = NEW.callid,
status = NEW.event, 
timestamp = FROM_UNIXTIME(NEW.time), 
queue = NEW.queuename, 
callduration = replace(substring(substring_index(NEW.data, '|', 4), length(substring_index(NEW.data, '|', 4 - 1)) + 1), '|', ''),
holdtime = replace(substring(substring_index(NEW.data, '|', 3), length(substring_index(NEW.data, '|', 3 - 1)) + 1), '|', '')
where callid = NEW.callid; 
INSERT INTO agent_status (agentId,agentStatus,timestamp,callid) VALUES 
(agent,'READY',FROM_UNIXTIME(NEW.time),NULL)
ON DUPLICATE KEY UPDATE 
agentStatus = "READY",
timestamp = FROM_UNIXTIME(NEW.time),
callid = NULL; 
ELSEIF NEW.event in ('ABANDON','EXITEMPTY') THEN 
UPDATE `call_status` SET
callid = NEW.callid, 
status = NEW.event,
timestamp = FROM_UNIXTIME(NEW.time),
queue = NEW.queuename, 
position = replace(substring(substring_index(NEW.data, '|', 1), length(substring_index(NEW.data, '|', 1 - 1)) + 1), '|', ''),
originalPosition = replace(substring(substring_index(NEW.data, '|', 2), length(substring_index(NEW.data, '|', 2 - 1)) + 1), '|', ''),
holdtime = replace(substring(substring_index(NEW.data, '|', 3), length(substring_index(NEW.data, '|', 3 - 1)) + 1), '|', '')
where callid = NEW.callid;
ELSEIF NEW.event = 'EXITWITHKEY'THEN
UPDATE `call_status` SET 
callid = NEW.callid,
status = NEW.event, 
agentId = NEW.agent,
timestamp = FROM_UNIXTIME(NEW.time),
queue = NEW.queuename,
position = replace(substring(substring_index(NEW.data, '|', 2), length(substring_index(NEW.data, '|', 2 - 1)) + 1), '|', ''),
keyPressed = replace(substring(substring_index(NEW.data, '|', 1), length(substring_index(NEW.data, '|', 1 - 1)) + 1), '|', '')
where callid = NEW.callid;
ELSEIF NEW.event = 'EXITWITHTIMEOUT' THEN
UPDATE `call_status` SET
callid = NEW.callid,
status = NEW.event,
timestamp = FROM_UNIXTIME(NEW.time),
queue = NEW.queuename,
position = replace(substring(substring_index(NEW.data, '|', 1), length(substring_index(NEW.data, '|', 1 - 1)) + 1), '|', '')
where callid = NEW.callid;
END IF;
select count(*) into calls from call_status where status = 'CONNECT';
select count(*) into inQue from call_status where status = 'inQue';
insert into sim_calls (`calls`, `inque`, `timestamp`) VALUES (calls, inque, now());

END
//
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure of table `sim_calls`
--

CREATE TABLE IF NOT EXISTS `sim_calls` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`timestamp` datetime NOT NULL,
`calls` int(11) NOT NULL,
`inque` int(11) NOT NULL,
PRIMARY KEY (`id`),
KEY `timestamp` (`timestamp`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=189583 ;

-- --------------------------------------------------------

--
-- Structure of table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
`userId` int(11) NOT NULL AUTO_INCREMENT,
`username` varchar(100) NOT NULL,
`role` varchar(10) NOT NULL,
`passwd` varchar(100) NOT NULL,
PRIMARY KEY (`userId`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=16 ;
