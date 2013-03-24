<?php
/**
 *  OpenLSS - Lighter Smarter Simpler
 *
 *	This file is part of OpenLSS.
 *
 *	OpenLSS is free software: you can redistribute it and/or modify
 *	it under the terms of the GNU Lesser General Public License as
 *	published by the Free Software Foundation, either version 3 of
 *	the License, or (at your option) any later version.
 *
 *	OpenLSS is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU Lesser General Public License for more details.
 *
 *	You should have received a copy of the 
 *	GNU Lesser General Public License along with OpenLSS.
 *	If not, see <http://www.gnu.org/licenses/>.
 */
namespace LSS;
ld('xport_common','xport_log','xport_stream','xport_auth');

class Xport extends XportCommon {

	//call flags
	const CALL_NOEXEC = 1;

	//env
	protected $http_scheme = 'http://';
	protected $http_host = 'localhost';
	protected $http_port = 80;
	protected $auth_handler = 'XportAuthStatic';

	//resources
	public $ch = null;
	public $stream = null;
	public $log = null;

	//static constructor access
	public static function _get(){
		$obj = new static();
		call_user_func_array(array($obj,'init'),func_get_args());
		return $obj;
	}

	private function __construct(){}

	//the real constructor
	protected function init(
		 $http_host='localhost'
		,$http_port=80
		,$http_scheme='http://'
		,$log_level=XportLog::INFO
	){
		//setup stream handler
		$this->stream = XportStream::_get();
		$this->stream->setCompression(XportStream::COMPRESS_GZIP);
		$this->stream->setEncryption(XportStream::CRYPT_LSS);
		//set environment
		$this->setHTTPHost($http_host);
		$this->setHTTPPort($http_port);
		$this->setHTTPScheme($http_scheme);
		//setup logging
		$this->log = XportLog::_get()->setLevel($log_level);
		//make sure the mda package exists
		if(!is_callable('mda_get'))
			throw new Exception('MDA package not loaded: required mda_get()');
		//startup complete
	}

	//-----------------------------------------------------
	//Environment Setters
	//-----------------------------------------------------
	public function setHTTPHost($http_host){
		$this->http_host = $http_host;
		return $this;
	}

	public function setHTTPPort($http_port){
		$this->http_port = $http_port;
		return $this;
	}

	public function setHTTPScheme($http_scheme){
		$this->http_scheme = $http_scheme;
		return $this;
	}

	//-----------------------------------------------------
	//Environment Getters
	//-----------------------------------------------------
	public function getHTTPHost(){
		return $this->http_host;
	}

	public function getHTTPPort(){
		return $this->http_port;
	}

	//-----------------------------------------------------
	//Transport Helpers
	//-----------------------------------------------------
	protected function errorHandler($result){
		if(
			   ($msg = mda_get($result,'error.msg')) != null 
			&& ($code = mda_get($result,'error.code')) != null
		){
			$obj = mda_get($result,'error.exception');
			if(!is_null($obj)){
				$e = unserialize(base64_decode($obj));
				if(is_object($e))
					throw $e;
			}
			throw new Exception($msg,$code);
		}
		return true;
	}

	private function initCURL(){
		$this->ch = curl_init();
		curl_setopt_array($this->ch,array(
			 CURLOPT_RETURNTRANSFER		=>	true
			,CURLOPT_POST				=>	true
		));
	}

	private function makeURL($uri){
		$http_port = ':'.$this->http_port;
		if(
			($this->http_scheme == 'http://' && $this->http_port == 80) ||
			($this->http_scheme == 'https://' && $this->http_port == 443)
		)
			$http_port = null;
		//return url
		return sprintf(
			'%s%s%s%s'
			,$this->http_scheme
			,$this->http_host
			,$http_port
			,$uri
		);
	}

	//-----------------------------------------------------
	//Transport Layer
	//-----------------------------------------------------
	public function call($uri,$cmd=array(),&$data=null,$flags=array()){
		$url = $this->makeURL($uri);

		//inject auth params
		if(!is_callable(array($this->auth_handler,'requestParams')))
			throw new Exception('Auth handler doesnt support requests');
		$cmd = array_merge($cmd,call_user_func($this->auth_handler.'::requestParams'));

		//print some info
		$this->log->add('Setting up call to: '.$url);
		$this->log->add('Command Params: '.print_r($cmd,true));
		$this->log->add('Flags Present: '.print_r($flags,true),XportLog::DEBUG);
		if(!is_null($data))
			$this->log->add('Data present ('.strlen($data).'): '.substr($data,0,50).'...',XportLog::DEBUG);

		//encode cmd params
		$request = $this->encode($cmd);
		$this->log->add('Request Encoded: '.$request,XportLog::DEBUG);

		//start curl if needed
		if(!$this->ch) $this->initCURL();

		//set the url to hit
		curl_setopt($this->ch,CURLOPT_URL,$url);

		//add the payload to the stream
		$this->stream->setPayload($request);

		//setup curl post
		curl_setopt(
			 $this->ch
			,CURLOPT_POSTFIELDS
			,http_build_query(array('request'=>$this->stream->encode(),'data'=>$data))
		);

		//if noexec is passed we simple pass the prepared curl handle back
		if(in_array(self::CALL_NOEXEC,$flags)) return $this->ch;

		//execute the call
		$result = curl_exec($this->ch);
		if($result === false)
			throw new Exception('Call failed to '.$url.' '.curl_error($this->ch));

		//separate channels
		parse_str($result,$response); unset($result);
		if(isset($response['data'])) $data = $response['data'];
		if(isset($response['debug']) && trim($response['debug']) != '') debug_dump($response['debug']);
		if(!isset($response['response']))
			throw new Exception('No response received with request: '.print_r($response,true));
		$response = $response['response'];

		//decode return payload
		$response = XportStream::receive($response,$this->stream->getCrypt())->decode();
		$this->log->add('Response Raw ('.strlen($response).'): '.substr($response,0,50).'...',XportLog::DEBUG);

		//decode the response
		$encoding = $this->decode($response);

		//log response
		if($encoding != self::ENC_RAW){
			// $response = array_shift($response);
			$this->log->add('Response received: '.print_r($response,true),XportLog::DEBUG);
			//pass to error handler
			$this->errorHandler($response);
		}

		//if we got here there were no errors
		return $response;
	}

}
