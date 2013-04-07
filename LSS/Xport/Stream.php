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
use \LSS\Config;

class Stream {

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

	//this function requires an LSS environment
	public static function receive($payload,$crypt=null){
		$stream = self::_get();
		if(!is_object($crypt))
			$stream->setCrypt(Crypt::_get(Config::get('xport.crypt.key'),Config::get('xport.crypt.iv')));
		else
			$stream->setCrypt($crypt);
		$stream->setup($payload);
		return $stream;
	}

	public static function _get(){
		return new self();
	}

	public function __construct(){}

	//set crypt handler
	public function setCrypt($crypt){
		$this->crypt = $crypt;
		return $this;
	}

	//get crypt handler
	public function getCrypt(){
		return $this->crypt;
	}

	public function setPayload($payload){
		$this->size = strlen($payload);
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
		if(!is_object($this->crypt))
			throw new Exception('Crypt handler object not available');
		switch($this->encrypt){
			case self::CRYPT_LSS:
				$size = pack('N',$this->size);
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
		if(!is_object($this->crypt))
			throw new Exception('Crypt handler object not available');
		switch($this->encrypt){
			case self::CRYPT_LSS:
				$this->payload = $this->crypt->decrypt($this->payload);
				$this->size = array_shift(unpack('N',substr($this->payload,0,4)));
				$this->payload = substr($this->payload,4,$this->size);
				break;
			case self::CRYPT_OFF:
			default:
				//anything but known values result in OFF
				break;
		}
		return true;
	}

	protected function setup($payload){
		$set = ord(substr($payload,0,1));
		$this->payload = substr($payload,1);
		$this->encrypt = $set >> 4;
		$this->compress = $set & 0x0f;
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
		$this->decompress();
		$this->decrypt();
		return $this->payload;
	}

	public function get(){
		return $this->payload;
	}

}

