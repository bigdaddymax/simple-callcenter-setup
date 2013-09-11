simple-callcenter-setup
====================
The project has two practical aims for me: 

* setup fully functional callcenter based on Asterisk 
* to learn Zend Framework on some real project
* to create a web based application for controlling this callcenter for our company (these steps will be described in separate project).

As the application works with Asterisk VoIP platform (http://www.asterisk.org/) 
some necessery configuration steps of Asterisk are also discribed later.

What we will need?
-----------------

1. Asterisk instalation.
2. MySQL/Apache/PHP
3. Zend Framwork 1.x (this project uses particular ZF 1.10, but I don't think there 
are some version related problems with older or newer versions of ZF).

Features
--------------------------------

We are going to build real callcenter application with all features that real callcenters have:

* handle simultaneous calls on the same inbound phone number
* provide calls queue feature with posibility to leave voice message
* calls recording (monitoring)
* operators performance tracking
* flexible call flow scenarios
* autodialing customers with prerecorded messaging (for example, due balance, info etc)
* monitoring outgoing calls as well

All incoming calls are processed according to scenarios that are created via web
interface (web application will be described separately).

Asterisk configuration.
--------------------

#### General idea

We will use both extensions.ael and extensions.conf

Extensions.ael contains all logic of internal call handling - queues description, voice messages, transfers etc. 

Most contexts and internal logic of extensions.conf will be generated via web application. Extensions.conf will contain 
only one preconfigured context - IVRmenues. Everything else will be created during web app setup.

Also we want to store everything we can in database - CDR and queues logs - for future use by our app.

#### DB setup

In db.sql you can find sample setup of mysql database. Also you have to configure res_config_mysql.conf and cdr_mysql.conf with your DB 
parameters and connection credentials.
To force Asterisk to save CDR data and queue logs to DB uncomment following line in extconfig.conf:

    queue_log=>mysql,asterisk

#### Queues

Now we define our queues:

     [callcenterq]				                   // Queue name
     music=default                                                 // Music to be played while waiting for operator
     context=tech_voicemail                                        // Where caller lend if he dials any number while waiting, allow him to leave message
     strategy=ringall                                              // All operators are called
     joinempty=strict                                              // If no operators caller cannot join queue
     leavewhenempty=strict                                         // When last operator leaves all callers in queue will be kicked out, sorry.
     periodic-announce=odeko/all_operators_busy                    // Announcement to be periodically played
     periodic-announce-frequency=20                                // How often it will be played
     monitor-format = gsm                                          // How to store recorded calls (we record everything)
     setinterfacevar=yes                                           // We need this to preserve interface variable for keep tracking of operators activity
     wrapuptime=10                                                 // Give operator 10 sec before next call can come

#### Extensions.ael

In this file we define behaviour of all parts of our callcenter. We want that all calls go through "inbound" context.
Also we define here contexts for our queues, voice messaging and outbound calls, agents (if they us).

For operators everything begins from dialing 2880 (you can configure whatever extension you want). Such a call is routed to check_operator.php AGI script. See file for further details.
Script allows operator to login to system from any interface, so operator is not tied to particular physical location or particular port.
After successful login operator's interface is added to the queue and operator is ready to receive calls.
To logout operator dials 2881.

Operators can transfer calls to other queues or extensions, this feature is secured by *transfered* context and setting variable  
    
    Set(__TRANSFER_CONTEXT=transfered)

While in queues callers can leave a voice message to operator by dialing any digit:
 
    *queues.conf:*
        context=tech_voicemail

    *extensions.ael:*
    context tech_voicemail {
	_X => {
		Voicemail(7007@default);
	}
    }


 

