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

	public function _get(){
		return new self(Config::get('crypt','key'),Config::get('crypt','iv'));
	}

	public function __construct($crypt_key,$crypt_iv){
		$this->crypt = Crypt::_get($crypt_key,$crypt_iv);
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

	//-----------------------------------------------------
	//Util Functions
	//-----------------------------------------------------
	public function compress($data){
		if($this->compress === false) return $data;
		return call_user_func($this->func_compress,$data,$this->compress);
	}

	public function decompress($data){
		if($this->compress === false) return $data;
		return call_user_func($this->func_decompress,$data);
	}

	public function encrypt($data){
		if($this->encrypt === false) return $data;
		return $this->crypt->encrypt($data,false);
	}

	public function decrypt($data){
		if($this->encrypt === false) return $data;
		return $this->crypt->decrypt($data,false);
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
