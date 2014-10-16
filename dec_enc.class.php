<?php

function _DecodeHex($b){
    return hexdec(_ReverseHex(bin2hex($b)));
}

function _hextobin($hexstr)
{
	$n = strlen($hexstr);
	$sbin="";
	$i=0;
	while($i<$n)
	{
		$a =substr($hexstr,$i,2);
		$c = pack("H*",$a);
		if ($i==0){$sbin=$c;}
		else {$sbin.=$c;}
		$i+=2;
	}
	return $sbin;
}

function _dec2hex($dec, $leading_zeros=0){
	$h = dechex($dec);
	if(strlen($h)%2 != 0)$h = "0".$h;
	return _ReverseHex($h, $leading_zeros);
}

function _str2hex($string, $leading_zeros=0){
    $hex = '';
    for ($i=0; $i<strlen($string); $i++){
        $ord = ord($string[$i]);
        $hexCode = dechex($ord);
        $hex .= substr('0'.$hexCode, -2);
    }
    return str_pad(strToUpper($hex), $leading_zeros, '0', STR_PAD_RIGHT);
}

function _ReverseHex($hex, $leading_zeros=0){
	return str_pad(implode('', array_reverse(str_split($hex, 2))), $leading_zeros, '0', STR_PAD_RIGHT);
}
?>
