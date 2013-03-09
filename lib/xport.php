<?php
lib('xport_common','xport_log','xport_stream');

class Xport extends XportCommon {

	//call flags
	const CALL_NOEXEC = 1;

	//env
	protected $http_scheme = 'http://';
	protected $http_host = 'localhost';
	protected $http_port = 8080;

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
		,$http_port=8080
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
		$this->log = XportLog::_get()->setLevel($log_level)->setLabel('VC-SDK');
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
		) throw new Exception('[VC-SDK Exception] '.$msg,$code);
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

		//inject auth key
		if(is_null(Config::get('xport','auth_key')))
			throw new Exception('Cannot make request, no auth key defined');
		$cmd['xport_auth_key'] = Config::get('xport','auth_key');

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
		$this->stream->addPayload($request);

		//setup curl post
		curl_setopt(
			 $this->ch
			,CURLOPT_POSTFIELDS
			,http_build_query(array('request'=>$this->stream->encode(),'data'=>$data)))
		);

		//if noexec is passed we simple pass the prepared curl handle back
		if(in_array(self::CALL_NOEXEC,$flags)) return $this->ch;

		//execute the call
		$result = curl_exec($this->ch);
		if($result === false)
			throw new Exception('Call failed to '.$url.' '.curl_error($this->ch));

		//separate channels
		if(!parse_str($result,$response))
			throw new Exception('Failed to parse result: '.substr($result,0,50));
		$data = $response['data'];
		$response = $response['response'];

		//decode return payload
		$response = XportStream::receive($response)->decode();
		$this->log->add('Response Raw ('.strlen($response).'): '.substr($response,0,50).'...',XportLog::DEBUG);

		//decode the response
		$encoding = $this->decode($result);

		//log response
		if($encoding != self::ENC_RAW){
			$this->log->add('Response received: '.print_r($result,true));
			//pass to error handler
			$this->errorHandler($result);
		}

		//if we got here there were no errors
		return $result;
	}

}
