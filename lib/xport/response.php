<?php
ld('xport_common','xport_stream','xport_log','xport_auth');

//This is the server response SDK
//	Handles the request and sets up the context
//	Used to properly respond to the client SDK
class XportResponse extends XportCommon {

	//yes this is a singleton by design
	static $inst = false;

	//resources
	public $stream = null;
	public $log = null;

	//response
	protected $cmd = array();
	protected $data = ''; //on purpose or it wont output properly

	//request
	protected $request = null;
	protected $request_data = null;

	//env
	protected $auth_handler = 'XportAuthStatic';

	public static function _get(){
		if(!self::$inst)
			self::$inst = new static(post('request'),post('data'));
		return self::$inst;
	}

	public function __construct($request=null,$data=null){
		//start stream handler
		$this->stream = XportStream::receive($request);
		//store request
		$this->request = $this->stream->decode();
		//store data
		$this->request_data = $data;
		//start logging
		$this->log = XportLog::_get()->setLevel(XportLog::DEBUG)->setLabel('Xport-Resp');
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
		if(!is_callable(array($this->auth_handler,'auth')))
			throw new Exception('Cannot authenticate request, auth handler doesnt support requests');
		$rv = call_user_func($this->auth_handler.'::auth',$this->request);
		if($rv !== true)
			throw new Exception('Authentication failed, rejected');
		return $this;
	}

	//-----------------------------------------------------
	//Request getters
	//-----------------------------------------------------
	public function get($key=false){return $this->getRequest($key);}

	public function getRequest($key=false){
		if($key === false){
			//remove api key from request
			$request = $this->request;
			if(is_array($request) && isset($request['xport_auth_key']))
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
	public function add($name,$value=null){return $this->addResponseCMD($name,$value);}

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
	protected function output(){
		//encode the command stream
		$debug = ob_get_contents(); ob_end_clean();
		$this->log->add('Response CMD: '.print_r($this->cmd,true));
		$this->stream->setPayload($this->encode($this->cmd,'response'));
		$rv = array('debug'=>$debug,'response'=>$this->stream->encode(),'data'=>$this->data);
		return http_build_query($rv);
	}

	//this is a shortcut to send success to the other end
	public function success(){
		$this->add('success',array('msg'=>'Request processed successfully','code'=>0));
		return $this;
	}

	public static function error(Exception $e){
		$obj = new self();
		$obj->humanize();
		$obj->add('error',array(
			 'msg'			=>	trim($e->getMessage())
			,'code'			=>	$e->getCode()
			,'exception'	=>	base64_encode(serialize($e))
		));
		return $obj;
	}

	public function __toString(){
		return $this->output();
	}

}
