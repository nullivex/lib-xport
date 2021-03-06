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

use \Exception;
use \LSS\Xport\Common;
use \LSS\Xport\Log;
use \LSS\Xport\Stream;
use \LSS\Xport\AuthStatic;

class Xport extends Common {

	//call flags
	const CALL_NOEXEC = 1;

	//exception handling
	const EXCEPT_NORMAL = 0;
	const EXCEPT_EXTRA = 1;
	const EXCEPT_FULL = 2;

	//env
	protected $http_scheme = 'http://';
	protected $http_host = 'localhost';
	protected $http_port = 80;
	protected $auth_handler = '\LSS\Xport\AuthStatic';
	protected $except_mode = self::EXCEPT_NORMAL;

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
		,$log_level=Log::INFO
	){
		//setup stream handler
		$this->stream = Stream::_get();
		$this->stream->setCompression(Stream::COMPRESS_GZIP);
		$this->stream->setEncryption(Stream::CRYPT_LSS);
		//set environment
		$this->setHTTPHost($http_host);
		$this->setHTTPPort($http_port);
		$this->setHTTPScheme($http_scheme);
		//setup logging
		$this->log = Log::_get()->setLevel($log_level);
		//make sure the mda package exists
		if(!is_callable('mda_get'))
			throw new Exception('MDA package not loaded: required mda_get()');
		//startup complete
	}

	//-----------------------------------------------------
	//Environment Setters
	//-----------------------------------------------------
	public function setHTTPHost($http_host){
		if(!is_string($http_host))
			throw new Exception('Cannot set HTTP host as it is not string');
		$this->http_host = $http_host;
		return $this;
	}

	public function setHTTPPort($http_port){
		if(!is_string($http_port) && !is_int($http_port))
			throw new Exception('Cannot set HTTP port as it is not a string');
		$this->http_port = intval($http_port);
		return $this;
	}

	public function setHTTPScheme($http_scheme){
		if(!is_string($http_scheme))
			throw new Exception('Cannot set HTTP scheme as it is not string');
		$this->http_scheme = $http_scheme;
		return $this;
	}

	public function setExceptMode($mode){
		switch($mode){
			case self::EXCEPT_NORMAL:
			case self::EXCEPT_EXTRA:
			case self::EXCEPT_FULL:
				$this->except_mode = $mode;
				break;
			default:
				throw new Exception('Invalid exception mode passed');
				break;
		}
		return true;
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

	public function getExceptMode(){
		return $this->except_mode;
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
				if(is_object($e)){
					switch($this->getExceptMode()){
						case self::EXCEPT_EXTRA:
						case self::EXCEPT_FULL:
							$class = get_class($e);
							throw new $class($e,$e->getCode());
							break;
						case self::EXCEPT_NORMAL:
						default:
							throw $e;
							break;
					}
				}
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
		$call_start = microtime(true);

		$url = $this->makeURL($uri);

		//inject auth params
		if(!is_callable(array($this->auth_handler,'requestParams')))
			throw new Exception('Auth handler doesnt support requests');
		$cmd = array_merge($cmd,call_user_func($this->auth_handler.'::requestParams'));

		//print some info
		$this->log->add('Setting up call to: '.$url);
		$this->log->add('Command Params: '.print_r($cmd,true));
		$this->log->add('Flags Present: '.print_r($flags,true),Log::DEBUG);
		if(!is_null($data))
			$this->log->add('Data present ('.strlen($data).'): '.substr($data,0,50).'...',Log::DEBUG);

		//encode cmd params
		$request = $this->encode($cmd);
		$this->log->add('Request Encoded: '.$request,Log::DEBUG);

		//start curl if needed
		if(!$this->ch) $this->initCURL();

		//set the url to hit
		curl_setopt($this->ch,CURLOPT_URL,$url);

		//add the payload to the stream
		$this->stream->setPayload($request);

		//setup curl post
		$post_query = http_build_query(array('request'=>$this->stream->encode(),'data'=>$data));
		curl_setopt(
			 $this->ch
			,CURLOPT_POSTFIELDS
			,$post_query
		);

		//if noexec is passed we simple pass the prepared curl handle back
		if(in_array(self::CALL_NOEXEC,$flags)) return $this->ch;

		//try the actual call
		$tries = 1;
		//TODO: tunables that need to be in the config
		$max_tries = 10;
		//the retry sleep gets multiplied by the number of tries
		//	thus the first call waits X ms and the last call waits X * $max_tries ms
		$retry_sleep = 300; //in ms
		//anything not defined here will trickle down and throw an exception
		$http_status_retry = array(5);
		$http_status_complete = array(2,3);
		do {
			//execute the call
			$result = curl_exec($this->ch);
			if($result === false)
				throw new Exception('Call failed to '.$url.' '.curl_error($this->ch));

			//check the response status
			$http_status = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
			$http_status_class = floor($http_status/100);
			if(in_array($http_status_class,$http_status_retry)){
				$sleep_time = $retry_sleep * $tries;
				$this->log->add(
					 "Request failed with response code: "
					.$http_status." retrying ".($max_tries - $tries)
					." more times waiting ".$sleep_time."ms"
					." until next try"
					." req(".$url.")"
					,Log::WARN
				);
				usleep($sleep_time * 1000);
				continue;
			}
			if(in_array($http_status_class,$http_status_complete)){
				//exit the loop on success and continue
				break;
			}
			throw new Exception("Unrecognized HTTP RESPONSE CODE: ".$http_status);
		} while(++$tries < $max_tries);

		//separate channels
		parse_str($result,$response); unset($result);
		if(isset($response['data'])) $data = $response['data'];
		if(isset($response['debug']) && trim($response['debug']) != '') debug_dump($response['debug']);
		if(!isset($response['response']))
			throw new Exception('No response received with request: '.print_r($response,true));
		$response = $response['response'];

		//decode return payload
		$response = Stream::receive($response,$this->stream->getCrypt())->decode();
		$this->log->add('Response Raw ('.strlen($response).'): '.substr($response,0,50).'...',Log::DEBUG);

		//decode the response
		$encoding = $this->decode($response);

		//log response
		if($encoding != self::ENC_RAW){
			// $response = array_shift($response);
			$this->log->add('Response received: '.print_r($response,true),Log::DEBUG);
			//pass to error handler
			$this->errorHandler($response);
		}

		$this->log->add('Request took '.number_format(microtime(true)-$call_start,5).' seconds',Log::DEBUG);

		//if we got here there were no errors
		return $response;
	}

}
