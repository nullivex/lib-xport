<?php
lib('array2xml','xport_stream');

//This is the server response SDK
//	Used to properly respond to the client SDK
//	Does not offer any other extended functionality
class XportResponse {

	//env
	public $params = array();
	public $raw = false;

	//resources
	public $tstream = null;

	public static function _get(){
		return new static();
	}

	public function __construct(){
		//start stream handler
		$this->stream = XportStream::_get()->setCompression(6)->setEncryption(true);
	}

	//-----------------------------------------------------
	//Output Builders
	//-----------------------------------------------------
	public function add($name,$value){
		$this->params[$name] = $value;
	}

	public function addRaw($data){
		$this->raw = $data;
	}

	//-----------------------------------------------------
	//Helpers
	//-----------------------------------------------------
	public function headers(){
		$headers = array();
		//set content type
		if($this->raw === false)
			$headers[] = 'Content-Type: text/xml';
		else
			$headers[] = 'Content-Type: raw';
		return $headers;
	}

	//-----------------------------------------------------
	//Response Transport
	//-----------------------------------------------------
	public function output($echo=true){
		if($this->raw === false)
			$response = Array2XML::createXML('response',$this->params)->saveXML();
		else
			$response = $this->raw;
		$response = $this->stream->encrypt($response);
		$response = $this->stream->compress($response);
		if($echo = false) return $response;
		//set proper output headers
		$headers = array_merge($this->stream->headers(),$this->headers());
		foreach($headers as $header)
			header($header);
		//send response
		echo $response;
		exit;
	}

	//this is a shortcut to send success to the other end
	public function success(){
		$this->add('success',array('msg'=>'Request processed successfully','code'=>0));
		$this->output();
	}

	public function error(Exception $e){
		$this->stream->setEncryption(false);
		$this->stream->setCompression(0);
		$this->add('error',array('msg'=>trim($e->getMessage()),'code'=>$e->getCode()));
		$this->output();
	}

	public function __toString(){
		return $this->output(false);
	}

}
