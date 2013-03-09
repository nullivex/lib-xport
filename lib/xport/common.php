<?php

abstract class XportCommon {

	//encoders
	const ENC_RAW = 0x00;
	const ENC_SERIALIZE = 0x01;
	const ENC_XML = 0x02;
	const ENC_JSON = 0x03;

	protected $encoding = self::ENC_RAW;

	//This basically turns the output into something human readable
	//	good for debugging, bad for security, size, flexibility
	public function humanize(){
		$this->stream->setCompression(XportStream::COMPRESS_OFF);
		$this->stream->setEncryption(XportStream::CRYPT_OFF);
		$this->setEncoding(XportCommon::ENC_XML);
		return $this;
	}

	public function setEncoding($encoding){
		$this->encoding = $encoding;
		return $this;
	}

	public function getEncoding(){
		return $this->encoding;
	}

	protected function encode($cmd){
		switch($this->encoding){
			default:
			case self::ENC_RAW:
				//void
				break;
			case self::ENC_SERIALIZE:
				$cmd = serialize($cmd);
				break;
			case self::ENC_XML:
				lib('array2xml');
				try {
					$cmd = Array2XML::createXML($cmd)->saveXML();
				} catch(Exception $e){
					throw new Exception('Could not encode XML: '.print_r($cmd));
				}
				break;
			case self::ENC_JSON:
				$cmd = json_encode($cmd);
				break;
		}
		//add encoding type
		$cmd = chr($this->encoding).$cmd;
		return $cmd;
	}

	protected function decode(&$response){
		$encoding = ord(substr($response,0,1));
		$response = substr($response,1);
		switch($encoding){
			default:
			case self::ENC_RAW:
				//void
				break;
			case self::ENC_SERIALIZE:
				$response = unserialize($response);
				break;
			case self::ENC_XML:
				lib('array2xml');
				try {
					$response = XML2Array::createArray($response);
				} catch(Exception $e){
					throw new Exception('Response is not valid XML: '.$response);
				}
				break;
			case self::ENC_JSON:
				$response = json_decode($cmd);
				break;
		}
		return $encoding;
	}

}
