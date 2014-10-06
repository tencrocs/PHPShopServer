<?php

class PHPMuShopSocket
{
	// maximum amount of clients that can be connected at one time
	const WS_MAX_CLIENTS = 1000;

	// maximum amount of clients that can be connected at one time on the same IP v4 address
	const WS_MAX_CLIENTS_PER_IP = 50;

	// internal
	const WS_READY_STATE_CONNECTING = 0;
	const WS_READY_STATE_OPEN =       1;
	const WS_READY_STATE_CLOSING =    2;
	const WS_READY_STATE_CLOSED =     3;

	// global vars
	public $wsClients       = array();
	public $wsRead          = array();
	public $wsClientCount   = 0;
	public $wsClientIPCount = array();
	public $wsOnEvents      = array();

	/*
		$this->wsClients[ integer ClientID ] = array(
			0 => resource  Socket,                            // client socket
			1 => string    MessageBuffer,                     // a blank string when there's no incoming frames
			2 => integer   ReadyState,                        // between 0 and 3
			3 => integer   LastRecvTime,                      // set to time() when the client is added
			4 => int/false PingSentTime,                      // false when the server is not waiting for a pong
			5 => int/false CloseStatus,                       // close status that wsOnClose() will be called with
			6 => integer   IPv4,                              // client's IP stored as a signed long, retrieved from ip2long()
			7 => int/false FramePayloadDataLength,            // length of a frame's payload data, reset to false when all frame data has been read (cannot reset to 0, to allow reading of mask key)
			8 => integer   FrameBytesRead,                    // amount of bytes read for a frame, reset to 0 when all frame data has been read
			9 => string    FrameBuffer,                       // joined onto end as a frame's data comes in, reset to blank string when all frame data has been read
			10 => integer  MessageOpcode,                     // stored by the first frame for fragmented messages, default value is 0
			11 => integer  MessageBufferLength                // the payload data length of MessageBuffer
		)

		$wsRead[ integer ClientID ] = resource Socket         // this one-dimensional array is used for socket_select()
															  // $wsRead[ 0 ] is the socket listening for incoming client connections

		$wsClientCount = integer ClientCount                  // amount of clients currently connected

		$wsClientIPCount[ integer IP ] = integer ClientCount  // amount of clients connected per IP v4 address
	*/

	function __destruct(){
		$this->wsStopServer();
	}
	
	// server state functions
	public function wsStartServer($host, $port) {
		global $DbConn;
		
		if (isset($this->wsRead[0])) return false;

		if (!$this->wsRead[0] = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) {
			return false;
		}
		if (!socket_set_option($this->wsRead[0], SOL_SOCKET, SO_REUSEADDR, 1)) {
			socket_close($this->wsRead[0]);
			return false;
		}
		if (!socket_bind($this->wsRead[0], $host, $port)) {
			socket_close($this->wsRead[0]);
			return false;
		}
		if (!socket_listen($this->wsRead[0], SOMAXCONN)) {
			socket_close($this->wsRead[0]);
			return false;
		}
		if (!socket_set_nonblock($this->wsRead[0])) {
			socket_close($this->wsRead[0]);
			return false;
		}

		$write = array();
		$except = array();

		$nextBdLiveCheck = time() + 1;
		while (isset($this->wsRead[0])) {
			$changed = $this->wsRead;
			$result = socket_select($changed, $write, $except, 1); // 1 sec max de delay

			if ($result > 0) {
				foreach ($changed as $clientID => $socket) {

					if ($clientID != 0) {
						// client socket changed
						$buffer1 = '';
						$buffer2 = '';
						$bytes = @socket_recv($socket, $buffer1, 4, MSG_PEEK);

						if($buffer1 === NULL || $bytes === 0){
							// 0 bytes received from client, meaning the client closed the TCP connection
							$this->wsRemoveClient($clientID);
						}
						else if ($bytes > 0) {
							$bytes = @socket_recv($socket, $buffer2, (_DecodeHex($buffer1)), 0);
							$this->wsProcessClient($clientID, $buffer2, $bytes);
						}
						 
					}
					else {
						// listen socket changed
						$client = socket_accept($this->wsRead[0]);
						if ($client !== false) {
							socket_set_nonblock($client);
							
							// fetch client IP as integer
							$clientIP = '';
							$result = socket_getpeername($client, $clientIP);
							$clientIP = ip2long($clientIP);

							if ($result !== false && $this->wsClientCount < self::WS_MAX_CLIENTS && (!isset($this->wsClientIPCount[$clientIP]) || $this->wsClientIPCount[$clientIP] < self::WS_MAX_CLIENTS_PER_IP)) {
								$this->wsAddClient($client, $clientIP);
							}
							else {
								socket_close($client);
							}
						}
					}
				}
			}else if($result === false){
				socket_close($this->wsRead[0]);
				return false;
			}
			
			$time = time();
            if ($time >= $nextBdLiveCheck) {
				/*if(!@odbc_check($DbConn)){ // Reconnect function
					$DbConn = SQLConnect();
				}  */
				$nextBdLiveCheck = $time + 5;
			}
		}

		return true; // returned when wsStopServer() is called
	}
	public function wsStopServer() {
		// check if server is not running
		if (!isset($this->wsRead[0])) return false;

		// close all client connections
		foreach ($this->wsClients as $clientID => $client) {
			socket_close($client[0]);
		}

		// close the socket which listens for incoming clients
		socket_close($this->wsRead[0]);

		// reset variables
		$this->wsRead          = array();
		$this->wsClients       = array();
		$this->wsClientCount   = 0;
		$this->wsClientIPCount = array();

		return true;
	}

	// client existence functions
	public function wsAddClient($socket, $clientIP) {
		// increase amount of clients connected
		$this->wsClientCount++;

		// increase amount of clients connected on this client's IP
		if (isset($this->wsClientIPCount[$clientIP])) {
			$this->wsClientIPCount[$clientIP]++;
		}
		else {
			$this->wsClientIPCount[$clientIP] = 1;
		}

		// fetch next client ID
		$clientID = $this->wsGetNextClientID();

		// store initial client data
		$this->wsClients[$clientID] = array($socket, '', self::WS_READY_STATE_CONNECTING, time(), false, 0, $clientIP, false, 0, '', 0, 0);
	
		// store socket - used for socket_select()
		$this->wsRead[$clientID] = $socket;
		
		if ( array_key_exists('open', $this->wsOnEvents) )
			foreach ( $this->wsOnEvents['open'] as $func )
				$func($clientID);
						
	}

	// client data functions
	public function wsGetNextClientID() {
		$i = 1; // starts at 1 because 0 is the listen socket
		while (isset($this->wsRead[$i])) $i++;
		return $i;
	}
	public function wsGetClientSocket($clientID) {
		return $this->wsClients[$clientID][0];
	}

	// client read functions
	public function wsProcessClient($clientID, &$buffer, $bufferLength) {
			if ( array_key_exists('message', $this->wsOnEvents) )
				foreach ( $this->wsOnEvents['message'] as $func )
					$func($clientID, $buffer, $bufferLength);
	}

	public function wsRemoveClient($clientID) {
		// fetch close status (which could be false), and call wsOnClose
		$closeStatus = $this->wsClients[$clientID][5];
		if ( array_key_exists('close', $this->wsOnEvents) )
			foreach ( $this->wsOnEvents['close'] as $func )
				$func($clientID, $closeStatus);

		// close socket
		$socket = $this->wsClients[$clientID][0];
		socket_close($socket);

		// decrease amount of clients connected on this client's IP
		$clientIP = $this->wsClients[$clientID][6];
		if ($this->wsClientIPCount[$clientIP] > 1) {
			$this->wsClientIPCount[$clientIP]--;
		}
		else {
			unset($this->wsClientIPCount[$clientIP]);
		}

		// decrease amount of clients connected
		$this->wsClientCount--;

		// remove socket and client data from arrays
		unset($this->wsRead[$clientID], $this->wsClients[$clientID]);
	}
	
	// client non-internal functions
	public function wsClose($clientID) {
        return $this->wsRemoveClient($clientID);
	}
	public function wsSend($clientID, $message) {
		// check if client ready state is already closing or closed
		if ($this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSING || $this->wsClients[$clientID][2] == self::WS_READY_STATE_CLOSED) return true;

		$socket = $this->wsClients[$clientID][0];
		$sent = @socket_send($socket, $message, strlen($message), 0);
		if ($sent === false) return false;

		return true;
	}

	public function log($message)
	{
		$log_txt = date('H:i:s ').$message;
		echo $log_txt."\n";
		
		$arq = fopen("Log\\".date('Y-m-d').".log","a");
		if($arq){
			@fwrite($arq, $log_txt."\r\n");
			@fclose($arq);
		}
	}

	public function bind( $type, $func )
	{
		if ( !isset($this->wsOnEvents[$type]) )
			$this->wsOnEvents[$type] = array();
		$this->wsOnEvents[$type][] = $func;
	}

	public function unbind( $type='' )
	{
		if ( $type ) unset($this->wsOnEvents[$type]);
		else $this->wsOnEvents = array();
	}
}
?>
