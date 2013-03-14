<?php

interface XportAuthInterface {

	public static function requestParams();
	public static function auth($params);

}

abstract class XportAuthStatic implements XportAuthInterface {

	public static function requestParams(){
		if(is_null(Config::get('xport','auth_key')))
			throw new Exception('Cannot make request, no auth key defined');
		return array('xport_auth_key'=>Config::get('xport','auth_key'));
	}

	public static function auth($params){
		if(is_null(Config::get('xport','auth_key')))
			throw new Exception('Cannot auth request, no auth key defined');
		if(!isset($params['xport_auth_key']))
			throw new Exception('No auth key present for authenticated request');
		if($params['xport_auth_key'] != Config::get('xport','auth_key'))
			throw new Exception('Invalid auth key passed with request');
		return true;
	}

}