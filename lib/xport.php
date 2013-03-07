<?php
lib('array2xml','xport_log','xport_stream');

class Xport {

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
		$this->stream = XportStream::_get()->setCompression(6)->setEncryption(true);
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

	protected static function http_parse_headers($header){
		$retVal = array();
		$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
		foreach( $fields as $field ) {
			if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
				$match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
				if( isset($retVal[$match[1]]) ) {
					if (!is_array($retVal[$match[1]])) {
						$retVal[$match[1]] = array($retVal[$match[1]]);
					}
					$retVal[$match[1]][] = $match[2];
				} else {
					$retVal[$match[1]] = trim($match[2]);
				}
			}
		}
		return $retVal;
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
	public function call($uri,$params=array(),$flags=array(),$rawpost=array()){
		$url = $this->makeURL($uri);
		//inspect raw params
		if(!is_array($rawpost))
			throw new Exception('Raw post data must be an array');

		//inject auth key
		if(is_null(Config::get('xport','auth_key')))
			throw new Exception('Cannot make request, no auth key defined');
		$params['xport_auth_key'] = Config::get('xport','auth_key');

		//print some info
		$this->log->add('Setting up call to: '.$url);
		$this->log->add('Params: '.print_r($params,true));
		$this->log->add('Flags Present: '.print_r($flags,true),XportLog::DEBUG);

		//convert params to xml for request
		$request = Array2XML::createXML('request',$params)->saveXML();
		$this->log->add('Request XML: '.$request,XportLog::DEBUG);

		//start curl if needed
		if(!$this->ch) $this->initCURL();

		//set the url to hit
		curl_setopt($this->ch,CURLOPT_URL,$url);

		//encrypt
		$request = $this->stream->encrypt($request);

		//compress
		$request = $this->stream->compress($request);

		//setup curl post
		curl_setopt(
			 $this->ch
			,CURLOPT_POSTFIELDS
			,http_build_query(array_merge($rawpost,array('request'=>$request)))
		);

		//set the proper headers
		curl_setopt($this->ch,CURLOPT_HTTPHEADER,$this->stream->headers());

		//if noexec is passed we simple pass the prepared curl handle back
		if(in_array(self::CALL_NOEXEC,$flags)) return $this->ch;

		//if we are going to exec the call lets make sure we get the headers back
		curl_setopt($this->ch,CURLOPT_HEADER,true);

		//execute the call
		$result = curl_exec($this->ch);
		$this->log->add('RAW Response: '.base64_encode($result),XportLog::DEBUG);

		//separate out the headers
		$header_size = curl_getinfo($this->ch,CURLINFO_HEADER_SIZE);
		$headers = substr($result,0,$header_size);
		$this->log->add('Response Headers RAW: '.$headers,XportLog::DEBUG);
		$headers = self::http_parse_headers($headers);
		$this->log->add('Response Headers PARSED: '.print_r($headers,true),XportLog::DEBUG);
		$this->stream->setByHeaders($headers);
		$result = substr($result,$header_size);

		//decompress
		$result = $this->stream->decompress($result);

		//decrypt
		$result = $this->stream->decrypt($result);

		//check for raw output and just return
		if(mda_get($headers,'Content-Type') == 'raw'){
			$this->log->add('Returning RAW result (size '.strlen($result).')',XportLog::DEBUG);
			return $result;
		}

		//parse the result back into an array
		try {
			$this->log->add('Translating request to array: '.$result,XportLog::DEBUG);
			$result = array_shift(XML2Array::createArray($result));
		} catch(Exception $e){
			throw new Exception('Result is not valid XML: '. $result);
		}

		//log response
		$this->log->add('Request received: '.print_r($result,true));

		//pass to error handler
		$this->errorHandler($result);

		//if we got here there were no errors
		return $result;
	}

}
