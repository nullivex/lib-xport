<?php
lib('crypt');

class XportStream {

	//const
	const HDR_ENCRYPT_NAME = 'OpenLSS-Crypt';
	const HDR_COMPRESS_NAME = 'compressed';

	//resources
	protected $crypt = null;

	//output modifiers
	protected $encrypt = false;
	protected $compress = false;

	protected $func_compress = 'gzdeflate';
	protected $func_decompress = 'gzinflate';

	protected $payload = null;
	protected $size = null;

	public function _get($payload){
		return new self($payload,Config::get('crypt','key'),Config::get('crypt','iv'));
	}

	public function __construct($payload,$crypt_key,$crypt_iv){
		$this->setPayload($payload);
		$this->crypt = Crypt::_get($crypt_key,$crypt_iv);
	}

	protected function setPayload($payload){
		$this->size = strlen($data);
		$this->payload = $payload;
	}

	//-----------------------------------------------------
	//Stream Modifiers
	//-----------------------------------------------------
	public function setByHeaders($headers){
		if(!is_array($headers))
			throw new Exception('Headers are not array for tstream setup');
		if(mda_get($headers,'X-Content-Encryption') == self::HDR_ENCRYPT_NAME)
			$this->encrypt = true;
		else
			$this->encrypt = false;
		if(mda_get($headers,'X-Content-Encoding') == self::HDR_COMPRESS_NAME)
			$this->compress = true;
		else
			$this->compress = false;
		return $this;
	}

	public function setByParams($params){
		if(!is_array($params))
			throw new Exception('Params are not array for tstream setup');
		if(is_numeric(mda_get($params,'compression')))
			$this->compress = true;
		else
			$this->compress = false;
		if(mda_get($params,'encryption') == 'true')
			$this->encrypt = true;
		else
			$this->encrypt = false;
		return $this;
	}

	public function setEncryption($flag=false){
		$this->encrypt = $flag;
		return $this;
	}

	public function setCompression($level=0){
		if(!is_numeric($level) || $level < 0 || $level > 9)
			throw new Exception('Invalid compression setting, must be integer 0-9: '.$level);
		if($level == 0)
			$this->compress = false;
		else
			$this->compress = $level;
		return $this;
	}

	public function setCompressCallback($callback){
		if(!is_callable($callback))
			throw new Exception('Invalid compress callback passed: '.$callback);
		$this->func_compress = $callback;
		return $this;
	}

	public function setDecompressCallback($callback){
		if(!is_callable($callback))
			throw new Exception('Invalid decompress callback passed: '.$callback);
		$this->func_decompress = $callback;
		return $this;
	}

	//--------------------------------------------------------
	//Internal Handlers
	//--------------------------------------------------------
	protected function chksumCreate(){
		$this->md5 = md5($this->payload);
		$this->sha1 = sha1($this->payload);
	}

	protected function chksumVerify(){
		$md5 = md5($this->payload);
		$sha1 = sha1($this->payload);
		if($sha1 !== $this->sha1)
			throw new Exception('Payload hash check (sha1) failed');
		if($md5 !== $this->md5)
			throw new Exception('Payload hash check (md5) failed');
	}

	protected function pad(){
		$block_size = $this->crypt->getBlockSize();
		$this->payload = str_pad($this->payload,$block_size,"\0",STR_PAD_RIGHT);
	}

	protected function unpad(){
		$this->payload = substr($this->payload,0,$this->size);
	}

	//-----------------------------------------------------
	//Process Functions
	//-----------------------------------------------------
	public function encode(){
		$this->chksumCreate();
		if($this->encrypt !== false){
			$this->pad();
			$this->payload = $this->crypt->encrypt($this->payload);
		}
		if($this->compress !== false)
			$this->payload = call_user_func_array($this->func_compress,$this->payload);
		return $this;
	}

	public function decode(){
		if($this->compress !== false)
			$this->payload = call_user_func_array($this->func_decompress,$this->payload);
		if($this->encrypt !== false){
			$this->payload = $this->crypt->decrypt($this->payload);
			$this->unpad();
		}
		$this->chksumVerify();
		return $this;
	}

	public function get(){
		return $this->payload;
	}

	public function headers(){
		$headers = array();
		if($this->compress !== false)
			$headers[] = 'X-Content-Encoding: '.self::HDR_COMPRESS_NAME;
		if($this->encrypt)
			$headers[] = 'X-Content-Encryption: '.self::HDR_ENCRYPT_NAME;
		return $headers;
	}

	public function params(){
		$params = array();
		if($this->compress !== false)
			$params['compression'] = $this->compress;
		else
			$params['compression'] = 'false';
		if($this->encrypt !== false)
			$params['encryption'] = 'true';
		else
			$params['encryption'] = 'false';
		return $params;
	}

}
