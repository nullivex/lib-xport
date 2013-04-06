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

abstract class Common extends \LSS\Xport {

	const FILE_IO_READ				=	'read';
	const FILE_IO_WRITE				=	'write';
	const FILE_IO_RCOPY				=	'rcopy';
	const FILE_IO_STORE				=	'store';

	//-----------------------------------------------------
	//Buffer Functionality
	//	for internal buffering (readahead, write coalescing)
	//-----------------------------------------------------
	protected $buffer_max			=	1048576;
	protected $buffer_ptr			=	-1;
	protected $buffer				=	'';

	protected function getBuffer(){
		return $this->buffer;
	}

	public function getBufferLimit(){
		return $this->buffer_max;
	}

	public function getBufferSize(){
		return strlen($this->buffer);
	}

	protected function getBufferSlice($offset,$length){
		return substr($this->buffer,$offset - $this->buffer_ptr,$length);
	}

	public function padBuffer($offset,$data){
		$pad_length = ($this->buffer_ptr + $this->getBufferSize()) - $offset;
		$this->buffer = str_pad($data,$pad_length,chr(0),STR_PAD_LEFT);
		return $this;
	}

	public function pokeBuffer($offset,$data){
		$buffer_size = $this->getBufferSize();
		//if we can't poke this data at the offset (outside of buffer window) return false
		if(
			($offset < $this->buffer_ptr)
			||
			($buffer_size + strlen($data) > $this->buffer_max)
		){
			return false;
		}
		if($this->getBufferSize() === 0){
			$this->setBuffer($offset,$data);
			return true;
		}
		if($offset = $this->buffer_ptr + $buffer_size){
			$this->buffer .= $data;
			return true;
		}
		if($offset > $this->buffer_ptr + $buffer_size){
			$this->padBuffer($offset,$data);
			return true;
		}
		return false;
	}

	public function setBuffer($offset,$data){
		$this->buffer_ptr = $offset;
		$this->buffer = $data;
		return $this;
	}

	public function setBufferLimit($limit){
		if(!is_numeric($limit) || $limit < 1)
			throw new Exception('Invalid buffer limit, must be integer greater than 0: '.$limit);
		$this->buffer_max = $limit;
		return $this;
	}

}