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
	protected $cmd = array();
	protected $data = false;

	//request
	protected $request = null;
	protected $request_data = null;

	public static function _get(){
		if(is_null(post('request')))
			throw new Exception('No request present');
		return new self(post('request'),post('data'));
	}

	public function __construct($request,$data){
		//start stream handler
		$this->stream = XportStream::receive($request);
		//store request
		$this->request = $this->stream->decode();
		//store data
		$this->request_data = $data;
		//start logging
		$this->log = XportLog::_get()->setLevel($log_level)->setLabel('VC-SDK-REQ');
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

	public function auth(){
		if(is_null(Config::get('xport','auth_key')))
			throw new Exception('Cannot auth request, no auth key defined');
		if(!isset($this->request['xport_auth_key']))
			throw new Exception('No auth key present for authenticated request');
		if($this->request['xport_auth_key'] != Config::get('xport','auth_key'))
			throw new Exception('Invalid auth key passed with request');
		return $this;
	}

	//-----------------------------------------------------
	//Request getters
	//-----------------------------------------------------
	public function get($key=false) return $this->getRequest($key);

	public function getRequest($key=false){
		if($key === false){
			//remove api key from request
			$request = $this->request;
			unset($request['xport_auth_key']);
			//send back
			return $request;
		}
		return mda_get($this->request,$key);
	}

	public function getRequestData(){
		return $this->request_data;
	}

	//-----------------------------------------------------
	//Output Builders
	//-----------------------------------------------------
	public function add($name,$value=null) return $this->addResponseCMD($name,$value);

	public function addResponseCMD($name,$value=null){
		$this->cmd[$name] = $value;
		return $this;
	}

	public function setResponseCMD($val){
		$this->cmd = $val;
		return $this;
	}

	public function getResponseCMD(){
		return $this->cmd;
	}

	public function addResponseData($data){
		$this->data .= $data;
		return $this;
	}

	public function setResponseData($data){
		$this->data = $data;
		return $this;
	}

	public function getResponseData(){
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
		$this->humanize();
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
