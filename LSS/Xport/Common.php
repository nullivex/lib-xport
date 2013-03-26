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

use \LSS\Array2XML;
use \LSS\XML2Array;

abstract class Common {

	//encoders
	const ENC_RAW = 0x00;
	const ENC_SERIALIZE = 0x01;
	const ENC_XML = 0x02;
	const ENC_JSON = 0x03;

	protected $encoding = self::ENC_RAW;

	//This basically turns the output into something human readable
	//	good for debugging, bad for security, size, flexibility
	public function humanize(){
		$this->stream->setCompression(Stream::COMPRESS_OFF);
		$this->stream->setEncryption(Stream::CRYPT_OFF);
		$this->setEncoding(Common::ENC_XML);
		return $this;
	}

	public function setEncoding($encoding){
		$this->encoding = $encoding;
		return $this;
	}

	public function getEncoding(){
		return $this->encoding;
	}

	protected function encode($cmd,$root='request'){
		switch($this->encoding){
			default:
			case self::ENC_RAW:
				//void
				break;
			case self::ENC_SERIALIZE:
				$cmd = serialize($cmd);
				break;
			case self::ENC_XML:
				ld('array2xml');
				try {
					$cmd = Array2XML::createXML($root,$cmd)->saveXML();
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
		$this->encoding = $encoding = ord(substr($response,0,1));
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
				ld('array2xml');
				try {
					$response = array_shift(XML2Array::createArray($response));
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
