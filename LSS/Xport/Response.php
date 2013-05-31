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
namespace LSS\Xport;

use \Exception;

//This is the server response SDK
//	Handles the request and sets up the context
//	Used to properly respond to the client SDK
class Response extends Common {

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
	protected $auth_handler = '\LSS\Xport\AuthStatic';

	public static function _get(){
		if(!self::$inst)
			self::$inst = new static(post('request'),post('data'));
		return self::$inst;
	}

	public function __construct($request=null,$data=null){
		//start stream handler
		$this->stream = Stream::receive($request);
		//store request
		$this->request = $this->stream->decode();
		//store data
		$this->request_data = $data;
		//start logging
		$this->log = Log::_get()->setLevel(Log::WARN)->setLabel('Xport-Resp');
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
	public function success($overrides=array()){
		$this->add('success',array_merge(array('msg'=>'Request processed successfully','code'=>0),$overrides));
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
		try {
			return $this->output();
		} catch(Exception $e){
			return (string) $e;
		}
	}

}
