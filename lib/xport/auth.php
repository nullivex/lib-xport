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
namespace LSS;

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
