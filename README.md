simple-callcenter-controller
====================
The project has two practical aims for me: 
* to learn Zend Framework on some real project
* to create a web based application for controlling homebrewed callcenter for our company.

As the application works with Asterisk VoIP platform (http://www.asterisk.org/) 
some necessery configuration steps of Asterisk are also discribed later.

What we will need?
-----------------

1. Asterisk instalation.
2. MySQL/Apache/PHP
3. Zend Framwork 1.x (this project uses particular ZF 1.10, but I don't think there 
are some version related problems with older or newer versions of ZF).

Few words about general algorithm
--------------------------------

We are going to build real callcenter application with all features that real callcenters have:
* handle simultaneous calls on the same inbound phone number
* provide configurable IVR with customized prompts and menues
* provide calls queues
* calls recording
* operators performance measurement
* flexible call flow scenarios
* autodialing customers with prerecorded messaging (for example, due balance, info etc)
* etc

All incoming calls are processed according to scenarios that are created via web
interface.

So lets get started.

Asterisk configuration.
--------------------

#### General idea

All calls are routed into IVRmenues extension.

In case if you for some reason has the same weird approach as I do and like to install everything
from sources here is small tip that might save you some time when compiling asterisk.
 
If you use clean Ubuntu server for this project the first steps before compiling 
source code should be:
##### apt-get install build-essential
##### apt-get install libncurses-dev

After Asterisk installation complete and you have Asterisk up and running double
check that it is compiled with mysql support. To see if that is true run following 
command in asterisk CLI:
##### asterisk*CLI> module show like mysql

You should see something like:
##### Module Description Use Count
##### cdr_addon_mysql.so MySQL CDR Backend 0
##### app_addon_sql_mysql.so Simple Mysql Interface 0
##### res_config_mysql.so MySQL RealTime Configuration Driver 0
##### 3 modules loaded

If not - please refer to Asterisk documentation, all aspects of installation/configuration
are described pretty nicely on http://www.voip-info.org/wiki/view/Asterisk

Create new MySQL database "asterisk" with structure that is discibed in application/config/db.sql

Edit [general] section of files cdr_mysql.conf and res_mysql.conf:

     [general]
      dbhost = localhost
      dbname = asterisk
      dbuser = dbuser
      dbpass = dbpassword

Add *queue_log => mysql,asterisk* to file  *extconfig.conf*.

Edit file *queues.conf*:

    [general]
     monitor-type = MixMonitor

    [callcenter]
     music=default
     context=cс_voicemail
     strategy=ringall
     joinempty=yes
     leavewhenempty=no
     periodic-announce=cc/all_agents_are_busy
     periodic-announce-frequency=20
     eventwhencalled=yes
     monitor-format = gsm

Now lets move to *extensions.ael*:

    context => cc_main {
        s=>{
            Answer();
            Background(cc/agent_greeting);
            Queue(callcenter,t);
           }
    }


Here is one trick - all "hardcoded" setting of our dialplan we will keep in extensions.ael,
all settings that we will manipulate via web will be stored in extensions.conf.

