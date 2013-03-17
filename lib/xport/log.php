<?php

class XportLog {

	//env
	protected $level	=	false;
	protected $callback	=	'dolog';
	protected $sig		=	null;
	protected $label	=	'Xport';

	//level constants
	const ERROR		=	0;
	const WARN		=	1;
	const NOTICE	=	5;
	const INFO		=	6;
	const DEBUG		=	7;

	public static function _get(){
		return new self();
	}

	public function __construct(){
		if(!is_callable('gen_handle'))
			throw new Exception('Cannot start XportLog requires func/gen package: gen_handle');
		$this->sig = gen_handle();
	}

	//-----------------------------------------------------
	//Setters
	//-----------------------------------------------------
	public function setCallback($callback){
		if(!is_callable($callback))
			throw new Exception('Invalid callback passed for logging: '.$callback);
		$this->callback = $callback;
		return $this;
	}

	public function setLevel($level=0){
		//turn logging off if false
		if(is_bool($level) && $level === false){
			$this->level = $level;
		} else {
			//set level otherwise
			if(!is_int($level) || $level < 0)
				throw new Exception('Logging level must be int greater than or equal to 0');
			$this->level = $level;
		}
		return $this;
	}

	public function setLabel($label){
		$this->label = $label;
		return $this;
	}

	//-----------------------------------------------------
	//Setters
	//-----------------------------------------------------
	public function getCallback(){
		return $this->callback;
	}

	public function getLevel(){
		return $this->level;
	}

	public function getLabel(){
		return $this->label;
	}

	//-----------------------------------------------------
	//Logger
	//-----------------------------------------------------
	public function add($msg,$level=self::INFO){
		//dont log if we dont need to
		if($level > $this->level || $this->level === false)
			return false;
		//fail if not initialized
		if(!$this->sig || !$this->callback)
			throw new Exception('Logging not initiated please call Vidcache->initLog()');
		//format the message (gets further formatted by the callback
		$msg = '['.$this->label.'] ['.$this->sig.'] - '.$msg;
		//call to the logging function
		return call_user_func_array($this->callback,array($msg,$level));
	}

}
