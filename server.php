<?php
set_time_limit(0);   // Prevent the server from timing out
error_reporting(15); // Show errors

// Settings
$ServerInfo = array("127.0.0.1", 45644); // Ip, Port
$dbInfo     = array("(LOCAL)", "MuOnline", "user", "password"); //Host, Database, User, Pass
define("DB_VER", 837); //Tested 837,811


include("server.class.php");
include("dec_enc.class.php");
$Server = array();
$DbConn = array();

// Client -> Server
function wsProtocolCore($clientID, $message, $messageLength) {
	global $Server;
	global $DbConn;
	
	// Check
	if($messageLength == 0){return;}
	
	$aRecv = str_split($message, 4); //0=len , 1=head
	$ip = long2ip($Server->wsClients[$clientID][6]);
	$dbVersion = _dec2hex(DB_VER, 8);
	
	/*
	         ( LEN  ) ( HEAD ) ( PACKET                            )
	Example: 16000000 95000000 00000000 00000000 4803 DE07 2200 0000
	*/
	//$Server->log("test: Packet [".bin2hex($message)."] default.");

	// Protocol Core Switch
	switch(_DecodeHex($aRecv[1])+1)
	{
		case 0x96:	// OnInquireSalesZoneScriptVersion
            //$Server->log("OnInquireSalesZoneScriptVersion"); //                                840   .2104  .034
            $Server->wsSend($clientID, _hextobin("16000000"."96000000"."00000000"."0000"."0000"."4803"."DE07"."2200"));
			break;
		
		
        case 0x88:  // OnInquireBannerZoneScriptVersion
            //$Server->log("OnInquireBannerZoneScriptVersion"); //                               841   .2011  .001
            $Server->wsSend($clientID, _hextobin("16000000"."88000000"."00000000"."0000"."0000"."4903"."DB07"."0100"));
            break;
            
			
        case 0x72:  // OnInquireCash
            $character = _DecodeHex($aRecv[4]);
            
            $sql = "SELECT
                    MEMB_INFO.CSPoints,
                    MEMB_CREDITO.creditos,
					MEMB_INFO.memb___id
                    FROM MEMB_INFO
                    LEFT JOIN MEMB_CREDITO ON (MEMB_INFO.memb___id = MEMB_CREDITO.memb___id)
                    WHERE MEMB_INFO.memb_guid='".$character."'";
            $rs = odbc_fetch_array(odbc_exec($DbConn, $sql));

            $ncspoints = is_numeric($rs['CSPoints'])?$rs['CSPoints']:0;
			$ncredits = is_numeric($rs['creditos'])?$rs['creditos']:0;
			
			$float64 = pack("d", $ncspoints);
            $epoints = unpack('H*',$float64);
            
            $float64 = pack("d", $ncredits);
            $credits = unpack('H*',$float64);

            $Server->log("OnInquireCash [".$rs['memb___id']."][".$character."] ePoints[".$ncspoints."] Credits[".$ncredits."]");
                                           //                          DB VER.               id_char                                            ( ?????? )
			$Server->wsSend($clientID, _hextobin("28000000"."72000000".$dbVersion."00000000" . bin2hex($aRecv[4]) . $epoints[1] . $credits[1] . "00000000"));
            break;
            
			
        case 0x74:  // OnBuyProduct
			$result = 4;
            $character = _DecodeHex($aRecv[4]);
			$character_name = str_replace(chr(0),"",substr($message,71,20));
			$account_name = str_replace(chr(0),"",substr($message,20,20));
			$wCoinType = ord(substr($message,157,1)); // 1 ePoint, 2 Credit, 3 Goblin Point
            $wItemCode = _DecodeHex(substr($message,145,4));
			
            $sql = "SELECT
                    MEMB_INFO.CSPoints,
                    MEMB_CREDITO.creditos,
					MEMB_INFO.memb___id
                    FROM MEMB_INFO
                    LEFT JOIN MEMB_CREDITO ON (MEMB_INFO.memb___id = MEMB_CREDITO.memb___id)
                    WHERE MEMB_INFO.memb_guid='".$character."'";
            $rs = odbc_fetch_array(odbc_exec($DbConn, $sql));
            $ncspoints = is_numeric($rs['CSPoints'])?$rs['CSPoints']:0;
			$ncredits = is_numeric($rs['creditos'])?$rs['creditos']:0;
			
			switch($wCoinType)
			{
				case 13: //ePoint
					//$Server->log("ePoint item ".$wItemCode);
					if($wItemCode == 263){ //Rage Fighter Create Card
						if($ncspoints >= 500){
							$sql2 = "SELECT RageFighter FROM AccountCharacter WHERE Id='".$account_name."'";
            				$rs2 = odbc_fetch_array(odbc_exec($DbConn, $sql2));
							if($rs2['RageFighter'] == 1){
								$result = 4;
							}else{
								$Server->log("OnBuyProduct [".$rs['memb___id']."][".$character."] RageFighter create enabled");
								$sql3 = "UPDATE AccountCharacter SET RageFighter=1 WHERE Id='".$account_name."'";
								odbc_exec($DbConn, $sql3);
								decrease_ePoints($character, 500);
								$result = 0;
							}
						}else{
							$result = 1;
						}
					}
					
					if($wItemCode == 2399){ //Summoner Create Card
						if($ncspoints >= 500){
							$sql2 = "SELECT Summoner FROM AccountCharacter WHERE Id='".$account_name."'";
            				$rs2 = odbc_fetch_array(odbc_exec($DbConn, $sql2));
							if($rs2['Summoner'] == 1){
								$result = 4;
							}else{
								$Server->log("OnBuyProduct [".$rs['memb___id']."][".$character."] Summoner create enabled");
								$sql3 = "UPDATE AccountCharacter SET Summoner=1 WHERE Id='".$account_name."'";
								odbc_exec($DbConn, $sql3);
								decrease_ePoints($character, 500);
								$result = 0;
							}
						}else{
							$result = 1;
						}
					}
					
					break;
				case 27: //Credits
					$Server->log("Credits item ".$wItemCode);
					break;
				case 57: //Goblin Point
					$Server->log("Goblin item ".$wItemCode);
					break;
			}
			
			/*
				Result (PT-BR)
				9 = Tipo de ponto selecionado incorreto
				8 = Item de evento - excedeu a quantidade de vezes que pode comprar
				7 = Item de evento não pode ser comprado
				6 = Não pode ser comprado
				5 = Não esta mais disponível
				4 = Não esta disponivel no momento
				3 = Item esgotado
				2 = Espaço insuficiente no armazenamento
				1 = Pontos insuficientes
				0 = Compra realizada
			*/
											//  len          head       DB_ver     result                  char_id             login
			$Server->wsSend($clientID, _hextobin("4F000000"."74000000".$dbVersion."0".$result."000000" . bin2hex($aRecv[4]) . _str2hex($character_name, 40). "000000"."00000000"."00000000"."00000000"."00000000"."00000000"."00000000"."00000000"."FFFFFFFF"."00000000"));
            //$Server->log("OnBuyProduct");
            break;


        case 0x78:  // OnInquireStorageList
            $Server->log("OnInquireStorageList");
            break;
            
			
        case 0x7A:  // OnGiftCash
            $Server->log("OnGiftCash");
            break;


        case 0x76:  // OnGiftProduct
            $Server->log("OnGiftProduct");
            break;


        case 0x7C:  // OnInquireBuyGiftPossibility
            $Server->log("OnInquireBuyGiftPossibility");
            break;


        case 0x84:  // OnInquireEventProductList
            $Server->log("OnInquireEventProductList");
            break;


        case 0x8A:  // OnInquireProductLeftCount
            $Server->log("OnInquireProductLeftCount");
            break;


        case 0x9E:  // OnInquireStorageListPage
            $Server->log("OnInquireStorageListPage");
            break;


        case 0xA7:  // OnInquireStorageListEx
            $Server->log("OnInquireStorageListEx");
            break;


        case 0xA9:  // OnInquireStorageListPageEx
            $Server->log("OnInquireStorageListPageEx");
            break;


        case 0x7E:  // OnUseStorage
            $Server->log("OnUseStorage");
            break;


        case 0xAD:  // OnUseStorageEx
            $Server->log("OnUseStorageEx");
            break;


        case 0xAF:  // OnUseStorageEx
            $Server->log("OnUseStorageEx");
            break;


        case 0x80:  // OnRollbackUseStorage
            $Server->log("OnRollbackUseStorage");
            break;


        case 0x82:  // OnThrowStorage
            $Server->log("OnThrowStorage");
            break;


        case 0xAE:  // OnMileageDeduct
            $Server->log("OnMileageDeduct");
            break;


        case 0x8C:  // OnMileageSave
            $Server->log("OnMileageSave");
            break;


        case 0x90:  // OnMileageLiveSaveUp
            //$Server->log("OnMileageLiveSaveUp");
            break;


        case 0x92:  // OnItemSerialUpdate
            $Server->log("OnItemSerialUpdate");
            break;


        case 0x98:  // OnUpdateVersion
            $Server->log("OnUpdateVersion");
            break;


        case 0x9A:  // OnUpdateBannerVersion
            $Server->log("OnUpdateBannerVersion");
            break;


        case 0x9C:  // OnInquireInGamePointValue
            $Server->log("OnInquireInGamePointValue");
            break;
            
		default: // Invalid
			$Server->log("Error: Packet [".bin2hex($message)."] default.");
			//return $Server->wsClose($clientID);
	}
}

function decrease_ePoints($char_id, $cost){
	global $DbConn;
	
	$sql = "UPDATE MEMB_INFO SET CSPoints=CSPoints-".$cost." WHERE memb_guid='".$char_id."'";
    return odbc_exec($DbConn, $sql);
}

// Conectado
function wsOnOpen($clientID)
{
	global $Server;
	$ip = long2ip($Server->wsClients[$clientID][6]);

	$Server->log("[".$clientID."][".$ip."] connected.");
}

// Disconnected
function wsOnClose($clientID, $status){
	global $Server;
	
	$ip = long2ip($Server->wsClients[$clientID][6]);
	$Server->log("[".$clientID."][".$ip."] disconnected.");
}

function SQLConnect(){
	global $dbInfo;
	global $DbConn;
	
    $DbConn = odbc_connect("Driver={SQL Server Native Client 10.0};Server=".$dbInfo[0].";Database=".$dbInfo[1].";", $dbInfo[2], $dbInfo[3]);
    return $DbConn;
}

//Função inicial
function StartServer()
{
		global $Server;
		global $ServerInfo;
		
		$Server = new PHPMuShopSocket();
		
		$Server->log("Starting PHP Shop Server");
		
		$Server->bind('message', 'wsProtocolCore');
		$Server->bind('open', 'wsOnOpen');
		$Server->bind('close', 'wsOnClose');
	
		$Server->log("Connecting to SQL Server DataBase");
		SQLConnect();
		
		//$Server->log("Loading Items");
		//LoadItems();
		
		$Server->log("Server running. Ip[".$ServerInfo[0]."] Port[".$ServerInfo[1]."]");
		$Server->wsStartServer($ServerInfo[0], $ServerInfo[1]);
}

StartServer();
?>
