<?php

declare(strict_types=1);
	class WaremaRollo extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();

			$this->RegisterPropertyString("Host", "192.168.1.16");
			$this->RegisterPropertyInteger("DestinationID", 304578);
			$this->RegisterTimer("StatusUpdate", 0, 'WaremaControl_UpdateStatus($_IPS["TARGET"]);');
			$this->RegisterVariableInteger("wmsDriveAction", "Fahrbefehl", "",2);
			IPS_SetVariableCustomProfile($this->GetIDForIdent("wmsDriveAction"), "~ShutterMoveStop");
			$this->EnableAction('wmsDriveAction');
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
			//Never delete this line!
			parent::ApplyChanges();
		}

		
		private function build_header($headerfields)
		{
			$header = [];
			foreach ($headerfields as $key => $value) {
				$header[] = $key . ': ' . $value;
			}
			return $header;
		}
		
		private function WebControlPro_HttpRequest($headerfields, $payload)
		{
			$host = $this->ReadPropertyString('Host');

			$url = 'http://' . $host . '/commonCommand';

			$header = $this->build_header($headerfields);

			$curl_opts = [
				CURLOPT_URL            => $url,
				CURLOPT_HTTPHEADER     => $header,
				CURLOPT_CUSTOMREQUEST  => 'GET',
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_HEADER         => true,
				CURLINFO_HEADER_OUT    => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
			];

			$this->SendDebug(__FUNCTION__, 'http-GET, url=' . $url, 0);
			$this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);
			$this->SendDebug(__FUNCTION__, '... payload=' . $payload, 0);

			$time_start = microtime(true);

			$ch = curl_init();
			curl_setopt_array($ch, $curl_opts);
			$response = curl_exec($ch);
			$cerrno = curl_errno($ch);
			$cerror = $cerrno ? curl_error($ch) : '';
			$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_info = curl_getinfo($ch);
			curl_close($ch);

			$duration = round(microtime(true) - $time_start, 2);
			$this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);

			$statuscode = 0;
			$err = '';
			if ($cerrno) {
				$statuscode = 10;//self::$IS_SERVERERROR;
				$err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
			} else {
				$header_size = $curl_info['header_size'];
				$head = substr($response, 0, $header_size);
				$body = substr($response, $header_size);
				$this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
				$this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
				if ($httpcode >= 500 && $httpcode <= 599) {
					$statuscode = 10; //self::$IS_SERVERERROR;
					$err = 'got http-code ' . $httpcode . ' (server error)';
				} elseif ($httpcode != 200) {
					$statuscode = 11;//self::$IS_HTTPERROR;
					$err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
				}
			}
			if ($statuscode == 0) {
				$jbody = @json_decode($body, true);
				if ($jbody == false) {
					$statuscode = self::$IS_INVALIDDATA;
					$err = 'invalid/malformed data';
				}
			}

			if ($statuscode) {
				$this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
				return false;
			}

			//$this->MaintainStatus(IS_ACTIVE);

			return $jbody;
		}

		private function WebControlPro_SendAction($action)
		{
			//$destinationId = $this->ReadPropertyInteger("DestinationID");
			
			$payload = [
				'protocolVersion' => '1.0',
				'source'          => 2,
				'command'         => 'action',
				'responseType'    => 0,
				'actions' 		  => $action
			];

			$headerfields = [
				'Content-Type'    => 'text/plain',
			];

			$response = $this->WebControlPro_HttpRequest($headerfields, json_encode($payload, JSON_PRETTY_PRINT));
			$this->SendDebug(__FUNCTION__, 'response=' . print_r($response, true), 0);
			if (isset($response['errors'])) {
				$ret = [
					'State'  => 9, //self::$STATE_ERROR,
					'Error'  => implode(',', $response['errors']),
				];
			} else {
				$ret = [
					'State'  => 0, //self::$STATE_OK,
					'Data'   => '',
				];
			}
			return $ret;
		}

		public function SetPosition(int $percentage)
		{
			$destinationId = $this->ReadPropertyInteger("DestinationID");
			$action = [[
				"destinationId" => $destinationId,
				"actionId" => 0,
				"parameters" => [
					"percentage" => $percentage
				]
			]];

			$this->WebControlPro_SendAction($action);

		}

		public function SendUp()
		{
			$this->SetPosition(0);
		}

		public function SendDown()
		{
			$this->SetPosition(100);
		}

		public function SendStop()
		{
			$destinationId = $this->ReadPropertyInteger("DestinationID");
			$action = [[
				"destinationId" => $destinationId,
				"actionId" => 16, 
			]];

			$this->WebControlPro_SendAction($action);
		}

		public function WebControlPro_GetConfiguration()
		{
			$payload = [
				'protocolVersion' => '1.0',
				'source'          => 2,
				'command'         => 'getConfiguration',
			];

			$headerfields = [
				'Content-Type'    => 'application/json',
			];

			$response = $this->WebControlPro_HttpRequest($headerfields, json_encode($payload, JSON_PRETTY_PRINT));
			$this->SendDebug(__FUNCTION__, 'response=' . print_r($response, true), 0);
			if (isset($response['errors'])) {
				$ret = [
					'State'  => 9, //self::$STATE_ERROR,
					'Error'  => implode(',', $response['errors']),
				];
			} else {
				$ret = [
					'State'  => 0, //self::$STATE_OK,
					'Data'   => '',
				];
			}
			return $ret;
		}

		public function RequestAction($Ident, $Value)
		{
			$this->SendDebug(__FUNCTION__, 'requestAction=' . print_r($Value, true), 0);
			switch ($Value) {
				case 0:
					$this->SendUp($Value);
					break;
				case 2:
					$this->SendStop($Value);
					break;
				case 4:
					$this->SendDown($Value);
					break;
			}
		}

	}