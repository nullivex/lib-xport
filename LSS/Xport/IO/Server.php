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

use \Exception;
use \LSS\Validate;
use \LSS\Xport\Stream;

abstract class Server extends Common {

	const RCOPY_BLOCK_SIZE = 1048576;

	public $path			=	null;
	public $file			=	null;
	public $handle			=	null;
	public $scratch_file	=	null;
	public $offset			=	0;

	public static function _get(){
		return new static();
	}

	protected function __construct(){}

	public function setPath($path){
		$this->path = $path;
		return $this;
	}

	public function setOffset($offset){
		$this->offset = $offset;
		return $this;
	}

	public function getPath(){
		return $this->path;
	}

	public function getOffset(){
		return $this->offset;
	}

	public function read($length){
		$read_path = $this->getReadPath();
		if(!is_readable($read_path))
			throw new Exception('Failed to read from file: '.$read_path);
		$fh = fopen($read_path,'r');
		if(!is_resource($fh))
			throw new Exception('Failed to open file: '.$read_path);
		if(fseek($fh,$this->offset) === -1)
			throw new Exception('Failed to seek to offset: '.$this->offset);
		$data = '';
		try{
			while((strlen($data) < $length) && (!feof($fh)))
				$data .= fread($fh,$length);
		} catch(Exception $e){
			throw new Exception('Failed to read from file: '.$e->getMessage());
		}
		if(!fclose($fh))
			throw new Exception('Failed to close file: '.$read_path);
		return $data;
	}

	public function write($data){
		$write_path = $this->getWritePath();
		if(!is_writable($write_path))
			throw new Exception('Cannot write to file: '.$write_path);
		$fh = fopen($write_path,'a');
		if(!is_resource($fh))
			throw new Exception('Failed to open file for writing: '.$write_path);
		if(fseek($fh,$this->offset) === -1)
				throw new Exception('Failed to seek to offset: '.$this->offset);
		if(($bytes_written = fwrite($fh,$data)) === false)
			throw new Exception('Failed to write to file: '.$write_path);
		if(!fclose($fh))
			throw new Exception('Failed to close file: '.$write_path);
		return $bytes_written;
	}

	public function rcopy($path,$roffset=0,$rlength=null){
		$remote_path = $this->getRCopyPath($path);
		$write_path = $this->getWritePath();
		//setup remote file
		$rh = fopen($remote_path,'r');
		if(!is_resource($rh))
			throw new Exception('Failed to open remote file for rcopy: '.$remote_path);
		//shift size to max on null or 0
		if(is_null($rlength) || $rlength === 0)
			$rlength = filesize($remote_path);
		if(!is_writable($write_path))
			throw new Exception('Cannot write to file: '.$write_path);
		//setup scratch file
		$fh = fopen($write_path,'a');
		if(!is_resource($fh))
			throw new Exception('Failed to open scratch file for rcopy: '.$write_path);
		if(fseek($fh,$this->offset) === -1)
			throw new Exception('Failed to seek to offset: '.$this->offset);
		//loop and write
		$length = 0;
		while($length < $rlength){
			//check for eof if it came first
			if(feof($rh)) break;
			//read block
			if(($block = fread($rh,self::RCOPY_BLOCK_SIZE)) === false)
				throw new Exception('Failed to read from remote file');
			if(!$block) break;
			if(fwrite($fh,$block) === false)
				throw new Exception('Failed to write to scratch file: '.$write_path);
			$length += self::RCOPY_BLOCK_SIZE;
		}
		if(!fclose($rh))
			throw new Exception('Failed to close readfile for rcopy: '.$remote_path);
		if(!fclose($fh))
			throw new Exception('Failed to close scratch file for rcopy: '.$write_path);
		return $length;
	}

	//-----------------------------------------------------
	//Processes a Request from Xport\IO\Client
	//-----------------------------------------------------
	public static function process($xp,$crypt=null){
		Validate::prime($xp->get());
		Validate::go('action')->not('blank')->is('al');
		Validate::paint();

		$stream = Stream::receive($xp->getRequestData(),$crypt);
		$fileio = static::_get();

		switch($xp->get('action')){

			case static::FILE_IO_READ:
				//validate request
				Validate::prime($xp->get());
				Validate::go('path')->not('blank');
				Validate::go('offset')->is('num');
				Validate::go('length')->is('num');
				Validate::paint();
				//setup and read
				$fileio->setPath($xp->get('path'));
				$fileio->setOffset($xp->get('offset'));
				$data = $fileio->read($xp->get('length'));
				$sha1 = sha1($data);
				$md5 = md5($data);
				//tune data
				$stream->setPayload($sha1.$md5.$data);
				//pass data back
				return $xp->addResponseData($stream->encode());
				break;

			case static::FILE_IO_WRITE:
				//validate request
				Validate::prime($xp->get());
				Validate::go('path')->not('blank');
				Validate::go('offset')->is('num');
				Validate::paint();
				//validate payload
				$data = $stream->decode();
				$sha1 = substr($data,0,40);
				$md5 = substr($data,40,32);
				$data = substr($data,72);
				if(sha1($data) !== $sha1)
					throw new Exception('Payload hash mismatch (sha1) block could not be written');
				if(md5($data) !== $md5)
					throw new Exception('Payload hash mismatch (md5) block could not be written');
				//Setup and write
				$fileio->setPath($xp->get('path'));
				$fileio->setOffset($xp->get('offset'));
				if(($bytes_written = $fileio->write($data)) === false)
					throw new Exception('Failed to write block');
				//pass success
				return $xp->add('bytes_written',$bytes_written);
				break;

			case static::FILE_IO_RCOPY:
				//validate request
				Validate::prime($xp->get());
				Validate::go('path')->not('blank');
				Validate::go('offset')->is('num');
				Validate::go('remote_path')->not('blank');
				Validate::go('remote_offset')->is('num');
				Validate::go('remote_length')->is('num');
				Validate::paint();
				//setup and copy
				$fileio->setPath($xp->get('path'));
				$fileio->setOffset($xp->get('offset'));
				if(
					!(
						$bytes_written = $fileio->rcopy(
							 $xp->get('remote_path')
							,$xp->get('remote_offset')
							,$xp->get('remote_length')
						)
					)
				)
					throw new Exception('Failed to copy from remote file');
				//pass success
				return $xp->add('bytes_written',$bytes_written);
				break;

			case static::FILE_IO_STORE:
				//validate request
				Validate::prime($xp->get());
				Validate::go('path')->not('blank');
				Validate::paint();
				//store new file
				$fileio->setPath($xp->get('path'));
				//send back file info
				return $xp->add('file',$fileio->store());
				break;

			default:
				throw new Exception('Invalid File IO action');
				break;

		}

	}

}
