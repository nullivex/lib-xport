<?php
lib('xport_common','xport_stream');

//This is the server response SDK
//	Handles the request and sets up the context
//	Used to properly respond to the client SDK
class XportResponse extends XportCommon {

	//resources
	public $stream = null;
	public $log = null;

	//response
	public $cmd = array();
	public $data = false;

	//request
	public $request = null;

	public static function _get($log_level=XportLog::INFO){
		if(is_null(post('request')))
			throw new Exception('No request present');
		return new self(post('request'),$log_level);
	}

	public function __construct($request,$log_level=XportLog::INFO){
		//start stream handler
		$this->stream = XportStream::receive($request);
		//store request
		$this->request = $this->stream->decode();
		//start logging
		$this->log = XportLog::_get()->setLevel($log_level)->setLabel('VC-SDK-REQ');
	}

	public function auth(){
		if(is_null(Config::get('xport','auth_key')))
			throw new Exception('Cannot auth request, no auth key defined');
		if(!isset($this->request['xport_auth_key']))
			throw new Exception('No auth key present for authenticated request');
		if($this->request['xport_auth_key'] != Config::get('xport','auth_key'))
			throw new Exception('Invalid auth key passed with request');
		return $this;
	}

	public function get(){
		//remove api key from request
		$request = $this->request;
		unset($request['xport_auth_key']);
		//send back
		return $request;
	}

	//-----------------------------------------------------
	//Transport Handler
	//-----------------------------------------------------
	public function process(){
		$this->log->add('Request Received from: '.server('REMOTE_ADDR'));
		$encoding = $this->decode($this->request);
		if($encoding != self::ENC_RAW)
			$this->log->add('Parsed Request: '.print_r($this->request,true));
		return $this;
	}

	//-----------------------------------------------------
	//Output Builders
	//-----------------------------------------------------
	public function add($name,$value){
		$this->cmd[$name] = $value;
		return $this;
	}

	public function getCMD(){
		return $this->cmd;
	}

	public function setData($data){
		$this->data = $data;
		return $this;
	}

	public function getData(){
		return $this->data;
	}

	//-----------------------------------------------------
	//Response Transport
	//-----------------------------------------------------
	public function output(){
		//encode the command stream
		$this->stream->addPayload($this->encode($this->cmd));
		return array($this->stream->encode(),$this->data);
	}

	//this is a shortcut to send success to the other end
	public function success(){
		$this->add('success',array('msg'=>'Request processed successfully','code'=>0));
		return $this;
	}

	public function error(Exception $e){
		$this->stream->setCompression(XportStream::COMPRESS_OFF);
		$this->stream->setEncryption(XportStream::CRYPT_OFF);
		$this->add('error',array(
			 'msg'	=>	trim($e->getMessage())
			,'code'	=>	$e->getCode()
			,'trace'=>	base64_encode(serialize($e->getTrace()))
		));
		return $this;
	}

	public function __toString(){
		list($response,$data) = $this->output(false);
		return http_build_query(array('response'=>$response,'data'=>$data));
	}

}
