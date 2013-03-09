<?php
lib('crypt');

class XportStream {

	//resources
	protected $crypt = null;

	//output modifiers
	protected $encrypt = false;
	protected $compress = false;

	protected $payload = null;
	protected $size = null;

	//compression algs
	const COMPRESS_OFF = 0x00;
	const COMPRESS_GZIP = 0x01;
	const COMPRESS_BZIP = 0x02;
	const COMPRESS_LZF = 0x03;

	//crypt handlers
	const CRYPT_OFF = 0x00;
	const CRYPT_LSS = 0x01;

	public static function receive($payload){
		$stream = self::_get();
		$stream->setPayload($payload);
		$stream->setup();
		return $stream;
	}

	public static function _get(){
		return new self(Config::get('crypt','key'),Config::get('crypt','iv'));
	}

	public function __construct($crypt_key,$crypt_iv){
		$this->crypt = Crypt::_get($crypt_key,$crypt_iv);
	}

	protected function setPayload($payload){
		$this->size = strlen($data);
		$this->payload = $payload;
	}

	//-----------------------------------------------------
	//Stream Modifiers
	//-----------------------------------------------------
	public function setEncryption($flag=false){
		$this->encrypt = $flag;
		return $this;
	}

	public function setCompression($flag=false){
		$this->compress = $flag;
		return $this;
	}

	//-----------------------------------------------------
	//Process Functions
	//-----------------------------------------------------
	protected function compress(){
		switch($this->compress){
			case self::COMPRESS_GZIP:
				$this->payload = gzdeflate($this->payload);
				break;
			case self::COMPRESS_BZIP:
				$this->payload = bzcompress($this->payload);
				break;
			case self::COMPRESS_LZF:
				$this->payload = lzf_compress($this->payload);
				break;
			case self::COMPRESS_OFF:
			default:
				//anything but known values result in OFF
				break;
		}
		return true;
	}

	protected function decompress(){
		switch($this->compress){
			case self::COMPRESS_GZIP:
				$this->payload = gzinflate($this->payload);
				break;
			case self::COMPRESS_BZIP:
				$this->payload = bzdecompress($this->payload);
				break;
			case self::COMPRESS_LZF:
				$this->payload = lzf_decompress($this->payload);
				break;
			case self::COMPRESS_OFF:
			default:
				//anything but known values result in OFF
				break;
		}
		return true;
	}

	protected function encrypt(){
		switch($this->encrypt){
			case self::CRYPT_LSS:
				$size = pack('NN',($this->size & 0xffffffff00000000) >> 32,($this->size & 0x00000000ffffffff)); 
				$this->payload = $this->crypt->encrypt($size.$this->payload);
				break;
			case self::CRYPT_OFF:
			default:
				//anything but known values result in OFF
				break;
		}
		return true;
	}

	protected function decrypt(){
		switch($this->encrypt){
			case self::CRYPT_LSS:
				$this->payload = $this->crypt->decrypt($this->payload);
				$s = unpack('NN',substr($this->payload,0,8));
				$this->size = ($s[1] << 32) | $s[2];
				unset($s);
				$this->payload = substr($this->payload,8,$this->size);
				break;
			case self::CRYPT_OFF:
			default:
				//anything but known values result in OFF
				break;
		}
		return true;
	}

	protected function setup(){
		$set = ord(substr($this->payload,0,1));
		$this->payload = substr($this->payload,1);
		$this->encrypt = $set >> 4;
		$this->compress = $set & 0xf0;
	}

	protected function finalize(){
		$set = ($this->encrypt << 4) | $this->compress;
		$this->payload = chr($set & 0xff).$this->payload;
	}

	//-----------------------------------------------------
	//Process Functions
	//-----------------------------------------------------
	public function encode(){
		$this->encrypt();
		$this->compress();
		$this->finalize();
		return $this->payload;
	}

	public function decode(){
		$this->setup();
		$this->decompress();
		$this->decrypt();
		return $this->payload();
	}

	public function get(){
		return $this->payload;
	}

}
