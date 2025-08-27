<?php
// ฟังก์ชันช่วยสร้าง PromptPay payload (ไม่มี echo ใด ๆ)
function emv($tag,$val){ $len=str_pad(strlen($val),2,'0',STR_PAD_LEFT); return $tag.$len.$val; }
function crc16_ccitt($s){
  $c=0xFFFF; for($i=0,$L=strlen($s);$i<$L;$i++){ $c^=(ord($s[$i])<<8);
    for($j=0;$j<8;$j++){ $c=($c&0x8000)?(($c<<1)^0x1021):($c<<1); $c&=0xFFFF; } }
  return $c;
}
function buildPromptPayPayload(string $ppId, float $amount, string $ref): string {
  $id = preg_replace('/\D+/','',$ppId);
  $isMobile = strlen($id)==10;
  $payloadFormat=emv('00','01');
  $method=emv('01','12'); // dynamic
  $aid=emv('00','A000000677010111');
  $sub=$isMobile? emv('01','0066'.substr($id,1)) : emv('02',$id);
  $acct=emv('29',$aid.$sub);
  $country=emv('58','TH'); $currency=emv('53','764');
  $amt=emv('54',number_format($amount,2,'.',''));
  $name=emv('59','TOPUP'); $city=emv('60','BANGKOK');
  $add=emv('62', emv('05', substr($ref,0,25)));
  $raw=$payloadFormat.$method.$acct.$country.$currency.$amt.$name.$city.$add.'6304';
  $crc=strtoupper(dechex(crc16_ccitt($raw)));
  return $raw.str_pad($crc,4,'0',STR_PAD_LEFT);
}
