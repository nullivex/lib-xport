<?php

interface XportCryptInterface {

	public function verify();
	public function encrypt($data,$base64_encode=true);
	public function decrypt($data,$base64_decode=true);

}

//we use the LSS crypt class as is
ld('crypt');
class XportCrypt extends Crypt implements XportCryptInterface{}
