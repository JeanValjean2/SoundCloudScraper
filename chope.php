<?php
	
global	$_PHPMode;
$_PHPMode = "";
function 	PHPMode()		{
global	$_PHPMode;
	if (strlen($_PHPMode))		return $_PHPMode;
	if (isset($_SERVER["HTTP_HOST"]))		$_PHPMode = "web";	elseif (isset($_SERVER["SHELL"]) || isset($_SERVER["argv"]))	$_PHPMode = "cli";	else 	$_PHPMode = "unknown";
	return $_PHPMode;	}
class 	CLIArgs		{	var 	$_ArgIdx;
	function CLIArgs()		{	$this->_ArgIdx = 1;		}
	function nextArg()		{	return $this->_ArgIdx >= $_SERVER["argc"] ? false : $_SERVER["argv"][$this->_ArgIdx++];		}
	function nbArgs()		{	return $_SERVER["argc"];	}	}
function 	prepareCLI()	{	return PHPMode() != "cli" ? false : new CLIArgs();		}

function 	deleteIntermediaryFiles($filesToDelete)		{	foreach($filesToDelete as $FileToDelete)	unlink($FileToDelete);	}
function 	fatalError($exitMessage, $filesToDelete)	{	deleteIntermediaryFiles($filesToDelete);	die($exitMessage);	}

function 	downloadSoundCloudTrack($sourcePage)		{
	$Comps = pathinfo($sourcePage);
	$FinalFileName = $Comps["basename"];
	
	echo "Téléchargement de la page $sourcePage pour analyse...\n";
	$TmpFilePath = "testSC";
	if (file_exists($TmpFilePath) === false)		`curl "$sourcePage" 2>/dev/null > "$TmpFilePath"`;
	$PageContents = file_get_contents($TmpFilePath);
	
	if (preg_match('@"download_url":".*?/tracks/([^/]*?)/@', $PageContents, $Matches) == 0)				fatalError("Pas de piste à extraire !\n", Array("testSC"));
	$TrackId = $Matches[1];
	echo "Piste trouvée : $TrackId\n";
	
	if (preg_match('@<script crossorigin src="(.*?app.*?.js)"></script>@', $PageContents, $Matches) == 0)		fatalError("Pas de fichier app.js à télécharger\n", Array("testSC"));
	$APPJSFileURL = $Matches[1];
	echo "Fichier app.js contenant les informations utiles : $APPJSFileURL\n";
	$PathComps = pathinfo($APPJSFileURL);
	$BaseName = $PathComps["basename"];
	if (file_exists($BaseName) === false)			`curl "$APPJSFileURL" 2>/dev/null > "$BaseName"`;
	$APPJS = file_get_contents($BaseName);
	if (preg_match_all('@client_id\s*:\s*"([^"]*)"@sim', $APPJS, $Matches) == 0)			fatalError("Pas de client_id dans le fichier app.js !\n", Array("testSC", $BaseName));
	$ClientId = $Matches[1][0];
	echo "Client_id trouvé : $ClientId\n";
	
	//L'URL est établie par le code ci-dessous (présent dans app.js) :
	//	r = e._endpointBaseUrl + "i1/tracks/" + encodeURI(e._trackId + "") + "/streams?client_id=" + encodeURIComponent(e._clientId);
	$StreamURL = "https://api.soundcloud.com/i1/tracks/$TrackId/streams?client_id=$ClientId";
	echo "URL du fichier Stream qui contient toutes les versions/qualités du morceau : $StreamURL\n";
	
	$StreamFilePath = "streams.url";
	if (file_exists($StreamFilePath) === false)			`curl "$StreamURL" 2>/dev/null > $StreamFilePath`;
	$AllStreams = json_decode(file_get_contents($StreamFilePath), true);
	if ($AllStreams === false)			fatalError("Impossible d'obtenir un JSON valable\n", Array("testSC", $BaseName, $StreamFilePath));
	
	$TargetFileURL = $AllStreams["http_mp3_128_url"];
	echo "Fichier à télécharger : $TargetFileURL\n";
	`curl "$TargetFileURL" > "$FinalFileName.mp3"`;
	deleteIntermediaryFiles(Array("testSC", $BaseName, $StreamFilePath));
}

//###Extraire l'URL de Soundcloud d'une page externe
function 	retrieveSoundCloudTrackFromExternalPage($sourcePageURL)		{
	$Comps = pathinfo($sourcePageURL);
	$TmpFilePath = $Comps["basename"];
	if (file_exists($TmpFilePath) === false)		`curl "$sourcePageURL" 2>/dev/null > "$TmpFilePath"`;
	$PageContents = file_get_contents($TmpFilePath);
	if (preg_match('@<iframe[^>]*src="(http.*?soundcloud.*?)">@sim', $PageContents, $Matches) == 0)			fatalError("Pas de lecteur Soundcloud sur la page\n", Array($TmpFilePath));
	
	$SoundCloudURL = $Matches[1];
	echo "URL SoundCloud : $SoundCloudURL\n";
	$ParsedURL = parse_url($SoundCloudURL);
	parse_str($ParsedURL["query"], $QueryVars);
	
	$TempFile = "temp.embed";
	if (file_exists($TempFile) === false)		`curl "$SoundCloudURL" 2>/dev/null > "$TempFile"`;
	$EmbedContents = file_get_contents($TempFile);
	$JSONStartMarker = "var c=[";
	$Pos = strpos($EmbedContents, $JSONStartMarker);
	if ($Pos === false)		fatalError("Pas de trace du JSON qui m'intéresse.\n", Array($TmpFilePath, $TempFile));
	$CurPos = $Pos;
	$NbOpened = 0;
	$Ln = strlen($EmbedContents);
	while ($CurPos<$Ln)		{
		$c = $EmbedContents[$CurPos];
		if ($c == "[")		$NbOpened += 1;
		if ($c == "]")		{
			$NbOpened -= 1;
			if (!$NbOpened)		break;
		}
		$CurPos += 1;
	}
	$ExtractedJSON = substr($EmbedContents, $Pos+strlen($JSONStartMarker)-1, $CurPos-$Pos-strlen($JSONStartMarker)+2);
	$JSONVars = json_decode($ExtractedJSON, true);
	if (!isset($JSONVars[0]["data"]))		fatalError("Le tableau JSON de l'embed n'est pas compréhensible...\n", Array($TmpFilePath, $TempFile));
	$TargetURL = null;
	foreach($JSONVars[0]["data"] as $Item)		{
		if (!isset($Item["kind"]))		die("Pas de clé 'kind' dans les données JSON\n");
		if ($Item["kind"] == "track")	{
			$TargetURL = $Item["permalink_url"];
			break;
		}
	}
	if (is_null($TargetURL))				fatalError("Pas d'URL Soundcloud trouvée...\n", Array($TmpFilePath, $TempFile));
	//
	echo "On a trouvé l'adresse suivante : $TargetURL\n";
	downloadSoundCloudTrack($TargetURL);
	deleteIntermediaryFiles(Array($TmpFilePath, $TempFile));
}

$CLI = prepareCLI();
$SourcePage = $CLI->nextArg();
if (preg_match("@soundcloud\.com/@", $SourcePage))		downloadSoundCloudTrack($SourcePage);
else													retrieveSoundCloudTrackFromExternalPage($SourcePage);
	
?>