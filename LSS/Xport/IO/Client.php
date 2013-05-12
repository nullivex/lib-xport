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
namespace LSS\Xport\IO;

use Exception;
use \LSS\Config;
use \LSS\Xport\Crypt;
use \LSS\Xport\Stream;

abstract class Client extends Common {

	protected	$path				=	null;
	protected	$mode				=	null;
	protected	$size				=	null;
	protected	$max_read_length 	=	1045867;
	public		$block_stream		=	null;

	public function __construct(){
		$this->block_stream = Stream::_get();
	}

	protected function genHandle(){
		return $this->handle = gen_handle();
	}

	//-----------------------------------------------------
	//Setters
	//-----------------------------------------------------
	public function setPath($path){
		$this->path = $path;
		return $this;
	}

	public function setMaxReadLength($max_read_length){
		if(!is_numeric($max_read_length) || $max_read_length < 1)
			throw new Exception('Invalid max read length must be a number greater than 0');
		$this->max_read_length = $max_read_length;
		return $this;
	}

	protected function setMode($mode){
		switch($mode){
			case 'r':
			case 'rb':
			case 'w':
			case 'wb':
			case 'a':
			case 'a+':
				$this->mode = $mode;
				return $this;
				break;
			default:
				throw new Exception('Invalid open mode passed: '.$mode);
				break;
		}
	}

	//-----------------------------------------------------
	//Getters
	//-----------------------------------------------------
	public function getPath(){
		return $this->path;
	}

	public function getMaxReadLength(){
		return $this->max_read_length;
	}

	public function getMode(){
		return $this->mode;
	}

	public function getSize(){
		return $this->size;
	}

	//-----------------------------------------------------
	//Stdio Functions
	//-----------------------------------------------------
	public function close(){
		switch($this->getMode()){
			case 'w':
			case 'wb':
			case 'a':
			case 'a+':
				$this->sync();
				$url = $this->getURI();
				return $this->call($url,array(
					 'action'	=>	self::FILE_IO_STORE
					,'path'		=>	$this->path
				));
				break;
			case 'r':
			case 'rb':
			default:
				return microtime(true);
				break;
		}
		return true;
	}

	public function open($path,$mode='r',$select_host=true){
		$this->log->add('XportIO->open called; path: '.$path.' mode: '.$mode);
		$this->setMode($mode);
		switch($mode){
			case 'r':
			case 'rb':
			case 'a':
			case 'ab':
			case 'a+':
				$this->setPath($path);
				$this->size = $this->getFileSizeByPath($path);
				if($select_host) return $this->selectHostByPath($path);
				return true;
				break;
			case 'w':
			case 'wb':
				$this->setPath($path);
				if($select_host) return $this->selectHostByPath($path);
				return true;
				break;
			default:
				return false;
				break;
		}
	}

	public function read($offset=0,$length=null,$crypt=null){
		if(strpos($this->getMode(),'w') === 0 || strpos($this->getMode(),'a') === 0)
			throw new Exception('Reading not supported in write/append only mode');
		//set read length if null
		$readsize = min($this->getMaxReadLength(),$this->buffer_max);
		if(is_null($length)) $length = $readsize;
		//validate length format
		if(!is_numeric($length) || ($length < 1))
			throw new Exception('Read request must have a length');
		//sanity check offset and length with known file size
		if($offset > $this->getSize())
			return false; //eof
		//clamp the length to what's left in the file
		if($offset + $length > $this->getSize())
			$length = $this->getSize() - $offset;
		//clamp length to max
		if($length > $readsize)
			$length = $readsize;
		//calculate our buffer window
		$buf_start	= ($this->buffer_ptr === -1) ? 0 : $this->buffer_ptr;
		$buf_end	= $buf_start + $this->getBufferSize();
		if(($offset < $buf_start) || ($offset + $length > $buf_end)){
			//we aren't in our buffered block, so fill the buffer with something useful
			//build request
			$url = $this->getURI();
			$data = $this->block_stream->encode();
			$rv = $this->call(
				 $url
				,array(
					 'action'		=>	self::FILE_IO_READ
					,'path'			=>	$this->getPath()
					,'offset'		=>	$offset
					,'length'		=>	$readsize
				)
				,$data
			);
			$rv = Stream::receive($data,$crypt)->decode();
			//first 40 bytes is the sha1
			$sha1 = substr($rv,0,40);
			//next 32 bytes is the md5
			$md5 = substr($rv,40,32);
			//strip the first 72 bytes to get the real paylod
			$rv = substr($rv,72,strlen($rv));
			//verify sha1
			if(sha1($rv) !== $sha1)
				throw new Exception('Read failed, payload hash mismatch (sha1)');
			//verify the md5
			if(md5($rv) !== $md5)
				throw new Exception('Read failed, payload hash mismatch (md5)');
			//safe to buffer
			$this->setBuffer($offset,$rv);
		}
		//we should have the request data in the buffer now, deliver the request
		return $this->getBufferSlice($offset,$length);
	}

	public function rcopy($remote_path,$remote_offset=0,$remote_length=null,$handle_offset=0){
		if(!is_numeric($offset) || $offset < 0)
			throw new Exception('Offset must be integer: 0 or greater');
		if(!is_numeric($remote_length) || ($remote_length < 0))
			throw new Exception('Remote File length must be integer 0 or greater');
		$url = $this->getURI();
		$rv = $this->call($url,array(
			 'action'			=>	self::FILE_IO_RCOPY
			,'path'				=>	$this->getPath()
			,'offset'			=>	$handle_offset
			,'remote_path'		=>	$remote_path
			,'remote_offset'	=>	$remote_offset
			,'remote_length'	=>	$remote_length
		));
		if(!isset($rv['offset']))
			throw new Exception('Remote copy to new handle failed');
		return $rv;
	}

	private function sync(){
		//write the current buffer
		$size = $this->getBufferSize();
		$data = $this->getBuffer();
		$sha1 = sha1($data);
		$md5 = md5($data);
		//encrypt the data if neccessary
		$this->block_stream->setPayload($sha1.$md5.$data);
		//setup the call
		$url = $this->getURI();
		$data = $this->block_stream->encode();
		$rv = $this->call(
			 $url
			,array(
				 'action'		=>	self::FILE_IO_WRITE
				,'path'			=>	$this->getPath()
				,'offset'		=>	($this->buffer_ptr > 0 ? $this->buffer_ptr : 0)
				,'size'			=>	$size
			)
			,$data //add data
		);
		//clear the buffer or die
		if(isset($rv['bytes_written']) && $rv['bytes_written'] == $size)
			$this->setBuffer(-1,'');
		else
			throw new Exception('Failed to clear buffer, invalid write response: '.print_r($rv,true));
		return $rv;
	}

	public function write($offset=0,$data=null){
		if(strpos($this->getMode(),'r') === 0)
			throw new Exception('Writing is not supported in read only mode');
		if(!is_null($data) && !is_string($data))
			throw new Exception('Invalid data passed for writing');
		if(!is_numeric($offset) || $offset < 0)
			throw new Exception('Offset must be integer: 0 or greater');
		//calculate our buffer window
		while(!$this->pokeBuffer($offset,$data)){
			$this->sync();
		}
		return array('bytes_written'=>strlen($data));
	}

}
