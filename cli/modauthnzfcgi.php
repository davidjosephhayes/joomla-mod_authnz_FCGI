#!/usr/bin/env php
<?php
/**
 * This is a CRON script which should be called from the command-line,
 * not the web. For example something like:
 * env php /path/to/joomla/cli/app.php
 */

// Make sure we're being called from the command line, not a web interface
if (PHP_SAPI !== 'cli') die('This is a command line only application.');

// Set flag that this is a valid Joomla entry point
define('_JEXEC', 1);

// Configure error reporting to maximum for CLI output.
error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
ini_set('display_errors', 1);

// Load system defines
if (!defined('_JDEFINES')) {
	define('JPATH_BASE', dirname(dirname(__FILE__)));
	require_once JPATH_BASE . '/includes/defines.php';
}
require_once JPATH_BASE . '/includes/framework.php';

// Fool Joomla into thinking we're in the administrator with com_lawnvoice as active component
$app = JFactory::getApplication('site');
$_SERVER['HTTP_HOST'] = 'domain.com';
$_SERVER['REQUEST_METHOD'] = 'GET';

class CliModAuthnzFCGI extends JApplicationDaemon {

	public function doExecute() {

        // lots of example code pulled from https://secure.php.net/manual/en/ref.sockets.php

        $address = '127.0.0.1'; 
        $port = 6901; 

        // only set these up once
        static $setup = false;
        static $sock;
        if (!$setup) {						
    		$this->addLogger();
            JLog::add('### Starting Auth Request ###', JLog::INFO, 'ModAuthnzFCGI');
            $setup = true;

            // Create a TCP Stream socket 
            $sock = socket_create(AF_INET, SOCK_STREAM, 0); 
            
            // Bind the socket to an address/port 
            if (!socket_bind($sock, $address, $port)) {
                $code = socket_last_error();
                $msg = socket_strerror($code);
                die("$code: $msg"); 
            }

            // Start listening for connections 
            socket_listen($sock); 

            // Non block socket type  
            socket_set_nonblock($sock);
        }

        // JLog::add('running!', JLog::INFO, 'ModAuthnzFCGI');

        static $connections = [];

        // grab all connections in the queue
        while (($newsock = socket_accept($sock)) !== false) {
            if (!is_resource($newsock)) continue; 
            // socket_write($newsock, "$j>", 2).chr(0);               
            JLog::add("New client connected", JLog::INFO, 'ModAuthnzFCGI');
            $connections[] = $newsock; 
        } 

        if (empty($connections)) {
            // JLog::add("No new client connected", JLog::INFO, 'ModAuthnzFCGI');
            return;
        }

        foreach ($connections as $key => $connection) { 
            JLog::add("Checking connection $key", JLog::INFO, 'ModAuthnzFCGI');
            $msg = '';
            while (($len = socket_recv($connection, $string, 1024, MSG_DONTWAIT)) !== false) { 
                // JLog::add("Receiving message ($len)", JLog::INFO, 'ModAuthnzFCGI');
                $msg .= $string;
            }
            if (empty($msg)) { 
                JLog::add("Received empty message", JLog::INFO, 'ModAuthnzFCGI');
                socket_close($connection);
                unset($connections[$key]);
                return;
            }
            JLog::add("Received message ($msg)", JLog::INFO, 'ModAuthnzFCGI');

            $encoded = '';
            for ($i=0; $i<strlen($msg); $i++) {
                $encoded .= $msg[$i].': '.ord($msg[$i])."\n";
            }
            JLog::add("$encoded", JLog::INFO, 'ModAuthnzFCGI');

            // socket_write($connection, "Status: 200\n");
            // socket_write($connection, "Variable-AUTHN_1: authn_01\n");
            // socket_write($connection, "Variable-AUTHN_2: authn_02\n");
            // socket_write($connection, "\n");
            /*
             * had to read the source to get this right
             * http://svn.apache.org/viewvc/httpd/httpd/trunk/modules/aaa/mod_authnz_fcgi.c?view=markup
             * 
             * https://github.com/dequis/dxhttp/blob/master/dxhttp/fcgi.py
             * header = struct.pack(FCGI_Header, self.version, self.type, self.requestId, self.contentLength, self.paddingLength)
             * FCGI_Header = '!BBHHBx'
             * self.version = FCGI_VERSION_1 = 1
             * self.requestId = 1
             * 
             * WELL HERE IS WHAT WE NEED, BUT THIS IS WAY BEYOND WHAT I WANT TO DO :|
             * https://packagist.org/packages/phpfastcgi/fastcgi-daemon
             * https://github.com/PHPFastCGI/FastCGIDaemon
             */
            $response = chr(1);
            $response .= "Status: 200\n\n";
            // echo $response;
            $wrotelen = socket_write($connection, $response);
            if ($wrotelen === false) {
                $code = socket_last_error();
                $msg = socket_strerror($code);
                JLog::add("Error wring to socket ($code: $msg)", JLog::INFO, 'ModAuthnzFCGI');
            } else {
                JLog::add("Wrote to socket ($wrotelen/".strlen($response).")", JLog::INFO, 'ModAuthnzFCGI');
            }
            socket_close($connection);
            unset($connections[$key]);
        } 

        return;

        $app = JFactory::getApplication();
		
		$stdin = fopen('php://stdin', 'r');
		stream_set_blocking($stdin, false);

        // Get the log in credentials.
		$credentials = [];
		$credentials['username']  = trim(fgets($stdin));
		$credentials['password']  = trim(fgets($stdin));
		$credentials['secretkey'] = null;
        
        $options = [];
		$options['remember'] = 0;
		$options['return']   = '';
		
		// foreach ($_ENV as $k => $v) {
		// 	JLog::add($k.'='.$v, JLog::INFO, 'ModAuthnzFCGI');
		// }

		// Accept the login if the user name matchs the password
		if (true !== $app->login($credentials, $options)) {
			$msg = 'Login Failed for'.$credentials['username'];
			JLog::add($msg, JLog::NOTICE, 'ModAuthnzFCGI');
			fwrite(STDERR, "$msg\n");
			exit(1);
		} else {
			$msg = 'Login Success for '.$credentials['username'];
			JLog::add($msg, JLog::NOTICE, 'ModAuthnzFCGI');
			// fwrite(STDERR, "$msg\n");
			$user = JFactory::getUser();
			JLog::add(print_r($user, true), JLog::INFO, 'ModAuthnzFCGI');
			// putenv('JOOMLA_ID', $user->id);
			// apache_setenv('JOOMLA_ID', $user->id);
			// fwrite(STDOUT, "JOOMLA_ID {$user->id}");
			// putenv('JOOMLA_USERNAME', $user->username);
			// apache_setenv('JOOMLA_USERNAME', $user->username);
			// fwrite(STDOUT, "JOOMLA_USERNAME {$user->username}");
			// putenv('JOOMLA_NAME', $user->name);
			// apache_setenv('JOOMLA_NAME', $user->name);
			// fwrite(STDOUT, "JOOMLA_NAME {$user->name}");
			// putenv('JOOMLA_EMAIL', $user->email);
			// apache_setenv('JOOMLA_EMAIL', $user->email);
			// fwrite(STDOUT, "JOOMLA_EMAIL {$user->email}");
			exit(0);
		}
	}

	protected function addLogger() {
		JLog::addLogger(
			array(
				 // Sets file name
				 'text_file' => 'ModAuthnzFCGI.log.php'
			),
			// Sets messages of all log levels to be sent to the file
			JLog::ALL,
			// The log category/categories which should be recorded in this file
			// In this case, it's just the one category from our extension, still
			// we need to put it inside an array
			['ModAuthnzFCGI']
		);
	}
}

JApplicationDaemon::getInstance('CliModAuthnzFCGI')->execute();


// https://github.com/phokz/mod-auth-external/tree/master/mod_authnz_external
/***
#!/usr/bin/perl
use FCGI;
my $request = FCGI::Request();
while ($request->Accept() >= 0) {
    die if $ENV{'FCGI_APACHE_ROLE'} ne "AUTHENTICATOR";
    die if $ENV{'FCGI_ROLE'}        ne "AUTHORIZER";
    die if !$ENV{'REMOTE_PASSWD'};
    die if !$ENV{'REMOTE_USER'};

    print STDERR "This text is written to the web server error log.\n";

    if ( ($ENV{'REMOTE_USER' } eq "foo" || $ENV{'REMOTE_USER'} eq "foo1") &&
        $ENV{'REMOTE_PASSWD'} eq "bar" ) {
        print "Status: 200\n";
        print "Variable-AUTHN_1: authn_01\n";
        print "Variable-AUTHN_2: authn_02\n";
        print "\n";
    }
    else {
        print "Status: 401\n\n";
    }
}
***/